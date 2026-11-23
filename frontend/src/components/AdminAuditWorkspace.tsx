"use client";
import {useEffect,useState,type FormEvent} from "react";
import {api,fetchTenantBlob} from "@/lib/api";
type Entry={id:number;action:string;entity_type:string;entity_id:number|null;created_at:string;user:{name:string}|null};
type Page<T>={data:T[];total:number;last_page:number};
export default function AdminAuditWorkspace({activeCompanyId}:{activeCompanyId:number|null}) {
 const [action,setAction]=useState(""),[from,setFrom]=useState(""),[to,setTo]=useState("");
 const [filter,setFilter]=useState({action:"",from:"",to:""}),[page,setPage]=useState(1);
 const [entries,setEntries]=useState<Entry[]>([]),[total,setTotal]=useState(0),[last,setLast]=useState(1);
 const [error,setError]=useState(""),[working,setWorking]=useState(false);
 const query=new URLSearchParams(Object.entries(filter).filter(([,v])=>v!==""));
 useEffect(()=>{let live=true;setError("");api<Page<Entry>>("audit-logs?page="+page+"&"+query.toString())
  .then(result=>{if(live){setEntries(result.data);setTotal(result.total);setLast(result.last_page);}})
  .catch((e:Error)=>{if(live)setError(e.message);});return()=>{live=false;};
 },[page,filter,activeCompanyId]);
 async function download(){
  setWorking(true);setError("");
  try{const blob=await fetchTenantBlob("audit-logs/export?"+query.toString(),"text/csv");
   const url=URL.createObjectURL(blob);const link=document.createElement("a");
   link.href=url;link.download="atlas-audit.csv";link.click();window.setTimeout(()=>URL.revokeObjectURL(url),1000);
  }catch(e){setError((e as Error).message);}finally{setWorking(false);}
 }
 return <section className="panel">
  <h2>Journal d’activité</h2><p>Historique append-only par entreprise active. Les métadonnées sensibles ne sont pas exportées.</p>
  <form onSubmit={(e:FormEvent<HTMLFormElement>)=>{e.preventDefault();setPage(1);setFilter({action,from,to});}}>
   <label>Action exacte<input maxLength={120} value={action} onChange={e=>setAction(e.target.value)}/></label>
   <label>Du<input type="date" value={from} onChange={e=>setFrom(e.target.value)}/></label>
   <label>Au<input type="date" value={to} onChange={e=>setTo(e.target.value)}/></label>
   <button type="submit">Filtrer</button><button type="button" disabled={working} onClick={()=>void download()}>Exporter CSV (max. 10 000)</button>
  </form>
  {error&&<p className="error" role="alert">{error}</p>}
  <div className="table-scroll"><table><thead><tr><th>Action</th><th>Auteur</th><th>Entité</th><th>ID</th><th>Horodatage</th></tr></thead>
   <tbody>{entries.map(e=><tr key={e.id}><td>{e.action}</td><td>{e.user?.name??"Compte historique"}</td>
    <td>{e.entity_type}</td><td>{e.entity_id??"—"}</td><td>{new Date(e.created_at).toLocaleString("fr-FR")}</td></tr>)}</tbody></table></div>
  <p>{total} événement(s) filtré(s)</p>
  <nav aria-label="Pagination de l’audit"><button disabled={page<=1} onClick={()=>setPage(v=>v-1)}>Précédent</button>
   <span> {page} / {Math.max(1,last)} </span><button disabled={page>=last} onClick={()=>setPage(v=>v+1)}>Suivant</button></nav>
 </section>;
}