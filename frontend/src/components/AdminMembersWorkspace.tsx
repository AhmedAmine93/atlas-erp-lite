"use client";

import { useEffect, useState, type FormEvent } from "react";
import { api, send } from "@/lib/api";

type Member = {id:number;name:string;email:string;role:string;suspended_at:string|null};
type Role = {id:number;name:string};
type Page<T> = {data:T[];last_page:number;total:number};
export default function AdminMembersWorkspace({activeCompanyId}:{activeCompanyId:number|null}) {
 const [members,setMembers]=useState<Member[]>([]),[roles,setRoles]=useState<Role[]>([]);
 const [page,setPage]=useState(1),[last,setLast]=useState(1),[total,setTotal]=useState(0);
 const [search,setSearch]=useState(""),[rev,setRev]=useState(0),[loading,setLoading]=useState(true),[busy,setBusy]=useState(false);
 const [error,setError]=useState(""),[notice,setNotice]=useState("");
 const [create,setCreate]=useState(false);
 const [draft,setDraft]=useState({name:"",email:"",role_id:"",password:""});
 useEffect(()=>{setPage(1);setMembers([]);setCreate(false);setSearch("");},[activeCompanyId]);
 useEffect(()=>{
  let live=true;setLoading(true);setError("");
  Promise.all([
   api<Page<Member>>("admin/memberships?page="+page+"&search="+encodeURIComponent(search)),
   api<Role[]>("roles"),
  ]).then(([list,roleList])=>{
   if(!live)return;setMembers(list.data);setLast(list.last_page);setTotal(list.total);setRoles(roleList);
  }).catch((e:Error)=>{if(live)setError(e.message);}).finally(()=>{if(live)setLoading(false);});
  return()=>{live=false;};
 },[page,search,rev,activeCompanyId]);
 async function act(fn:()=>Promise<unknown>,message:string) {
  if(busy)return;setBusy(true);setError("");setNotice("");
  try{await fn();setNotice(message);setRev(n=>n+1);}
  catch(e){setError((e as Error).message);}
  finally{setBusy(false);}
 }
 function transition(member:Member, suspend:boolean){
  const reason=window.prompt(suspend?"Motif de suspension (au moins 5 caractères)":"Motif de réactivation (au moins 5 caractères)");
  if(reason===null)return;if(reason.trim().length<5){setError("Motif obligatoire : au moins 5 caractères.");return;}
  void act(()=>send("admin/memberships/"+member.id+"/"+(suspend?"suspend":"reactivate"),"POST",{reason:reason.trim()}),
    suspend?"Affiliation suspendue et sessions invalidées.":"Affiliation réactivée.");
 }
 function changeRole(member:Member,roleId:number){
  if(!window.confirm("Modifier le rôle de ce membre dans l’entreprise active ?"))return;
  void act(()=>send("users/"+member.id,"PUT",{name:member.name,email:member.email,role_id:roleId}),"Rôle mis à jour.");
 }
 function revoke(member:Member){
  if(!window.confirm("Révoquer l’affiliation de ce membre ? Son identité et les traces historiques seront conservées."))return;
  void act(()=>api("users/"+member.id,{method:"DELETE"}),"Affiliation révoquée.");
 }
 return <section className="panel">
  <h2>Utilisateurs et affiliations</h2>
  <p>Les autorisations sont attribuées par entreprise active. Suspendre ou révoquer ne supprime pas l’identité historique.</p>
  {error&&<p className="error" role="alert">{error}</p>}{notice&&<p className="notice" role="status">{notice}</p>}
  <div className="atlas-domain-toolbar">
   <label>Rechercher un membre<input type="search" value={search} onChange={e=>{setPage(1);setSearch(e.target.value)}}/></label>
   <button onClick={()=>setCreate(v=>!v)}>{create?"Fermer":"+ Créer un utilisateur"}</button>
  </div>
  {create&&<form onSubmit={(e:FormEvent<HTMLFormElement>)=>{
   e.preventDefault();void act(async()=>{await send("users","POST",{...draft,role_id:Number(draft.role_id)});setCreate(false);setDraft({name:"",email:"",role_id:"",password:""});},"Utilisateur créé.");}}>
   <label>Nom<input required maxLength={255} value={draft.name} onChange={e=>setDraft(v=>({...v,name:e.target.value}))}/></label>
   <label>Email<input required type="email" value={draft.email} onChange={e=>setDraft(v=>({...v,email:e.target.value}))}/></label>
   <label>Rôle<select required value={draft.role_id} onChange={e=>setDraft(v=>({...v,role_id:e.target.value}))}>
    <option value="">Sélectionner</option>{roles.map(r=><option key={r.id} value={r.id}>{r.name}</option>)}</select></label>
   <label>Mot de passe temporaire<input type="password" required minLength={12} autoComplete="new-password"
    value={draft.password} onChange={e=>setDraft(v=>({...v,password:e.target.value}))}/></label>
   <p>La distribution du mot de passe doit suivre un canal sécurisé. Aucune invitation par email n’est simulée.</p>
   <button disabled={busy} type="submit">Enregistrer</button>
  </form>}
  {loading&&<p role="status">Chargement…</p>}
  <div className="table-scroll"><table><thead><tr><th>Nom</th><th>Email</th><th>Rôle</th><th>État</th><th>Actions</th></tr></thead><tbody>
   {members.map(m=><tr key={m.id}><td>{m.name}</td><td>{m.email}</td><td>
    <select aria-label={"Rôle de "+m.name} disabled={busy||!!m.suspended_at} value={roles.find(r=>r.name===m.role)?.id??""} onChange={e=>changeRole(m,Number(e.target.value))}>
     {roles.map(r=><option key={r.id} value={r.id}>{r.name}</option>)}</select></td>
    <td>{m.suspended_at?"Suspendu":"Actif"}</td><td>
     {m.suspended_at?<button disabled={busy} onClick={()=>transition(m,false)}>Réactiver</button>:
      <button disabled={busy} onClick={()=>transition(m,true)}>Suspendre</button>}
     <button disabled={busy} onClick={()=>revoke(m)}>Révoquer</button>
    </td></tr>)}
  </tbody></table></div>
  <p>{total} membre(s)</p>
  <nav aria-label="Pagination utilisateurs"><button disabled={page<=1||loading} onClick={()=>setPage(n=>n-1)}>Précédent</button>
   <span> {page} / {Math.max(last,1)} </span><button disabled={page>=last||loading} onClick={()=>setPage(n=>n+1)}>Suivant</button></nav>
 </section>;
}