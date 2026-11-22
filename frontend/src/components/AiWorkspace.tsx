"use client";

import { useEffect, useState, type FormEvent } from "react";
import { api, send } from "@/lib/api";

type RecordRow = Record<string, unknown> & { id: number; status?: string };
type Paged = { data: RecordRow[]; total: number; last_page: number };
type Field = { key: string; label: string; type?: "number"|"textarea"|"date"|"datetime-local"|"select"; required?: boolean; options?: string[] };
const f=(key:string,label:string,required=true,type?:Field["type"],options?:string[]):Field=>({key,label,required,type,options});
const endpoints:Record<string,{title:string;path:string;columns:string[];fields:Field[]}>={
  "ai-assistant":{title:"Assistant métier",path:"ai/v1/runs",columns:["id","question","answer","status","created_at"],fields:[]},
  "ai-search":{title:"Recherche intelligente",path:"ai/v1/documents",columns:["title","source_type","source_ref","classification","status"],fields:[
    f("title","Titre"),f("source_type","Type de source"),f("source_ref","Référence source"),
    f("excerpt","Contenu vérifié",true,"textarea"),f("classification","Classification",true,"select",["public","internal","confidential","restricted"]),
    f("establishment_id","ID établissement",false,"number")
  ]},
  "ai-anomalies":{title:"Détection d’anomalies",path:"ai/v1/anomalies",columns:["id","entity_type","entity_ref","score","threshold","model_version","status"],fields:[
    f("model_id","ID modèle actif",true,"number"),f("entity_type","Domaine",true,"select",["maintenance","stock","finance","hotel","data"]),
    f("entity_ref","Référence de l'objet"),f("score","Score (0 à 1)",true,"number"),
    f("threshold","Seuil (0 à 1)",true,"number"),f("evidence","Preuve JSON",true,"textarea")
  ]},
  "ai-forecasts":{title:"Prévisions",path:"ai/v1/forecasts",columns:["id","model_version","cutoff_date","horizon_start","horizon_end","predicted_value","lower_bound","upper_bound","status"],fields:[
    f("model_id","ID modèle actif",true,"number"),f("cutoff_date","Date de coupe",true,"date"),f("horizon_start","Début horizon",true,"date"),
    f("horizon_end","Fin horizon",true,"date"),f("predicted_value","Prévision",true,"number"),
    f("lower_bound","Borne basse",true,"number"),f("upper_bound","Borne haute",true,"number"),
    f("training_population","Population d’entraînement"),f("backtest_mae","Erreur absolue moyenne",false,"number"),
    f("backtest_evidence","Preuves de backtest JSON",true,"textarea")
  ]},
  "ai-suggestions":{title:"Suggestions d’actions",path:"ai/v1/suggestions",columns:["id","action_type","arguments","cost_cents","policy_version","status","expires_at","work_order_id"],fields:[
    f("idempotency_key","Clé d’idempotence (8 caractères min.)"),
    f("action_type","Action autorisée",true,"select",["maintenance_create_work_order"]),
    f("maintenance_request_id","ID demande d’intervention qualifiée",true,"number"),
    f("summary","Résumé du BT",false,"textarea"),f("due_at","Échéance",false,"datetime-local"),
    f("cost_cents","Coût prévisionnel en centimes",true,"number"),f("policy_version","Version de politique"),
    f("explanation","Explication",true,"textarea"),f("expires_at","Expiration",true,"datetime-local")
  ]},
};
const modelFields=[f("code","Code"),f("name","Nom"),f("version","Version"),f("kind","Type",true,"select",["rules","predictive","llm"]),f("policy_version","Politique"),f("description","Description",false,"textarea")];
const agentFields=[f("code","Code"),f("name","Nom"),f("model_id","ID modèle",false,"number"),f("max_steps","Max étapes",false,"number")];
type Mode="runs"|"models"|"agents";
function renderCell(value:unknown):string {
  if(value===undefined||value===null||value==="")return "—";
  if(typeof value==="object")return JSON.stringify(value);
  if(typeof value==="boolean")return value?"Oui":"Non";
  return String(value);
}
function Fields({fields,values,change}:{fields:Field[];values:Record<string,string>;change:(key:string,value:string)=>void}) {
  return <div className="form-grid">{fields.map(field=><label key={field.key} className="form-field" style={{display:"grid",gap:6}}>
    <span>{field.label}</span>
    {field.type==="textarea"?<textarea rows={3} required={field.required} value={values[field.key]??""} onChange={e=>change(field.key,e.target.value)}/>:
      field.type==="select"?<select required={field.required} value={values[field.key]??""} onChange={e=>change(field.key,e.target.value)}>
        <option value="">Sélectionner</option>{field.options?.map(option=><option key={option} value={option}>{option}</option>)}
      </select>:<input type={field.type==="date"?"date":field.type==="datetime-local"?"datetime-local":field.type==="number"?"number":"text"}
        step={field.type==="number"?"any":undefined} required={field.required} value={values[field.key]??""} onChange={e=>change(field.key,e.target.value)}/>}
  </label>)}</div>;
}

export default function AiWorkspace({view,activeCompanyId}:{view:string;activeCompanyId:number|null}) {
  const config=endpoints[view];
  const [rows,setRows]=useState<RecordRow[]>([]);
  const [page,setPage]=useState(1),[lastPage,setLastPage]=useState(1),[refresh,setRefresh]=useState(0);
  const [loading,setLoading]=useState(true),[busy,setBusy]=useState(false);
  const [error,setError]=useState(""),[message,setMessage]=useState("");
  const [query,setQuery]=useState(""),[results,setResults]=useState<RecordRow[]>([]);
  const [answer,setAnswer]=useState(""),[values,setValues]=useState<Record<string,string>>({});
  const [openForm,setOpenForm]=useState(false),[mode,setMode]=useState<Mode>("runs");
  const [agentId,setAgentId]=useState("");
  const currentPath=view==="ai-assistant"?(mode==="models"?"ai/v1/models":mode==="agents"?"ai/v1/agents":"ai/v1/runs"):config.path;
  const fields=view==="ai-assistant"?(mode==="models"?modelFields:mode==="agents"?agentFields:[]):config.fields;
  useEffect(()=>{setPage(1);setRows([]);setOpenForm(false);setError("");setMessage("");},[view,activeCompanyId,mode]);
  useEffect(()=>{
    let live=true;setLoading(true);
    api<Paged>(currentPath+"?page="+page).then(r=>{if(live){setRows(r.data);setLastPage(r.last_page);}})
      .catch((e:Error)=>{if(live)setError(e.message);}).finally(()=>{if(live)setLoading(false);});
    return()=>{live=false;};
  },[currentPath,page,refresh,activeCompanyId]);
  function change(key:string,value:string){setValues(v=>({...v,[key]:value}));}
  async function perform(path:string,data?:object) {
    if(busy)return;
    setBusy(true);setError("");setMessage("");
    try {await send(path,"POST",data);setMessage("Opération enregistrée et journalisée.");setRefresh(x=>x+1);setOpenForm(false);}
    catch(e){setError((e as Error).message);}
    finally{setBusy(false);}
  }
  async function submit(e:FormEvent<HTMLFormElement>){
    e.preventDefault();
    let payload:Record<string,unknown>={};
    try {
      for(const field of fields) {
        const raw=(values[field.key]??"").trim();
        if(!raw){if(field.required)throw Error("Champ requis : "+field.label);continue;}
        payload[field.key]=field.type==="number"?Number(raw):
          field.type==="datetime-local"?new Date(raw).toISOString():raw;
      }
      if(view==="ai-assistant"&&mode==="agents")payload.allowed_tools=["read_verified_documents"];
      if(view==="ai-anomalies"){payload.evidence=JSON.parse(String(payload.evidence));}
      if(view==="ai-forecasts"){payload.backtest_evidence=JSON.parse(String(payload.backtest_evidence));}
      if(view==="ai-suggestions"){
        const args:Record<string,unknown>={maintenance_request_id:payload.maintenance_request_id};
        if(payload.summary)args.summary=payload.summary;
        if(payload.due_at)args.due_at=payload.due_at;
        delete payload.maintenance_request_id;delete payload.summary;delete payload.due_at;
        payload.arguments=args;
      }
      await perform(currentPath,payload);
    } catch(err) {setError((err as Error).message);}
  }
  async function find(e:FormEvent<HTMLFormElement>) {
    e.preventDefault();setBusy(true);setError("");setAnswer("");
    try {
      if(view==="ai-search") {
        const r=await api<{data:RecordRow[];message:string}>("ai/v1/search?q="+encodeURIComponent(query));
        setResults(r.data);setMessage(r.message);
      } else {
        const r=await send<{answer:string;sources:RecordRow[]}>("ai/v1/assistant","POST",
          {question:query,...(agentId?{agent_id:Number(agentId)}:{})});
        setAnswer(r.answer);setResults(r.sources);setRefresh(v=>v+1);
      }
    }catch(e){setError((e as Error).message);}
    finally{setBusy(false);}
  }
  const columns=view==="ai-assistant"?mode==="models"?["id","code","name","version","kind","status"]:
    mode==="agents"?["id","code","name","model_id","status","configuration_version"]:config.columns:config.columns;
  return <section className="ai-workspace" style={{display:"grid",gap:20}}>
    <p>{config.title} · Données limitées à l’entreprise active. Les modèles externes ne sont pas connectés ; les données de prévision et d’anomalies sont importées avec leurs preuves. Aucune écriture métier sans autorisation humaine.</p>
    {view==="ai-assistant"&&<nav aria-label="Gestion IA" className="form-actions wrap">
      {(["runs","models","agents"] as const).map(m=><button key={m} type="button"
        className={"button "+(mode===m?"primary":"secondary")} onClick={()=>setMode(m)}>
        {m==="runs"?"Conversations":m==="models"?"Modèles":"Agents"}
      </button>)}
    </nav>}
    {(view==="ai-search"||(view==="ai-assistant"&&mode==="runs"))&&<form onSubmit={find} style={{display:"grid",gap:12}}>
      <label>{view==="ai-search"?"Recherche dans les documents vérifiés":"Poser une question sur les sources enregistrées"}
        <input style={{width:"100%"}} minLength={3} maxLength={view==="ai-search"?300:1000} required
          value={query} onChange={e=>setQuery(e.target.value)} placeholder="Objet, référence, mot-clé..." />
      </label>
      {view==="ai-assistant"&&<label>ID agent actif (facultatif)
        <input type="number" min={1} value={agentId} onChange={e=>setAgentId(e.target.value)}/>
      </label>}
      <button className="button primary" disabled={busy}>Chercher les sources</button>
      {answer&&<p role="status">{answer}</p>}
      {results.length>0&&<div aria-label="Sources vérifiées" style={{display:"grid",gap:8}}>
        {results.map(row=><article key={row.id} className="card" style={{padding:12}}>
          <strong>{renderCell(row.title)}</strong><p>{renderCell(row.excerpt)}</p>
          <small>{renderCell(row.source_type)} / {renderCell(row.source_ref)}</small>
        </article>)}
      </div>}
    </form>}
    {error&&<p role="alert" className="error">{error}</p>}
    {message&&<p role="status" className="notice">{message}</p>}
    {fields.length>0&&<div>
      <button className="button primary" type="button" onClick={()=>{setOpenForm(x=>!x);setValues({
        ...(view==="ai-search"?{classification:"internal"}:{}),
        ...(view==="ai-assistant"&&mode==="models"?{kind:"rules"}:{}),
        ...(view==="ai-suggestions"?{action_type:"maintenance_create_work_order",idempotency_key:crypto.randomUUID(),cost_cents:"0"}:{})
      });}}>{openForm?"Fermer le formulaire":"Ajouter une entrée"}</button>
      {openForm&&<form onSubmit={submit} className="card" style={{padding:16,display:"grid",gap:12,marginTop:12}}>
        <Fields fields={fields} values={values} change={change}/>
        <button disabled={busy} className="button primary">Enregistrer avec contrôle serveur</button>
      </form>}
    </div>}
    <div className="table-wrap">
      <h3>{view==="ai-assistant"&&mode==="models"?"Versions de modèles":view==="ai-assistant"&&mode==="agents"?"Registre des agents":"Historique et suivi"}</h3>
      {loading?<p>Chargement…</p>:rows.length===0?<p>Aucune donnée enregistrée.</p>:
      <div style={{overflowX:"auto"}}><table><thead><tr>{columns.map(c=><th key={c}>{c.replaceAll("_"," ")}</th>)}<th>Décisions</th></tr></thead><tbody>
        {rows.map(row=><tr key={row.id}>{columns.map(c=><td key={c}>{renderCell(row[c])}</td>)}<td>
          {view==="ai-search"&&row.status==="active"&&<button className="button secondary" disabled={busy} onClick={()=>void perform("ai/v1/documents/"+row.id+"/revoke")}>Révoquer</button>}
          {view==="ai-anomalies"&&row.status==="open"&&<div style={{display:"flex",gap:6}}>
            <button className="button secondary" disabled={busy} onClick={()=>void perform("ai/v1/anomalies/"+row.id+"/review",{decision:"confirmed"})}>Confirmer</button>
            <button className="button secondary" disabled={busy} onClick={()=>void perform("ai/v1/anomalies/"+row.id+"/review",{decision:"false_positive"})}>Faux positif</button>
          </div>}
          {view==="ai-forecasts"&&row.status==="active"&&<button className="button secondary" disabled={busy} onClick={()=>void perform("ai/v1/forecasts/"+row.id+"/stale")}>Périmer</button>}
          {view==="ai-suggestions"&&row.status==="proposed"&&<div style={{display:"flex",gap:6}}>
            <button className="button secondary" disabled={busy} onClick={()=>void perform("ai/v1/suggestions/"+row.id+"/decision",{decision:"approved"})}>Approuver</button>
            <button className="button secondary" disabled={busy} onClick={()=>void perform("ai/v1/suggestions/"+row.id+"/decision",{decision:"rejected"})}>Refuser</button>
          </div>}
          {view==="ai-suggestions"&&row.status==="approved"&&<button className="button secondary" disabled={busy} title="Exécution désactivée par défaut" onClick={()=>void perform("ai/v1/suggestions/"+row.id+"/execute")}>Exécuter (si autorisé)</button>}
          {view==="ai-assistant"&&mode==="models"&&row.status==="draft"&&<button className="button secondary" disabled={busy} onClick={()=>void perform("ai/v1/models/"+row.id+"/activate")}>Publier</button>}
          {view==="ai-assistant"&&mode==="agents"&&row.status==="draft"&&<button className="button secondary" disabled={busy} onClick={()=>void perform("ai/v1/agents/"+row.id+"/activate")}>Activer</button>}
        </td></tr>)}
      </tbody></table></div>}
    </div>
    <div className="form-actions wrap">
      <button className="button secondary" type="button" disabled={page<=1} onClick={()=>setPage(x=>x-1)}>Précédent</button>
      <span>Page {page} / {Math.max(1,lastPage)}</span>
      <button className="button secondary" type="button" disabled={page>=lastPage} onClick={()=>setPage(x=>x+1)}>Suivant</button>
      <button className="button secondary" type="button" onClick={()=>setRefresh(x=>x+1)}>Actualiser</button>
    </div>
  </section>;
}
