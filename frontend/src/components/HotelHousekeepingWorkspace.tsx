"use client";

import { useEffect, useMemo, useState } from "react";
import { all, api, send } from "@/lib/api";

type Hotel = {id:number;code:string;name:string;timezone:string;active:boolean};
type Room = {id:number;hotel_id:number;room_number:string;floor_code:string|null;category:string;room_status:string};
type Status = "dirty"|"in_progress"|"clean"|"inspected";
type Task = {id:number;hotel_id:number;room_id:number;business_date:string;status:Status};
type RoomDay = Room & {task_id:number|null;housekeeping_status:Status|null;occupied:number;arrivals:number;departures:number};
const states:Record<Status,string> = {
  dirty:"Sale",in_progress:"En cours",clean:"Propre",inspected:"Inspectée"
};
const nextStatus:Partial<Record<Status,Status>> = {
  dirty:"in_progress",in_progress:"clean",clean:"inspected"
};
const nextAction:Partial<Record<Status,string>> = {
  dirty:"Commencer",in_progress:"Terminer le nettoyage",clean:"Valider l'inspection"
};
const localDate=()=> {
  const current=new Date();
  current.setMinutes(current.getMinutes()-current.getTimezoneOffset());
  return current.toISOString().slice(0,10);
};

export default function HotelHousekeepingWorkspace({activeCompanyId}:{activeCompanyId:number|null}){
  const [hotels,setHotels]=useState<Hotel[]>([]);
  const [rooms,setRooms]=useState<RoomDay[]>([]);

  const [hotelId,setHotelId]=useState<number|null>(null);
  const [businessDate,setBusinessDate]=useState(localDate);
  const [filter,setFilter]=useState("");
  const [loading,setLoading]=useState(true);
  const [tasksLoading,setTasksLoading]=useState(false);
  const [busyId,setBusyId]=useState<number|null>(null);
  const [error,setError]=useState("");
  const [notice,setNotice]=useState("");
  const [reload,setReload]=useState(0);

  useEffect(()=>{
    let live=true;
    setLoading(true);setError("");setRooms([]);setHotels([]);setHotelId(null);
    all<Hotel>("hotels")
      .then(h=>{
        if(!live)return;
        setHotels(h);
        setHotelId(h[0]?.id??null);
      })
      .catch((e:Error)=>{if(live)setError(e.message);})
      .finally(()=>{if(live)setLoading(false);});
    return()=>{live=false;};
  },[activeCompanyId]);

  const selectedHotel=hotels.find(h=>h.id===hotelId);
  const displayedRooms=useMemo(()=>rooms.filter(r=>r.hotel_id===hotelId).sort(
    (a,b)=>(a.floor_code??"").localeCompare(b.floor_code??"","fr")||
      a.room_number.localeCompare(b.room_number,"fr",{numeric:true})
  ),[hotelId,rooms]);
  const taskByRoom=useMemo(()=>new Map(rooms.flatMap(room=>
    room.task_id && room.housekeeping_status ? [[room.id,{
      id:room.task_id,room_id:room.id,hotel_id:room.hotel_id,
      business_date:businessDate,status:room.housekeeping_status
    }] as const] : []
  )),[rooms,businessDate]);

  useEffect(()=>{
    if(hotelId===null || !businessDate) return;
    let live=true;
    setRooms([]);setTasksLoading(true);setError("");
    api<{rooms:RoomDay[]}>("hotels/room-day?hotel_id="+hotelId+"&business_date="+encodeURIComponent(businessDate))
      .then(result=>{if(live)setRooms(result.rooms);})
      .catch((e:Error)=>{if(live)setError(e.message);})
      .finally(()=>{if(live)setTasksLoading(false);});
    return()=>{live=false;};
  },[hotelId,businessDate,reload,activeCompanyId]);

  const counters=useMemo(()=>{
    const counts={dirty:0,in_progress:0,clean:0,inspected:0,unassigned:0,blocked:0};
    for(const room of displayedRooms){
      if(room.room_status!=="available")counts.blocked++;
      const task=taskByRoom.get(room.id);
      if(task)counts[task.status]++;
      else counts.unassigned++;
    }
    return counts;
  },[displayedRooms,taskByRoom]);

  async function act(room:Room,task?:Task){
    if(busyId!==null)return;
    setBusyId(room.id);setNotice("");setError("");
    try{
      if(!task){
        await send("hotels/housekeeping/tasks","POST",{
          room_id:room.id,business_date:businessDate
        });
        setNotice("Mission créée pour la chambre "+room.room_number+".");
      }else{
        const next=nextStatus[task.status];
        if(!next)return;
        await send("hotels/housekeeping/tasks/"+task.id+"/transition","POST",{status:next});
        setNotice("Chambre "+room.room_number+" : "+states[next]+".");
      }
      setReload(v=>v+1);
    }catch(err){setError(err instanceof Error?err.message:"Erreur pendant l'opération.");}
    finally{setBusyId(null);}
  }

  const filtered=displayedRooms.filter(r=>{
    const q=filter.trim().toLowerCase();
    return !q||(r.room_number+" "+(r.floor_code??"")+" "+r.category).toLowerCase().includes(q);
  });
  const floors=Array.from(new Set(filtered.map(r=>r.floor_code||"Étage non renseigné")));
  return <section className="atlas-housekeeping">
    <div className="atlas-housekeeping-head">
      <div>
        <h2>Housekeeping · Pilotage des chambres</h2>
        <p>Missions journalières réelles par établissement, inspection et traçabilité.
          Le statut de propreté reste distinct de la disponibilité des réservations.</p>
      </div>
      <div className="atlas-housekeeping-filters">
        <label>Établissement
          <select aria-label="Établissement housekeeping" value={hotelId??""}
            disabled={loading} onChange={e=>setHotelId(Number(e.target.value))}>
            {hotels.map(h=><option key={h.id} value={h.id}>{h.name} · {h.code}</option>)}
          </select>
        </label>
        <label>Journée d'exploitation
          <input type="date" aria-label="Journée housekeeping"
            value={businessDate} onChange={e=>{setRooms([]);setBusinessDate(e.target.value);}}/>
        </label>
        <label>Rechercher une chambre
          <input type="search" aria-label="Rechercher chambre housekeeping"
            value={filter} placeholder="Numéro, étage, catégorie…"
            onChange={e=>setFilter(e.target.value)}/>
        </label>
      </div>
    </div>
    {error&&<p className="error" role="alert">{error}</p>}
    {notice&&<p className="notice" role="status">{notice}</p>}
    {(loading||tasksLoading)&&<p role="status">Chargement des chambres et missions…</p>}
    {!loading&&!selectedHotel&&<p>Créez un hôtel puis ses chambres dans les menus de référentiel.</p>}
    {selectedHotel&&<>
      <div className="atlas-housekeeping-stats" aria-label="Statistiques housekeeping">
        {([
          ["Chambres",displayedRooms.length],
          ["Sans mission",counters.unassigned],
          ["Sales",counters.dirty],
          ["En cours",counters.in_progress],
          ["Propres",counters.clean],
          ["Inspectées",counters.inspected],
          ["Bloquées techniquement",counters.blocked]
        ] as [string,number][]).map(([label,count])=><div key={label}>
          <span>{label}</span><strong>{count}</strong>
        </div>)}
      </div>
      <p className="atlas-housekeeping-note">
        Une chambre inspectée mais bloquée pour maintenance reste non prête pour l'accueil.
        Les arrivées et les départs proviennent des réservations locales.
      </p>
      {floors.map(floor=><div className="atlas-housekeeping-floor" key={floor}>
        <h3>{floor}</h3>
        <div className="atlas-housekeeping-room-grid">
          {filtered.filter(r=>(r.floor_code||"Étage non renseigné")===floor).map(room=>{
            const task=taskByRoom.get(room.id);
            const blocked=room.room_status!=="available";
            const ready=!blocked&&task?.status==="inspected";
            return <article className="atlas-housekeeping-room" key={room.id}>
              <div className="atlas-housekeeping-room-title">
                <strong>Chambre {room.room_number}</strong>
                <span>{room.category}</span>
              </div>
              <div className="atlas-housekeeping-room-badges">
                <span data-state={task?.status??"none"}>{task?states[task.status]:"Sans mission"}</span>
                <span>{Number(room.occupied)>0?"Séjour en cours":"Aucun séjour en cours enregistré"}</span>
                {Number(room.arrivals)>0&&<span>Arrivées : {room.arrivals}</span>}
                {Number(room.departures)>0&&<span>Départs : {room.departures}</span>}
                {blocked&&<span className="atlas-housekeeping-blocked">Indisponible : {room.room_status}</span>}
                {ready&&<span className="atlas-housekeeping-inspected">Propreté contrôlée</span>}
              </div>
              <button type="button" disabled={busyId!==null||tasksLoading||!!(task?.status==="inspected")}
                onClick={()=>void act(room,task)}>
                {busyId===room.id?"En cours…":task?(nextAction[task.status]??"Inspectée"):"Créer une mission"}
              </button>
            </article>;
          })}
        </div>
      </div>)}
      {!filtered.length&&!tasksLoading&&<p>Aucune chambre pour ces filtres.</p>}
    </>}
  </section>;
}
