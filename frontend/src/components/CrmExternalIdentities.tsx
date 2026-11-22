"use client";

import {useEffect,useState,type FormEvent} from "react";
import {api,send} from "@/lib/api";

type Kind="customers"|"suppliers"|"contacts"|"prospects";
type Alias={id:number;source_system:string;external_id:string;active:boolean;revision:number};
type Page={data:Alias[]};

export default function CrmExternalIdentities({kind,entityId}:{kind:Kind;entityId:number}){
 const [list,setList]=useState<Alias[]>([]);
 const [system,setSystem]=useState("PMS");
 const [external,setExternal]=useState("");
 const [busy,setBusy]=useState(false);
 const [error,setError]=useState("");
 const [notice,setNotice]=useState("");
 const [revision,setRevision]=useState(0);
 useEffect(()=>{
   let active=true;
   setError("");setList([]);setNotice("");
   void api<Page>("crm/external-identifiers/"+kind+"/"+entityId)
     .then(r=>{if(active)setList(r.data);})
     .catch((e:Error)=>{if(active)setError(e.message);});
   return ()=>{active=false;};
 },[kind,entityId,revision]);
 async function create(e:FormEvent){
   e.preventDefault();if(busy)return;setBusy(true);setError("");
   try {
     await send("crm/external-identifiers/"+kind+"/"+entityId,"POST",{
       source_system:system.trim(),external_id:external.trim()
     });
     setExternal("");setNotice("Correspondance enregistrée.");setRevision(x=>x+1);
   }catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 async function revoke(row:Alias){
   const reason=window.prompt("Pourquoi révoquer cet identifiant ? (8 caractères minimum)");
   if(reason===null)return;
   if(reason.trim().length<8){setError("Motif insuffisant.");return;}
   setBusy(true);setError("");
   try{
     await send("crm/external-identifiers/"+row.id+"/revoke","POST",{
       reason:reason.trim(),expected_revision:row.revision,
     });
     setNotice("Alias révoqué sans perdre son historique.");setRevision(x=>x+1);
   }catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 return <section className="atlas-domain" aria-label="Identifiants externes">
   <h3>Identifiants externes — #{entityId}</h3>
   <p>Correspondances contrôlées avec les autres logiciels. Aucun transfert automatique de données.</p>
   {error&&<p role="alert" className="error">{error}</p>}
   {notice&&<p role="status" className="notice">{notice}</p>}
   <form className="atlas-domain-form" onSubmit={e=>void create(e)}>
     <div className="atlas-domain-fields">
       <label>Système source <input required minLength={2} maxLength={50}
         value={system} onChange={e=>setSystem(e.target.value)}/></label>
       <label>Identifiant externe <input required maxLength={180}
         value={external} onChange={e=>setExternal(e.target.value)}/></label>
     </div>
     <button disabled={busy} className="button secondary">Enregistrer la correspondance</button>
   </form>
   <div className="table-scroll"><table><thead><tr>
     <th>Système</th><th>Identifiant</th><th>Statut</th><th>Action</th>
   </tr></thead><tbody>{list.map(row=><tr key={row.id}>
     <td>{row.source_system}</td><td>{row.external_id}</td>
     <td>{row.active?"Actif":"Révoqué"}</td>
     <td>{row.active&&<button type="button" className="text-button" disabled={busy}
       onClick={()=>void revoke(row)}>Révoquer</button>}</td>
   </tr>)}</tbody></table></div>
 </section>;
}

export function CrmCustomerExternalPanel(){
 const [kind,setKind]=useState<"customers"|"suppliers">("customers");
 const [entityId,setEntityId]=useState("");
 const [selected,setSelected]=useState<{kind:"customers"|"suppliers";id:number}|null>(null);
 return <section className="atlas-domain">
   <h3>Correspondances externes d'un client ou fournisseur</h3>
   <form className="atlas-domain-form" onSubmit={e=>{e.preventDefault();if(entityId)setSelected({kind,id:Number(entityId)});}}>
     <label>Type de tiers <select value={kind} onChange={e=>{setKind(e.target.value as "customers"|"suppliers");setSelected(null);}}>
       <option value="customers">Client</option><option value="suppliers">Fournisseur</option>
     </select></label>
     <label>Identifiant de la fiche <input type="number" min="1" required value={entityId}
       onChange={e=>setEntityId(e.target.value)}/></label>
     <button className="button secondary">Afficher les correspondances</button>
   </form>
   {selected&&<CrmExternalIdentities key={selected.kind+"-"+selected.id} kind={selected.kind} entityId={selected.id}/>}
 </section>;
}
