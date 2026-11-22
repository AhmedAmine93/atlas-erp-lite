"use client";
import {useState,type FormEvent} from "react";
import {api,fetchTenantBlob} from "@/lib/api";

type Result={id:number;accepted_rows:number;rejected_rows:number;replayed:boolean;
 rejections:Array<{line?:number;email?:string;reason:string}>};

export default function CrmCustomerImport({onImported}:{onImported:()=>void}){
 const [file,setFile]=useState<File|null>(null);
 const [busy,setBusy]=useState(false);
 const [error,setError]=useState("");
 const [result,setResult]=useState<Result|null>(null);
 async function exportCustomers(){
   if(busy)return;
   setBusy(true);setError("");
   try {
     const blob=await fetchTenantBlob("crm/export/customers","text/csv");
     const url=URL.createObjectURL(blob);
     try{
       const link=document.createElement("a");
       link.href=url;link.download="atlas_crm_customers.csv";
       document.body.appendChild(link);link.click();link.remove();
     }finally{window.setTimeout(()=>URL.revokeObjectURL(url),1000);}
   }catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 async function upload(e:FormEvent<HTMLFormElement>){
   e.preventDefault();if(!file||busy)return;
   setBusy(true);setError("");setResult(null);
   try{
     if(file.size>1048576)throw Error("Fichier supérieur à 1 Mio.");
     const body=new FormData();body.append("file",file,file.name);
     const r=await api<Result>("crm/customers/import",{method:"POST",body});
     setResult(r);onImported();
   }catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 return <section className="atlas-domain" aria-label="Import clients CSV">
   <div className="atlas-domain-toolbar"><div><h3>Import de clients CSV</h3>
   <p>Format : name,email,phone · maximum 1 Mio / 1000 lignes. Les imports identiques ne sont pas dupliqués.</p>
   <button type="button" className="button secondary" disabled={busy} onClick={()=>void exportCustomers()}>Exporter les clients CSV</button>
   </div></div>
   {error&&<p role="alert" className="error">{error}</p>}
   <form className="atlas-domain-form" onSubmit={e=>void upload(e)}>
     <label>Fichier CSV
       <input type="file" accept=".csv,text/csv,text/plain" required
         onChange={e=>setFile(e.target.files?.[0]??null)}/>
     </label>
     <button className="button secondary" disabled={!file||busy}>{busy?"Importation…":"Importer le CSV"}</button>
   </form>
   {result&&<div role="status" className="notice">
     Lot #{result.id} · {result.accepted_rows} clients créés · {result.rejected_rows} lignes rejetées
     {result.replayed?" · import déjà traité":""}
     {result.rejections.length>0&&<details><summary>Voir les rejets</summary>
       <ul>{result.rejections.map((r,i)=><li key={i}>
         {r.line?"Ligne "+r.line:"Email "+(r.email??"—")} : {r.reason}
       </li>)}</ul></details>}
   </div>}
 </section>;
}
