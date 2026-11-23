"use client";
import {useEffect,useState,type FormEvent} from "react";
import {api,send} from "@/lib/api";
type RecordRow=Record<string,unknown>&{id:number};
type Page={data:RecordRow[];total:number;last_page:number};
type Field={name:string;label:string;type?:"number"|"password"|"select";options?:string[]};
type Config={area:string;title:string;description:string;columns:string[];fields:Field[]};
const field=(name:string,label:string,type?:Field["type"],options?:string[]):Field=>({name,label,type,options});
const configs:Record<string,Config>={
 "integrations-connectors":{area:"connectors",title:"Connecteurs externes",description:"Connecteurs sandbox uniquement. Les fournisseurs réels nécessitent une certification et un coffre de secrets.",columns:["id","code","name","provider","status","secret_version"],fields:[field("code","Code"),field("name","Nom"),field("provider","Fournisseur","select",["mock","sirene","microsoft_graph","google_workspace","sap","oracle","sqlserver","pms","psp","iot"]),field("secret","Secret (minimum 32 caractères)","password"),field("quota_per_minute","Quota/min","number"),field("timeout_seconds","Timeout (s)","number")]},
 "integrations-api":{area:"api",title:"API et webhooks",description:"Journal des réceptions signées HMAC SHA-256, vérification horodatage et idempotence.",columns:["id","connector_id","event_id","event_type","status","created_at"],fields:[]},
 "integrations-imports":{area:"imports",title:"Imports / exports",description:"Prévisualisation des lignes, rejets documentés, rapport durable et rejeu sans doublon. Aucune mutation de référentiel externe.",columns:["id","kind","mapping_version","sha256","status","accepted_rows","rejected_rows"],fields:[]},
 "integrations-jobs":{area:"jobs",title:"Tâches planifiées",description:"Registre de calendriers et exécutions idempotentes manuelles. Ordonnanceur automatique non connecté.",columns:["id","code","name","task","cadence","timezone","suspended"],fields:[field("code","Code"),field("name","Nom"),field("task","Tâche","select",["health_snapshot"]),field("cadence","Cadence","select",["hourly","daily"]),field("timezone","Fuseau IANA")]},
 "integrations-errors":{area:"errors",title:"Erreurs de synchronisation",description:"Suivi des exceptions et résolution humaine avec journal d'audit.",columns:["id","source_type","source_reference","cause_code","error_summary","status","attempt_count"],fields:[]},
 "integrations-iot":{area:"iot",title:"Capteurs IoT",description:"Registre de capteurs, télémétrie simulée durable et contrôles de bornes. Aucun pilotage machine.",columns:["id","site_id","code","name","unit","lower_bound","upper_bound","active"],fields:[field("site_id","Identifiant du site technique","number"),field("code","Code"),field("name","Nom"),field("unit","Unité"),field("lower_bound","Borne basse","number"),field("upper_bound","Borne haute","number"),field("secret","Secret appareil (minimum 32 caractères)","password")]}
};
const show=(v:unknown)=>v===null||v===undefined?"—":typeof v==="object"?"Voir historique":typeof v==="boolean"?(v?"Oui":"Non"):String(v);
export default function IntegrationsWorkspace({view}:{view:string}){
 const cfg=configs[view];
 const [rows,setRows]=useState<RecordRow[]>([]);
 const [form,setForm]=useState<Record<string,string>>({});
 const [page,setPage]=useState(1),[last,setLast]=useState(1),[total,setTotal]=useState(0),[refresh,setRefresh]=useState(0);
 const [search,setSearch]=useState(""),[creating,setCreating]=useState(false),[busy,setBusy]=useState(false);
 const [error,setError]=useState(""),[notice,setNotice]=useState("");
 const [history,setHistory]=useState<RecordRow[]>([]),[selected,setSelected]=useState<number|null>(null);
 const [secret,setSecret]=useState(""),[target,setTarget]=useState(""),[eventId,setEventId]=useState(""),[value,setValue]=useState("");
 const [rowsJson,setRowsJson]=useState('[{"external_id":"DEMO-01","value":"exemple"}]');
 const [mapping,setMapping]=useState("v1"),[report,setReport]=useState<unknown>(null),[resolution,setResolution]=useState("");
 useEffect(()=>{
  let mounted=true;
  api<Page>("integrations/v1/"+cfg.area+"?page="+page).then(result=>{
   if(!mounted)return;
   setRows(result.data);setTotal(result.total);setLast(result.last_page);setError("");
  }).catch((e:Error)=>{if(mounted)setError(e.message);});
  return()=>{mounted=false;};
 },[cfg.area,page,refresh]);
 const reload=()=>setRefresh(v=>v+1);
 async function post(path:string,payload:unknown,message:string){
  if(busy)return;
  setBusy(true);setError("");setNotice("");
  try{await send(path,"POST",payload);setNotice(message);reload();setCreating(false);}
  catch(e){setError((e as Error).message);}
  finally{setBusy(false);}
 }
 async function create(e:FormEvent<HTMLFormElement>){
  e.preventDefault();
  const payload:Record<string,string|number>={};
  for(const [k,v] of Object.entries(form))if(v!=="")payload[k]=["site_id","lower_bound","upper_bound","quota_per_minute","timeout_seconds"].includes(k)?Number(v):v;
  await post("integrations/v1/"+cfg.area,payload,"Enregistrement ajouté.");
 }
 async function historyFor(id:number){
  try{const r=await api<Page>("integrations/v1/"+cfg.area+"/"+id+"/history");setHistory(r.data);setSelected(id);}
  catch(e){setError((e as Error).message);}
 }
 async function importRows(commit:boolean){
  try{
   const parsed:unknown=JSON.parse(rowsJson);
   if(!Array.isArray(parsed)){setError("Un tableau JSON est requis.");return;}
   setBusy(true);setError("");
   const result=await send("integrations/v1/exchange","POST",{kind:"import",mapping_version:mapping,rows:parsed,commit});
   setReport(result);setNotice(commit?"Rapport validé.":"Prévisualisation sans effet métier.");
   if(commit)reload();
  }catch(e){setError((e as Error).message);}
  finally{setBusy(false);}
 }
 async function webhook(){
  if(!target||!secret||!eventId){setError("Connecteur, secret et événement requis.");return;}
  setBusy(true);setError("");
  try{
   const body=JSON.stringify({event_id:eventId,event_type:"sandbox.demo",payload:{demo:true}});
   const timestamp=String(Math.floor(Date.now()/1000));
   const key=await crypto.subtle.importKey("raw",new TextEncoder().encode(secret),{name:"HMAC",hash:"SHA-256"},false,["sign"]);
   const signatureBuffer=await crypto.subtle.sign("HMAC",key,new TextEncoder().encode(timestamp+"."+body));
   const signature=Array.from(new Uint8Array(signatureBuffer),b=>b.toString(16).padStart(2,"0")).join("");
   await api("integrations/v1/webhooks/"+Number(target)+"/receive",{method:"POST",headers:{"X-Atlas-Webhook-Timestamp":timestamp,"X-Atlas-Webhook-Signature":signature},body});
   setNotice("Événement accepté (ou dédupliqué).");reload();
  }catch(e){setError((e as Error).message);}
  finally{setBusy(false);}
 }
 return <section style={{display:"grid",gap:"1rem"}} aria-label={cfg.title}>
  <p>{cfg.description}</p>
  {error&&<div role="alert" className="alert error">{error}</div>}
  {notice&&<div role="status" className="alert success">{notice}</div>}
  <div style={{display:"flex",gap:8,flexWrap:"wrap",alignItems:"center"}}>
   <input aria-label="Filtrer les enregistrements affichés" placeholder="Rechercher sur cette page" value={search} onChange={e=>setSearch(e.target.value)}/>
   <button type="button" onClick={reload}>Actualiser</button>
   {!!cfg.fields.length&&<button type="button" onClick={()=>setCreating(v=>!v)}>{creating?"Fermer":"Ajouter"}</button>}
   <span>{total} enregistrement(s)</span>
  </div>
  {creating&&<form onSubmit={create} style={{display:"grid",gap:8,maxWidth:560}}>
   {cfg.fields.map(f=><label key={f.name}>{f.label}
    {f.type==="select"?<select required value={form[f.name]||""} onChange={e=>setForm(v=>({...v,[f.name]:e.target.value}))}>
     <option value="">Choisir…</option>{f.options?.map(x=><option key={x} value={x}>{x}</option>)}
    </select>:<input required={!["lower_bound","upper_bound","quota_per_minute","timeout_seconds"].includes(f.name)} type={f.type==="password"?"password":f.type==="number"?"number":"text"} step={f.type==="number"?"any":undefined} value={form[f.name]||""} onChange={e=>setForm(v=>({...v,[f.name]:e.target.value}))}/>}
   </label>)}
   <button type="submit" disabled={busy}>Créer</button>
  </form>}
  {cfg.area==="imports"&&<section style={{display:"grid",gap:8,maxWidth:620}}>
   <label>Version du mapping <input value={mapping} onChange={e=>setMapping(e.target.value)}/></label>
   <label>Lignes JSON (external_id et value) <textarea rows={5} value={rowsJson} onChange={e=>setRowsJson(e.target.value)}/></label>
   <div><button type="button" disabled={busy} onClick={()=>importRows(false)}>Prévisualiser</button> <button type="button" disabled={busy} onClick={()=>importRows(true)}>Valider le rapport</button></div>
   {report!==null&&<pre style={{whiteSpace:"pre-wrap",overflowWrap:"anywhere"}}>{JSON.stringify(report,null,2)}</pre>}
  </section>}
  {cfg.area==="api"&&<section style={{display:"grid",gap:8,maxWidth:560}}>
   <h3>Webhook signé de démonstration</h3>
   <label>ID du connecteur mock actif <input type="number" value={target} onChange={e=>setTarget(e.target.value)}/></label>
   <label>Secret HMAC <input type="password" value={secret} onChange={e=>setSecret(e.target.value)}/></label>
   <label>Identifiant unique de l'événement <input value={eventId} onChange={e=>setEventId(e.target.value)}/></label>
   <button type="button" disabled={busy} onClick={webhook}>Émettre l'événement sandbox</button>
  </section>}
  {cfg.area==="iot"&&<section style={{display:"grid",gap:8,maxWidth:560}}>
   <h3>Simuler une mesure</h3>
   <label>Secret appareil <input type="password" value={secret} onChange={e=>setSecret(e.target.value)}/></label>
   <label>Identifiant de mesure <input value={eventId} onChange={e=>setEventId(e.target.value)}/></label>
   <label>Valeur <input type="number" step="any" value={value} onChange={e=>setValue(e.target.value)}/></label>
  </section>}
  {cfg.area==="errors"&&<label>Motif détaillé de résolution <input value={resolution} onChange={e=>setResolution(e.target.value)}/></label>}
  <div style={{overflowX:"auto"}}>
   <table className="data-table">
    <thead><tr>{cfg.columns.map(c=><th key={c}>{c.replaceAll("_"," ")}</th>)}<th>Actions</th></tr></thead>
    <tbody>{rows.filter(r=>!search||cfg.columns.some(c=>show(r[c]).toLowerCase().includes(search.toLowerCase()))).map(row=><tr key={row.id}>
     {cfg.columns.map(c=><td key={c}>{show(row[c])}</td>)}
     <td><div style={{display:"flex",gap:5,flexWrap:"wrap"}}>
      {cfg.area==="connectors"&&<>
       {row.status==="draft"&&<button disabled={busy} onClick={()=>post("integrations/v1/connectors/"+row.id+"/sandbox",{expected_version:row.secret_version},"Contrôle sandbox effectué.")}>Tester mock</button>}
       {row.status==="draft"&&<button disabled={busy} onClick={()=>post("integrations/v1/connectors/"+row.id+"/activate",{expected_version:row.secret_version},"Connecteur mock actif.")}>Activer</button>}
       {row.status==="active"&&<button disabled={busy} onClick={()=>post("integrations/v1/connectors/"+row.id+"/suspend",{expected_version:row.secret_version},"Connecteur suspendu.")}>Suspendre</button>}
      </>}
      {cfg.area==="jobs"&&<>
       <button disabled={busy} onClick={()=>post("integrations/v1/jobs/"+row.id+"/"+(row.suspended?"resume":"suspend"),{},"Statut mis à jour.")}>{row.suspended?"Reprendre":"Suspendre"}</button>
       {!row.suspended&&<button disabled={busy} onClick={()=>post("integrations/v1/jobs/"+row.id+"/run",{},"Exécution enregistrée.")}>Exécuter</button>}
      </>}
      {cfg.area==="errors"&&row.status==="needs_review"&&<button disabled={busy||resolution.length<8} onClick={()=>post("integrations/v1/errors/"+row.id+"/resolve",{resolution},"Résolution tracée.")}>Résoudre</button>}
      {cfg.area==="iot"&&<button disabled={busy||!secret||!eventId||!value} onClick={()=>post("integrations/v1/devices/"+row.id+"/telemetry",{secret,event_id:eventId,value:Number(value),observed_at:new Date().toISOString()},"Mesure enregistrée.")}>Envoyer mesure</button>}
      {["connectors","jobs","errors","iot"].includes(cfg.area)&&<button type="button" onClick={()=>historyFor(row.id)}>Historique</button>}
     </div>{selected===row.id&&<div>{history.length?history.map(x=><div key={x.id}>{show(x.created_at)} · {show(x.action)}</div>):"Aucun événement dans le journal."}</div>}</td>
    </tr>)}
    {!rows.length&&<tr><td colSpan={cfg.columns.length+1}>Aucun enregistrement.</td></tr>}</tbody>
   </table>
  </div>
  <div style={{display:"flex",alignItems:"center",gap:10}}>
   <button disabled={page<=1} onClick={()=>setPage(n=>n-1)}>Précédent</button><span>Page {page} / {Math.max(1,last)}</span>
   <button disabled={page>=last} onClick={()=>setPage(n=>n+1)}>Suivant</button>
  </div>
 </section>;
}
