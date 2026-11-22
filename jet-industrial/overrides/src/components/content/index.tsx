import { h } from "preact";
import { useEffect, useMemo, useState } from "preact/hooks";
import "ojs/ojbutton";
import { PlanningWorkspace, TeamWorkspace, ReportWorkspace } from "./planning";
import { PreventiveWorkspace, type PreventivePlan, type PreventiveOccurrence } from "./preventive";
import {
  api,fetchPages,post,resetSession,setSession,
  type Site,type Asset,type Intervention,type WorkOrder,type SessionProfile,
  type MaintenanceTeam,type MaintenanceTechnician,type MaintenanceSlot,type MaintenanceReport
} from "./atlas-api";

type Tab="overview"|"assets"|"requests"|"orders"|"teams"|"planning"|"reports"|"preventive";
const priorities=["low","normal","high","critical"] as const;
const statusLabels:Record<string,string>={
 new:"Nouvelle",qualified:"Qualifiée",open:"Ouvert",in_progress:"En cours",
 completed:"Terminé",in_service:"En service",out_of_service:"Hors service"
};
function status(value:string){return statusLabels[value]??value;}
function localizedError(err:unknown){return err instanceof Error?err.message:"Erreur inconnue";}
const blankRequest={asset_id:"",reference:"",title:"",description:"",priority:"normal"};
const blankAsset={site_id:"",asset_code:"",name:""};
const blankSite={code:"",name:""};

export function Content(){
 const [tab,setTab]=useState<Tab>("overview");
 const [profile,setProfile]=useState<SessionProfile|null>(null);
 const [sites,setSites]=useState<Site[]>([]);
 const [assets,setAssets]=useState<Asset[]>([]);
 const [requests,setRequests]=useState<Intervention[]>([]);
 const [orders,setOrders]=useState<WorkOrder[]>([]);
 const [teams,setTeams]=useState<MaintenanceTeam[]>([]);
 const [technicians,setTechnicians]=useState<MaintenanceTechnician[]>([]);
 const [slots,setSlots]=useState<MaintenanceSlot[]>([]);
 const [report,setReport]=useState<MaintenanceReport|null>(null);
 const [plans,setPlans]=useState<PreventivePlan[]>([]);
 const [occurrences,setOccurrences]=useState<PreventiveOccurrence[]>([]);
 const [busy,setBusy]=useState(false);
 const [loading,setLoading]=useState(true);
 const [error,setError]=useState("");
 const [notice,setNotice]=useState("");
 const [filter,setFilter]=useState("");
 const [siteForm,setSiteForm]=useState(blankSite);
 const [assetForm,setAssetForm]=useState(blankAsset);
 const [diForm,setDiForm]=useState(blankRequest);
 const [openForm,setOpenForm]=useState<"site"|"asset"|"di"|null>(null);
 const company=profile?.companies.find(c=>c.id===profile.active_company_id);
 const canWrite=profile?.role.name==="admin";
 const siteName=(id:number)=>sites.find(s=>s.id===id)?.name??("Site #"+id);
 const assetName=(id:number)=>assets.find(a=>a.id===id)?.name??("Équipement #"+id);
 const bySite=useMemo(()=>sites.map(s=>({
  site:s,assets:assets.filter(a=>a.site_id===s.id)
 })),[sites,assets]);
 const activeRequests=requests.filter(r=>["new","qualified"].includes(r.status)).length;
 const activeOrders=orders.filter(o=>o.status!=="completed").length;

 async function refresh(){
  setLoading(true);setError("");
  try{
   // Always obtain the active company from Laravel, never from URL/localStorage.
   const p=await api<SessionProfile>("me");
   if(p.role.name!=="admin"){resetSession();throw Error("Ce poste GMAO requiert actuellement le rôle administrateur de la société.");}
   setSession(p);
   const [s,a,r,o,t,tech,planning,summary,plans,nextOccurrences]=await Promise.all([
    fetchPages<Site>("maintenance/sites"),
    fetchPages<Asset>("maintenance/assets"),
    fetchPages<Intervention>("maintenance/requests"),
    fetchPages<WorkOrder>("maintenance/work-orders"),
    fetchPages<MaintenanceTeam>("maintenance/teams"),
    fetchPages<MaintenanceTechnician>("maintenance/technicians"),
    fetchPages<MaintenanceSlot>("maintenance/planning"),
    api<MaintenanceReport>("maintenance/reports/summary"),
    fetchPages<PreventivePlan>("maintenance/preventive/plans"),
    fetchPages<PreventiveOccurrence>("maintenance/preventive/occurrences")
   ]);
   setProfile(p);setSites(s);setAssets(a);setRequests(r);setOrders(o);
   setTeams(t);setTechnicians(tech);setSlots(planning);setReport(summary);
   setPlans(plans);setOccurrences(nextOccurrences);
  }catch(e){
   setProfile(null);setSites([]);setAssets([]);setRequests([]);setOrders([]);
   setTeams([]);setTechnicians([]);setSlots([]);setReport(null);
   setPlans([]);setOccurrences([]);
   setError(localizedError(e));
  }finally{setLoading(false);}
 }
 useEffect(()=>{void refresh();},[]);

 async function execute(label:string,callback:()=>Promise<unknown>){
  if(busy)return;
  setBusy(true);setError("");setNotice("");
  try{
   await callback();
   setNotice(label+" — opération enregistrée et auditée.");
   await refresh();
  }catch(e){setError(localizedError(e));}
  finally{setBusy(false);}
 }
 function createSite(e:Event){
  e.preventDefault();
  void execute("Site créé",async()=>{
   await post("maintenance/sites",{code:siteForm.code.trim(),name:siteForm.name.trim()});
   setOpenForm(null);setSiteForm(blankSite);
  });
 }
 function createAsset(e:Event){
  e.preventDefault();
  void execute("Équipement créé",async()=>{
   await post("maintenance/assets",{
    site_id:Number(assetForm.site_id),asset_code:assetForm.asset_code.trim(),name:assetForm.name.trim()
   });
   setOpenForm(null);setAssetForm(blankAsset);
  });
 }
 function createDi(e:Event){
  e.preventDefault();
  void execute("Demande d’intervention créée",async()=>{
   await post("maintenance/requests",{
    asset_id:Number(diForm.asset_id),reference:diForm.reference.trim(),
    title:diForm.title.trim(),description:diForm.description.trim(),
    priority:diForm.priority
   });
   setOpenForm(null);setDiForm(blankRequest);
  });
 }
 function closeOrder(id:number){
  const resolution=window.prompt("Compte-rendu de clôture du BT (10 caractères minimum)");
  if(resolution===null)return;
  if(resolution.trim().length<10){setError("La clôture nécessite un compte-rendu d’au moins 10 caractères.");return;}
  void execute("BT clôturé",()=>post("maintenance/work-orders/"+id+"/complete",{resolution:resolution.trim()}));
 }
 return <div class="atlas-jet-shell">
  <header class="atlas-jet-header">
   <div class="atlas-jet-brand"><span class="atlas-jet-mark">A</span>
    <div><strong>ATLAS ONE</strong><small>GMAO INDUSTRIAL · ORACLE JET 21</small></div></div>
   <div class="atlas-jet-context"><span>{company?.name??"Session requise"}</span>
    <span class="atlas-jet-role">{profile?.role.name??"—"}</span>
    <oj-button onojAction={()=>void refresh()} disabled={busy||loading}>Actualiser</oj-button>
   </div>
  </header>
  <div class="atlas-jet-body">
   <nav class="atlas-jet-nav" aria-label="Navigation GMAO">
    <p>POSTE INDUSTRIEL</p>
    {([
     ["overview","Vue d’ensemble"],["assets","Sites et équipements"],
     ["requests","Demandes d’intervention"],["orders","Bons de travail"],
     ["teams","Techniciens & équipes"],["planning","Planning technique"],
     ["preventive","Maintenance préventive"],["reports","Rapports GMAO"]
    ] as [Tab,string][]).map(([id,name])=>
     <button type="button" key={id} class={tab===id?"active":""} aria-current={tab===id?"page":undefined}
      onClick={()=>setTab(id)}>{name}</button>
    )}
    <div class="atlas-jet-nav-info">
      <strong>Isolation multi-sociétés</strong>
      <span>Le contexte provient de la session Laravel. Changez de société dans ATLAS Portal puis actualisez cette page.</span>
    </div>
   </nav>
   <main class="atlas-jet-main">
    <div class="atlas-jet-toolbar">
     <div><span class="atlas-jet-overline">MAINTENANCE · WORKSPACE</span>
      <h1>{tab==="overview"?"Pilotage industriel":tab==="assets"?"Parc technique":
       tab==="requests"?"Demandes d’intervention":tab==="orders"?"Bons de travail":
       tab==="teams"?"Équipes et techniciens":tab==="planning"?"Planning technique":
       tab==="preventive"?"Maintenance préventive":"Rapports industriels"}</h1>
      <p>Opérations réelles via les API métier ATLAS ONE, sans écriture directe dans PostgreSQL.</p></div>
     <div class="atlas-jet-actions">
      {canWrite&&tab==="assets"&&<><oj-button onojAction={()=>setOpenForm("site")}>+ Site</oj-button>
      <oj-button onojAction={()=>setOpenForm("asset")}>+ Équipement</oj-button></>}
      {canWrite&&tab==="requests"&&<oj-button onojAction={()=>setOpenForm("di")}>+ Nouvelle DI</oj-button>}
     </div>
    </div>
    {notice&&<div class="atlas-jet-notice" role="status">{notice}</div>}
    {error&&<div class="atlas-jet-error" role="alert">{error}
      {!profile&&<p><a href="http://127.0.0.1:3100">Ouvrir le portail ATLAS pour se connecter</a></p>}
    </div>}
    {loading&&<p class="atlas-jet-muted">Chargement des données de la société active…</p>}
    {!loading&&profile&&<>
     {tab==="overview"&&<>
      <div class="atlas-jet-metrics">
       {[
        ["Sites",sites.length],["Équipements",assets.length],
        ["DI actives",activeRequests],["BT ouverts",activeOrders]
       ].map(([name,value])=><div class="atlas-jet-metric" key={name}><span>{name}</span><strong>{value}</strong></div>)}
      </div>
      <div class="atlas-jet-card"><h2>Interventions prioritaires</h2><p>Dernières demandes critiques et hautes priorités.</p>
       <table><thead><tr><th>Référence</th><th>Équipement</th><th>Priorité</th><th>Statut</th></tr></thead>
       <tbody>{requests.filter(x=>x.priority==="critical"||x.priority==="high").slice(0,12).map(r=>
        <tr key={r.id}><td>{r.reference}</td><td>{assetName(r.asset_id)}</td>
        <td>{r.priority}</td><td><span class="atlas-jet-status">{status(r.status)}</span></td></tr>)}</tbody></table>
      </div>
     </>}
     {tab==="assets"&&<>
       <div class="atlas-jet-card"><h2>Arborescence des sites et équipements</h2>
        <input aria-label="Rechercher un équipement" type="search" value={filter} placeholder="Rechercher code ou nom…"
         onInput={e=>setFilter(e.currentTarget.value)}/>
        <div class="atlas-jet-tree">
         {bySite.map(({site,assets:children})=><details key={site.id} open>
          <summary><strong>{site.name}</strong><span>{site.code} · {children.length} équipements</span></summary>
          <ul>{children.filter(a=>(a.asset_code+" "+a.name).toLowerCase().includes(filter.toLowerCase())).map(a=>
           <li key={a.id}><span>{a.asset_code} · {a.name}</span><span class="atlas-jet-status">{status(a.status)}</span></li>)}</ul>
         </details>)}
        </div>
       </div>
     </>}
     {tab==="requests"&&<div class="atlas-jet-card"><h2>Demandes d’intervention (DI)</h2>
       <div class="atlas-jet-scroll"><table><thead><tr><th>Référence</th><th>Objet</th><th>Équipement</th><th>Priorité</th><th>Statut</th><th>Actions</th></tr></thead>
       <tbody>{requests.map(r=><tr key={r.id}><td>{r.reference}</td><td>{r.title}</td>
        <td>{assetName(r.asset_id)}</td><td>{r.priority}</td><td>{status(r.status)}</td>
        <td class="atlas-jet-row-actions">
         {r.status==="new"&&canWrite&&<oj-button disabled={busy} onojAction={()=>void execute("DI qualifiée",()=>post("maintenance/requests/"+r.id+"/qualify"))}>Qualifier</oj-button>}
         {r.status==="qualified"&&canWrite&&<oj-button disabled={busy} onojAction={()=>void execute("BT créé ou retrouvé",()=>post("maintenance/requests/"+r.id+"/work-orders"))}>Créer / voir BT</oj-button>}
        </td></tr>)}</tbody></table></div>
      </div>}
     {tab==="teams"&&<TeamWorkspace teams={teams} technicians={technicians} busy={busy} execute={execute}/>}
     {tab==="planning"&&<PlanningWorkspace slots={slots} technicians={technicians} orders={orders} busy={busy} execute={execute}/>}
     {tab==="reports"&&<ReportWorkspace report={report}/>}
     {tab==="preventive"&&<PreventiveWorkspace plans={plans} occurrences={occurrences} assets={assets} busy={busy} execute={execute}/>}
     {tab==="orders"&&<div class="atlas-jet-card"><h2>Bons de travail (BT)</h2>
       <div class="atlas-jet-scroll"><table><thead><tr><th>BT</th><th>DI</th><th>Équipement</th><th>Priorité</th><th>État</th><th>Action</th></tr></thead>
       <tbody>{orders.map(o=><tr key={o.id}><td>BT-{o.id}</td><td>DI-{o.maintenance_request_id}</td>
        <td>{assetName(o.asset_id)}</td><td>{o.priority}</td><td>{status(o.status)}</td>
        <td>{canWrite&&o.status==="open"&&<oj-button disabled={busy} onojAction={()=>void execute("BT démarré",()=>post("maintenance/work-orders/"+o.id+"/start"))}>Démarrer</oj-button>}
        {canWrite&&o.status==="in_progress"&&<oj-button disabled={busy} onojAction={()=>closeOrder(o.id)}>Clôturer</oj-button>}</td></tr>)}</tbody></table></div>
      </div>}
    </>}
    {openForm!==null&&profile&&<div class="atlas-jet-dialog-backdrop">
     <form class="atlas-jet-dialog" onSubmit={openForm==="site"?createSite:openForm==="asset"?createAsset:createDi}>
      <h2>{openForm==="site"?"Nouveau site":openForm==="asset"?"Nouvel équipement":"Nouvelle DI"}</h2>
      {openForm==="site"&&<>
       <label>Code *<input required maxLength={50} value={siteForm.code} onInput={e=>setSiteForm({...siteForm,code:e.currentTarget.value})}/></label>
       <label>Nom *<input required maxLength={180} value={siteForm.name} onInput={e=>setSiteForm({...siteForm,name:e.currentTarget.value})}/></label>
      </>}
      {openForm==="asset"&&<>
       <label>Site *<select required value={assetForm.site_id} onChange={e=>setAssetForm({...assetForm,site_id:e.currentTarget.value})}>
        <option value="">Sélectionner…</option>{sites.map(s=><option value={s.id} key={s.id}>{s.name}</option>)}</select></label>
       <label>Code équipement *<input required maxLength={60} value={assetForm.asset_code} onInput={e=>setAssetForm({...assetForm,asset_code:e.currentTarget.value})}/></label>
       <label>Nom *<input required maxLength={180} value={assetForm.name} onInput={e=>setAssetForm({...assetForm,name:e.currentTarget.value})}/></label>
      </>}
      {openForm==="di"&&<>
       <label>Équipement *<select required value={diForm.asset_id} onChange={e=>setDiForm({...diForm,asset_id:e.currentTarget.value})}>
        <option value="">Sélectionner…</option>{assets.map(a=><option value={a.id} key={a.id}>{a.asset_code} · {a.name}</option>)}</select></label>
       <label>Référence DI *<input required maxLength={60} value={diForm.reference} onInput={e=>setDiForm({...diForm,reference:e.currentTarget.value})}/></label>
       <label>Objet *<input required maxLength={180} value={diForm.title} onInput={e=>setDiForm({...diForm,title:e.currentTarget.value})}/></label>
       <label>Priorité *<select value={diForm.priority} onChange={e=>setDiForm({...diForm,priority:e.currentTarget.value})}>
        {priorities.map(p=><option key={p} value={p}>{p}</option>)}</select></label>
       <label>Détails<textarea rows={3} maxLength={2000} value={diForm.description} onInput={e=>setDiForm({...diForm,description:e.currentTarget.value})}/></label>
      </>}
      <div class="atlas-jet-dialog-buttons">
       <button type="button" disabled={busy} onClick={()=>setOpenForm(null)}>Annuler</button>
       <button type="submit" disabled={busy}>{busy?"Traitement…":"Enregistrer"}</button>
      </div>
     </form>
    </div>}
   </main>
  </div>
 </div>;
}
