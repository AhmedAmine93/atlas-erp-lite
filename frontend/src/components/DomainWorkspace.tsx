"use client";
import {useEffect,useState,type FormEvent} from "react";
import {api,send,all} from "@/lib/api";
import type {Page} from "@/lib/types";

type Row = Record<string,unknown> & {id:number;status?:string};
type Field = {key:string;label:string;required?:boolean;kind?:"number"|"date"|"textarea"|"select"|"file";options?:string[];lookup?:string};
type Definition = {endpoint:string;description:string;columns:string[];fields:Field[]};
const field=(key:string,label:string,required=false,kind?:Field["kind"],options?:string[],lookup?:string):Field=>({key,label,required,kind,options,lookup});
const definitions:Record<string,Definition>={
 "purchase-requests":{endpoint:"purchasing/requests",description:"Demandes, soumissions, décisions à quatre yeux et révisions.",columns:["reference","title","estimated_total_cents","currency","revision","status"],fields:[field("reference","Référence",true),field("title","Objet",true),field("justification","Justification",false,"textarea"),field("estimated_total_cents","Montant en centimes",true,"number"),field("currency","Devise",true,"select",["EUR","USD","GBP"])]},
 "maintenance-sites":{endpoint:"maintenance/sites",description:"Sites techniques autorisés de la société active.",columns:["code","name","active"],fields:[field("code","Code",true),field("name","Nom",true)]},
 "maintenance-assets":{endpoint:"maintenance/assets",description:"Équipements associés aux sites.",columns:["asset_code","name","site_id","status"],fields:[field("site_id","Site",true,"select",undefined,"maintenance/sites"),field("asset_code","Code",true),field("name","Nom",true),field("commissioned_on","Mise en service",false,"date")]},
 "maintenance-requests":{endpoint:"maintenance/requests",description:"Qualification des DI et création des bons de travail.",columns:["reference","title","asset_id","priority","status"],fields:[field("asset_id","Équipement",true,"select",undefined,"maintenance/assets"),field("reference","Référence",true),field("title","Objet",true),field("description","Description",false,"textarea"),field("priority","Priorité",true,"select",["low","normal","high","critical"])]},
 "maintenance-workorders":{endpoint:"maintenance/work-orders",description:"Démarrage et clôture tracés. Les BT sont créés depuis les DI qualifiées.",columns:["id","maintenance_request_id","asset_id","priority","status","due_at"],fields:[]},
 "hotel-properties":{endpoint:"hotels",description:"Établissements hôteliers et fuseaux horaires.",columns:["code","name","timezone","active"],fields:[field("code","Code",true),field("name","Nom",true),field("timezone","Fuseau IANA",true)]},
 "hotel-rooms":{endpoint:"hotel-rooms",description:"Chambres rattachées à chaque hôtel.",columns:["hotel_id","room_number","floor_code","category","room_status"],fields:[field("hotel_id","Hôtel",true,"select",undefined,"hotels"),field("room_number","Numéro",true),field("floor_code","Étage"),field("category","Catégorie",true)]},
 "hotel-bookings":{endpoint:"hotel-bookings",description:"Brouillons, confirmations sous contrôle anti-surbooking et annulations.",columns:["reference","guest_display_name","room_id","arrival_date","departure_date","status"],fields:[field("room_id","Chambre",true,"select",undefined,"hotel-rooms"),field("reference","Référence",true),field("guest_display_name","Client",true),field("arrival_date","Arrivée",true,"date"),field("departure_date","Départ",true,"date")]},
 "data-sources":{endpoint:"data/sources",description:"Sources déclarées et validées sans credentials.",columns:["code","name","source_type","classification","status"],fields:[field("code","Code",true),field("name","Nom",true),field("source_type","Type",true,"select",["csv","pms","api","postgresql","oracle","kafka"]),field("classification","Classification",true,"select",["internal","public","confidential","restricted"]),field("schema_version","Version du schéma"),field("purpose","Finalité",false,"textarea")]},
 "data-batches":{endpoint:"data/ingestion-batches",description:"Ingestion CSV v1 vérifiée par manifeste SHA-256 et idempotence.",columns:["batch_key","source_id","expected_rows","sha256","status"],fields:[field("source_id","Source CSV validée",true,"select",undefined,"data/sources"),field("batch_key","Clé du lot",true),field("csv_file","CSV : external_id,amount_cents",true,"file")]}
};
const headings:Record<string,string>={
 "purchase-requests":"Demandes d’achat","maintenance-sites":"Sites et localisations",
 "maintenance-assets":"Parc d’équipements","maintenance-requests":"Demandes d’intervention",
 "maintenance-workorders":"Bons de travail","hotel-properties":"Établissements hôteliers",
 "hotel-rooms":"Chambres","hotel-bookings":"Réservations","data-sources":"Catalogue des sources",
 "data-batches":"Lots d’ingestion CSV"
};
const columnNames:Record<string,string>={
 reference:"Référence",title:"Objet",estimated_total_cents:"Montant (centimes)",currency:"Devise",
 revision:"Révision",status:"Statut",code:"Code",name:"Nom",active:"Actif",asset_code:"Code équipement",
 site_id:"Site #",maintenance_request_id:"DI #",asset_id:"Équipement #",priority:"Priorité",id:"Identifiant",
 due_at:"Échéance",hotel_id:"Hôtel #",room_number:"Chambre",floor_code:"Étage",category:"Catégorie",
 room_status:"État",guest_display_name:"Client",room_id:"Chambre #",arrival_date:"Arrivée",
 departure_date:"Départ",timezone:"Fuseau",source_type:"Type",classification:"Classification",
 batch_key:"Référence lot",source_id:"Source #",expected_rows:"Lignes",sha256:"Empreinte SHA-256"
};
export const domainViews = new Set(Object.keys(definitions));
const show=(v:unknown)=>v==null?"—":typeof v==="boolean"?(v?"Oui":"Non"):typeof v==="object"?"—":String(v);
const getOption=(path:string,r:Row)=>path==="hotel-rooms"?"Chambre "+show(r.room_number)+" (hôtel #"+show(r.hotel_id)+")":show(r.code??r.asset_code??r.name??r.id)+" · "+show(r.name??r.room_number??r.id);

export default function DomainWorkspace({view,activeCompanyId}:{view:string;activeCompanyId:number|null}){
 const conf=definitions[view];
 const [refresh,setRefresh]=useState(0),[rows,setRows]=useState<Row[]>([]),[lookups,setLookups]=useState<Record<string,Row[]>>({}),[page,setPage]=useState(1),[last,setLast]=useState(1),[total,setTotal]=useState(0),[search,setSearch]=useState(""),[loading,setLoading]=useState(true),[busy,setBusy]=useState(false),[error,setError]=useState(""),[notice,setNotice]=useState(""),[form,setForm]=useState(false),[values,setValues]=useState<Record<string,string>>({}),[edit,setEdit]=useState<Row|null>(null),[csv,setCsv]=useState<File|null>(null);
 useEffect(()=>{let live=true;setLoading(true);api<Page<Row>>(conf.endpoint+"?page="+page).then(r=>{if(live){setRows(r.data);setTotal(r.total);setLast(r.last_page);}}).catch((e:Error)=>{if(live)setError(e.message);}).finally(()=>{if(live)setLoading(false);});return()=>{live=false;};},[conf,page,activeCompanyId,refresh]);
 useEffect(()=>{let live=true;const paths=[...new Set(conf.fields.map(f=>f.lookup).filter((v):v is string=>Boolean(v)))];Promise.all(paths.map(async path=>{const items=await all<Row>(path);return [path,items] as const;})).then(all=>{if(live)setLookups(Object.fromEntries(all));}).catch((e:Error)=>{if(live)setError(e.message);});return()=>{live=false;};},[conf,activeCompanyId,refresh]);
 const filtered=rows.filter(r=>!search||conf.columns.some(k=>show(r[k]).toLowerCase().includes(search.toLowerCase())));
 const fields=edit?conf.fields.filter(f=>f.key!=="reference"):conf.fields;
 function create(){setEdit(null);setValues({currency:"EUR",priority:"normal",timezone:"Europe/Paris",source_type:"csv",classification:"internal"});setCsv(null);setForm(true);setError("");}
 async function upload(id:number,file:File){const body=new FormData();body.append("file",file,file.name);await api("data/ingestion-batches/"+id+"/ingest-csv",{method:"POST",body});}
 async function save(e:FormEvent<HTMLFormElement>){e.preventDefault();if(busy)return;setBusy(true);setError("");try{
   const payload:Record<string,string|number>={};
   for(const f of fields){if(f.kind==="file")continue;const v=values[f.key]??"";if(!v&&!f.required)continue;payload[f.key]=f.kind==="number"||f.lookup?Number(v):v;}
   let saved:Row;
   if(view==="data-batches"){if(!csv)throw Error("Sélectionnez un CSV.");if(csv.size>1048576)throw Error("Fichier supérieur à 1 Mio.");const bytes=await csv.arrayBuffer();const hash=Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256",bytes))).map(b=>b.toString(16).padStart(2,"0")).join("");const text=new TextDecoder("utf-8",{fatal:true}).decode(bytes).replace(/\r\n/g,"\n");const lines=text.split("\n");if(lines.at(-1)==="")lines.pop();if(lines[0]!=="external_id,amount_cents")throw Error("En-tête CSV attendu : external_id,amount_cents.");payload.sha256=hash;payload.expected_rows=lines.slice(1).filter(x=>x.trim()).length;saved=await send<Row>(conf.endpoint,"POST",payload);await upload(saved.id,csv);}
   else if(edit&&view==="purchase-requests")saved=await send<Row>(conf.endpoint+"/"+edit.id+"/revise","PATCH",payload);
   else saved=await send<Row>(conf.endpoint,"POST",payload);
   setNotice("Opération enregistrée (#"+saved.id+").");setRefresh(n=>n+1);setPage(1);setForm(false);setEdit(null);setCsv(null);
 }catch(e){setError((e as Error).message);}finally{setBusy(false);}}
 async function action(path:string,data?:object){if(busy)return;setBusy(true);setError("");try{await send(path,"POST",data);setNotice("Transition enregistrée.");setRefresh(n=>n+1);}catch(e){setError((e as Error).message);}finally{setBusy(false);}}
 function button(row:Row,label:string,path:string,requireReason?:"reason"|"resolution"){return <button key={label} type="button" className="button secondary" disabled={busy} onClick={()=>{if(requireReason){const answer=window.prompt(requireReason==="reason"?"Motif du refus (5 caractères minimum)":"Compte-rendu (10 caractères minimum)");if(answer===null)return;if(answer.trim().length<(requireReason==="reason"?5:10)){setError("Le texte est trop court.");return;}void action(path,{[requireReason]:answer.trim()});}else void action(path);}}>{label}</button>;}
 function actions(row:Row){const state=row.status??"";if(view==="purchase-requests")return <>{state==="draft"&&button(row,"Soumettre","purchasing/requests/"+row.id+"/submit")}{state==="submitted"&&button(row,"Approuver","purchasing/requests/"+row.id+"/approve")}{state==="submitted"&&button(row,"Refuser","purchasing/requests/"+row.id+"/reject","reason")}{["draft","submitted","rejected"].includes(state)&&<button type="button" className="button secondary" onClick={()=>{setEdit(row);setValues(Object.fromEntries(conf.fields.map(f=>[f.key,row[f.key]==null?"":String(row[f.key])])));setForm(true);}}>Réviser</button>}</>;
 if(view==="maintenance-requests")return <>{state==="new"&&button(row,"Qualifier","maintenance/requests/"+row.id+"/qualify")}{state==="qualified"&&button(row,"Créer / voir BT","maintenance/requests/"+row.id+"/work-orders")}</>;
 if(view==="maintenance-workorders")return <>{state==="open"&&button(row,"Démarrer","maintenance/work-orders/"+row.id+"/start")}{state==="in_progress"&&button(row,"Clôturer","maintenance/work-orders/"+row.id+"/complete","resolution")}</>;
 if(view==="hotel-bookings")return <>{state==="draft"&&button(row,"Confirmer","hotel-bookings/"+row.id+"/confirm")}{["draft","confirmed"].includes(state)&&button(row,"Annuler","hotel-bookings/"+row.id+"/cancel")}</>;
 if(view==="data-sources"&&state==="draft")return button(row,"Valider","data/sources/"+row.id+"/validate");
 if(view==="data-batches"&&state==="registered")return <label className="button secondary">Charger CSV<input type="file" accept=".csv,text/csv" className="atlas-domain-hidden-upload" disabled={busy} onChange={async e=>{const f=e.target.files?.[0];if(!f)return;setBusy(true);setError("");try{await upload(row.id,f);setNotice("CSV ingéré.");setRefresh(n=>n+1);}catch(err){setError((err as Error).message);}finally{setBusy(false);e.target.value="";}}}/></label>;
 return <span aria-label="Aucune action disponible">—</span>;
 }
 return <section className="atlas-domain"><div className="atlas-domain-toolbar"><div><h2>{headings[view]}</h2><p>{conf.description}</p></div>{conf.fields.length>0&&<button type="button" className="button primary" onClick={create}>+ {view==="data-batches"?"Importer un CSV":"Nouvelle fiche"}</button>}</div>
 {notice&&<p className="notice" role="status">{notice}</p>}{error&&<p className="error" role="alert">{error}</p>}
 {form&&<form className="atlas-domain-form" onSubmit={e=>void save(e)}><div className="atlas-domain-form-heading"><h3>{edit?"Réviser la demande":"Création"}</h3><button className="button secondary" type="button" onClick={()=>setForm(false)}>Fermer</button></div><div className="atlas-domain-fields">{fields.map(f=><label key={f.key}><span>{f.label}{f.required?" *":""}</span>
 {f.kind==="textarea"?<textarea rows={3} value={values[f.key]??""} required={f.required} onChange={e=>setValues(v=>({...v,[f.key]:e.target.value}))}/>:
 f.kind==="file"?<input type="file" required={f.required} accept=".csv,text/csv" onChange={e=>setCsv(e.target.files?.[0]??null)}/>:
 f.kind==="select"?<select required={f.required} value={values[f.key]??""} onChange={e=>setValues(v=>({...v,[f.key]:e.target.value}))}><option value="">Sélectionner…</option>{f.lookup?(lookups[f.lookup]??[]).filter(r=>f.lookup!=="data/sources"||(r.status==="validated"&&r.source_type==="csv")).map(r=><option key={r.id} value={r.id}>{getOption(f.lookup!,r)}</option>):(f.options??[]).map(o=><option key={o} value={o}>{o}</option>)}</select>:
 <input type={f.kind==="date"?"date":f.kind==="number"?"number":"text"} required={f.required} value={values[f.key]??""} onChange={e=>setValues(v=>({...v,[f.key]:e.target.value}))}/>}</label>)}</div><button className="button primary" disabled={busy} type="submit">{busy?"En cours…":"Enregistrer"}</button></form>}
 <div className="atlas-domain-list"><div className="atlas-domain-list-heading"><span>{total} enregistrement(s)</span><input type="search" aria-label="Filtrer les enregistrements" placeholder="Filtrer la page…" value={search} onChange={e=>setSearch(e.target.value)}/></div><div className="table-scroll"><table><thead><tr>{conf.columns.map(k=><th key={k}>{columnNames[k]??k}</th>)}<th>Actions</th></tr></thead><tbody>{filtered.map(row=><tr key={row.id}>{conf.columns.map(k=><td key={k}>{k==="sha256"?String(row[k]??"").slice(0,12)+"…":show(row[k])}</td>)}<td><div className="atlas-domain-actions">{actions(row)}</div></td></tr>)}</tbody></table></div>{loading?<p className="atlas-domain-empty">Chargement…</p>:filtered.length===0&&<p className="atlas-domain-empty">Aucun résultat sur cette page.</p>}<div className="atlas-domain-pagination"><button type="button" className="button secondary" disabled={page<=1||loading} onClick={()=>setPage(p=>p-1)}>Précédent</button><span>Page {page} / {Math.max(last,1)}</span><button type="button" className="button secondary" disabled={page>=last||loading} onClick={()=>setPage(p=>p+1)}>Suivant</button></div></div></section>;
}
