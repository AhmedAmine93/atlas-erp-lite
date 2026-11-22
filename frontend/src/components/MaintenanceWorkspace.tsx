"use client";
import { useEffect, useState, type FormEvent } from "react";
import { api, send } from "@/lib/api";

type Row = Record<string, unknown> & { id: number };
type Field = { name: string; label: string; optional?: boolean; kind?: "number" | "date" | "select" | "textarea" | "checkbox"; lookup?: string; choices?: string[] };
type Operation = { label: string; path: (v: Record<string,string>) => string; fields: Field[] };
const field = (name: string, label: string, kind?: Field["kind"], optional = false): Field => ({ name, label, kind, optional });
const lookup = (name: string, label: string, endpoint: string): Field => ({ name, label, kind: "select", lookup: endpoint });
const wo = lookup("work_order_id", "Bon de travail", "maintenance/work-orders");
const asset = lookup("asset_id", "Équipement", "maintenance/assets");
const reason = field("reason", "Motif justifié", "textarea");
const key = field("operation_key", "Clé d'opération unique");
const quantity = field("quantity", "Quantité", "number");
const operations: Record<string, Operation[]> = {
 "maintenance-sites": [
  { label:"Créer un emplacement",path:()=>"maintenance/locations",fields:[lookup("site_id","Site","maintenance/sites"),field("code","Code"),field("name","Nom"),{...lookup("parent_id","Parent","maintenance/locations"),optional:true}] },
  { label:"Déplacer un équipement",path:v=>"maintenance/assets/"+v.asset_id+"/move",fields:[asset,lookup("location_id","Nouvel emplacement","maintenance/locations"),reason] }
 ],
 "maintenance-assets": [
  {label:"Immobiliser",path:v=>"maintenance/assets/"+v.asset_id+"/stop",fields:[asset,reason]},
  {label:"Remettre en service",path:v=>"maintenance/assets/"+v.asset_id+"/restore",fields:[asset,field("inspection_id","ID inspection validée","number"),reason]}
 ],
 "maintenance-requests": [
  {label:"Refuser une demande",path:v=>"maintenance/requests/"+v.request_id+"/reject",fields:[lookup("request_id","DI","maintenance/requests"),reason]}
 ],
 "maintenance-workorders": [
  {label:"Enregistrer du travail",path:v=>"maintenance/work-orders/"+v.work_order_id+"/labor",fields:[wo,key,field("minutes","Durée (minutes)","number"),field("hourly_rate_cents","Tarif horaire (centimes)","number")]},
  {label:"Contrôle final",path:v=>"maintenance/work-orders/"+v.work_order_id+"/inspect",fields:[wo,field("passed","Contrôle réussi","checkbox"),field("note","Compte rendu","textarea")]}
 ],
 "maintenance-planning": [
  {label:"Créer une équipe",path:()=>"maintenance/teams",fields:[field("code","Code"),field("name","Nom")]},
  {label:"Créer un technicien",path:()=>"maintenance/technicians",fields:[field("code","Code"),field("name","Nom"),{...lookup("team_id","Équipe","maintenance/teams"),optional:true}]},
  {label:"Affecter un créneau",path:v=>"maintenance/work-orders/"+v.work_order_id+"/schedule",fields:[wo,lookup("technician_id","Technicien","maintenance/technicians"),field("starts_at","Début ISO 8601 + décalage"),field("ends_at","Fin ISO 8601 + décalage")]},
  {label:"Annuler un créneau",path:v=>"maintenance/planning/"+v.schedule_id+"/cancel",fields:[lookup("schedule_id","Créneau","maintenance/planning"),reason]}
 ],
 "maintenance-preventive": [
  {label:"Créer un plan",path:()=>"maintenance/preventive/plans",fields:[asset,field("code","Code"),field("title","Titre"),{...field("priority","Priorité","select"),choices:["low","normal","high","critical"]},field("interval_days","Période en jours","number"),field("next_due_on","Prochaine échéance","date")]},
  {label:"Générer les occurrences",path:()=>"maintenance/preventive/run",fields:[{...field("through_date","Jusqu'au","date"),optional:true}]}
 ],
 "maintenance-spares": [
  {label:"Réserver",path:v=>"maintenance/work-orders/"+v.work_order_id+"/spares",fields:[wo,lookup("product_id","Produit","products"),quantity,key]},
  {label:"Consommer",path:v=>"maintenance/spares/"+v.reservation_id+"/consume",fields:[lookup("reservation_id","Réservation","maintenance/spares"),quantity,key]},
  {label:"Libérer",path:v=>"maintenance/spares/"+v.reservation_id+"/release",fields:[lookup("reservation_id","Réservation","maintenance/spares"),quantity,reason]}
 ],
 "maintenance-contracts": [
  {label:"Créer un contrat",path:()=>"maintenance/contracts",fields:[field("code","Code"),field("name","Nom"),lookup("site_id","Site","maintenance/sites"),field("response_hours","Délai réponse (heures)","number"),field("starts_on","Début","date"),field("ends_on","Fin","date"),field("calendar_timezone","Fuseau IANA")]},
  {label:"Événement SLA",path:v=>"maintenance/contracts/"+v.contract_id+"/sla-events",fields:[lookup("contract_id","Contrat","maintenance/contracts"),wo,{...field("event","Événement","select"),choices:["started","paused","resumed","breached","escalated","achieved"]},reason]}
 ],
 "maintenance-mobile": [
  {label:"Synchroniser une action terrain",path:v=>"maintenance/work-orders/"+v.work_order_id+"/field-events",fields:[wo,key,{...field("kind","Type","select"),choices:["note","arrival","departure","checklist"]},field("note","Note","textarea"),field("base_version","Version serveur connue","number")]}
 ]
};
const screens: Record<string,{title:string;list:string;cols:string[]}> = {
 "maintenance-sites":{title:"Localisations techniques",list:"maintenance/locations",cols:["id","site_id","code","name","active"]},
 "maintenance-assets":{title:"Suivi des équipements",list:"maintenance/assets",cols:["id","asset_code","status","criticality","location_id"]},
 "maintenance-requests":{title:"Décisions DI",list:"maintenance/requests",cols:["id","reference","title","status"]},
 "maintenance-workorders":{title:"Travaux et contrôle",list:"maintenance/work-orders",cols:["id","summary","status","field_version"]},
 "maintenance-planning":{title:"Planning maintenance",list:"maintenance/planning",cols:["id","work_order_id","technician_id","starts_at","ends_at","status"]},
 "maintenance-preventive":{title:"Plans préventifs",list:"maintenance/preventive/plans",cols:["id","code","title","next_due_on","active"]},
 "maintenance-spares":{title:"Réservations de pièces",list:"maintenance/spares",cols:["id","work_order_id","product_id","reserved_quantity","consumed_quantity","released_quantity"]},
 "maintenance-contracts":{title:"Contrats de maintenance",list:"maintenance/contracts",cols:["id","code","name","response_hours","status"]},
 "maintenance-mobile":{title:"Mobilité terrain",list:"maintenance/work-orders",cols:["id","summary","status","field_version"]},
};
const labelRow = (x:Row) => String(x.code ?? x.reference ?? x.asset_code ?? x.name ?? x.title ?? x.summary ?? x.id);
const printable = (v: unknown) => v == null ? "—" : typeof v === "object" ? JSON.stringify(v) : String(v);
export default function MaintenanceWorkspace({view,activeCompanyId}:{view:string;activeCompanyId:number|null}) {
 const spec=screens[view], isDashboard=view==="maintenance-dashboard";
 const [rows,setRows]=useState<Row[]>([]),[metrics,setMetrics]=useState<Record<string,unknown>>({});
 const [lookups,setLookups]=useState<Record<string,Row[]>>({});
 const [page,setPage]=useState(1),[lastPage,setLastPage]=useState(1),[revision,setRevision]=useState(0);
 const [actionIndex,setActionIndex]=useState(0),[form,setForm]=useState<Record<string,string>>({});
 const [error,setError]=useState(""),[notice,setNotice]=useState(""),[busy,setBusy]=useState(false);
 const op=operations[view]?.[actionIndex];
 const fields=op?.fields ?? [];
 const lookupIds=fields.filter(x=>x.lookup).map(x=>x.lookup as string).join("|");
 useEffect(()=>{
  let active=true;
  if(activeCompanyId===null)return;
  if(isDashboard) api<Record<string,unknown>>("maintenance/dashboard").then(x=>{if(active)setMetrics(x);}).catch(e=>{if(active)setError(e.message);});
  else if(spec)api<{data:Row[];last_page:number}>(spec.list+"?page="+page).then(x=>{if(active){setRows(x.data);setLastPage(x.last_page);}}).catch(e=>{if(active)setError(e.message);});
  return ()=>{active=false;};
 },[view,activeCompanyId,isDashboard,page,revision]); // eslint-disable-line react-hooks/exhaustive-deps
 useEffect(()=>{
  let active=true;
  const list=[...new Set(lookupIds.split("|").filter(Boolean))];
  Promise.all(list.map(async endpoint=>[endpoint,(await api<{data:Row[]}>(endpoint+"?per_page=100")).data] as const))
   .then(values=>{if(active)setLookups(Object.fromEntries(values));}).catch(e=>{if(active)setError(e.message);});
  return ()=>{active=false;};
 },[lookupIds,activeCompanyId,revision]);
 useEffect(()=>setForm({operation_key:crypto.randomUUID()}),[actionIndex,view,activeCompanyId]);
 async function submit(e:FormEvent) {
  e.preventDefault();if(!op||busy)return;
  setBusy(true);setError("");setNotice("");
  const url=op.path(form),payload:Record<string,string|number|boolean>={};
  for(const x of fields) {
   const value=form[x.name]??"";
   if(value===""&&x.optional)continue;
   payload[x.name]=x.kind==="number"?Number(value):x.kind==="checkbox"?value==="true":value;
  }
  for(const x of ["work_order_id","asset_id","request_id","reservation_id","contract_id","schedule_id"])
   if(form[x]&&(url.includes("/"+form[x]+"/")||url.endsWith("/"+form[x])))delete payload[x];
  try{
   await send(url,"POST",payload);
   setNotice("Opération enregistrée et validée par le serveur.");
   setForm({operation_key:crypto.randomUUID()});setRevision(v=>v+1);
  }catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 if(isDashboard)return <section className="panel">
  <h2>Pilotage maintenance</h2>{error&&<p role="alert">{error}</p>}
  <div className="form-grid">{Object.entries(metrics).map(([k,v])=><div key={k} className="card"><strong>{k.replaceAll("_"," ")}</strong><p>{printable(v)}</p></div>)}</div>
  <p>MTTR approximatif basé sur le cycle de vie des BT ; calculs SLA contractuels et MTBF non certifiés.</p>
 </section>;
 if(!spec)return <p>Vue GMAO indisponible.</p>;
 return <section className="panel">
  <div className="panel-heading"><h2>{spec.title}</h2><button className="button secondary" type="button" onClick={()=>setRevision(v=>v+1)}>Actualiser</button></div>
  <p>Données de l’entreprise active. Toutes les écritures sont contrôlées côté serveur.</p>
  {error&&<p role="alert">{error}</p>}{notice&&<p role="status">{notice}</p>}
  <div style={{overflowX:"auto"}}><table><thead><tr>{spec.cols.map(c=><th key={c}>{c.replaceAll("_"," ")}</th>)}</tr></thead>
   <tbody>{rows.map(x=><tr key={x.id}>{spec.cols.map(c=><td key={c}>{printable(x[c])}</td>)}</tr>)}</tbody></table></div>
  {rows.length===0&&<p>Aucune donnée.</p>}
  <div style={{display:"flex",gap:12,alignItems:"center"}}>
   <button type="button" className="button secondary" disabled={page<=1} onClick={()=>setPage(x=>x-1)}>Précédent</button>
   <span>Page {page} / {lastPage}</span>
   <button type="button" className="button secondary" disabled={page>=lastPage} onClick={()=>setPage(x=>x+1)}>Suivant</button>
  </div>
  <section className="panel"><h3>Opérations métier</h3>
   <label htmlFor="m07-action">Opération</label>
   <select id="m07-action" value={actionIndex} onChange={e=>setActionIndex(Number(e.target.value))}>
    {(operations[view]??[]).map((a,i)=><option value={i} key={a.label}>{a.label}</option>)}</select>
   {op&&<form onSubmit={submit}><div className="form-grid">
    {fields.map(x=><label key={x.name}>{x.label}
     {x.kind==="select"?<select required={!x.optional} value={form[x.name]??""} onChange={e=>setForm(v=>({...v,[x.name]:e.target.value}))}>
      <option value="">Choisir</option>{x.lookup?(lookups[x.lookup]??[]).map(row=><option key={row.id} value={row.id}>{labelRow(row)}</option>):
      (x.choices??[]).map(choice=><option value={choice} key={choice}>{choice}</option>)}</select>:
      x.kind==="textarea"?<textarea required={!x.optional} value={form[x.name]??""} onChange={e=>setForm(v=>({...v,[x.name]:e.target.value}))}/>:
      x.kind==="checkbox"?<input type="checkbox" checked={form[x.name]==="true"} onChange={e=>setForm(v=>({...v,[x.name]:String(e.target.checked)}))}/>:
      <input type={x.kind??"text"} required={!x.optional} value={form[x.name]??""} onChange={e=>setForm(v=>({...v,[x.name]:e.target.value}))}/>}
    </label>)}</div>
    <button type="submit" className="button primary" disabled={busy}>{busy?"Enregistrement…":"Valider"}</button>
   </form>}
  </section>
 </section>;
}