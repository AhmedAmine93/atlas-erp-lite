"use client";
import { useEffect, useState, type FormEvent } from "react";
import { api, send } from "@/lib/api";
type Row = Record<string, unknown>;
type Paginated = {data:Row[];last_page:number;total:number};
type RoleCatalog={roles:{id:number;name:string}[];permissions:{code:string;roles:string[];description:string}[]};
export const adminViews = ["admin-roles","admin-settings","admin-documents","admin-security"];
const string=(v:unknown)=>v==null?"—":String(v);
function Records({items,keys}:{items:Row[];keys:string[]}) {
 return <div className="table-scroll"><table><thead><tr>{keys.map(key=><th key={key}>{key}</th>)}</tr></thead><tbody>
 {items.map((r,i)=><tr key={String(r.id??i)}>{keys.map(k=><td key={k}>{string(r[k])}</td>)}</tr>)}</tbody></table>
 {!items.length&&<p>Aucun résultat.</p>}</div>;
}
export default function AdministrationWorkspace({view,activeCompanyId}:{view:string;activeCompanyId:number|null}) {
 const [rows,setRows]=useState<Row[]>([]),[catalog,setCatalog]=useState<RoleCatalog|null>(null);
 const [allocations,setAllocations]=useState<Row[]>([]);
 const [page,setPage]=useState(1),[last,setLast]=useState(1),[revision,setRevision]=useState(0);
 const [error,setError]=useState(""),[notice,setNotice]=useState(""),[busy,setBusy]=useState(false),[loading,setLoading]=useState(true);
 const [setting,setSetting]=useState({key:"timezone",value:"Europe/Paris",effective_at:new Date().toISOString().slice(0,10)});
 const [series,setSeries]=useState({document_type:"invoice",prefix:"FAC",period_year:new Date().getFullYear(),establishment_id:""});
 useEffect(()=>{setPage(1);setRows([]);setCatalog(null);setError("");setNotice("");},[view,activeCompanyId]);
 useEffect(()=>{
  let live=true;setLoading(true);setError("");
  const endpoint=view==="admin-settings"?"admin/settings":view==="admin-documents"?"admin/series":"admin/sessions";
  const main=view==="admin-roles"?api<RoleCatalog>("admin/roles"):api<Paginated>(endpoint+"?page="+page);
  const other=view==="admin-documents"?api<Paginated>("admin/allocations"):Promise.resolve(null);
  Promise.all([main,other]).then(([value,extra])=>{if(!live)return;
   if(view==="admin-roles")setCatalog(value as RoleCatalog);
   else {const p=value as Paginated;setRows(p.data);setLast(p.last_page);}
   if(extra)setAllocations(extra.data);
  }).catch((e:Error)=>{if(live)setError(e.message);}).finally(()=>{if(live)setLoading(false);});
  return()=>{live=false;};
 },[view,page,activeCompanyId,revision]);
 async function mutate(operation:()=>Promise<unknown>,success:string) {
  if(busy)return;setBusy(true);setError("");setNotice("");
  try {await operation();setNotice(success);setRevision(v=>v+1);}
  catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 return <section className="panel" aria-label="Administration sécurisée">
  {loading&&<p role="status">Chargement…</p>}{error&&<p className="error" role="alert">{error}</p>}
  {notice&&<p className="notice" role="status">{notice}</p>}
  {view==="admin-roles"&&<>
   <h2>Rôles et permissions</h2><p>Les rôles globaux restent gérés par les migrations et les politiques Laravel. Les affectations par entreprise sont modifiables depuis Utilisateurs.</p>
   <Records items={(catalog?.roles??[]) as unknown as Row[]} keys={["id","name"]}/>
   <div className="table-scroll"><table><thead><tr><th>Code</th><th>Description</th><th>Rôles</th></tr></thead><tbody>
   {(catalog?.permissions??[]).map(p=><tr key={p.code}><td>{p.code}</td><td>{p.description}</td><td>{p.roles.join(", ")}</td></tr>)}
   </tbody></table></div>
  </>}
  {view==="admin-settings"&&<>
   <h2>Paramètres versionnés</h2><p>Les changements futurs ne recalculent pas les pièces déjà émises.</p>
   <form onSubmit={(e:FormEvent<HTMLFormElement>)=>{e.preventDefault();const version=rows.filter(r=>r.key===setting.key).reduce((m,r)=>Math.max(m,Number(r.version)),0);
    void mutate(()=>send("admin/settings","POST",{...setting,expected_version:version}),"Nouvelle version publiée.");}}>
    <label>Paramètre<select value={setting.key} onChange={e=>setSetting(v=>({...v,key:e.target.value}))}>
     <option value="timezone">Fuseau horaire</option><option value="locale">Langue</option><option value="reference_currency">Devise de référence</option></select></label>
    <label>Valeur<input required maxLength={80} value={setting.value} onChange={e=>setSetting(v=>({...v,value:e.target.value}))}/></label>
    <label>Date d’effet<input type="date" required value={setting.effective_at} onChange={e=>setSetting(v=>({...v,effective_at:e.target.value}))}/></label>
    <button type="submit" disabled={busy||loading}>Publier la version</button>
   </form>
   <Records items={rows} keys={["key","version","value","effective_at"]}/>
  </>}
  {view==="admin-documents"&&<>
   <h2>Séries de documents</h2><p>Numérotation atomique par société, établissement et année. L’émission effective des factures utilise encore le moteur historique.</p>
   <form onSubmit={(e:FormEvent<HTMLFormElement>)=>{e.preventDefault();void mutate(()=>send("admin/series","POST",
    {document_type:series.document_type,prefix:series.prefix,period_year:series.period_year,
    ...(series.establishment_id?{establishment_id:Number(series.establishment_id)}:{})}),"Série créée.");}}>
    <label>Type<select value={series.document_type} onChange={e=>setSeries(v=>({...v,document_type:e.target.value}))}>
     <option value="invoice">Facture</option><option value="quote">Devis</option><option value="order">Commande</option></select></label>
    <label>Préfixe<input required maxLength={32} pattern="[A-Z0-9_-]+" value={series.prefix} onChange={e=>setSeries(v=>({...v,prefix:e.target.value.toUpperCase()}))}/></label>
    <label>Année<input type="number" required min={2026} max={2100} value={series.period_year} onChange={e=>setSeries(v=>({...v,period_year:Number(e.target.value)}))}/></label>
    <label>Établissement (identifiant optionnel)<input type="number" min={1} value={series.establishment_id} onChange={e=>setSeries(v=>({...v,establishment_id:e.target.value}))}/></label>
    <button disabled={busy||loading} type="submit">Créer une série</button>
   </form>
   <Records items={rows} keys={["id","scope_key","document_type","period_year","prefix","next_number"]}/>
   <h3>Numéros réservés</h3><Records items={allocations} keys={["document_number","series_id","created_at"]}/>
  </>}
  {view==="admin-security"&&<>
   <h2>Sessions du compte connecté</h2><p>Révocation immédiate d’une session. MFA et rotation des secrets nécessitent un service et une procédure d’exploitation vérifiés ; ils ne sont pas activés ici.</p>
   <div className="table-scroll"><table><thead><tr><th>Session</th><th>IP</th><th>Dernière activité</th><th>Action</th></tr></thead><tbody>
   {rows.map(r=><tr key={string(r.id)}><td>{string(r.id).slice(0,12)+"…"}</td><td>{string(r.ip_address)}</td>
    <td>{new Date(Number(r.last_activity)*1000).toLocaleString("fr-FR")}</td><td><button disabled={busy} onClick={()=>{
    if(window.confirm("Révoquer cette session ?"))void mutate(()=>api("admin/sessions/"+encodeURIComponent(string(r.id)),{method:"DELETE"}),"Session révoquée.");}}>Révoquer</button></td></tr>)}
   </tbody></table></div>
  </>}
  {view!=="admin-roles"&&<nav aria-label="Pagination"><button disabled={page<=1||loading} onClick={()=>setPage(v=>v-1)}>Précédent</button>
   <span> Page {page} / {Math.max(1,last)} </span><button disabled={page>=last||loading} onClick={()=>setPage(v=>v+1)}>Suivant</button></nav>}
 </section>;
}