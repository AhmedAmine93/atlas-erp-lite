import { h } from "preact";
import { useEffect, useState } from "preact/hooks";
import { api, type Asset, type Page } from "./atlas-api";
import type { Execute } from "./planning";

export type IndustrialTab = "industrial-overview"|"meters"|"inspections"|"inspection-templates"|"industrial-rules";
export interface IndustrialSummary {
  company_id:number;generated_at:string;range:{from:string;to:string};
  assets_by_status:Record<string,number>;requests_by_priority:Record<string,number>;
  work_orders_by_status:Record<string,number>;meter_readings_30d:number;
  failed_inspections_30d:number;pending_preventive:number;
}
interface Meter {id:number;asset_id:number;meter_code:string;reading_value:string;unit:string;recorded_at:string;}
interface Inspection {id:number;asset_id:number;template_id:number;result:string;observations:string;inspected_at:string;}
interface Template {id:number;code:string;title:string;description:string|null;active:boolean;}
interface ActionResponse {id:number;replayed:boolean;corrective_request_id?:number|null;corrective_work_order_id?:number|null;}
const errorText=(e:unknown)=>e instanceof Error?e.message:"Erreur industrielle.";
export function IndustrialWorkspace({
 mode,assets,busy,execute,refreshVersion,
}:{
 mode:IndustrialTab;assets:Asset[];busy:boolean;execute:Execute;refreshVersion:number;
}){
 const [summary,setSummary]=useState<IndustrialSummary|null>(null);
 const [meters,setMeters]=useState<Meter[]>([]);
 const [templates,setTemplates]=useState<Template[]>([]);
 const [inspections,setInspections]=useState<Inspection[]>([]);
 const [busyLoad,setBusyLoad]=useState(false);
 const [error,setError]=useState("");
 const [filterAsset,setFilterAsset]=useState("");
 const [meterAsset,setMeterAsset]=useState("");
 const [meterCode,setMeterCode]=useState("HOURS");
 const [meterValue,setMeterValue]=useState("");
 const [inspectAsset,setInspectAsset]=useState("");
 const [templateId,setTemplateId]=useState("");
 const [inspectResult,setInspectResult]=useState("pass");
 const [observations,setObservations]=useState("");
 const [newCode,setNewCode]=useState("");
 const [newTitle,setNewTitle]=useState("");
 const [newDescription,setNewDescription]=useState("");
 const [resultNotice,setResultNotice]=useState("");
 const assetName=(id:number)=>assets.find(a=>a.id===id)?.name??("Équipement #"+id);
 const templateName=(id:number)=>templates.find(t=>t.id===id)?.title??("Modèle #"+id);
 const filteredMeters=meters.filter(m=>!filterAsset||m.asset_id===Number(filterAsset));
 const filteredInspections=inspections.filter(m=>!filterAsset||m.asset_id===Number(filterAsset));
 useEffect(()=>{
  let live=true;setBusyLoad(true);setError("");
  const run=async()=>{
   if(mode==="industrial-overview"||mode==="industrial-rules"){
    const data=await api<IndustrialSummary>("industrial-jet/overview");
    if(live)setSummary(data);
   }
   if(mode==="meters"){
    const data=await api<Page<Meter>>("industrial-jet/meters");
    if(live)setMeters(data.data);
   }
   if(mode==="inspections"||mode==="inspection-templates"||mode==="industrial-rules"){
    const [t,r]=await Promise.all([
     api<Page<Template>>("industrial-jet/templates"),
     api<Page<Inspection>>("industrial-jet/inspections"),
    ]);
    if(live){setTemplates(t.data);setInspections(r.data);}
   }
  };
  void run().catch(e=>{if(live)setError(errorText(e));})
   .finally(()=>{if(live)setBusyLoad(false);});
  return()=>{live=false;};
 },[mode,refreshVersion]);
 const assetOptions=assets.filter(a=>a.status!=="retired");
 const captureKey=()=>crypto.randomUUID();
 const submitMeter=(event:Event)=>{
  event.preventDefault();setResultNotice("");
  if(!meterAsset||!meterValue){setError("Équipement et relevé obligatoires.");return;}
  const key=captureKey();
  execute("Relevé validé en base",async()=>{
   const result=await api<ActionResponse>("industrial-jet/meters",{
    method:"POST",body:JSON.stringify({
     asset_id:Number(meterAsset),meter_code:meterCode,
     reading_value:Number(meterValue),idempotency_key:key
    })
   });
   setResultNotice("Relevé #"+result.id+" — "+(result.replayed?"déjà présent":"enregistré")+".");
   setMeterValue("");
  });
 };
 const submitTemplate=(event:Event)=>{
  event.preventDefault();setResultNotice("");
  execute("Modèle de contrôle créé",async()=>{
   await api<ActionResponse>("industrial-jet/templates",{
    method:"POST",body:JSON.stringify({
     code:newCode.trim().toUpperCase(),title:newTitle.trim(),
     description:newDescription.trim()||null
    })
   });
   setNewCode("");setNewTitle("");setNewDescription("");
  });
 };
 const submitInspection=(event:Event)=>{
  event.preventDefault();setResultNotice("");
  if(!inspectAsset||!templateId){setError("Équipement et modèle obligatoires.");return;}
  const key=captureKey();
  execute("Contrôle industriel enregistré",async()=>{
   const result=await api<ActionResponse>("industrial-jet/inspections",{
    method:"POST",body:JSON.stringify({
     asset_id:Number(inspectAsset),template_id:Number(templateId),
     result:inspectResult,observations:observations.trim(),idempotency_key:key,
    })
   });
   setResultNotice(result.corrective_work_order_id
    ? "Non-conformité #"+result.id+" : DI #"+result.corrective_request_id+" et BT #"+result.corrective_work_order_id+" créés."
    : "Contrôle #"+result.id+" — "+(result.replayed?"déjà enregistré":"enregistré")+".");
   setObservations("");
  });
 };
 const showSummary=mode==="industrial-overview"||mode==="industrial-rules";
 return <div class="atlas-industrial-workspace">
  {busyLoad&&<p class="atlas-jet-muted" role="status">Chargement des informations via Node.js / PostgreSQL…</p>}
  {error&&<p class="atlas-jet-error" role="alert">{error}</p>}
  {resultNotice&&<p class="atlas-jet-notice" role="status">{resultNotice}</p>}
  {showSummary&&summary&&<>
   <div class="atlas-jet-metrics">
    {[
     ["Relevés / 30 jours",summary.meter_readings_30d],
     ["Contrôles non conformes / 30 jours",summary.failed_inspections_30d],
     ["Préventifs échus",summary.pending_preventive],
     ["BT en cours",summary.work_orders_by_status.in_progress??0],
    ].map(([name,value])=><div key={String(name)} class="atlas-jet-metric">
     <span>{name}</span><strong>{Number(value).toLocaleString("fr-FR")}</strong>
    </div>)}
   </div>
   {mode==="industrial-overview"&&<div class="atlas-jet-two-col">
    <section class="atlas-jet-card"><h2>États des équipements</h2>
     {Object.keys(summary.assets_by_status).length===0?<p>Aucun équipement.</p>:
      <ul class="atlas-jet-directory">{Object.entries(summary.assets_by_status).map(([key,value])=>
       <li key={key}><strong>{key}</strong><span>{value.toLocaleString("fr-FR")}</span></li>)}</ul>}
    </section>
    <section class="atlas-jet-card"><h2>Urgences de maintenance</h2>
     {Object.keys(summary.requests_by_priority).length===0?<p>Aucune demande ouverte.</p>:
      <ul class="atlas-jet-directory">{Object.entries(summary.requests_by_priority).map(([key,value])=>
       <li key={key}><strong>{key}</strong><span>{value.toLocaleString("fr-FR")}</span></li>)}</ul>}
    </section>
   </div>}
   <p class="atlas-jet-muted">Consolidation entreprise active uniquement. Calcul PostgreSQL · {new Date(summary.generated_at).toLocaleString("fr-FR")} · fenêtre {summary.range.from} — {summary.range.to}. Redis : cache court, non source de vérité.</p>
  </>}
  {mode==="meters"&&<>
   <section class="atlas-jet-card"><h2>Nouveau relevé de compteur</h2>
    <p>Règles SQL : compteur non négatif, monotonie par équipement/type, refus si équipement retiré ; requêtes répétées avec même clé sans doublons.</p>
    <form class="atlas-jet-planning-form" onSubmit={submitMeter}>
     <label>Équipement<select value={meterAsset} required onChange={e=>setMeterAsset(e.currentTarget.value)}>
      <option value="">Sélectionner…</option>{assetOptions.map(a=><option key={a.id} value={a.id}>{a.asset_code} · {a.name}</option>)}
     </select></label>
     <label>Type de compteur<select value={meterCode} onChange={e=>setMeterCode(e.currentTarget.value)}>
      <option value="HOURS">Heures (h)</option><option value="CYCLES">Cycles</option>
      <option value="DISTANCE">Kilométrage (km)</option><option value="ENERGY">Énergie (kWh)</option>
     </select></label>
     <label>Valeur<input required type="number" min="0" max="999999999999" step="0.001"
      value={meterValue} onInput={e=>setMeterValue(e.currentTarget.value)}/></label>
     <button disabled={busy} type="submit">Enregistrer et contrôler</button>
    </form>
   </section>
   <section class="atlas-jet-card"><h2>Historique des relevés (20 derniers)</h2>
    <label>Équipement <select value={filterAsset} onChange={e=>setFilterAsset(e.currentTarget.value)}>
     <option value="">Tous</option>{assets.map(a=><option key={a.id} value={a.id}>{a.name}</option>)}
    </select></label>
    <div class="atlas-jet-scroll"><table><thead><tr><th>Équipement</th><th>Compteur</th><th>Valeur</th><th>Enregistré le</th></tr></thead>
     <tbody>{filteredMeters.map(m=><tr key={m.id}><td>{assetName(m.asset_id)}</td>
      <td>{m.meter_code}</td><td>{m.reading_value} {m.unit}</td>
      <td>{new Date(m.recorded_at).toLocaleString("fr-FR")}</td></tr>)}</tbody></table></div>
   </section>
  </>}
  {mode==="inspection-templates"&&<>
   <section class="atlas-jet-card"><h2>Définir un modèle de contrôle</h2>
    <p>Le modèle appartient à la société active. Il ne peut pas référencer les équipements ou modèles d’une autre société.</p>
    <form class="atlas-jet-planning-form" onSubmit={submitTemplate}>
     <label>Code<input required minLength={2} maxLength={40} pattern="[A-Z0-9][A-Z0-9_-]*"
      value={newCode} onInput={e=>setNewCode(e.currentTarget.value.toUpperCase())}/></label>
     <label>Intitulé<input required minLength={5} maxLength={160} value={newTitle}
      onInput={e=>setNewTitle(e.currentTarget.value)}/></label>
     <label>Description<input maxLength={500} value={newDescription}
      onInput={e=>setNewDescription(e.currentTarget.value)}/></label>
     <button disabled={busy} type="submit">Créer le modèle</button>
    </form>
   </section>
   <section class="atlas-jet-card"><h2>Modèles disponibles</h2>
    {!templates.length?<p>Aucun modèle enregistré.</p>:
     <ul class="atlas-jet-directory">{templates.map(t=><li key={t.id}><strong>{t.code} · {t.title}</strong>
      <span>{t.active?"Actif":"Inactif"}</span></li>)}</ul>}
   </section>
  </>}
  {mode==="inspections"&&<>
   <section class="atlas-jet-card"><h2>Enregistrer un contrôle</h2>
    <p>Une non-conformité crée dans la même transaction une demande d’intervention qualifiée et un bon de travail correctif.</p>
    <form class="atlas-jet-planning-form" onSubmit={submitInspection}>
     <label>Équipement<select required value={inspectAsset} onChange={e=>setInspectAsset(e.currentTarget.value)}>
      <option value="">Sélectionner…</option>{assetOptions.map(a=><option key={a.id} value={a.id}>{a.asset_code} · {a.name}</option>)}
     </select></label>
     <label>Modèle<select required value={templateId} onChange={e=>setTemplateId(e.currentTarget.value)}>
      <option value="">Sélectionner…</option>{templates.filter(t=>t.active).map(t=><option key={t.id} value={t.id}>{t.code} · {t.title}</option>)}
     </select></label>
     <label>Décision<select value={inspectResult} onChange={e=>setInspectResult(e.currentTarget.value)}>
      <option value="pass">Conforme</option><option value="fail">Non conforme → DI + BT</option>
      <option value="observation">Observation</option></select></label>
     <label>Observations<input required minLength={10} maxLength={2000} value={observations}
      onInput={e=>setObservations(e.currentTarget.value)} placeholder="Constat et actions engagées…"/></label>
     <button disabled={busy} type="submit">Valider le contrôle</button>
    </form>
   </section>
   <section class="atlas-jet-card"><h2>Contrôles enregistrés (20 derniers)</h2>
    <label>Équipement <select value={filterAsset} onChange={e=>setFilterAsset(e.currentTarget.value)}>
     <option value="">Tous</option>{assets.map(a=><option key={a.id} value={a.id}>{a.name}</option>)}
    </select></label>
    <div class="atlas-jet-scroll"><table><thead><tr><th>Équipement</th><th>Modèle</th><th>Décision</th><th>Constat</th><th>Date</th></tr></thead>
     <tbody>{filteredInspections.map(i=><tr key={i.id}><td>{assetName(i.asset_id)}</td>
      <td>{templateName(i.template_id)}</td><td>{i.result}</td><td>{i.observations}</td>
      <td>{new Date(i.inspected_at).toLocaleString("fr-FR")}</td></tr>)}</tbody></table></div>
   </section>
  </>}
  {mode==="industrial-rules"&&<>
   <section class="atlas-jet-card"><h2>Règles métiers exécutées dans PostgreSQL</h2>
    <p>Logique atomique dans les fonctions PL/pgSQL <code>atlas_industrial.record_meter</code> et <code>atlas_industrial.record_inspection</code>. Ce ne sont pas des packages Oracle : Oracle Database n’est pas connecté.</p>
    <ul class="atlas-jet-rule-list">
     <li><strong>RM-IND-01</strong> — Chaque société reste isolée via Laravel et RLS PostgreSQL.</li>
     <li><strong>RM-IND-02</strong> — Une mesure ne peut pas décroître à équipement et type constants.</li>
     <li><strong>RM-IND-03</strong> — Même clé et même contenu : pas de doublon ; même clé différente : conflit.</li>
     <li><strong>RM-IND-04</strong> — Modèle actif obligatoire et tenant cohérent avec l’équipement.</li>
     <li><strong>RM-IND-05</strong> — Non-conformité : DI et BT correctif atomiques.</li>
     <li><strong>RM-IND-06</strong> — Redis ne confère aucun droit, Laravel vérifie le rôle.</li>
    </ul>
    <p>Modèles actifs connus : {templates.filter(t=>t.active).length} · Contrôles chargés : {inspections.length}. Les listes sont paginées à 20 lignes côté serveur.</p>
   </section>
  </>}
 </div>;
}
