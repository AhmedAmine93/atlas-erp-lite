import { h } from "preact";
import { useState } from "preact/hooks";
import { post } from "./atlas-api";
import type { Execute } from "./planning";

export interface PreventivePlan {
  id:number;
  code:string;
  title:string;
  asset_id:number;
  description?:string|null;
  priority:"low"|"normal"|"high"|"critical";
  interval_days:number;
  next_due_on:string;
  active:boolean;
}
export interface PreventiveOccurrence {
  id:number;
  plan_id:number;
  due_on:string;
  maintenance_request_id:number;
  work_order_id:number;
}
export interface PreventiveRunResult {
  generated_count:number;
  generated:{id:number;plan_id:number;due_on:string;work_order_id:number}[];
  batch_limit:number;
}

export function PreventiveWorkspace({
  plans, occurrences, assets, busy, execute,
}:{
  plans:PreventivePlan[];
  occurrences:PreventiveOccurrence[];
  assets:{id:number;asset_code:string;name:string}[];
  busy:boolean;
  execute:Execute;
}){
  const [open,setOpen]=useState(false);
  const [assetId,setAssetId]=useState("");
  const [code,setCode]=useState("");
  const [title,setTitle]=useState("");
  const [description,setDescription]=useState("");
  const [priority,setPriority]=useState("normal");
  const [intervalDays,setIntervalDays]=useState("30");
  const [dueOn,setDueOn]=useState("");
  const [preview,setPreview]=useState("");
  const [manualResult,setManualResult]=useState("");

  const due=plans.filter(p=>p.active&&p.next_due_on.slice(0,10)<=new Date().toISOString().slice(0,10));
  const assetName=(id:number)=>assets.find(a=>a.id===id)?.name??"Équipement #"+id;
  function submit(event:Event){
    event.preventDefault();
    if(busy)return;
    execute("Plan préventif créé",async()=>{
      await post("maintenance/preventive/plans",{
        asset_id:Number(assetId),code:code.trim(),title:title.trim(),
        description:description.trim(),priority,interval_days:Number(intervalDays),
        next_due_on:dueOn
      });
      setOpen(false);setAssetId("");setCode("");setTitle("");setDescription("");
      setPriority("normal");setIntervalDays("30");setDueOn("");
    });
  }
  function runDue(){
    if(!due.length||busy)return;
    if(!window.confirm(
      "Créer les demandes d’intervention et bons de travail pour au maximum 20 plans échus de la société active ?"
    ))return;
    execute("Échéances examinées",async()=>{
      const response=await post<PreventiveRunResult>("maintenance/preventive/run");
      setManualResult(response.generated_count+
        " occurrence(s) créée(s), limite de traitement "+response.batch_limit+" plans par exécution.");
    });
  }
  function showPreview(plan:PreventivePlan){
    const tomorrow=new Date(plan.next_due_on.slice(0,10)+"T12:00:00Z");
    tomorrow.setUTCDate(tomorrow.getUTCDate()+plan.interval_days);
    setPreview("Plan "+plan.code+" · prochaine période théorique : "+tomorrow.toISOString().slice(0,10)+
      " après création de l’occurrence actuelle. Validation serveur impérative.");
  }
  return <>
    <div class="atlas-jet-metrics">
      {([
        ["Plans préventifs",plans.length],
        ["Plans à échéance",due.length],
        ["Occurrences générées",occurrences.length],
      ] as [string,number][]).map(([name,count])=>
        <div class="atlas-jet-metric" key={name}><span>{name}</span><strong>{count}</strong></div>
      )}
    </div>
    <section class="atlas-jet-card">
      <div class="atlas-jet-report-top">
        <div>
          <h2>Maintenance préventive calendaire</h2>
          <p>Les plans et occurrences sont liés à votre société. L’exécution reste manuelle et auditée.</p>
        </div>
        <div class="atlas-jet-actions">
          <button type="button" disabled={busy||due.length===0}
            onClick={runDue}>Générer DI / BT échus</button>
          <button type="button" disabled={busy} onClick={()=>setOpen(x=>!x)}>
            {open?"Fermer":"Créer un plan"}
          </button>
        </div>
      </div>
      {manualResult&&<p role="status" class="atlas-jet-notice">{manualResult}</p>}
      {preview&&<p role="status" class="atlas-jet-notice">{preview}</p>}
      {open&&<form onSubmit={submit} class="atlas-jet-planning-form">
        <label>Équipement *
          <select value={assetId} required onChange={e=>setAssetId(e.currentTarget.value)}>
            <option value="">Sélectionner…</option>
            {assets.map(a=><option key={a.id} value={a.id}>{a.asset_code} · {a.name}</option>)}
          </select>
        </label>
        <label>Code du plan *
          <input value={code} required maxLength={60} onInput={e=>setCode(e.currentTarget.value)}/>
        </label>
        <label>Intitulé *
          <input value={title} required maxLength={180} onInput={e=>setTitle(e.currentTarget.value)}/>
        </label>
        <label>Priorité *
          <select value={priority} onChange={e=>setPriority(e.currentTarget.value)}>
            {["low","normal","high","critical"].map(p=><option value={p} key={p}>{p}</option>)}
          </select>
        </label>
        <label>Périodicité en jours (1 à 3650) *
          <input type="number" min="1" max="3650" required value={intervalDays}
            onInput={e=>setIntervalDays(e.currentTarget.value)}/>
        </label>
        <label>Première échéance *
          <input type="date" required value={dueOn} onInput={e=>setDueOn(e.currentTarget.value)}/>
        </label>
        <label>Détails
          <textarea rows={3} maxLength={2000} value={description}
            onInput={e=>setDescription(e.currentTarget.value)}/>
        </label>
        <button type="submit" disabled={busy||!assets.length}>Enregistrer le plan</button>
      </form>}
      <div class="atlas-jet-scroll">
        <table>
          <thead><tr><th>Code</th><th>Équipement</th><th>Opération</th><th>Priorité</th>
            <th>Récurrence</th><th>Prochaine échéance</th><th>État</th><th>Action</th></tr></thead>
          <tbody>{plans.map(p=><tr key={p.id}>
            <td>{p.code}</td><td>{assetName(p.asset_id)}</td><td>{p.title}</td><td>{p.priority}</td>
            <td>Tous les {p.interval_days} jours</td>
            <td>{p.next_due_on.slice(0,10)}</td>
            <td>{p.active?(due.includes(p)?"Échu":"Actif"):"Inactif"}</td>
            <td><button type="button" onClick={()=>showPreview(p)}>Prévisualiser</button></td>
          </tr>)}</tbody>
        </table>
      </div>
      {!plans.length&&<p>Aucun plan préventif enregistré pour cette société.</p>}
    </section>
    <section class="atlas-jet-card">
      <h2>Historique des occurrences</h2>
      <div class="atlas-jet-scroll"><table>
        <thead><tr><th>Plan</th><th>Date d’échéance</th><th>DI</th><th>BT</th></tr></thead>
        <tbody>{occurrences.map(o=><tr key={o.id}>
          <td>{plans.find(p=>p.id===o.plan_id)?.code??"Plan #"+o.plan_id}</td>
          <td>{o.due_on.slice(0,10)}</td><td>DI-{o.maintenance_request_id}</td>
          <td>BT-{o.work_order_id}</td>
        </tr>)}</tbody>
      </table></div>
    </section>
  </>;
}
