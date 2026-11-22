"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { api, send } from "@/lib/api";

type RecordRow = { id: number; version?: number; status?: string; [key: string]: unknown };
type Page = { data: RecordRow[]; current_page: number; last_page: number; total: number };
type Resource = "services" | "observations" | "logs" | "jobs" | "backups" | "deployments" | "alerts";
type Field = { name: string; title: string; kind?: "select" | "number" | "date" | "checkbox" | "textarea"; options?: string[]; required?: boolean };
const uuid = () => globalThis.crypto?.randomUUID?.() ?? "00000000-0000-4000-8000-000000000001";
const fields: Record<Resource, Field[]> = {
  services: [
    {name:"code",title:"Code technique",required:true},
    {name:"name",title:"Nom du service",required:true},
    {name:"owner",title:"Responsable",required:true},
    {name:"runbook_url",title:"Lien du runbook"},
    {name:"criticality",title:"Criticité",kind:"select",options:["normal","high","critical"],required:true},
  ],
  observations: [
    {name:"service_id",title:"Identifiant du service",kind:"number",required:true},
    {name:"layer",title:"Dimension",kind:"select",options:["technical","business","data"],required:true},
    {name:"state",title:"État observé",kind:"select",options:["healthy","degraded","down","unknown"],required:true},
    {name:"latency_ms",title:"Latence (ms)",kind:"number"},
    {name:"source",title:"Source de mesure",required:true},
    {name:"observed_at",title:"Horodatage UTC",kind:"date",required:true},
  ],
  logs: [
    {name:"component",title:"Composant",required:true},
    {name:"level",title:"Niveau",kind:"select",options:["debug","info","warning","error","critical"],required:true},
    {name:"correlation_id",title:"ID de corrélation (UUID)",required:true},
    {name:"message",title:"Message épuré de secrets",kind:"textarea",required:true},
    {name:"occurred_at",title:"Horodatage UTC",kind:"date",required:true},
  ],
  jobs: [
    {name:"name",title:"Nom du job",required:true},
    {name:"correlation_id",title:"ID de corrélation (UUID)",required:true},
    {name:"window_start",title:"Début de fenêtre",kind:"date",required:true},
    {name:"window_end",title:"Fin de fenêtre",kind:"date",required:true},
    {name:"max_attempts",title:"Tentatives maximum",kind:"number",required:true},
    {name:"runbook_url",title:"Runbook",required:true},
  ],
  backups: [
    {name:"artifact_reference",title:"Référence d'artefact (sans mot de passe)",required:true},
    {name:"environment",title:"Environnement",kind:"select",options:["demo","dev","staging","production"],required:true},
    {name:"encrypted",title:"Sauvegarde chiffrée",kind:"checkbox",required:true},
    {name:"completed_at",title:"Date de la sauvegarde",kind:"date",required:true},
  ],
  deployments: [
    {name:"application",title:"Application",required:true},
    {name:"environment",title:"Environnement",kind:"select",options:["demo","dev","staging","production"],required:true},
    {name:"image_digest",title:"Digest de l'image (sha256:…)",required:true},
    {name:"sbom_reference",title:"Référence SBOM",required:true},
    {name:"provenance_reference",title:"Attestation de provenance",required:true},
    {name:"migration_strategy",title:"Stratégie de migration",kind:"select",options:["none","expand_contract"],required:true},
    {name:"rollback_plan",title:"Plan de repli",kind:"textarea",required:true},
  ],
  alerts: [
    {name:"service_id",title:"Identifiant du service",kind:"number",required:true},
    {name:"severity",title:"Priorité",kind:"select",options:["P1","P2","P3","P4"],required:true},
    {name:"title",title:"Incident / alerte",required:true},
    {name:"owner",title:"Responsable",required:true},
    {name:"runbook_url",title:"Runbook",required:true},
    {name:"started_at",title:"Début de l'indisponibilité",kind:"date",required:true},
  ],
};
const definitions: Record<string,{title:string;resources:Resource[];description:string}> = {
  "ops-overview": {title:"Supervision générale", resources:["services","alerts","observations"],description:"Synthèse d'observations enregistrées, sans prétention de collecte temps réel."},
  "ops-logs": {title:"Journaux techniques", resources:["logs"],description:"Journaux minimisés, corrélés et conservés par entreprise."},
  "ops-health": {title:"État des services", resources:["services","observations"],description:"Disponibilité technique, aptitude métier et qualité des données."},
  "ops-jobs": {title:"Jobs et batchs", resources:["jobs"],description:"Suivi des exécutions et requalification. Aucun job distant n'est lancé ici."},
  "ops-backups": {title:"Sauvegardes", resources:["backups"],description:"Registre de sauvegardes et preuves de restauration isolée, sans restauration automatique."},
  "ops-deployments": {title:"Déploiements et CI/CD", resources:["deployments"],description:"Dossier de promotion contrôlé par preuves, sans déploiement automatique."},
};
const labels: Record<Resource,string> = {
  services:"Services",observations:"Mesures",logs:"Journaux",jobs:"Jobs",
  backups:"Sauvegardes",deployments:"Releases",alerts:"Alertes",
};
const transitions: Record<string,string[]> = {
  queued:["running","cancelled"],running:["succeeded","failed"],failed:["requalified"],
  requalified:["queued","cancelled"],recorded:["restore_verified","rejected"],
  planned:["staging_verified","rejected"],staging_verified:["approved","rejected"],
  approved:["promoted","rejected"],promoted:["degraded","rolled_back"],
  degraded:["rolled_back"],open:["acknowledged","resolved"],
  acknowledged:["resolved"],
};
const transitionFields: Field[] = [
  {name:"failure_reason",title:"Motif d'échec",kind:"textarea"},
  {name:"isolated_restore_reference",title:"Référence de restauration isolée"},
  {name:"observed_rpo_seconds",title:"RPO mesuré (secondes)",kind:"number"},
  {name:"observed_rto_seconds",title:"RTO mesuré (secondes)",kind:"number"},
  {name:"expected_rows",title:"Lignes avant sauvegarde",kind:"number"},
  {name:"restored_rows",title:"Lignes restaurées",kind:"number"},
  {name:"staging_evidence",title:"Preuve de staging"},
  {name:"backup_restore_reference",title:"Référence d'une sauvegarde vérifiée"},
  {name:"decision",title:"Décision",kind:"select",options:["go","no_go"]},
];
const relevantFields:Record<string,string[]> = {
  failed:["failure_reason"],restore_verified:["isolated_restore_reference","observed_rpo_seconds","observed_rto_seconds","expected_rows","restored_rows"],
  staging_verified:["staging_evidence"],approved:["backup_restore_reference","decision"],
};
function initial(resource:Resource) {
  const state:Record<string,string|boolean> = {};
  for(const field of fields[resource])state[field.name]=field.kind==="checkbox"?false:field.options?.[0]??"";
  if(resource==="jobs"||resource==="logs")state.correlation_id=uuid();
  return state;
}
const visible=(value:unknown) => {
  if(value==null)return "—";
  if(typeof value==="object")return JSON.stringify(value);
  return String(value);
};
export default function OperationsWorkspace({view,canWrite,activeCompanyId}:{view:string;canWrite:boolean;activeCompanyId:number|null}) {
  const definition=definitions[view];
  const [resource,setResource]=useState<Resource>(definition.resources[0]);
  const [page,setPage]=useState(1);
  const [filter,setFilter]=useState("");
  const [rows,setRows]=useState<Page|null>(null);
  const [overview,setOverview]=useState<{source:string;live_monitoring:boolean;counts:Record<string,number>;open_alerts:number;failed_jobs:number}|null>(null);
  const [selected,setSelected]=useState<{record:RecordRow;events:RecordRow[]}|null>(null);
  const [values,setValues]=useState<Record<string,string|boolean>>(initial(definition.resources[0]));
  const [transition,setTransition]=useState("");
  const [transitionValues,setTransitionValues]=useState<Record<string,string>>({});
  const [editing,setEditing]=useState(false);
  const [busy,setBusy]=useState(false);
  const [loading,setLoading]=useState(false);
  const [error,setError]=useState("");
  const [notice,setNotice]=useState("");
  const [revision,setRevision]=useState(0);
  const heading=useMemo(()=>definition,[definition]);
  useEffect(()=>{
    setResource(heading.resources[0]);setPage(1);setFilter("");setSelected(null);setEditing(false);setValues(initial(heading.resources[0]));
  },[heading,activeCompanyId]);
  const reload=useCallback(()=>{
    let live=true;
    setLoading(true);setError("");
    const query=new URLSearchParams({page:String(page)});
    if(filter.trim())query.set("search",filter.trim());
    Promise.all([
      api<Page>(`v1/ops/${resource}?${query}`),
      api<{source:string;live_monitoring:boolean;counts:Record<string,number>;open_alerts:number;failed_jobs:number}>("v1/ops/overview")
    ]).then(([list,summary])=>{if(live){setRows(list);setOverview(summary);}})
      .catch((e:Error)=>{if(live)setError(e.message);})
      .finally(()=>{if(live)setLoading(false);});
    return ()=>{live=false;};
  },[resource,page,filter,revision,activeCompanyId]);
  useEffect(()=>reload(),[reload]);
  async function openDetail(row:RecordRow) {
    setError("");setTransition("");setTransitionValues({});
    try{setSelected(await api<{record:RecordRow;events:RecordRow[]}>(`v1/ops/${resource}/${row.id}`));}
    catch(e){setError((e as Error).message);}
  }
  async function create() {
    if(!canWrite)return;
    setBusy(true);setError("");setNotice("");
    try{
      const data:Record<string,unknown>={};
      for(const field of fields[resource]){
        const value=values[field.name];
        if(value==="" || value==null)continue;
        data[field.name]=field.kind==="number"?Number(value):field.kind==="date"?new Date(String(value)).toISOString():value;
      }
      if(["jobs","backups","deployments"].includes(resource))data.idempotency_key=uuid();
      await send(`v1/ops/${resource}`,"POST",data);
      setEditing(false);setValues(initial(resource));setNotice("Preuve enregistrée (aucune opération d'infrastructure déclenchée).");
      setRevision(n=>n+1);
    }catch(e){setError((e as Error).message);}
    finally{setBusy(false);}
  }
  async function applyTransition() {
    if(!selected?.record.version||!transition||!canWrite)return;
    setBusy(true);setError("");setNotice("");
    try{
      const payload:Record<string,unknown>={status:transition,version:selected.record.version};
      for(const key of relevantFields[transition]??[])if(transitionValues[key]) {
        payload[key]=["observed_rpo_seconds","observed_rto_seconds","expected_rows","restored_rows"].includes(key)
          ?Number(transitionValues[key]):transitionValues[key];
      }
      await send(`v1/ops/${resource}/${selected.record.id}/transition`,"POST",payload);
      setNotice("Transition enregistrée et journalisée.");
      setSelected(null);setRevision(n=>n+1);
    }catch(e){setError((e as Error).message);}
    finally{setBusy(false);}
  }
  const available=selected?.record.status?transitions[selected.record.status]??[]:[];
  return (
    <section aria-label={heading.title} style={{display:"grid",gap:18}}>
      <p>{heading.description}</p>
      <p role="note" style={{fontSize:13}}>Source : <strong>preuves enregistrées</strong>. Aucune collecte OpenTelemetry / Prometheus ou exécution de déploiement n'est prétendue active.</p>
      {overview&&<div style={{display:"flex",flexWrap:"wrap",gap:10}}>
        {Object.entries(overview.counts).map(([key,value])=><div key={key} className="panel" style={{padding:14,minWidth:130,background:"var(--surface,transparent)",border:"1px solid var(--border,#ddd)",borderRadius:10}}>
          <small>{labels[key as Resource]}</small><div style={{fontSize:24,fontWeight:700}}>{value}</div></div>)}
      </div>}
      <div style={{display:"flex",gap:8,flexWrap:"wrap"}}>
        {heading.resources.map(r=><button key={r} type="button" aria-pressed={resource===r} className="btn" onClick={()=>{setResource(r);setPage(1);setFilter("");setSelected(null);setEditing(false);setValues(initial(r));}}>{labels[r]}</button>)}
      </div>
      <div style={{display:"flex",alignItems:"center",flexWrap:"wrap",gap:10}}>
        <label htmlFor="ops-filter">Recherche</label><input id="ops-filter" value={filter} onChange={e=>{setFilter(e.target.value);setPage(1);}} placeholder="Filtrer les données du tenant" />
        {canWrite&&<button type="button" onClick={()=>{setEditing(v=>!v);setSelected(null);}}>{editing?"Annuler":"Ajouter une preuve"}</button>}
        <button type="button" onClick={()=>setRevision(n=>n+1)}>Actualiser</button>
      </div>
      {error&&<p role="alert" style={{color:"var(--danger,#c53030)"}}>{error}</p>}
      {notice&&<p role="status">{notice}</p>}
      {editing&&<form onSubmit={e=>{e.preventDefault();void create();}} style={{display:"grid",gap:10,maxWidth:650}}>
        <h3>Enregistrer : {labels[resource]}</h3>
        {fields[resource].map(f=><label key={f.name} style={{display:"grid",gap:4}}>{f.title}
          {f.kind==="select"?<select required={f.required} value={String(values[f.name]??"")} onChange={e=>setValues(v=>({...v,[f.name]:e.target.value}))}>{f.options?.map(o=><option key={o} value={o}>{o}</option>)}</select>
          :f.kind==="textarea"?<textarea required={f.required} value={String(values[f.name]??"")} onChange={e=>setValues(v=>({...v,[f.name]:e.target.value}))}/>
          :f.kind==="checkbox"?<input type="checkbox" checked={Boolean(values[f.name])} onChange={e=>setValues(v=>({...v,[f.name]:e.target.checked}))}/>
          :<input required={f.required} type={f.kind==="number"?"number":f.kind==="date"?"datetime-local":"text"} value={String(values[f.name]??"")} onChange={e=>setValues(v=>({...v,[f.name]:e.target.value}))}/>}
        </label>)}
        <button type="submit" disabled={busy}>{busy?"Enregistrement…":"Enregistrer"}</button>
      </form>}
      {loading?<p role="status">Chargement…</p>:!rows?.data.length?<p>Aucune donnée enregistrée pour ce périmètre.</p>:
        <div style={{overflowX:"auto"}}><table style={{width:"100%"}}><thead><tr><th scope="col">#</th><th scope="col">Référence</th><th scope="col">État</th><th scope="col">Date</th><th scope="col">Détail</th></tr></thead>
          <tbody>{rows.data.map(r=><tr key={r.id}><td>{r.id}</td><td>{visible(r.name??r.component??r.application??r.artifact_reference??r.title??r.source??r.code??r.correlation_id)}</td>
            <td>{visible(r.status??r.state??r.level??r.criticality)}</td><td>{visible(r.created_at??r.observed_at)}</td>
            <td><button type="button" onClick={()=>void openDetail(r)}>Consulter</button></td></tr>)}</tbody>
        </table></div>}
      <div style={{display:"flex",alignItems:"center",gap:10}}>
        <button type="button" disabled={page<=1} onClick={()=>setPage(p=>p-1)}>Précédent</button>
        <span>Page {page} / {rows?.last_page??1} — {rows?.total??0} résultats</span>
        <button type="button" disabled={page>=(rows?.last_page??1)} onClick={()=>setPage(p=>p+1)}>Suivant</button>
      </div>
      {selected&&<aside style={{border:"1px solid var(--border,#ccc)",borderRadius:12,padding:16,display:"grid",gap:12}}>
        <div style={{display:"flex",justifyContent:"space-between",gap:10}}><h3>Fiche #{selected.record.id}</h3><button type="button" onClick={()=>setSelected(null)}>Fermer</button></div>
        <dl style={{display:"grid",gridTemplateColumns:"minmax(100px,1fr) 2fr",gap:8,overflowWrap:"anywhere"}}>
          {Object.entries(selected.record).filter(([key])=>!["payload_hash"].includes(key)).map(([key,value])=><div key={key} style={{display:"contents"}}><dt>{key}</dt><dd>{visible(value)}</dd></div>)}
        </dl>
        <h4>Historique immuable</h4>
        {selected.events.length?selected.events.map(e=><p key={e.id}>#{e.id} {visible(e.created_at)} — {visible(e.action)} {visible(e.from_status)} → {visible(e.to_status)}</p>):<p>Aucun événement.</p>}
        {canWrite&&available.length>0&&<>
          <h4>Transition contrôlée</h4><label>Nouvel état
            <select value={transition} onChange={e=>{setTransition(e.target.value);setTransitionValues({});}}>
              <option value="">Choisir une transition</option>{available.map(a=><option key={a} value={a}>{a}</option>)}
            </select></label>
          {transition&&transitionFields.filter(f=>(relevantFields[transition]??[]).includes(f.name)).map(f=><label key={f.name} style={{display:"grid",gap:4}}>{f.title}
            {f.kind==="select"?<select value={transitionValues[f.name]??""} onChange={e=>setTransitionValues(v=>({...v,[f.name]:e.target.value}))}><option value="">Choisir</option>{f.options?.map(o=><option key={o}>{o}</option>)}</select>
             :<input type={f.kind==="number"?"number":"text"} value={transitionValues[f.name]??""} onChange={e=>setTransitionValues(v=>({...v,[f.name]:e.target.value}))}/>}
          </label>)}
          <button type="button" disabled={!transition||busy} onClick={()=>void applyTransition()}>Valider la transition</button>
        </>}
      </aside>}
    </section>
  );
}
