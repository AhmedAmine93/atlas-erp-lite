"use client";

import { useEffect, useState, type FormEvent } from "react";
import { all, api, send } from "@/lib/api";

type Hotel = { id:number; name:string; code:string; timezone:string };
type Room = { room_id:number; room_number:string; category:string; physical_status:string; locally_available:boolean; reason_codes:string[] };
type Inventory = {
  hotel_id:number; timezone:string; arrival_date:string; departure_date:string;
  inventory_scope:string; external_allotments_checked:boolean;
  pricing_checked:boolean; subject_to_confirmation:boolean;
  data:Room[]; total:number; current_page:number; last_page:number;
};
type Block = { id:number; room_id:number; kind:string; status:string; starts_on:string; ends_on:string; reason:string };
type Page<T> = {data:T[]; total:number; last_page:number};

const reasonLabels:Record<string,string> = {
  HOTEL_INACTIVE:"Hôtel inactif",
  ROOM_PHYSICALLY_BLOCKED:"Chambre bloquée techniquement",
  OCCUPIED_OR_CONFIRMED:"Réservation confirmée ou chambre occupée",
  ACTIVE_HOLD:"Option temporaire active",
  MAINTENANCE_WINDOW:"Maintenance prévue",
  RESTRICTION:"Restriction locale",
  UNRECOGNIZED_BLOCK:"Blocage non reconnu",
};
const dateToday=()=>{
  const now=new Date();
  return [now.getFullYear(),String(now.getMonth()+1).padStart(2,"0"),String(now.getDate()).padStart(2,"0")].join("-");
};
const tomorrow=(date:string)=>{
  const day=new Date(date+"T12:00:00Z");
  day.setUTCDate(day.getUTCDate()+1);
  return day.toISOString().slice(0,10);
};

export default function HotelAvailabilityWorkspace({activeCompanyId}:{activeCompanyId:number|null}){
  const [hotels,setHotels]=useState<Hotel[]>([]);
  const [hotelId,setHotelId]=useState<number|null>(null);
  const [arrival,setArrival]=useState(dateToday);
  const [departure,setDeparture]=useState(()=>tomorrow(dateToday()));
  const [page,setPage]=useState(1);
  const [result,setResult]=useState<Inventory|null>(null);
  const [blocks,setBlocks]=useState<Block[]>([]);
  const [loading,setLoading]=useState(true);
  const [pending,setPending]=useState(false);
  const [error,setError]=useState("");
  const [notice,setNotice]=useState("");
  const [revision,setRevision]=useState(0);
  const [kind,setKind]=useState("hold");
  const [roomId,setRoomId]=useState("");
  const [reason,setReason]=useState("");
  const [expiresAt,setExpiresAt]=useState("");
  const [blockStart,setBlockStart]=useState(dateToday);
  const [blockEnd,setBlockEnd]=useState(()=>tomorrow(dateToday()));

  useEffect(()=>{
    let live=true;
    setHotels([]);setHotelId(null);setResult(null);setBlocks([]);setLoading(true);setError("");
    all<Hotel>("hotels").then(items=>{
      if(live){setHotels(items);setHotelId(items[0]?.id??null);}
    }).catch((e:Error)=>{if(live)setError(e.message);})
      .finally(()=>{if(live)setLoading(false);});
    return()=>{live=false;};
  },[activeCompanyId]);

  useEffect(()=>{
    if(hotelId===null || !arrival || !departure) return;
    let live=true;
    setResult(null);setError("");setLoading(true);
    const qs=new URLSearchParams({hotel_id:String(hotelId),arrival_date:arrival,departure_date:departure,page:String(page)});
    api<Inventory>("hotels/rooms/availability?"+qs).then(data=>{
      if(live)setResult(data);
    }).catch((e:Error)=>{if(live)setError(e.message);})
      .finally(()=>{if(live)setLoading(false);});
    return()=>{live=false;};
  },[hotelId,arrival,departure,page,activeCompanyId,revision]);

  useEffect(()=>{
    if(hotelId===null)return;
    let live=true;
    const load=async()=>{
      const collected:Block[]=[];
      for(let p=1;p<=20;p++){
        const qs=new URLSearchParams({hotel_id:String(hotelId),page:String(p)});
        const response=await api<Page<Block>>("hotels/rooms/blocks?"+qs);
        collected.push(...response.data);
        if(p>=response.last_page)return collected;
      }
      throw Error("Liste de blocages trop importante pour cette vue.");
    };
    setBlocks([]);
    load().then(rows=>{if(live)setBlocks(rows);})
      .catch((e:Error)=>{if(live)setError(e.message);});
    return()=>{live=false;};
  },[hotelId,revision,activeCompanyId]);

  async function addBlock(e:FormEvent<HTMLFormElement>){
    e.preventDefault();
    if(pending)return;
    if(!roomId){setError("Sélectionnez une chambre affichée.");return;}
    setPending(true);setError("");setNotice("");
    try{
      const payload:Record<string,string|number>={
        room_id:Number(roomId),kind,starts_on:blockStart,ends_on:blockEnd,reason:reason.trim(),
      };
      if(kind==="hold")payload.expires_at=new Date(expiresAt).toISOString();
      await send("hotels/rooms/blocks","POST",payload);
      setNotice("Blocage créé et audité.");setReason("");setRevision(n=>n+1);
    }catch(e){setError((e as Error).message);}
    finally{setPending(false);}
  }

  async function releaseBlock(id:number){
    if(pending)return;
    setPending(true);setError("");setNotice("");
    try{
      await send("hotels/rooms/blocks/"+id+"/release","POST");
      setNotice("Blocage levé.");setRevision(n=>n+1);
    }catch(e){setError((e as Error).message);}
    finally{setPending(false);}
  }

  const hotel=hotels.find(h=>h.id===hotelId);
  const options=result?.data??[];
  return <section className="atlas-domain" aria-label="Disponibilité hôtelière locale">
    <div className="atlas-domain-toolbar">
      <div>
        <h2>Disponibilité des chambres · ATLAS local</h2>
        <p>Occupation, options et maintenance dans ATLAS. La propreté est indépendante du statut commercial.</p>
      </div>
    </div>
    <p className="notice" role="note">
      Vérification locale uniquement : ni disponibilité PMS/CRS externe, ni allotements,
      ni tarifs. Une consultation ne réserve pas une chambre. Confirmation serveur obligatoire.
    </p>
    {error&&<p role="alert" className="error">{error}</p>}
    {notice&&<p role="status" className="notice">{notice}</p>}
    <div className="atlas-domain-form">
      <div className="atlas-domain-fields">
        <label>Hôtel
          <select aria-label="Hôtel disponibilité" value={hotelId??""} onChange={e=>{setHotelId(Number(e.target.value));setPage(1);}}>
            {hotels.map(h=><option key={h.id} value={h.id}>{h.name} · {h.code}</option>)}
          </select>
        </label>
        <label>Arrivée
          <input aria-label="Arrivée disponibilité" type="date" value={arrival}
            onChange={e=>{setArrival(e.target.value);setPage(1);}}/>
        </label>
        <label>Départ
          <input aria-label="Départ disponibilité" type="date" value={departure}
            min={arrival} onChange={e=>{setDeparture(e.target.value);setPage(1);}}/>
        </label>
      </div>
      <small>Fuseau de l’hôtel : {hotel?.timezone??"—"} · Séjour [arrivée, départ).</small>
    </div>
    {loading&&<p role="status">Chargement de l’inventaire local…</p>}
    {!loading&&hotelId===null&&<p>Aucun établissement : créez d’abord un hôtel.</p>}
    {result&&<>
      <p role="status">{result.total} chambre(s) examinées au total.</p>
      <div className="table-scroll"><table>
        <thead><tr><th>Chambre</th><th>Catégorie</th><th>Disponibilité locale</th><th>Motifs</th></tr></thead>
        <tbody>{result.data.map(room=><tr key={room.room_id}>
          <td>{room.room_number}</td><td>{room.category}</td>
          <td>{room.locally_available?"Libre localement":"Indisponible localement"}</td>
          <td>{room.reason_codes.length?room.reason_codes.map(code=>reasonLabels[code]??code).join(" · "):"—"}</td>
        </tr>)}</tbody>
      </table></div>
      <div className="atlas-domain-pagination">
        <button type="button" className="button secondary" disabled={page<=1||loading} onClick={()=>setPage(p=>p-1)}>Précédent</button>
        <span>Page {result.current_page} / {Math.max(result.last_page,1)}</span>
        <button type="button" className="button secondary" disabled={page>=result.last_page||loading} onClick={()=>setPage(p=>p+1)}>Suivant</button>
      </div>
    </>}
    <div className="atlas-domain-toolbar"><div><h3>Options et blocages locaux</h3><p>Gestion datée avec audit, sans accès direct SQL.</p></div></div>
    <form className="atlas-domain-form" onSubmit={e=>void addBlock(e)}>
      <div className="atlas-domain-fields">
        <label>Chambre
          <select aria-label="Chambre à bloquer" required value={roomId} onChange={e=>setRoomId(e.target.value)}>
            <option value="">Sélectionner sur la page</option>
            {options.map(r=><option key={r.room_id} value={r.room_id}>{r.room_number} · {r.category}</option>)}
          </select>
        </label>
        <label>Type de blocage
          <select aria-label="Type de blocage" value={kind} onChange={e=>setKind(e.target.value)}>
            <option value="hold">Option temporaire</option>
            <option value="maintenance">Maintenance</option>
            <option value="restriction">Restriction locale</option>
          </select>
        </label>
        <label>Début <input aria-label="Début du blocage" type="date" required value={blockStart} onChange={e=>setBlockStart(e.target.value)}/></label>
        <label>Fin <input aria-label="Fin du blocage" type="date" required min={blockStart} value={blockEnd} onChange={e=>setBlockEnd(e.target.value)}/></label>
        {kind==="hold"&&<label>Expiration de l’option
          <input aria-label="Expiration de l’option" type="datetime-local" required value={expiresAt} onChange={e=>setExpiresAt(e.target.value)}/>
        </label>}
        <label>Motif <input aria-label="Motif de blocage" required maxLength={300} value={reason} onChange={e=>setReason(e.target.value)}/></label>
      </div>
      <button type="submit" className="button primary" disabled={pending||!roomId}>{pending?"Traitement…":"Créer le blocage"}</button>
    </form>
    <div className="table-scroll"><table>
      <thead><tr><th>Chambre #</th><th>Type</th><th>Début</th><th>Fin</th><th>Statut</th><th>Action</th></tr></thead>
      <tbody>{blocks.map(block=><tr key={block.id}>
        <td>{block.room_id}</td><td>{block.kind}</td><td>{block.starts_on.slice(0,10)}</td>
        <td>{block.ends_on.slice(0,10)}</td><td>{block.status}</td>
        <td>{block.status==="active"?<button type="button" className="button secondary" disabled={pending}
          onClick={()=>void releaseBlock(block.id)}>Lever le blocage</button>:"—"}</td>
      </tr>)}</tbody>
    </table></div>
  </section>;
}
