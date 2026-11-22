"use client";

import {useEffect,useMemo,useState,type FormEvent} from "react";
import {api,send} from "@/lib/api";
import type {Page} from "@/lib/types";

type Row={id:number;status?:string;version?:number;[key:string]:unknown};
type Field={key:string;label:string;type?:"text"|"date"|"datetime-local"|"number"|"email"|"select"|"checkbox";ref?:string;required?:boolean};
type Resource={name:string;description:string;columns:string[];fields:Field[];transitions?:Record<string,{label:string;status:string;reason?:boolean}[]>};
const f=(key:string,label:string,required=false,type:Field["type"]="text",ref?:string):Field=>({key,label,required,type,ref});
const resources:Record<string,Resource>={
 employees:{name:"Collaborateurs",description:"Dossiers salariés et rattachements. Les rémunérations et documents médicaux ne figurent pas dans ce module.",columns:["code","given_name","family_name","establishment_id","hired_on","left_on","status"],fields:[f("code","Matricule",true),f("given_name","Prénom",true),f("family_name","Nom",true),f("email","E-mail professionnel",false,"email"),f("establishment_id","Établissement",true,"select","establishments"),f("hired_on","Entrée",true,"date")],transitions:{active:[{label:"Sortie du salarié",status:"inactive"}]}},
 teams:{name:"Équipes",description:"Référentiel des équipes, responsables et dates de validité.",columns:["code","name","manager_employee_id","effective_from","effective_to"],fields:[f("code","Code",true),f("name","Nom",true),f("manager_employee_id","Manager",false,"select","employees"),f("effective_from","Date d'effet",true,"date"),f("effective_to","Fin de validité",false,"date")]},
 assignments:{name:"Affectations",description:"Un collaborateur peut être affecté à plusieurs établissements et équipes sans dupliquer son dossier.",columns:["employee_id","establishment_id","team_id","starts_on","ends_on","allocation_percent"],fields:[f("employee_id","Collaborateur",true,"select","employees"),f("establishment_id","Établissement",true,"select","establishments"),f("team_id","Équipe",false,"select","teams"),f("starts_on","Début",true,"date"),f("ends_on","Fin",false,"date"),f("allocation_percent","Allocation (%)",true,"number")]},
 schedules:{name:"Planning",description:"Affectations horaires avec fuseau et contrôles des conflits au moment de la publication.",columns:["employee_id","establishment_id","starts_at","ends_at","timezone","status","version"],fields:[f("employee_id","Collaborateur",true,"select","employees"),f("establishment_id","Établissement",true,"select","establishments"),f("starts_at","Début",true,"datetime-local"),f("ends_at","Fin",true,"datetime-local"),f("timezone","Fuseau IANA",true)],transitions:{draft:[{label:"Publier",status:"published"},{label:"Annuler",status:"cancelled",reason:true}],published:[{label:"Annuler",status:"cancelled",reason:true}]}},
 balances:{name:"Soldes annuels",description:"Dotations paramétrées par RH. Aucune durée légale n'est inventée par l'application.",columns:["employee_id","year","allocated_days","used_days"],fields:[f("employee_id","Collaborateur",true,"select","employees"),f("year","Année",true,"number"),f("allocated_days","Jours alloués",true,"number")]},
 leaves:{name:"Congés et absences",description:"Demandes tracées, solde annuel contrôlé sous verrou, conflit avec planning publié.",columns:["employee_id","starts_on","ends_on","days","year","status","version"],fields:[f("employee_id","Collaborateur",true,"select","employees"),f("starts_on","Du",true,"date"),f("ends_on","Au",true,"date"),f("days","Jours décomptés",true,"number")],transitions:{draft:[{label:"Soumettre",status:"submitted"},{label:"Annuler",status:"cancelled",reason:true}],submitted:[{label:"Approuver",status:"approved"},{label:"Refuser",status:"rejected",reason:true},{label:"Annuler",status:"cancelled",reason:true}],approved:[{label:"Annuler",status:"cancelled",reason:true}]}},
 timesheets:{name:"Temps passé",description:"Temps par projet OU bon de travail, contrôle du cumul journalier et circuit d'approbation.",columns:["employee_id","work_on","duration_minutes","project_ref","work_order_id","status","version"],fields:[f("employee_id","Collaborateur",true,"select","employees"),f("work_on","Date",true,"date"),f("duration_minutes","Minutes",true,"number"),f("project_ref","Référence projet (ou BT)"),f("work_order_id","Bon de travail (ou projet)",false,"number")],transitions:{draft:[{label:"Soumettre",status:"submitted"}],submitted:[{label:"Approuver",status:"approved"},{label:"Retourner",status:"returned",reason:true}],returned:[{label:"Resoumettre",status:"submitted"}],approved:[{label:"Transférer",status:"transferred"}]}},
 skills:{name:"Compétences",description:"Catalogue des compétences, avec signalement des habilitations de sécurité.",columns:["code","name","safety_critical"],fields:[f("code","Code",true),f("name","Intitulé",true),f("safety_critical","Habilitation de sécurité",false,"checkbox")]},
 certifications:{name:"Formations et habilitations",description:"Validité, organisme et preuve référencée. Une habilitation expirée ne peut pas être approuvée.",columns:["employee_id","skill_id","obtained_on","expires_on","issuer","status","version"],fields:[f("employee_id","Collaborateur",true,"select","employees"),f("skill_id","Compétence",true,"select","skills"),f("obtained_on","Date d'obtention",true,"date"),f("expires_on","Échéance",false,"date"),f("issuer","Organisme"),f("evidence_ref","Référence de preuve")],transitions:{pending:[{label:"Valider",status:"approved"},{label:"Révoquer",status:"revoked",reason:true}],approved:[{label:"Révoquer",status:"revoked",reason:true}]}},
 events:{name:"Historique des décisions",description:"Événements métier horodatés en lecture seule.",columns:["entity_type","entity_id","operation","from_state","to_state","actor_id","created_at"],fields:[]},
};
const sections:Record<string,{title:string;intro:string;tabs:string[]}>={
 "hr-employees":{title:"Collaborateurs",intro:"Dossiers, identité professionnelle, employeurs et affectations.",tabs:["employees","assignments"]},
 "hr-teams":{title:"Équipes et hiérarchies",intro:"Organisation, management et rattachements datés.",tabs:["teams","assignments"]},
 "hr-schedules":{title:"Planning RH",intro:"Créneaux, publication et gestion des conflits.",tabs:["schedules","assignments"]},
 "hr-leaves":{title:"Congés et absences",intro:"Demandes, circuits d'approbation et dotations annuelles.",tabs:["leaves","balances"]},
 "hr-timesheets":{title:"Temps passé",intro:"Ventilation unique des heures et validation des imputations.",tabs:["timesheets"]},
 "hr-skills":{title:"Compétences et formations",intro:"Référentiel, certifications datées, justificatifs et échéances.",tabs:["skills","certifications"]},
};
const refs:Record<string,string>={employees:"hr/employees",teams:"hr/teams",skills:"hr/skills",establishments:"hr/sites"};
const pretty=(value:unknown)=>value===null||value===undefined||value===""?"—":typeof value==="boolean"?(value?"Oui":"Non"):String(value);
const statusName=(s:string)=>({draft:"Brouillon",submitted:"Soumis",approved:"Approuvé",rejected:"Refusé",cancelled:"Annulé",published:"Publié",transferred:"Transféré",returned:"Retourné",pending:"À valider",revoked:"Révoqué",active:"Actif",inactive:"Inactif"} as Record<string,string>)[s]??s;
function freshKey(){return crypto.randomUUID().replaceAll("-","");}

export default function HumanResourcesWorkspace({view,activeCompanyId}:{view:string;activeCompanyId:number|null}){
 const section=sections[view];
 const [resource,setResource]=useState(section.tabs[0]);
 const [page,setPage]=useState(1),[last,setLast]=useState(1),[total,setTotal]=useState(0);
 const [rows,setRows]=useState<Row[]>([]),[lookups,setLookups]=useState<Record<string,Row[]>>({});
 const [summary,setSummary]=useState<Record<string,number>>({});
 const [loading,setLoading]=useState(true),[busy,setBusy]=useState(false),[error,setError]=useState(""),[notice,setNotice]=useState("");
 const [open,setOpen]=useState(false),[values,setValues]=useState<Record<string,string>>({});
 const [filter,setFilter]=useState(""),[version,setVersion]=useState(0);
 const config=resources[resource];
 useEffect(()=>{setResource(section.tabs[0]);setPage(1);setOpen(false);setFilter("");},[view,section]);
 useEffect(()=>{let alive=true;setLoading(true);setError("");api<Page<Row>>("hr/"+resource+"?page="+page).then(r=>{if(alive){setRows(r.data);setTotal(r.total);setLast(r.last_page);}}).catch(e=>{if(alive)setError((e as Error).message);}).finally(()=>{if(alive)setLoading(false);});return()=>{alive=false;};},[resource,page,activeCompanyId,version]);
 useEffect(()=>{let alive=true;api<Record<string,number>>("hr/summary").then(r=>{if(alive)setSummary(r);}).catch(()=>{});return()=>{alive=false;};},[activeCompanyId,version]);
 useEffect(()=>{let alive=true;const paths=[...new Set(config.fields.map(x=>x.ref).filter((x):x is string=>!!x))];Promise.all(paths.map(async x=>{
  const result:Row[]=[];let n=1;do{const data=await api<Page<Row>>(refs[x]+"?per_page=100&page="+n);result.push(...data.data);if(n>=data.last_page)break;n++;}while(n<=20);return [x,result] as const;
 })).then(items=>{if(alive)setLookups(Object.fromEntries(items));}).catch(e=>{if(alive)setError((e as Error).message);});return()=>{alive=false;};},[resource,activeCompanyId,version,config.fields]);
 const filtered=useMemo(()=>rows.filter(row=>!filter||config.columns.some(k=>pretty(row[k]).toLowerCase().includes(filter.toLowerCase()))),[rows,filter,config.columns]);
 function pick(target:string){setResource(target);setPage(1);setOpen(false);setFilter("");setError("");}
 function start(){setOpen(true);setError("");setValues({timezone:"Europe/Paris",allocation_percent:"100",year:String(new Date().getFullYear()),safety_critical:"false"});}
 async function create(e:FormEvent<HTMLFormElement>){e.preventDefault();if(busy)return;setBusy(true);setError("");try{
  const data:Record<string,unknown>={request_key:freshKey()};
  for(const field of config.fields){const value=values[field.key]??"";if(field.type==="checkbox"){data[field.key]=value==="true";continue;}if(value===""){if(field.required)throw new Error("Champ requis : "+field.label);continue;}
   if(field.type==="number"||field.ref||field.key==="work_order_id")data[field.key]=Number(value);
   else if(field.type==="datetime-local")data[field.key]=new Date(value).toISOString();
   else data[field.key]=value;
  }
  await send("hr/"+resource,"POST",data);
  setNotice("Fiche RH enregistrée.");setOpen(false);setPage(1);setVersion(x=>x+1);
 }catch(e){setError((e as Error).message);}finally{setBusy(false);}}
 async function transition(row:Row,status:string,reasonNeeded=false){if(busy)return;
  const reason=reasonNeeded?window.prompt("Motif de la décision (minimum cinq caractères) :"):"";
  if(reasonNeeded && reason===null)return;
  if(reasonNeeded && (reason??"").trim().length<5){setError("Motif trop court.");return;}
  let leftOn:string|undefined;
  if(resource==="employees" && status==="inactive"){const result=window.prompt("Date de sortie AAAA-MM-JJ :");if(result===null)return;leftOn=result;}
  setBusy(true);setError("");
  try{await send("hr/"+resource+"/"+row.id+"/transition","POST",{
    request_key:freshKey(),status,version:row.version??1,
    ...(reason?{reason:reason.trim()}:{}),...(leftOn?{left_on:leftOn}:{})
  });setNotice("Transition RH enregistrée.");setVersion(x=>x+1);}
  catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 const optionLabel=(ref:string,row:Row)=>ref==="employees"?pretty(row.given_name)+" "+pretty(row.family_name)+" ("+pretty(row.code)+")":pretty(row.name??row.code??row.id);
 return <section className="atlas-domain" aria-label={section.title}>
  <div className="atlas-domain-toolbar"><div><h2>{section.title}</h2><p>{section.intro}</p></div>
   <button type="button" className="button secondary" onClick={()=>{setVersion(x=>x+1);setNotice("Données actualisées.");}}>Actualiser</button>
  </div>
  <div className="atlas-domain-list-heading" aria-label="Indicateurs RH"><span>Collaborateurs : {summary.employees??"—"}</span><span>Équipes : {summary.teams??"—"}</span><span>Congés à traiter : {summary.pending_leaves??"—"}</span><span>Temps à valider : {summary.pending_timesheets??"—"}</span><span>Habilitations à échéance : {summary.expiring_skills??"—"}</span></div>
  <div className="atlas-domain-actions" role="tablist" aria-label="Sous-vues RH">
   {section.tabs.map(tab=><button key={tab} role="tab" type="button" aria-selected={resource===tab} className={"button "+(resource===tab?"primary":"secondary")} onClick={()=>pick(tab)}>{resources[tab].name}</button>)}
   <button type="button" className="button secondary" onClick={()=>pick("events")}>Historique</button>
  </div>
  <p>{config.description}</p>
  {notice&&<p className="notice" role="status">{notice}</p>}
  {error&&<p className="error" role="alert">{error}</p>}
  <div className="atlas-domain-toolbar">
   <label>Rechercher sur cette page <input type="search" value={filter} onChange={e=>setFilter(e.target.value)} aria-label="Filtrer cette page RH" /></label>
   {config.fields.length>0&&<button className="button primary" type="button" onClick={start}>+ Nouvelle fiche</button>}
  </div>
  {open&&<form onSubmit={e=>void create(e)} className="atlas-domain-form">
   <div className="atlas-domain-form-heading"><h3>Créer : {config.name}</h3><button type="button" className="button secondary" onClick={()=>setOpen(false)}>Fermer</button></div>
   <div className="atlas-domain-fields">{config.fields.map(field=><label key={field.key}><span>{field.label}{field.required?" *":""}</span>
    {field.type==="select"?<select required={field.required} value={values[field.key]??""} onChange={e=>setValues(p=>({...p,[field.key]:e.target.value}))}>
      <option value="">Sélectionner…</option>{(lookups[field.ref??""]??[]).map(r=><option value={r.id} key={r.id}>{optionLabel(field.ref??"",r)}</option>)}
    </select>:field.type==="checkbox"?<input type="checkbox" checked={values[field.key]==="true"} onChange={e=>setValues(p=>({...p,[field.key]:String(e.target.checked)}))}/>:
     <input type={field.type??"text"} required={field.required} value={values[field.key]??""}
      min={field.type==="number"?"0":undefined} step={field.type==="number"?"any":undefined}
      onChange={e=>setValues(p=>({...p,[field.key]:e.target.value}))}/>}
   </label>)}</div>
   <button type="submit" className="button primary" disabled={busy}>{busy?"Enregistrement…":"Enregistrer"}</button>
  </form>}
  <div className="atlas-domain-list"><div className="atlas-domain-list-heading"><span>{total} fiche(s)</span><span>Page {page} / {Math.max(1,last)}</span></div>
    <div className="table-scroll"><table><thead><tr>{config.columns.map(key=><th key={key}>{key.replaceAll("_"," ")}</th>)}<th>Actions</th></tr></thead>
     <tbody>{filtered.map(row=><tr key={row.id}>{config.columns.map(key=><td key={key}>{key==="status"?statusName(String(row[key])):pretty(row[key])}</td>)}<td>
      <div className="atlas-domain-actions">{(config.transitions?.[row.status??""]??[]).map(t=><button key={t.status} type="button" disabled={busy} className="button secondary" onClick={()=>void transition(row,t.status,!!t.reason)}>{t.label}</button>)}</div>
     </td></tr>)}</tbody></table></div>
    {loading?<p className="atlas-domain-empty">Chargement…</p>:filtered.length===0&&<p className="atlas-domain-empty">Aucune fiche sur cette page.</p>}
    <div className="atlas-domain-pagination"><button type="button" className="button secondary" disabled={page===1||loading} onClick={()=>setPage(n=>n-1)}>Précédent</button><span>Page {page}</span><button type="button" className="button secondary" disabled={page>=last||loading} onClick={()=>setPage(n=>n+1)}>Suivant</button></div>
   </div>
  </section>;
}
