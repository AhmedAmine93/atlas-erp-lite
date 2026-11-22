import { h } from "preact";
import { useState } from "preact/hooks";
import { post, type MaintenanceTeam, type MaintenanceTechnician, type MaintenanceSlot, type MaintenanceReport, type WorkOrder } from "./atlas-api";

export type Execute = (title:string, task:()=>Promise<unknown>)=>void;
type Data = {
 teams:MaintenanceTeam[];
 technicians:MaintenanceTechnician[];
 slots:MaintenanceSlot[];
 orders:WorkOrder[];
 busy:boolean;
 execute:Execute;
};
export function TeamWorkspace({teams,technicians,busy,execute}:Pick<Data,"teams"|"technicians"|"busy"|"execute">){
 const [teamCode,setTeamCode]=useState("");
 const [teamName,setTeamName]=useState("");
 const [techCode,setTechCode]=useState("");
 const [techName,setTechName]=useState("");
 const [teamId,setTeamId]=useState("");
 const createTeam=(event:Event)=>{
  event.preventDefault();
  execute("Équipe créée",async()=>{
   await post("maintenance/teams",{code:teamCode.trim(),name:teamName.trim()});
   setTeamCode("");setTeamName("");
  });
 };
 const createTechnician=(event:Event)=>{
  event.preventDefault();
  execute("Technicien créé",async()=>{
   await post("maintenance/technicians",{
    code:techCode.trim(),name:techName.trim(),
    ...(teamId?{team_id:Number(teamId)}:{})
   });
   setTechCode("");setTechName("");setTeamId("");
  });
 };
 return <div class="atlas-jet-two-col">
  <section class="atlas-jet-card"><h2>Équipes techniques ({teams.length})</h2>
   <form class="atlas-jet-inline-form" onSubmit={createTeam}>
    <label>Code équipe *<input value={teamCode} maxLength={60} required onInput={e=>setTeamCode(e.currentTarget.value)}/></label>
    <label>Nom équipe *<input value={teamName} maxLength={180} required onInput={e=>setTeamName(e.currentTarget.value)}/></label>
    <button type="submit" disabled={busy}>Créer l’équipe</button>
   </form>
   <ul class="atlas-jet-directory">{teams.map(team=>
    <li key={team.id}><strong>{team.name}</strong><span>{team.code} · {team.active?"Active":"Inactive"}</span></li>
   )}</ul>
  </section>
  <section class="atlas-jet-card"><h2>Techniciens ({technicians.length})</h2>
   <form class="atlas-jet-inline-form" onSubmit={createTechnician}>
    <label>Code technicien *<input value={techCode} maxLength={60} required onInput={e=>setTechCode(e.currentTarget.value)}/></label>
    <label>Nom technicien *<input value={techName} maxLength={180} required onInput={e=>setTechName(e.currentTarget.value)}/></label>
    <label>Équipe<select value={teamId} onChange={e=>setTeamId(e.currentTarget.value)}>
     <option value="">Aucune</option>{teams.filter(t=>t.active).map(t=><option value={t.id} key={t.id}>{t.name}</option>)}
    </select></label>
    <button type="submit" disabled={busy}>Créer le technicien</button>
   </form>
   <ul class="atlas-jet-directory">{technicians.map(tech=>
    <li key={tech.id}><strong>{tech.name}</strong>
     <span>{tech.code} · {teams.find(t=>t.id===tech.team_id)?.name??"Sans équipe"} · {tech.active?"Actif":"Inactif"}</span></li>
   )}</ul>
  </section>
 </div>;
}
function utcWithOffset(value:string){
 const date=new Date(value);
 if(!value||Number.isNaN(date.getTime()))throw Error("Sélectionnez une date et une heure valides.");
 return date.toISOString().replace(/\.\d{3}Z$/,"+00:00");
}
function dateTimeText(value:string){
 const date=new Date(value);
 return Number.isNaN(date.getTime())?value:date.toLocaleString("fr-FR",{dateStyle:"short",timeStyle:"short"});
}
export function PlanningWorkspace({slots,technicians,orders,busy,execute}:Pick<Data,"slots"|"technicians"|"orders"|"busy"|"execute">){
 const [orderId,setOrderId]=useState("");
 const [technicianId,setTechnicianId]=useState("");
 const [startsAt,setStartsAt]=useState("");
 const [endsAt,setEndsAt]=useState("");
 const activeOrders=new Set(slots.filter(s=>s.status==="scheduled").map(s=>s.work_order_id));
 const bookable=orders.filter(o=>o.status==="open"&&!activeOrders.has(o.id));
 const submit=(event:Event)=>{
  event.preventDefault();
  if(!orderId||!technicianId)return;
  let from:string,to:string;
  try{
   from=utcWithOffset(startsAt);to=utcWithOffset(endsAt);
   if(new Date(from).getTime()>=new Date(to).getTime())throw Error("La fin doit être postérieure au début.");
  }catch(error){
   window.alert(error instanceof Error?error.message:"Dates incorrectes");
   return;
  }
  execute("BT planifié",async()=>{
   await post("maintenance/work-orders/"+orderId+"/schedule",{
    technician_id:Number(technicianId),starts_at:from,ends_at:to
   });
   setOrderId("");setTechnicianId("");setStartsAt("");setEndsAt("");
  });
 };
 const cancel=(id:number)=>{
  const reason=window.prompt("Motif d’annulation / réaffectation du créneau (10 caractères minimum)");
  if(reason===null)return;
  if(reason.trim().length<10){window.alert("Motif de 10 caractères minimum requis.");return;}
  execute("Créneau annulé",()=>post("maintenance/planning/"+id+"/cancel",{reason:reason.trim()}));
 };
 return <>
  <section class="atlas-jet-card"><h2>Planifier un bon de travail</h2>
   <p>Une seule affectation active par BT. PostgreSQL interdit tout chevauchement horaire pour un même technicien.</p>
   <form class="atlas-jet-planning-form" onSubmit={submit}>
    <label>BT ouvert *<select required value={orderId} onChange={e=>setOrderId(e.currentTarget.value)}>
     <option value="">Sélectionner…</option>{bookable.map(o=><option value={o.id} key={o.id}>BT-{o.id} · {o.summary}</option>)}
    </select></label>
    <label>Technicien actif *<select required value={technicianId} onChange={e=>setTechnicianId(e.currentTarget.value)}>
     <option value="">Sélectionner…</option>{technicians.filter(t=>t.active).map(t=><option value={t.id} key={t.id}>{t.code} · {t.name}</option>)}
    </select></label>
    <label>Début *<input required type="datetime-local" value={startsAt} onInput={e=>setStartsAt(e.currentTarget.value)}/></label>
    <label>Fin *<input required type="datetime-local" value={endsAt} onInput={e=>setEndsAt(e.currentTarget.value)}/></label>
    <button type="submit" disabled={busy||!bookable.length||!technicians.some(t=>t.active)}>Planifier</button>
   </form>
  </section>
  <section class="atlas-jet-card"><h2>Calendrier des interventions ({slots.filter(s=>s.status==="scheduled").length} actives)</h2>
   <div class="atlas-jet-scroll"><table><thead><tr>
    <th>Technicien</th><th>BT</th><th>Début</th><th>Fin</th><th>État</th><th>Action</th>
   </tr></thead><tbody>
    {slots.map(slot=><tr key={slot.id}>
     <td>{technicians.find(t=>t.id===slot.technician_id)?.name??("Technicien #"+slot.technician_id)}</td>
     <td>BT-{slot.work_order_id}</td><td>{dateTimeText(slot.starts_at)}</td>
     <td>{dateTimeText(slot.ends_at)}</td><td>{slot.status==="scheduled"?"Planifié":"Annulé"}</td>
     <td>{slot.status==="scheduled"&&orders.find(o=>o.id===slot.work_order_id)?.status==="open"&&
      <button type="button" disabled={busy} onClick={()=>cancel(slot.id)}>Annuler / réaffecter</button>}</td>
    </tr>)}
   </tbody></table></div>
  </section>
 </>;
}
const csvCell=(value:unknown)=>{
 const plain=String(value??"");
 // Prevent spreadsheet formula injection in exported technician names.
 const safe=/^[\s]*[=+\-@]/.test(plain)?"'"+plain:plain;
 return '"'+safe.replace(/"/g,'""')+'"';
};
export function ReportWorkspace({report}: {report:MaintenanceReport|null}){
 const rows=[
  ["BT ouverts",report?.work_orders_by_status.open??0],
  ["BT en cours",report?.work_orders_by_status.in_progress??0],
  ["BT terminés",report?.work_orders_by_status.completed??0],
  ["BT en retard",report?.overdue_work_orders??0],
  ["Créneaux planifiés",report?.schedules_by_status.scheduled??0],
  ["Créneaux annulés",report?.schedules_by_status.cancelled??0]
 ] as [string,number][];
 const exportCsv=()=>{
  if(!report)return;
  const data=[["Indicateur","Valeur"],...rows,[],["Technicien","Interventions planifiées"],
   ...report.technicians_scheduled.map(t=>[t.name,t.scheduled] as [string,number])];
  const csv=data.map(row=>row.map(csvCell).join(";")).join("\r\n");
  const file=new Blob(["\ufeff",csv],{type:"text/csv;charset=utf-8"});
  const url=URL.createObjectURL(file);
  const link=document.createElement("a");
  link.href=url;link.download="atlas-gmao-report-"+report.period.from+"-"+report.period.to+".csv";
  document.body.append(link);link.click();link.remove();URL.revokeObjectURL(url);
 };
 return <section class="atlas-jet-card"><div class="atlas-jet-report-top">
  <div><h2>Rapport technique GMAO</h2><p>{report?"Période du "+report.period.from+" au "+report.period.to:"Données indisponibles"}</p></div>
  <button type="button" disabled={!report} onClick={exportCsv}>Exporter le rapport CSV</button>
 </div>
 <div class="atlas-jet-metrics">{rows.map(([name,value])=>
  <div class="atlas-jet-metric" key={name}><span>{name}</span><strong>{value}</strong></div>
 )}</div>
 <h3>Charge par technicien</h3><div class="atlas-jet-scroll"><table>
  <thead><tr><th>Technicien</th><th>Interventions planifiées</th></tr></thead>
  <tbody>{report?.technicians_scheduled.map(t=><tr key={t.technician_id}>
   <td>{t.name}</td><td>{t.scheduled}</td>
  </tr>)}</tbody>
 </table></div>
 </section>;
}
