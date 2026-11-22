"use client";

import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { api } from "@/lib/api";
import type { Page, User } from "@/lib/types";

type Module = "quality-controls" | "nonconformities" | "corrective-actions" | "quality-audits" | "compliance-docs";
type QualityRecord = Record<string, unknown> & { id: number; status?: string; title?: string; reference?: string; lock_version?: number };
type Field = { key: string; label: string; type?: string; required?: boolean; values?: string[] };
type Definition = { endpoint: string; title: string; intro: string; create: string; fields: Field[] };
const definitions: Record<Module, Definition> = {
  "quality-controls": {
    endpoint: "controls", title: "Contrôles qualité", create: "Nouvelle règle versionnée",
    intro: "Définissez les seuils d'échantillonnage. Les contrôles hors tolérance ouvrent une non-conformité et une action CAPA.",
    fields: [
      { key: "code", label: "Code du plan", required: true },
      { key: "revision", label: "Révision", type: "number", required: true },
      { key: "title", label: "Nom du plan", required: true },
      { key: "metric", label: "Mesure / unité", required: true },
      { key: "minimum", label: "Minimum acceptable", type: "number", required: true },
      { key: "maximum", label: "Maximum acceptable", type: "number", required: true },
      { key: "sample_size", label: "Taille minimale de l'échantillon", type: "number", required: true },
      { key: "valid_from", label: "Valide à partir du", type: "date", required: true },
      { key: "valid_until", label: "Jusqu'au", type: "date" },
    ],
  },
  "nonconformities": {
    endpoint: "nonconformities", title: "Non-conformités", create: "Déclarer un écart",
    intro: "Identifiez les écarts, isolez les quantités concernées et faites valider la fermeture par un responsable.",
    fields: [
      { key: "reference", label: "Référence", required: true },
      { key: "title", label: "Description courte", required: true },
      { key: "severity", label: "Gravité", required: true, values: ["minor","major","critical"] },
      { key: "scope", label: "Périmètre / cause observée", required: true },
      { key: "defect_code", label: "Code défaut" },
      { key: "owner_id", label: "Responsable", type: "member", required: true },
      { key: "quarantine_units", label: "Quantité en quarantaine", type: "number", required: true },
    ],
  },
  "corrective-actions": {
    endpoint: "actions", title: "Actions correctives CAPA", create: "Créer depuis une non-conformité",
    intro: "Suivez la cause racine, la preuve de correction, les échéances et la mesure d'efficacité.",
    fields: [
      { key: "nonconformity_id", label: "ID de non-conformité", type: "number", required: true },
      { key: "kind", label: "Type d'action", required: true, values: ["corrective","preventive"] },
      { key: "title", label: "Action prévue", required: true },
      { key: "root_cause", label: "Cause racine", required: true },
      { key: "owner_id", label: "Responsable", type: "member", required: true },
      { key: "due_on", label: "Échéance", type: "date", required: true },
      { key: "target_metric", label: "Critère d'efficacité", required: true },
      { key: "target_value", label: "Valeur minimale cible", type: "number", required: true },
    ],
  },
  "quality-audits": {
    endpoint: "audits", title: "Audits qualité", create: "Planifier un audit",
    intro: "Planifiez des audits indépendants, documentez les constats et conservez leurs preuves.",
    fields: [
      { key: "reference", label: "Référence d'audit", required: true },
      { key: "scope", label: "Périmètre", required: true },
      { key: "standard", label: "Référentiel", required: true },
      { key: "standard_version", label: "Version du référentiel", required: true },
      { key: "auditor_id", label: "Auditeur", type: "member", required: true },
      { key: "starts_on", label: "Début", type: "date", required: true },
      { key: "ends_on", label: "Fin", type: "date", required: true },
    ],
  },
  "compliance-docs": {
    endpoint: "documents", title: "Documents et procédures", create: "Créer une procédure",
    intro: "Suivez les versions, révisez, signez les empreintes des documents et recevez les accusés de lecture.",
    fields: [
      { key: "code", label: "Référence documentaire", required: true },
      { key: "title", label: "Titre", required: true },
      { key: "country_code", label: "Code pays ISO (ex. FR)", required: true },
      { key: "content", label: "Contenu de la procédure", type: "textarea", required: true },
    ],
  },
};
const statusLabels: Record<string, string> = {
  open:"Ouvert",contained:"Confiné",closed:"Clôturé",needs_plan:"Plan à compléter",
  planned:"Planifié",implemented:"Mis en œuvre",effective:"Efficace",ineffective:"Non efficace",
  in_progress:"En cours",draft:"Brouillon",review:"En revue",approved:"Approuvé",
  published:"Publié",obsolete:"Obsolète",
};
const formatDate=(date: unknown) => typeof date === "string" && date
  ? new Date(date).toLocaleDateString("fr-FR") : "—";
const itemLabel=(item: QualityRecord) =>
  String(item.reference ?? item.code ?? item.title ?? item.id);
const scope = (row: Record<string, unknown>, fields: string[]) =>
  fields.reduce<Record<string, unknown>>((obj, key) => { if (row[key] != null) obj[key] = row[key]; return obj; }, {});

export default function QualityWorkspace({ view, user }: { view: Module; user: User }) {
  const config=definitions[view];
  const role=user.role.name;
  const isManager=["admin","quality_manager"].includes(role);
  const canWrite=isManager || role==="quality_operator" || role==="quality_auditor";
  const canCreate=canWrite && (!["quality-controls","quality-audits"].includes(view) || isManager) && view!=="corrective-actions" || (view==="corrective-actions"&&canWrite);
  const [records,setRecords]=useState<QualityRecord[]>([]);
  const [selected,setSelected]=useState<QualityRecord | null>(null);
  const [users,setUsers]=useState<{id:number;name:string}[]>([]);
  const [metrics,setMetrics]=useState<Record<string,number>>({});
  const [form,setForm]=useState<Record<string,string>>({});
  const [workflow,setWorkflow]=useState<Record<string,string>>({});
  const [creating,setCreating]=useState(false);
  const [busy,setBusy]=useState(false);
  const [loading,setLoading]=useState(false);
  const [message,setMessage]=useState("");
  const [error,setError]=useState("");
  const [search,setSearch]=useState("");
  const [status,setStatus]=useState("");
  const [page,setPage]=useState(1);
  const [pages,setPages]=useState(1);
  const [revision,setRevision]=useState(0);

  const load=useCallback(async()=>{
    setLoading(true);
    setError("");
    const query=new URLSearchParams({page:String(page)});
    if(status)query.set("status",status);
    if(search.trim())query.set("search",search.trim());
    try{
      const [data,stats,members]=await Promise.all([
        api<Page<QualityRecord>>('quality/'+config.endpoint+'?'+query),
        api<Record<string,number>>('quality/metrics'),
        api<{id:number;name:string}[]>('quality/users'),
      ]);
      setRecords(data.data);
      setPages(Math.max(1,data.last_page));
      setMetrics(stats);
      setUsers(members);
    }catch(e){setError((e as Error).message);}
    finally{setLoading(false);}
  },[config.endpoint,page,search,status,revision]);

  useEffect(()=>{void load();},[load]);
  const fieldValue=(key:string)=>form[key]??"";
  const update=(key:string,value:string)=>setForm(previous=>({...previous,[key]:value}));
  const changed=()=>{setRevision(n=>n+1);setMessage("Modification enregistrée.");};
  const write=async (path:string,data:Record<string,unknown>)=>{
    const key="atlas-"+Date.now().toString(36)+"-"+Math.random().toString(36).slice(2);
    return api<unknown>("quality/"+path,{method:"POST",headers:{"Idempotency-Key":key},body:JSON.stringify(data)});
  };
  const newEntity=async(e:FormEvent)=>{
    e.preventDefault();setBusy(true);setError("");setMessage("");
    const payload: Record<string,unknown>={};
    for(const f of config.fields){
      const value=fieldValue(f.key).trim();
      if(value) payload[f.key]=f.type==="number"||f.type==="member"?Number(value):value;
    }
    try{
      if(view==="corrective-actions"){
        const ncId=payload.nonconformity_id;delete payload.nonconformity_id;
        await write("nonconformities/"+ncId+"/actions",payload);
      }else await write(config.endpoint,payload);
      setCreating(false);setForm({});setPage(1);changed();
    }catch(e){setError((e as Error).message);}
    finally{setBusy(false);}
  };
  const show=async(row:QualityRecord)=>{
    setBusy(true);setError("");setWorkflow({});
    try{setSelected(await api<QualityRecord>("quality/"+config.endpoint+"/"+row.id));}
    catch(e){setError((e as Error).message);}
    finally{setBusy(false);}
  };
  const submit=async(path:string,payload:Record<string,unknown>)=>{
    setBusy(true);setError("");setMessage("");
    try{
      await write(path,payload);
      const id=selected?.id;
      if(id) setSelected(await api<QualityRecord>("quality/"+config.endpoint+"/"+id));
      setWorkflow({});changed();
    }catch(e){setError((e as Error).message);}
    finally{setBusy(false);}
  };
  const action=async(statusValue:string)=>{
    if(!selected)return;
    const version=selected.lock_version??1;
    if(view==="nonconformities")await submit("nonconformities/"+selected.id+"/transition",{
      version,status:statusValue,
      ...(statusValue==="closed"?scope(workflow,["decision","closure_reason","closure_evidence"]):{}),
    });
    if(view==="corrective-actions")await submit("actions/"+selected.id+"/transition",{
      version,status:statusValue,...scope(workflow,["root_cause","due_on","target_metric","target_value","measured_value","evidence_ref"]),
    });
    if(view==="quality-audits")await submit("audits/"+selected.id+"/transition",{
      version,status:statusValue,...(statusValue==="closed"?{closure_evidence:workflow.closure_evidence}:{}),
    });
  };
  const extraOptions=useMemo(()=>{
    if(view==="nonconformities")return selected?.status==="open"?["contained"]:selected?.status==="contained" && isManager?["closed"]:[];
    if(view==="corrective-actions"){
      if(selected?.status==="needs_plan"||selected?.status==="ineffective")return ["planned"];
      if(selected?.status==="planned")return ["implemented"];
      if(selected?.status==="implemented"&&isManager)return ["effective","ineffective"];
      return [];
    }
    if(view==="quality-audits")return selected?.status==="planned"?["in_progress"]:selected?.status==="in_progress"&&isManager?["closed"]:[];
    return [];
  },[view,selected,isManager]);
  const input=(f:Field, value:string, onChange:(value:string)=>void)=>{
    if(f.type==="member")return <select required={f.required} value={value} onChange={e=>onChange(e.target.value)}>
      <option value="">Choisir un membre de l'entreprise</option>
      {users.map(u=><option value={u.id} key={u.id}>{u.name} (#{u.id})</option>)}
    </select>;
    if(f.values)return <select required={f.required} value={value} onChange={e=>onChange(e.target.value)}>
      <option value="">Choisir…</option>{f.values.map(v=><option key={v} value={v}>{statusLabels[v]??v}</option>)}
    </select>;
    if(f.type==="textarea")return <textarea rows={5} required={f.required} value={value} maxLength={100000}
      onChange={e=>onChange(e.target.value)}/>;
    return <input type={f.type??"text"} required={f.required} value={value}
      step={f.type==="number"?"any":undefined} onChange={e=>onChange(e.target.value)}/>;
  };
  const workflowField=(key:string,label:string,type?:string,values?:string[])=>
    <label key={key}>{label}{input({key,label,type,values,required:true},workflow[key]??"",v=>setWorkflow(p=>({...p,[key]:v})))}</label>;

  const checkFields: Field[]=[
    {key:"source_type",label:"Origine du contrôle",values:["lot","receipt","production","import"],required:true},
    {key:"source_ref",label:"Référence de lot / source",required:true},
    {key:"product_id",label:"ID produit (facultatif)",type:"number"},
    {key:"measured",label:"Valeur mesurée",type:"number",required:true},
    {key:"inspected_units",label:"Unités inspectées",type:"number",required:true},
    {key:"rejected_units",label:"Unités rejetées",type:"number",required:true},
    {key:"evidence_ref",label:"Référence de preuve",required:true},
  ];
  const extraForm=(fields:Field[],endpoint:string)=>{
    return <form className="editor-form" onSubmit={async e=>{
      e.preventDefault();
      const data:Record<string,unknown>={};
      for(const f of fields){
        const value=workflow[f.key]??"";
        if(value)data[f.key]=f.type==="number"?Number(value):value;
      }
      await submit(endpoint,data);
    }}>
      <div className="form-grid">{fields.map(f=><label key={f.key}>{f.label}
        {input(f,workflow[f.key]??"",v=>setWorkflow(p=>({...p,[f.key]:v})))}
      </label>)}</div>
      <div className="form-actions"><button type="submit" disabled={busy} className="button primary">Enregistrer</button></div>
    </form>;
  };

  return <section aria-label={config.title}>
    <div className="page-heading"><div><span className="eyebrow">QUALITÉ & CONFORMITÉ · M12</span>
      <h2>{config.title}</h2><p>{config.intro}</p></div>
      {canCreate && <button className="button primary" disabled={busy} onClick={()=>{setCreating(true);setForm({});setSelected(null);}}>
        {config.create}</button>}
    </div>
    <div className="panel" style={{padding:"1rem",marginBottom:"1rem"}}>
      <strong>Qualité : </strong>
      {metrics.failed_controls??0} contrôles en échec · {metrics.open_nonconformities??0} écarts ouverts
      {" · "}{metrics.quarantined_units??0} unités signalées en quarantaine
      {" · "}{metrics.late_actions??0} CAPA en retard · {metrics.open_audits??0} audits ouverts
      <p><small>Le stock ERP n'est pas débité automatiquement : aucun lot physique traçable n'est actuellement garanti par le module Stocks.</small></p>
    </div>
    {message&&<div role="status" className="notice">{message}</div>}
    {error&&<div role="alert" className="error">{error}</div>}
    <div className="panel">
      <div className="list-tools">
        <input aria-label="Rechercher" value={search} placeholder="Rechercher…"
          onChange={e=>{setSearch(e.target.value);setPage(1);}}/>
        <input aria-label="Filtrer par statut" value={status} placeholder="Statut exact (facultatif)"
          onChange={e=>{setStatus(e.target.value);setPage(1);}}/>
        <button className="button secondary" disabled={loading} onClick={()=>setRevision(n=>n+1)}>Actualiser</button>
      </div>
      <div className="table-scroll" style={{overflowX:"auto"}}>
        <table><thead><tr><th>Référence / titre</th><th>Identifiant</th><th>Statut</th><th>Créé le</th><th>Action</th></tr></thead>
          <tbody>
            {records.map(row=><tr key={row.id}>
              <td>{itemLabel(row)}</td><td>{row.id}</td>
              <td>{statusLabels[row.status??""]??row.status??"—"}</td>
              <td>{formatDate(row.created_at)}</td>
              <td><button className="text-button" type="button" onClick={()=>void show(row)}>Détail</button></td>
            </tr>)}
            {records.length===0&&<tr><td colSpan={5}>{loading?"Chargement…":"Aucun enregistrement."}</td></tr>}
          </tbody></table>
      </div>
      <div className="pagination">
        <span>Page {page} / {pages}</span>
        <div><button className="button secondary" disabled={page<=1||loading} onClick={()=>setPage(n=>n-1)}>Précédent</button>
          <button className="button secondary" disabled={page>=pages||loading} onClick={()=>setPage(n=>n+1)}>Suivant</button></div>
      </div>
    </div>
    {creating&&<div className="modal-backdrop">
      <div role="dialog" aria-modal="true" aria-label={config.create} className="modal">
        <header><h2>{config.create}</h2>
          <button aria-label="Fermer" className="icon-button" onClick={()=>setCreating(false)}>✕</button></header>
        <form className="editor-form" onSubmit={newEntity}>
          <div className="form-grid">{config.fields.map(f=><label key={f.key}>{f.label}
            {input(f,fieldValue(f.key),v=>update(f.key,v))}
          </label>)}</div>
          <div className="form-actions"><button type="button" className="button secondary" onClick={()=>setCreating(false)}>Annuler</button>
            <button type="submit" className="button primary" disabled={busy||!canWrite}>Enregistrer</button></div>
        </form>
      </div>
    </div>}
    {selected&&<div className="modal-backdrop">
      <div role="dialog" aria-modal="true" aria-label="Détail qualité" className="modal">
        <header><h2>{itemLabel(selected)}</h2>
          <button aria-label="Fermer" className="icon-button" onClick={()=>setSelected(null)}>✕</button></header>
        <div className="detail-content">
          <dl>{Object.entries(selected).filter(([k,v])=>!["events","versions","checks","findings","actions","content"].includes(k)&&
            v!==null&&typeof v!=="object").map(([k,v])=><div key={k}><dt><strong>{k}</strong></dt><dd>{String(v)}</dd></div>)}</dl>
          {typeof selected.content==="string"&&<div><h3>Contenu</h3><pre style={{whiteSpace:"pre-wrap"}}>{selected.content}</pre></div>}
          {view==="quality-controls"&&canWrite&&<>
            <h3>Enregistrer un contrôle</h3>{extraForm(checkFields,"controls/"+selected.id+"/checks")}
          </>}
          {view==="quality-audits"&&canWrite&&<>
            <h3>Ajouter un constat d'audit</h3>{extraForm([
              {key:"title",label:"Titre",required:true},
              {key:"severity",label:"Gravité",values:["minor","major","critical"],required:true},
              {key:"description",label:"Description",type:"textarea",required:true},
              {key:"evidence_ref",label:"Référence de preuve",required:true},
            ],"audits/"+selected.id+"/findings")}
          </>}
          {view==="compliance-docs"&&canWrite&&<>
            <h3>Nouvelle révision</h3>
            <p>La révision crée une nouvelle version, sans modifier les signatures antérieures.</p>
            {extraForm([{key:"content",label:"Texte de la nouvelle version",type:"textarea",required:true}],
              "documents/"+selected.id+"/versions")}
          </>}
          {view==="compliance-docs"&&Array.isArray(selected.versions)&&
            <div><h3>Versions documentaires</h3>{selected.versions.map((v:QualityRecord)=>
              <div key={v.id} className="panel" style={{padding:".8rem",marginBottom:".5rem"}}>
                <strong>Révision {String(v.revision)} — {statusLabels[String(v.status)]??String(v.status)}</strong>
                <p>SHA-256 : <code>{String(v.sha256)}</code></p>
                <details><summary>Lire la version</summary><pre style={{whiteSpace:"pre-wrap"}}>{String(v.content)}</pre></details>
                <div className="form-actions">
                  {canWrite&&v.status==="draft"&&<button className="button secondary" disabled={busy}
                    onClick={()=>void submit("versions/"+v.id+"/transition",{status:"review"})}>Soumettre en revue</button>}
                  {isManager&&v.status==="review"&&<button className="button secondary" disabled={busy}
                    onClick={()=>void submit("versions/"+v.id+"/transition",{status:"approved"})}>Approuver</button>}
                  {isManager&&v.status==="approved"&&<button className="button secondary" disabled={busy}
                    onClick={()=>void submit("versions/"+v.id+"/transition",{status:"published",effective_at:workflow.effective_at})}>Publier</button>}
                  {isManager&&v.status==="approved"&&<input type="datetime-local" aria-label="Date d'effet"
                    value={workflow.effective_at??""} onChange={e=>setWorkflow(p=>({...p,effective_at:e.target.value}))}/>}
                  {canWrite&&v.status==="published"&&<button className="button secondary" disabled={busy}
                    onClick={()=>void submit("versions/"+v.id+"/acknowledge",{})}>Accuser lecture</button>}
                  {isManager&&v.status==="published"&&<button className="button secondary" disabled={busy}
                    onClick={()=>void submit("versions/"+v.id+"/transition",{status:"obsolete"})}>Rendre obsolète</button>}
                </div>
              </div>)}</div>}
          {extraOptions.length>0&&<div>
            <h3>Transition métier</h3>
            <div className="form-grid">
              {view==="nonconformities"&&selected.status==="contained"&&<>
                {workflowField("decision","Décision",undefined,["corrected","rejected","concession"])}
                {workflowField("closure_reason","Justification de clôture")}
                {workflowField("closure_evidence","Référence de preuve")}
              </>}
              {view==="corrective-actions"&&["needs_plan","ineffective"].includes(selected.status??"")&&<>
                {workflowField("root_cause","Cause racine")}
                {workflowField("due_on","Échéance","date")}
                {workflowField("target_metric","Indicateur mesurable")}
                {workflowField("target_value","Valeur cible","number")}
              </>}
              {view==="corrective-actions"&&selected.status==="planned"&&workflowField("evidence_ref","Preuve d'exécution")}
              {view==="corrective-actions"&&selected.status==="implemented"&&workflowField("measured_value","Mesure d'efficacité","number")}
              {view==="quality-audits"&&selected.status==="in_progress"&&workflowField("closure_evidence","Preuve / rapport de clôture")}
            </div>
            <div className="form-actions">{extraOptions.map(s=><button key={s} className="button secondary"
              type="button" disabled={busy} onClick={()=>void action(s)}>{statusLabels[s]??s}</button>)}</div>
          </div>}
          {(["checks","findings","actions","events"] as const).map(k=>Array.isArray(selected[k])&&
            <div key={k}><h3>{({checks:"Mesures",findings:"Constats",actions:"CAPA",events:"Journal immuable"})[k]}</h3>
              <ul>{(selected[k] as QualityRecord[]).map((v,i)=>
                <li key={String(v.id??i)}>
                  <strong>{String(v.reference??v.title??v.action??v.id)}</strong>
                  {" — "}{String(v.status??v.result??v.severity??"")}
                  {v.evidence_ref&&" · preuve "+String(v.evidence_ref)}
                </li>)}</ul>
            </div>)}
        </div>
      </div>
    </div>}
  </section>;
}

export const qualityViews = new Set<string>(Object.keys(definitions));
