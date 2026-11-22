"use client";
import {useEffect,useState,type FormEvent} from "react";
import {api,send} from "@/lib/api";

type View="prospects"|"crm-contacts"|"opportunities"|"crm-activities"|"customer-segments";
type Row={id:number;revision:number;status?:string;stage?:string;[key:string]:unknown};
type Field={key:string;label:string;type?:string;required?:boolean;options?:string[]};
type Config={resource:string;title:string;fields:Field[];columns:string[]};
const configs:Record<View,Config>={
  prospects:{resource:"prospects",title:"Prospects",fields:[
    {key:"name",label:"Nom",required:true},{key:"email",label:"Email",type:"email"},
    {key:"phone",label:"Téléphone"},{key:"source",label:"Origine"},
    {key:"opt_out",label:"Opposition prospection",type:"checkbox"}
  ],columns:["name","email","source","status"]},
  "crm-contacts":{resource:"contacts",title:"Contacts",fields:[
    {key:"customer_id",label:"ID client",type:"number"},
    {key:"first_name",label:"Prénom",required:true},{key:"last_name",label:"Nom",required:true},
    {key:"email",label:"Email",type:"email"},{key:"phone",label:"Téléphone"},
    {key:"job_title",label:"Fonction"},{key:"consent",label:"Consentement",type:"select",options:["unknown","granted","refused"]},
    {key:"active",label:"Actif",type:"checkbox"},{key:"valid_from",label:"Début",type:"date"},
    {key:"valid_to",label:"Fin",type:"date"}
  ],columns:["first_name","last_name","email","job_title","consent"]},
  opportunities:{resource:"opportunities",title:"Opportunités",fields:[
    {key:"title",label:"Opportunité",required:true},{key:"customer_id",label:"ID client",type:"number"},
    {key:"prospect_id",label:"ID prospect",type:"number"},
    {key:"amount_cents",label:"Montant en centimes",type:"number"},
    {key:"currency",label:"Devise",type:"select",options:["EUR","USD","GBP"]},
    {key:"probability_bps",label:"Probabilité (0–10000)",type:"number"},
    {key:"expected_close_at",label:"Clôture prévue",type:"date"}
  ],columns:["title","stage","amount_cents","currency","expected_close_at"]},
  "crm-activities":{resource:"activities",title:"Activités et relances",fields:[
    {key:"title",label:"Activité",required:true},
    {key:"customer_id",label:"ID client",type:"number"},{key:"prospect_id",label:"ID prospect",type:"number"},
    {key:"contact_id",label:"ID contact",type:"number"},{key:"opportunity_id",label:"ID opportunité",type:"number"},
    {key:"type",label:"Type",type:"select",options:["call","meeting","email","task"]},
    {key:"priority",label:"Priorité",type:"select",options:["low","normal","high"]},
    {key:"due_at",label:"Échéance",type:"datetime-local",required:true},
    {key:"timezone",label:"Fuseau",type:"select",options:["Europe/Paris","UTC"]}
  ],columns:["title","type","due_at","status"]},
  "customer-segments":{resource:"segments",title:"Segmentation clients",fields:[
    {key:"code",label:"Code",required:true},{key:"name",label:"Nom",required:true},
    {key:"mode",label:"Mode",type:"select",options:["static","dynamic"]},
    {key:"criteria_field",label:"Critère dynamique",type:"select",options:["","name","email_domain"]},
    {key:"criteria_value",label:"Valeur du critère"}
  ],columns:["code","name","mode","revision"]}
};
const transitions:Record<string,Record<string,string[]>>={
  prospects:{new:["qualified","lost"],qualified:["lost"],lost:["qualified"]},
  opportunities:{new:["qualified","lost"],qualified:["proposal","lost"],proposal:["won","lost"]},
  activities:{planned:["completed","cancelled"]}
};
const labels:Record<string,string>={new:"Nouveau",qualified:"Qualifié",lost:"Perdu",
  won:"Gagnée",proposal:"Proposition",planned:"Prévu",completed:"Terminé",cancelled:"Annulé",
  unknown:"Non renseigné",granted:"Accord",refused:"Refus",static:"Statique",dynamic:"Dynamique"};
function shown(v:unknown){if(v==null||v==="")return "—";if(typeof v==="boolean")return v?"Oui":"Non";return labels[String(v)]??String(v);}
function defaultValues():Record<string,string|boolean>{return {active:true,opt_out:false,consent:"unknown",
  currency:"EUR",probability_bps:"0",amount_cents:"0",type:"call",priority:"normal",mode:"static",
  timezone:"Europe/Paris",criteria_field:"",criteria_value:""};}

export default function CrmWorkspace({view,activeCompanyId,canEdit}:{view:View;activeCompanyId:number|null;canEdit:boolean}){
 const conf=configs[view];
 const [rows,setRows]=useState<Row[]>([]);
 const [page,setPage]=useState(1),[lastPage,setLastPage]=useState(1),[total,setTotal]=useState(0);
 const [search,setSearch]=useState(""),[query,setQuery]=useState("");
 const [loading,setLoading]=useState(true),[busy,setBusy]=useState(false);
 const [error,setError]=useState(""),[notice,setNotice]=useState("");
 const [selected,setSelected]=useState<Row|null>(null),[edit,setEdit]=useState<Row|null>(null);
 const [form,setForm]=useState(false),[values,setValues]=useState<Record<string,string|boolean>>(defaultValues);
 const [refresh,setRefresh]=useState(0);
 const [members,setMembers]=useState<Array<{id:number;name:string;email:string}>>([]);
 const [memberId,setMemberId]=useState("");
 const [quoteId,setQuoteId]=useState("");
 const [summary,setSummary]=useState<{customers_active:number;prospects_open:number;opportunities_open:number;forecast_cents:number;activities_overdue:number;activities_due_today:number}|null>(null);
 const [quotes,setQuotes]=useState<Array<{id:number;number:string;status:string;total_cents:number}>>([]);
 const resource=conf.resource;
 useEffect(()=>{setRows([]);setSelected(null);setForm(false);setEdit(null);setPage(1);setSearch("");setQuery("");},[view,activeCompanyId]);
 useEffect(()=>{
   let live=true;setLoading(true);setError("");
   api<{data:Row[];current_page:number;last_page:number;total:number}>("crm/"+resource+"?page="+page+"&search="+encodeURIComponent(query))
    .then(r=>{if(live){setRows(r.data);setLastPage(r.last_page);setTotal(r.total);}})
    .catch((e:Error)=>{if(live)setError(e.message);})
    .finally(()=>{if(live)setLoading(false);});
   return ()=>{live=false;};
 },[resource,page,query,refresh,activeCompanyId]);
 useEffect(()=>{
   let active=true;
   api<typeof summary>("crm/summary").then(data=>{if(active)setSummary(data);})
     .catch(()=>{if(active)setSummary(null);});
   return ()=>{active=false;};
 },[resource,refresh,activeCompanyId]);
 function start(row?:Row){
   setError("");setEdit(row??null);setForm(true);
   const next=defaultValues();
   if(row)for(const f of conf.fields){const x=row[f.key];if(x!==undefined&&x!==null)
     next[f.key]=typeof x==="boolean"?x:String(x).slice(0,f.type==="datetime-local"?16:1000);}
   if(row&&resource==="segments"&&row.criteria){
     try{const c=typeof row.criteria==="string"?JSON.parse(row.criteria):row.criteria;
       next.criteria_field=String(c.field??"");next.criteria_value=String(c.value??"");
     }catch{/* preserve existing record */}
   }
   setValues(next);
 }
 async function save(e:FormEvent){
   e.preventDefault();if(busy)return;setBusy(true);setError("");
   try{
     const payload:Record<string,unknown>={};
     for(const f of conf.fields){
       if(f.key.startsWith("criteria_"))continue;
       const v=values[f.key]??"";
       if(v===""&&!edit&&!f.required)continue;
       if(f.type==="checkbox")payload[f.key]=Boolean(v);
       else if(v==="")payload[f.key]=null;
       else if(f.type==="number")payload[f.key]=Number(v);
       else if(f.type==="datetime-local")payload[f.key]=new Date(String(v)).toISOString();
       else payload[f.key]=v;
     }
     if(resource==="segments")payload.criteria=values.mode==="dynamic"
       ?{field:values.criteria_field,value:values.criteria_value}:{};
     if(edit)payload.expected_revision=edit.revision;
     const result=edit?await send<Row>("crm/"+resource+"/"+edit.id,"PUT",payload)
       :await send<Row>("crm/"+resource,"POST",payload);
     setNotice("Fiche enregistrée #"+result.id);setSelected(result);
     setForm(false);setEdit(null);setRefresh(x=>x+1);
   }catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 async function transition(row:Row,target:string){
   let reason="";
   if(["lost","won","completed","cancelled"].includes(target)){
     const input=window.prompt("Motif ou résultat (3 caractères minimum)");
     if(input===null)return;reason=input.trim();
     if(reason.length<3){setError("Motif ou résultat trop court.");return;}
   }
   setBusy(true);setError("");
   try{const result=await send<Row>("crm/"+resource+"/"+row.id+"/transition","POST",{
     target,reason,expected_revision:row.revision
   });setNotice("Statut actualisé");setSelected(result);setRefresh(x=>x+1);}
   catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 async function convert(row:Row){
   setBusy(true);setError("");
   try{const r=await send<{customer_id:number}>("crm/prospects/"+row.id+"/convert","POST",{
     expected_revision:row.revision
   });setNotice("Client créé ou réutilisé #"+r.customer_id);setSelected(null);setRefresh(x=>x+1);}
   catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 async function reschedule(row:Row){
   const due=window.prompt("Nouvelle échéance (ex. 2026-12-30T15:00) :");
   if(due===null)return;
   const parsed=new Date(due);
   if(Number.isNaN(parsed.getTime())){setError("Date d'échéance invalide.");return;}
   const reason=window.prompt("Motif du report (3 caractères minimum) :");
   if(reason===null)return;
   if(reason.trim().length<3){setError("Motif trop court.");return;}
   setBusy(true);setError("");
   try{
     const result=await send<Row>("crm/activities/"+row.id+"/reschedule","POST",{
       due_at:parsed.toISOString(),reason:reason.trim(),expected_revision:row.revision
     });
     setNotice("Échéance reportée et auditée.");setSelected(result);setRefresh(x=>x+1);
   }catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 async function removeMember(id:number){
   if(!selected)return;
   setBusy(true);setError("");
   try{
     await send("crm/segments/"+selected.id+"/members","PUT",{customer_id:id,active:false});
     await loadMembers(selected.id);setNotice("Client retiré du segment.");
   }catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 async function loadQuotes(id:number){
   setQuotes([]);setQuoteId("");
   try{const r=await api<{data:Array<{id:number;number:string;status:string;total_cents:number}>}>("crm/opportunities/"+id+"/quotes");
     setQuotes(r.data);
   }catch(e){setError((e as Error).message);}
 }
 async function linkQuote(e:FormEvent){
   e.preventDefault();if(!selected||!quoteId)return;
   setBusy(true);setError("");
   try{
     await send("crm/opportunities/"+selected.id+"/quotes","POST",{quote_id:Number(quoteId)});
     setNotice("Devis rattaché à l'opportunité.");await loadQuotes(selected.id);
   }catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 async function loadMembers(id:number){
   try{const r=await api<{data:Array<{id:number;name:string;email:string}>}>("crm/segments/"+id+"/members");
     setMembers(r.data);
   }catch(e){setError((e as Error).message);}
 }
 async function addMember(e:FormEvent){
   e.preventDefault();if(!selected||!memberId)return;
   setBusy(true);setError("");
   try{await send("crm/segments/"+selected.id+"/members","PUT",{customer_id:Number(memberId),active:true});
     setMemberId("");await loadMembers(selected.id);setNotice("Client ajouté.");}
   catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 return <section className="atlas-domain">
  <div className="atlas-domain-toolbar"><div><h2>{conf.title}</h2>
   <p>Référentiel multi-entreprises, historique et contrôles métier côté serveur.</p></div>
   {canEdit&&<button type="button" className="button primary" onClick={()=>start()}>+ Nouvelle fiche</button>}
  </div>
  {summary&&<div className="atlas-housekeeping-stats" aria-label="Indicateurs CRM">
    <div><span>Clients actifs</span><strong>{summary.customers_active}</strong></div>
    <div><span>Prospects ouverts</span><strong>{summary.prospects_open}</strong></div>
    <div><span>Affaires en cours</span><strong>{summary.opportunities_open}</strong></div>
    <div><span>Pipeline pondéré</span><strong>{new Intl.NumberFormat("fr-FR",{style:"currency",currency:"EUR"}).format(summary.forecast_cents/100)}</strong></div>
    <div><span>Relances en retard</span><strong>{summary.activities_overdue}</strong></div>
    <div><span>Relances aujourd'hui</span><strong>{summary.activities_due_today}</strong></div>
  </div>}
  {error&&<p role="alert" className="error">{error}</p>}
  {notice&&<p role="status" className="notice">{notice}</p>}
  <form className="atlas-domain-form" onSubmit={e=>{e.preventDefault();setPage(1);setQuery(search.trim());}}>
   <label>Recherche <input type="search" value={search} maxLength={100} onChange={e=>setSearch(e.target.value)}/></label>
   <button className="button secondary">Rechercher</button>
  </form>
  {loading&&<p role="status">Chargement…</p>}
  <div className="table-scroll"><table><thead><tr>
   {conf.columns.map(k=><th key={k}>{conf.fields.find(f=>f.key===k)?.label??k}</th>)}<th>Actions</th>
  </tr></thead><tbody>{rows.map(row=><tr key={row.id}>
   {conf.columns.map(k=><td key={k}>{shown(row[k])}</td>)}
   <td><button type="button" className="text-button" onClick={()=>{
    setSelected(row);if(resource==="segments")void loadMembers(row.id);if(resource==="opportunities")void loadQuotes(row.id);
   }}>Détail</button>
   {canEdit&&<button className="text-button" type="button" onClick={()=>start(row)}>Modifier</button>}</td>
  </tr>)}</tbody></table></div>
  {!loading&&rows.length===0&&<p>Aucun résultat.</p>}
  <div className="atlas-domain-pagination">
    <button className="button secondary" type="button" disabled={page<=1||loading} onClick={()=>setPage(x=>x-1)}>Précédent</button>
    <span>Page {page} / {lastPage} · {total} résultat(s)</span>
    <button className="button secondary" type="button" disabled={page>=lastPage||loading} onClick={()=>setPage(x=>x+1)}>Suivant</button>
  </div>
  {selected&&<div className="atlas-domain-form">
    <div className="atlas-domain-form-heading"><h3>Détail #{selected.id}</h3>
      <button type="button" className="button secondary" onClick={()=>setSelected(null)}>Fermer</button>
    </div>
    {conf.columns.map(k=><p key={k}><strong>{conf.fields.find(f=>f.key===k)?.label??k} : </strong>{shown(selected[k])}</p>)}
    {canEdit&&(transitions[resource]?.[String(selected.stage??selected.status)]??[]).map(target=>
      <button key={target} type="button" disabled={busy} className="button secondary"
        onClick={()=>void transition(selected,target)}>{labels[target]??target}</button>)}
    {canEdit&&resource==="activities"&&selected.status==="planned"&&
      <button className="button secondary" type="button" disabled={busy}
        onClick={()=>void reschedule(selected)}>Reporter l'échéance</button>}
    {canEdit&&resource==="prospects"&&selected.status==="qualified"&&
      <button type="button" disabled={busy} className="button primary"
        onClick={()=>void convert(selected)}>Convertir en client</button>}
    {resource==="opportunities"&&<section aria-label="Devis rattachés">
      <h3>Devis commerciaux liés à l'opportunité</h3>
      {quotes.length===0&&<p>Aucun devis rattaché.</p>}
      {quotes.map(q=><p key={q.id}>{q.number} — {q.status} — {(q.total_cents/100).toFixed(2)} €</p>)}
      {canEdit&&<form onSubmit={e=>void linkQuote(e)}>
        <label>Identifiant d'un devis existant
          <input type="number" min="1" required value={quoteId} onChange={e=>setQuoteId(e.target.value)}/>
        </label>
        <button className="button secondary" disabled={busy}>Rattacher le devis</button>
      </form>}
    </section>}
    {resource==="segments"&&<><h3>Clients du segment</h3>
      {members.map(m=><p key={m.id}>{m.name} — {m.email}
        {canEdit&&selected.mode==="static"&&
          <button type="button" className="text-button" disabled={busy}
            onClick={()=>void removeMember(m.id)}>Retirer</button>}
      </p>)}
      {selected.mode==="static"&&canEdit&&<form onSubmit={e=>void addMember(e)}>
        <label>ID client <input required type="number" min="1" value={memberId} onChange={e=>setMemberId(e.target.value)}/></label>
        <button disabled={busy} className="button secondary">Ajouter</button>
      </form>}
    </>}
  </div>}
  {form&&<form className="atlas-domain-form" onSubmit={e=>void save(e)}>
   <div className="atlas-domain-form-heading"><h3>{edit?"Modifier":"Nouvelle fiche"}</h3>
    <button className="button secondary" type="button" onClick={()=>setForm(false)}>Annuler</button>
   </div>
   <div className="atlas-domain-fields">{conf.fields.map(f=><label key={f.key}>
    <span>{f.label}{f.required?" *":""}</span>
    {f.type==="checkbox"?<input type="checkbox" checked={Boolean(values[f.key])}
      onChange={e=>setValues(v=>({...v,[f.key]:e.target.checked}))}/>:
    f.type==="select"?<select value={String(values[f.key]??"")}
      onChange={e=>setValues(v=>({...v,[f.key]:e.target.value}))}>
      {f.options?.map(option=><option value={option} key={option}>{labels[option]??option}</option>)}
    </select>:<input type={f.type??"text"} required={f.required}
      value={String(values[f.key]??"")}
      onChange={e=>setValues(v=>({...v,[f.key]:e.target.value}))}/>}
   </label>)}</div>
   <button type="submit" disabled={busy} className="button primary">Enregistrer</button>
  </form>}
 </section>;
}
