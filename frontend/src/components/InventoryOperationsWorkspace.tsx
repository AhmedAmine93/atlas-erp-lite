"use client";

import { useEffect, useState, type FormEvent } from "react";
import { api, send, all } from "@/lib/api";
import type { Page } from "@/lib/types";

type Row = Record<string, unknown> & { id: number; status?: string };
type Field = { name: string; title: string; type?: "number"|"date"|"select"; options?: string[]; lookup?: "products"|"locations"|"lots"|"suppliers"; required?: boolean };
type View = "stock-inventories" | "stock-transfers" | "lots-serials" | "replenishment";
type Definition = { title: string; endpoint: string; fields: Field[]; columns: string[] };
const f=(name:string,title:string,type?:Field["type"],lookup?:Field["lookup"],required=true):Field=>({name,title,type,lookup,required});
const definitions:Record<Exclude<View,"lots-serials">,Definition> = {
  "stock-inventories": {
    title:"Inventaires physiques",endpoint:"inventory/counts",
    fields:[f("reference","Référence",undefined,undefined),f("product_id","Produit","select","products"),f("location_id","Emplacement","select","locations"),f("lot_id","Lot (facultatif)","select","lots",false)],
    columns:["reference","product_id","location_id","snapshot_quantity","first_count","second_count","status"]
  },
  "stock-transfers":{
    title:"Transferts inter-emplacements",endpoint:"inventory/transfers",
    fields:[f("reference","Référence"),f("product_id","Produit","select","products"),f("source_location_id","Emplacement source","select","locations"),f("target_location_id","Emplacement destination","select","locations"),f("lot_id","Lot (facultatif)","select","lots",false),f("quantity_requested","Quantité","number")],
    columns:["reference","product_id","source_location_id","target_location_id","quantity_requested","quantity_shipped","quantity_received","quantity_lost","status"]
  },
  "replenishment":{
    title:"Propositions de réapprovisionnement",endpoint:"inventory/replenishments",
    fields:[f("product_id","Produit","select","products"),f("supplier_id","Fournisseur (facultatif)","select","suppliers",false),f("safety_quantity","Stock de sécurité cible","number"),f("reserved_quantity","Quantité réservée","number",undefined,false),f("incoming_quantity","Réceptions attendues","number",undefined,false)],
    columns:["product_id","safety_quantity","available_quantity","reserved_quantity","incoming_quantity","suggested_quantity","status"]
  }
};
const lotDefinition:Definition={
  title:"Lots et traçabilité",endpoint:"inventory/lots",
  fields:[f("product_id","Produit","select","products"),f("code","Code du lot"),f("expires_on","Péremption (facultatif)","date",undefined,false)],
  columns:["code","product_id","expires_on","status","decision_reason"]
};
const serialDefinition:Definition={
  title:"Numéros de série",endpoint:"inventory/serials",
  fields:[f("product_id","Produit","select","products"),f("location_id","Emplacement","select","locations"),f("lot_id","Lot (facultatif)","select","lots",false),f("serial_number","Numéro de série")],
  columns:["serial_number","product_id","lot_id","location_id","status"]
};
const display=(v:unknown):string=>v===null||v===undefined?"—":typeof v==="object"?"—":String(v);
const uuid=()=>crypto.randomUUID();

export default function InventoryOperationsWorkspace({view,role,activeCompanyId}:{
  view:View;role:string;activeCompanyId:number|null;
}){
  const canWrite=role==="admin"||role==="stock";
  const canApprove=role==="admin";
  const [tab,setTab]=useState<"lots"|"serials">("lots");
  const definition=view==="lots-serials"?(tab==="lots"?lotDefinition:serialDefinition):definitions[view];
  const [rows,setRows]=useState<Row[]>([]);
  const [lookups,setLookups]=useState<Record<string,Row[]>>({});
  const [values,setValues]=useState<Record<string,string>>({});
  const [page,setPage]=useState(1),[lastPage,setLastPage]=useState(1);
  const [version,setVersion]=useState(0);
  const [loading,setLoading]=useState(true),[busy,setBusy]=useState(false);
  const [error,setError]=useState(""),[notice,setNotice]=useState("");
  useEffect(()=>{
    let live=true;setLoading(true);setRows([]);setError("");
    const endpoints:Record<string,string>={
      products:"products",locations:"inventory/locations",lots:"inventory/lots",suppliers:"inventory/supplier-options"
    };
    const needs=[...new Set(definition.fields.map(field=>field.lookup).filter((v):v is NonNullable<Field["lookup"]>=>!!v))];
    Promise.all([
      api<Page<Row>>(definition.endpoint+"?page="+page),
      Promise.all(needs.map(async name=>[name,await all<Row>(endpoints[name])] as const))
    ]).then(([result,opts])=>{
      if(!live)return;setRows(result.data);setLastPage(result.last_page);
      setLookups(Object.fromEntries(opts));
    }).catch((e:Error)=>{if(live)setError(e.message);})
      .finally(()=>{if(live)setLoading(false);});
    return()=>{live=false;};
  },[definition.endpoint,definition.fields,activeCompanyId,page,version]);

  const done=(msg:string)=>{setNotice(msg);setValues({});setVersion(v=>v+1);};
  async function create(event:FormEvent<HTMLFormElement>){
    event.preventDefault();if(busy||!canWrite)return;setBusy(true);setError("");setNotice("");
    try{
      const data:Record<string,string|number>={};
      for(const field of definition.fields){
        const value=values[field.name]??"";
        if(value===""){
          if(field.required)throw Error("Champ requis : "+field.title);
          continue;
        }
        data[field.name]=field.type==="number"||field.lookup?Number(value):value;
      }
      if(view==="replenishment")data.operation_key=uuid();
      await send(definition.endpoint,"POST",data);
      done("Création enregistrée dans le journal de l'entreprise.");
    }catch(e){setError((e as Error).message);}finally{setBusy(false);}
  }
  async function action(path:string,body?:object){
    if(busy)return;setBusy(true);setError("");setNotice("");
    try{await send(path,"POST",body);done("Transition enregistrée et auditée.");}
    catch(e){setError((e as Error).message);}finally{setBusy(false);}
  }
  function promptNumber(text:string){
    const answer=window.prompt(text);
    if(answer===null)return null;
    const n=Number(answer);
    if(!Number.isSafeInteger(n)||n<0){setError("Saisissez un entier positif ou nul.");return null;}
    return n;
  }
  function promptReason(text:string){
    const value=window.prompt(text);
    return value!==null&&value.trim().length>=5?value.trim():null;
  }
  function actions(row:Row){
    if(!canWrite)return null;
    if(view==="stock-transfers"){
      if(row.status==="requested")return <div className="inventory-inline-actions">
        <button type="button" disabled={busy} onClick={()=>void action("inventory/transfers/"+row.id+"/ship",{operation_key:uuid()})}>Expédier</button>
        <button type="button" disabled={busy} onClick={()=>void action("inventory/transfers/"+row.id+"/cancel")}>Annuler</button>
      </div>;
      if(row.status==="in_transit")return <div className="inventory-inline-actions">
        <button type="button" disabled={busy} onClick={()=>{
          const quantity=promptNumber("Quantité réceptionnée ?");
          const reference=window.prompt("Référence du bordereau de réception ?");
          if(quantity!==null&&quantity>0&&reference?.trim())void action("inventory/transfers/"+row.id+"/receive",{quantity,reference,operation_key:uuid()});
        }}>Réceptionner</button>
        <button type="button" disabled={busy} onClick={()=>{
          const quantity=promptNumber("Quantité endommagée à réceptionner en quarantaine ?");
          const location=promptNumber("Identifiant de l'emplacement quarantaine dans le dépôt destination ?");
          const reference=window.prompt("Référence du bordereau de dommages ?");
          const reason=promptReason("Motif des dommages (5 caractères minimum) ?");
          if(quantity!==null&&quantity>0&&location!==null&&location>0&&reference?.trim()&&reason)
            void action("inventory/transfers/"+row.id+"/damaged-receipt",{
              quantity,damage_location_id:location,reference,reason,operation_key:uuid()
            });
        }}>Réceptionner les dommages</button>
        {canApprove&&<button type="button" disabled={busy} onClick={()=>{
          const quantity=promptNumber("Quantité perdue pendant le transit ?");
          const reason=promptReason("Motif de la perte (5 caractères minimum) ?");
          if(quantity!==null&&quantity>0&&reason)void action("inventory/transfers/"+row.id+"/loss",{quantity,reason,operation_key:uuid()});
        }}>Déclarer une perte</button>}
      </div>;
    }
    if(view==="stock-inventories"){
      if(row.status==="draft"||row.status==="counted")return <button type="button" disabled={busy} onClick={()=>{
        const quantity=promptNumber(row.status==="draft"?"Premier comptage ?":"Recomptage indépendant ?");
        if(quantity!==null){
          const reason = row.status === "counted" ? promptReason("Motif obligatoire du recomptage indépendant ?") : undefined;
          if(row.status !== "counted" || reason)void action("inventory/counts/"+row.id+"/count",{quantity,...(reason ? {reason} : {})});
        }
      }}>{row.status==="draft"?"Compter":"Recompter"}</button>;
      if(canApprove&&row.status==="recounted")return <button type="button" disabled={busy} onClick={()=>{
        const reason=promptReason("Motif d'approbation / ajustement ?");
        if(reason)void action("inventory/counts/"+row.id+"/approve",{reason});
      }}>Approuver</button>;
      if(canApprove&&row.status==="counted")return <button type="button" disabled={busy} onClick={()=>{
        const reason=promptReason("Motif d'approbation (possible uniquement si aucun écart) ?");
        if(reason)void action("inventory/counts/"+row.id+"/approve",{reason});
      }}>Approuver sans écart</button>;
    }
    if(view==="lots-serials"&&tab==="lots"&&canApprove)return <button type="button" disabled={busy} onClick={()=>{
      const status=window.prompt("Décision : available, quarantine, blocked ou recalled ?");
      if(!status||!["available","quarantine","blocked","recalled"].includes(status))return;
      const reason=promptReason("Motif de décision qualité ?");
      if(reason)void send("inventory/lots/"+row.id+"/decision","PATCH",{status,reason})
        .then(()=>done("Décision qualité enregistrée."))
        .catch((e:Error)=>setError(e.message));
    }}>Décision qualité</button>;
    if(view==="replenishment"&&canApprove&&row.status==="proposed")return <div className="inventory-inline-actions">
      <button type="button" disabled={busy} onClick={()=>void action("inventory/replenishments/"+row.id+"/decision",{decision:"approved"})}>Approuver</button>
      <button type="button" disabled={busy} onClick={()=>void action("inventory/replenishments/"+row.id+"/decision",{decision:"rejected"})}>Rejeter</button>
    </div>;
    return null;
  }
  return <section className="inventory-operations" aria-label={definition.title}>
    {view==="lots-serials"&&<div className="inventory-inline-actions" role="tablist" aria-label="Traçabilité">
      <button type="button" role="tab" aria-selected={tab==="lots"} onClick={()=>{setTab("lots");setPage(1);}}>Lots</button>
      <button type="button" role="tab" aria-selected={tab==="serials"} onClick={()=>{setTab("serials");setPage(1);}}>Numéros de série</button>
    </div>}
    <h2>{definition.title}</h2>
    <p className="inventory-help">Données de l'entreprise active. Les opérations sont vérifiées par Laravel et tracées en base. Aucune proposition ne déclenche automatiquement une commande fournisseur.</p>
    {error&&<p className="error" role="alert">{error}</p>}
    {notice&&<p className="notice" role="status">{notice}</p>}
    {loading?<p role="status">Chargement…</p>:<div className="table-scroll">
      <table><thead><tr>{definition.columns.map(c=><th key={c}>{c.replaceAll("_"," ")}</th>)}<th>Action</th></tr></thead>
      <tbody>{rows.map(row=><tr key={row.id}>{definition.columns.map(c=><td key={c}>{display(row[c])}</td>)}<td>{actions(row)}</td></tr>)}</tbody></table>
      {!rows.length&&<p>Aucune donnée pour le moment.</p>}
    </div>}
    <div className="inventory-inline-actions" aria-label="Pagination">
      <button type="button" disabled={page<=1||loading} onClick={()=>setPage(v=>v-1)}>Précédent</button>
      <span>Page {page} / {lastPage}</span>
      <button type="button" disabled={page>=lastPage||loading} onClick={()=>setPage(v=>v+1)}>Suivant</button>
    </div>
    {canWrite&&<form className="inventory-form panel" onSubmit={create}>
      <h3>Nouvelle opération</h3>
      {definition.fields.map(field=><label key={field.name}>{field.title}
        {field.lookup?<select required={field.required} value={values[field.name]??""} onChange={e=>setValues(v=>({...v,[field.name]:e.target.value}))}>
          <option value="">Choisir…</option>
          {(lookups[field.lookup]??[]).map(option=><option key={option.id} value={option.id}>{display(option.code??option.reference??option.name??option.serial_number??option.id)} · #{option.id}</option>)}
        </select>:<input required={field.required} type={field.type==="date"?"date":field.type==="number"?"number":"text"}
          min={field.type==="number"?"0":undefined} maxLength={field.type==="number"?undefined:160}
          value={values[field.name]??""} onChange={e=>setValues(v=>({...v,[field.name]:e.target.value}))}/>}
      </label>)}
      <button className="button primary" type="submit" disabled={busy}>{busy?"Enregistrement…":"Enregistrer"}</button>
    </form>}
  </section>;
}
