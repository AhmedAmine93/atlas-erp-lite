"use client";
import {useCallback,useEffect,useState,type FormEvent} from "react";
import {api,send} from "@/lib/api";
type Row=Record<string,string|number|boolean|null>&{id:number};
type Page={data:Row[];last_page:number;total:number};
type Overview={scope:string;data_source:string;services:Row[];counts:Record<string,number>};
type Field={key:string;label:string;type?:"number"|"checkbox"|"datetime-local"|"select";required?:boolean;options?:string[]};
type Section="overview"|"logs"|"health"|"jobs"|"backups"|"deployments";
const headings:Record<Section,string>={
 overview:"Supervision générale",logs:"Journaux techniques",health:"État des services",
 jobs:"Jobs et batchs",backups:"Sauvegardes",deployments:"Déploiements et CI/CD"
};
const field=(key:string,label:string,type?:Field["type"],options?:string[]):Field=>({key,label,type,options,required:true});
const schema:Record<Exclude<Section,"overview">,Field[]>={
 health:[field("service_key","Identifiant du service"),field("technical_status","Santé technique","select",["ok","degraded","down"]),field("business_status","Santé métier","select",["operational","degraded","blocked"]),field("source","Source de mesure","select",["manual","synthetic","otel","prometheus"]),{...field("latency_ms","Latence ms","number"),required:false},field("observed_at","Date d'observation","datetime-local")],
 logs:[field("component","Composant"),field("level","Niveau","select",["info","warning","error","critical"]),field("event","Code événement"),field("correlation_id","Corrélation UUID"),field("message","Résumé sans données sensibles"),field("observed_at","Date d'observation","datetime-local")],
 jobs:[field("job_type","Type de travail","select",["reconciliation","report","export","quality_check"]),field("idempotency_key","Clé de rejeu unique"),field("max_attempts","Nombre maximum d'essais","number"),{...field("window_start","Début de fenêtre","datetime-local"),required:false},{...field("window_end","Fin de fenêtre","datetime-local"),required:false}],
 backups:[field("backup_ref","Référence sauvegarde"),field("environment","Environnement","select",["dev","staging","production"]),field("checksum_sha256","Empreinte SHA-256"),field("encrypted","Chiffrement confirmé","checkbox"),field("captured_at","Date de capture","datetime-local")],
 deployments:[field("environment","Environnement","select",["dev","staging","production"]),field("image_digest","Digest de l'image sha256:…")],
};
const cols:Record<Exclude<Section,"overview">,string[]>={
 health:["service_key","technical_status","business_status","source","latency_ms","observed_at"],
 logs:["component","level","event","correlation_id","message","observed_at"],
 jobs:["job_type","status","attempts","max_attempts","version","correlation_id"],
 backups:["backup_ref","environment","encrypted","isolated_restore_verified","proof_ref","captured_at"],
 deployments:["environment","image_digest","status","backup_id","decision_ref"]
};
const actions:Record<string,string[]>={pending:["start","dead_letter"],running:["complete","fail"],failed:["retry","dead_letter"],draft:["approve","block"]};
const actionNames:Record<string,string>={start:"Démarrage déclaré",complete:"Fin déclarée",fail:"Échec déclaré",retry:"Autoriser reprise",dead_letter:"Quarantaine",attest_restore:"Attester restauration isolée",approve:"Décision Go",block:"Décision No-Go"};
const dates=new Set(["observed_at","captured_at","restored_at","window_start","window_end"]);
const cell=(v:unknown)=>v===null||v===undefined?"—":typeof v==="boolean"?(v?"Oui":"Non"):String(v);
function Fields({definitions,values,change}:{definitions:Field[];values:Record<string,string|boolean>;change:(k:string,v:string|boolean)=>void}){
 return <div style={{display:"grid",gridTemplateColumns:"repeat(auto-fit,minmax(220px,1fr))",gap:"1rem"}}>
  {definitions.map(f=><label key={f.key} style={{display:"grid",gap:".4rem"}}>
   {f.label}
   {f.type==="checkbox"?<input type="checkbox" required={f.required} checked={values[f.key]===true} onChange={e=>change(f.key,e.target.checked)}/>:
    f.type==="select"?<select required={f.required} value={String(values[f.key]??"")} onChange={e=>change(f.key,e.target.value)}>
     <option value="">Sélectionner…</option>{f.options?.map(v=><option value={v} key={v}>{v}</option>)}</select>:
    <input required={f.required} type={f.type||"text"} value={String(values[f.key]??"")} onChange={e=>change(f.key,e.target.value)}/>}
  </label>)}
 </div>;
}
export default function OperationsWorkspace({view}:{view:string}){
 const section=view.replace("ops-","") as Section;
 const valid=Object.prototype.hasOwnProperty.call(headings,section);
 const [data,setData]=useState<Row[]>([]),[overview,setOverview]=useState<Overview|null>(null);
 const [page,setPage]=useState(1),[pages,setPages]=useState(1),[search,setSearch]=useState(""),[filter,setFilter]=useState("");
 const [draft,setDraft]=useState<Record<string,string|boolean>>({max_attempts:"3"});
 const [action,setAction]=useState<{id:number;key:string;version:number}|null>(null);
 const [actionValues,setActionValues]=useState<Record<string,string|boolean>>({});
 const [busy,setBusy]=useState(false),[error,setError]=useState(""),[notice,setNotice]=useState("");
 const load=useCallback(async()=>{
  if(!valid)return;setBusy(true);setError("");
  try{
   if(section==="overview")setOverview(await api<Overview>("ops/overview"));
   else{const q=new URLSearchParams({page:String(page)});if(search)q.set("search",search);if(filter)q.set("status",filter);
    const res=await api<Page>("ops/"+section+"?"+q.toString());setData(res.data);setPages(res.last_page)}
  }catch(e){setError((e as Error).message)}finally{setBusy(false)}
 },[section,valid,page,search,filter]);
 useEffect(()=>{void load()},[load]);
 const update=(key:string,value:string|boolean)=>setDraft(d=>({...d,[key]:value}));
 async function create(e:FormEvent<HTMLFormElement>){
  e.preventDefault();if(section==="overview")return;
  setBusy(true);setError("");setNotice("");
  try{
   const payload:Record<string,unknown>={};
   schema[section].forEach(f=>{const v=draft[f.key];if(v===undefined||v==="")return;
    payload[f.key]=dates.has(f.key)?new Date(String(v)).toISOString():f.type==="number"?Number(v):v});
   if(section==="logs"&&!payload.correlation_id)payload.correlation_id=crypto.randomUUID();
   const result=await send<{already_exists?:boolean}>("ops/"+section,"POST",payload);
   setNotice(result.already_exists?"Rejeu reconnu : aucun doublon.":"Déclaration auditée.");
   setDraft({max_attempts:"3"});await load();
  }catch(e){setError((e as Error).message)}finally{setBusy(false)}
 }
 const actionFields:Field[]=!action?[]:section==="backups"?[
  field("restored_at","Date restauration","datetime-local"),field("observed_rpo_seconds","RPO observé (sec)","number"),
  field("observed_rto_seconds","RTO observé (sec)","number"),field("proof_ref","Référence preuve externe")
 ]:section==="deployments"?[
  field("decision_ref","Référence PV Go/No-Go"),
  ...(action.key==="approve"?[field("backup_id","Identifiant de sauvegarde restaurée","number"),
   ...["sbom_verified","provenance_verified","staging_smoke_passed","migration_compatible"].map(k=>field(k,k.replaceAll("_"," "),"checkbox"))]:[])
 ]:section==="jobs"&&["complete","fail"].includes(action.key)?[{...field("result","Résumé sans secret"),required:false}]:[];
 async function transition(e:FormEvent<HTMLFormElement>){
  e.preventDefault();if(!action||section==="overview")return;
  setBusy(true);setError("");setNotice("");
  try{
   const p:Record<string,unknown>={action:action.key};
   if(section==="jobs"){p.version=action.version;if(actionValues.result)p.result=actionValues.result}
   if(section==="backups"){p.restored_at=new Date(String(actionValues.restored_at)).toISOString();
    p.observed_rpo_seconds=Number(actionValues.observed_rpo_seconds);
    p.observed_rto_seconds=Number(actionValues.observed_rto_seconds);p.proof_ref=actionValues.proof_ref}
   if(section==="deployments"){p.decision_ref=actionValues.decision_ref;
    if(action.key==="approve"){p.backup_id=Number(actionValues.backup_id);
     for(const k of ["sbom_verified","provenance_verified","staging_smoke_passed","migration_compatible"])p[k]=actionValues[k]===true}}
   await send("ops/"+section+"/"+action.id+"/transition","POST",p);
   setNotice("Décision enregistrée, aucune opération externe déclenchée.");
   setAction(null);setActionValues({});await load();
  }catch(e){setError((e as Error).message)}finally{setBusy(false)}
 }
 if(!valid)return <p>Menu inexistant.</p>;
 return <section aria-label={headings[section]}>
  <div className="page-heading"><div><div className="eyebrow">ATLAS ONE · EXPLOITATION M18</div>
   <h2>{headings[section]}</h2><p>Registre d'exploitation limité à l'entreprise active. Les preuves sont déclaratives et non des contrôles live.</p>
  </div><button className="button" type="button" disabled={busy} onClick={()=>void load()}>Actualiser</button></div>
  <div className="panel" style={{padding:"1rem",marginBottom:"1rem"}}>
   <strong>Accès réservé aux administrateurs.</strong>
   <p>Les déploiements, sauvegardes, reprises, sondes et collectes d'infrastructure ne sont jamais exécutés depuis ces formulaires. Une attestation exige une preuve externe.</p>
  </div>
  {notice&&<p className="notice" role="status">{notice}</p>}
  {error&&<p className="error" role="alert">{error}</p>}
  {section==="overview" ? <>
   <div style={{display:"grid",gridTemplateColumns:"repeat(auto-fit,minmax(170px,1fr))",gap:"1rem"}}>
    {Object.entries(overview?.counts??{}).map(([k,v])=><div key={k} className="panel" style={{padding:"1rem"}}><small>{k.replaceAll("_"," ")}</small><h3>{v}</h3></div>)}
   </div><h3>Dernières observations enregistrées</h3>
   <div className="panel" style={{padding:"1rem",overflowX:"auto"}}>
    <table><thead><tr>{["Service","Technique","Métier","Source","Date UTC"].map(k=><th key={k}>{k}</th>)}</tr></thead>
    <tbody>{overview?.services.length?overview.services.map(r=><tr key={r.id}>
     {["service_key","technical_status","business_status","source","observed_at"].map(k=><td key={k}>{cell(r[k])}</td>)}</tr>):
     <tr><td colSpan={5}>Aucune observation, disponibilité inconnue.</td></tr>}</tbody></table>
   </div><p>{overview?.data_source}</p>
  </> : <>
   <form className="panel" style={{padding:"1rem",marginBottom:"1rem"}} onSubmit={create}>
    <h3>Nouvelle déclaration : {headings[section]}</h3>
    <Fields definitions={schema[section]} values={draft} change={update}/>
    <button type="submit" disabled={busy} className="button primary" style={{marginTop:"1rem"}}>Enregistrer</button>
   </form>
   <div className="panel" style={{padding:"1rem",marginBottom:"1rem",display:"flex",gap:"1rem",flexWrap:"wrap"}}>
    <label>Rechercher <input type="search" maxLength={80} value={search} onChange={e=>{setSearch(e.target.value);setPage(1)}}/></label>
    {section!=="backups"&&<label>Filtrer <select value={filter} onChange={e=>{setFilter(e.target.value);setPage(1)}}>
     <option value="">Tous</option>{(section==="health"?["ok","degraded","down"]:section==="logs"?["info","warning","error","critical"]:
      section==="jobs"?["pending","running","completed","failed","dead_letter"]:["draft","approved","blocked"])
      .map(k=><option key={k} value={k}>{k}</option>)}</select></label>}
   </div>
   <div className="panel" style={{padding:"1rem",overflowX:"auto"}}>
    <table><thead><tr>{cols[section].map(k=><th key={k}>{k.replaceAll("_"," ")}</th>)}<th>Opérations</th></tr></thead>
    <tbody>{data.length?data.map(r=><tr key={r.id}>{cols[section].map(k=><td key={k} style={{maxWidth:240,overflowWrap:"anywhere"}}>{cell(r[k])}</td>)}
     <td>{(section==="jobs"?actions[String(r.status)]??[]:
      section==="backups"&&!r.isolated_restore_verified?["attest_restore"]:
      section==="deployments"?actions[String(r.status)]??[]:[]).map(k=>
      <button key={k} type="button" className="button" onClick={()=>{setAction({id:r.id,key:k,version:Number(r.version??1)});setActionValues({})}}>{actionNames[k]}</button>)}</td>
     </tr>):<tr><td colSpan={cols[section].length+1}>Aucune déclaration.</td></tr>}</tbody></table>
    <div style={{display:"flex",justifyContent:"space-between",alignItems:"center",marginTop:"1rem"}}>
     <button type="button" className="button" disabled={page<=1} onClick={()=>setPage(n=>n-1)}>Précédent</button>
     <span>Page {page} / {pages}</span>
     <button type="button" className="button" disabled={page>=pages} onClick={()=>setPage(n=>n+1)}>Suivant</button>
    </div>
   </div>
   {action&&<form className="panel" style={{padding:"1rem",marginTop:"1rem"}} onSubmit={transition}>
    <h3>{actionNames[action.key]} — #{action.id}</h3><p>Transition déclarative et auditée, non exécutée sur l'infrastructure.</p>
    <Fields definitions={actionFields} values={actionValues} change={(k,v)=>setActionValues(d=>({...d,[k]:v}))}/>
    <div style={{display:"flex",gap:"1rem",marginTop:"1rem"}}>
     <button type="submit" className="button primary" disabled={busy}>Confirmer</button>
     <button type="button" className="button" onClick={()=>setAction(null)}>Annuler</button>
    </div>
   </form>}
  </>}
 </section>;
}
