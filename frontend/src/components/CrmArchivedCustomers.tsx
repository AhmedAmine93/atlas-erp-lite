"use client";

import {useEffect,useState} from "react";
import {api,send} from "@/lib/api";

type Customer={id:number;name:string;email:string;archived_at:string;archive_reason:string|null};
type Page={data:Customer[];total:number;last_page:number};

export default function CrmArchivedCustomers({activeCompanyId,onChanged}:{
  activeCompanyId:number|null;onChanged:()=>void;
}){
 const [records,setRecords]=useState<Customer[]>([]);
 const [page,setPage]=useState(1);
 const [lastPage,setLastPage]=useState(1);
 const [search,setSearch]=useState("");
 const [query,setQuery]=useState("");
 const [busy,setBusy]=useState(false);
 const [error,setError]=useState("");
 const [notice,setNotice]=useState("");
 const [revision,setRevision]=useState(0);
 useEffect(()=>{
   let live=true;setRecords([]);setError("");
   const params=new URLSearchParams({page:String(page),...(query?{search:query}:{})});
   api<Page>("crm/customers/archived?"+params.toString())
     .then(result=>{if(live){setRecords(result.data);setLastPage(result.last_page);}})
     .catch((e:Error)=>{if(live)setError(e.message);});
   return ()=>{live=false;};
 },[activeCompanyId,page,query,revision]);
 async function restore(row:Customer){
   const reason=window.prompt("Motif de réactivation du client "+row.name+" (8 caractères minimum) :");
   if(reason===null)return;
   if(reason.trim().length<8){setError("Motif insuffisant.");return;}
   setBusy(true);setError("");setNotice("");
   try {
     await send("crm/customers/"+row.id+"/restore","POST",{reason:reason.trim()});
     setNotice("Client réactivé sans modifier les documents historiques.");
     setRevision(n=>n+1);onChanged();
   }catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 return <section className="atlas-domain" aria-label="Clients archivés">
   <h3>Clients archivés et réactivation contrôlée</h3>
   <p>Les factures, commandes et historiques d'origine sont conservés.</p>
   <form className="atlas-domain-form" onSubmit={e=>{e.preventDefault();setPage(1);setQuery(search.trim());}}>
     <label>Rechercher <input type="search" maxLength={100} value={search} onChange={e=>setSearch(e.target.value)}/></label>
     <button className="button secondary">Filtrer</button>
   </form>
   {error&&<p className="error" role="alert">{error}</p>}
   {notice&&<p className="notice" role="status">{notice}</p>}
   <div className="table-scroll"><table><thead><tr><th>Client</th><th>Email</th><th>Date archivage</th><th>Motif</th><th>Action</th></tr></thead>
    <tbody>{records.map(row=><tr key={row.id}>
      <td>{row.name} (#{row.id})</td><td>{row.email}</td>
      <td>{new Date(row.archived_at).toLocaleDateString("fr-FR")}</td><td>{row.archive_reason??"—"}</td>
      <td><button type="button" className="text-button" disabled={busy}
        onClick={()=>void restore(row)}>Réactiver</button></td>
    </tr>)}</tbody></table></div>
   {records.length===0&&<p>Aucun client archivé dans cette page.</p>}
   <div className="atlas-domain-pagination">
     <button type="button" className="button secondary" disabled={page===1||busy} onClick={()=>setPage(p=>p-1)}>Précédent</button>
     <span>Page {page} sur {lastPage}</span>
     <button type="button" className="button secondary" disabled={page>=lastPage||busy} onClick={()=>setPage(p=>p+1)}>Suivant</button>
   </div>
 </section>;
}
