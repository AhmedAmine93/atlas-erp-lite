"use client";

import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import { api } from "@/lib/api";


type Entry = Record<string, unknown> & { id: number; status?: string; order_id?: number; quote_id?: number; delivery_id?: number; revision_no?: number };
type Page<T> = { data: T[]; current_page: number; last_page: number; total: number };
type Field = { name: string; label: string; type?: "number" | "date" | "textarea" | "select"; options?: string[]; required?: boolean; lookup?: "products" | "customers" | "quotes" | "orders" | "deliveries" };
type FormAction = { label: string; endpoint: string; fields: Field[]; record?: Entry };
type Module = "quotes" | "orders" | "deliveries" | "sales-contracts" | "sales-returns" | "pricing";
const f = (name:string,label:string,type?:Field["type"],required=true,options?:string[],lookup?:Field["lookup"]):Field => ({name,label,type,required,options,lookup});
const today = () => new Date().toISOString().slice(0,10);
const DAYS = 24 * 60 * 60 * 1000;
const dateAfter = (n:number) => new Date(Date.now()+n*DAYS).toISOString().slice(0,10);
const names:Record<Module,string> = {
  quotes:"Révisions et approbations des devis",
  orders:"Réservations et expéditions",
  deliveries:"Bons de livraison",
  "sales-contracts":"Contrats commerciaux",
  "sales-returns":"Retours clients et contrôle qualité",
  pricing:"Tarifs et remises"
};
const columns:Record<Module,string[]> = {
  quotes:["quote_id","revision_no","discount_bps","valid_until","status","total_cents"],
  orders:["number","status","fulfillment_mode","total_cents"],
  deliveries:["number","order_id","recipient","carrier","status","issued_at"],
  "sales-contracts":["number","title","customer_id","currency","effective_on","expires_on","status"],
  "sales-returns":["number","delivery_id","reason","status"],
  pricing:["title","product_id","price_cents","discount_bps","starts_on","ends_on","status"]
};
const endpoint:Record<Module,string> = {
  quotes:"sales-v5/revisions",orders:"orders",deliveries:"sales-v5/deliveries",
  "sales-contracts":"sales-v5/contracts","sales-returns":"sales-v5/returns",pricing:"sales-v5/pricing"
};
function format(name:string,value:unknown):string {
  if(value===null||value===undefined||value==="") return "—";
  if(name.endsWith("_cents")) return new Intl.NumberFormat("fr-FR",{style:"currency",currency:"EUR"}).format(Number(value)/100);
  if(name==="discount_bps") return (Number(value)/100).toLocaleString("fr-FR")+" %";
  if(name.endsWith("_at")) return new Date(String(value)).toLocaleString("fr-FR");
  return String(value);
}
export const salesCommerceViews = new Set<Module>(["quotes","orders","deliveries","sales-contracts","sales-returns","pricing"]);
const getUniqueId = () => crypto.randomUUID().replaceAll("-","");

export default function SalesCommerceWorkspace({view,activeCompanyId,canWrite,canApprove=false,embedded=false}:{
  view:Module; activeCompanyId:number|null; canWrite:boolean; canApprove?:boolean; embedded?:boolean
}) {
  const [items,setItems] = useState<Entry[]>([]);
  const [approvals,setApprovals] = useState<Entry[]>([]);
  const [pages,setPages] = useState({current_page:1,last_page:1,total:0});
  const [page,setPage] = useState(1);
  const [status,setStatus] = useState("");
  const [search,setSearch] = useState("");
  const [lookups,setLookups] = useState<Record<string,Entry[]>>({});
  const [focus,setFocus] = useState<Entry|null>(null);
  const [details,setDetails] = useState<Record<string,unknown>|null>(null);
  const [form,setForm] = useState<FormAction|null>(null);
  const [values,setValues] = useState<Record<string,string>>({});
  const [lines,setLines] = useState<{id:string;quantity:string}[]>([]);
  const [loading,setLoading] = useState(true);
  const [busy,setBusy] = useState(false);
  const [error,setError] = useState("");
  const [notice,setNotice] = useState("");
  const [refresh,setRefresh] = useState(0);
  const readPath = endpoint[view];
  const load = useCallback(async () => {
    setLoading(true);setError("");
    try {
      const params=new URLSearchParams({page:String(page)});
      if(view!=="orders"&&status)params.set("status",status);
      if(view!=="orders"&&search.trim())params.set("search",search.trim());
      const data=await api<Page<Entry>>(readPath+"?"+params.toString());
      setItems(data.data);setPages({current_page:data.current_page,last_page:data.last_page,total:data.total});
    } catch(e) {setError((e as Error).message);}
    finally {setLoading(false);}
  },[page,status,search,refresh,readPath,view,activeCompanyId]);
  useEffect(()=>{void load();},[load]);
  useEffect(()=>{
    let alive=true;
    Promise.all(["products","customers","quotes","orders","sales-v5/deliveries"].map(async p=>{
      const d=await api<Page<Entry>>(p+"?page=1");return [p.replace("sales-v5/",""),d.data] as const;
    })).then(r=>{if(alive)setLookups(Object.fromEntries(r));}).catch(()=>{});
    return()=>{alive=false;};
  },[activeCompanyId,refresh]);
  useEffect(()=>{
    if(view!=="quotes"){setApprovals([]);return;}
    let live=true;
    api<Page<Entry>>("sales-v5/approvals?page=1").then(r=>{if(live)setApprovals(r.data);})
      .catch(()=>{if(live)setApprovals([])});
    return()=>{live=false;};
  },[view,activeCompanyId,refresh]);
  const labelFor=(key:string,row:Entry):string=> {
    if(key==="products")return String(row.reference??row.id)+" · "+String(row.name??"");
    if(key==="customers")return String(row.name??row.id);
    if(key==="orders"||key==="quotes"||key==="deliveries")return String(row.number??row.id);
    return String(row.id);
  };
  const open=(action:FormAction,defaults:Record<string,string>={},source:Entry|null=null)=>{
    setError("");setNotice("");setValues(defaults);setForm(action);setFocus(source);
    setLines([{id:"",quantity:"1"}]);
  };
  const actions=(row:Entry):FormAction[]=>{
    if(view==="quotes")return [
      ...(row.status==="draft"?[{label:"Envoyer",endpoint:`sales-v5/revisions/${row.id}/send`,fields:[]}]:[]),
      ...(row.status==="sent"?[{label:"Accepter",endpoint:`sales-v5/revisions/${row.id}/accept`,fields:[]}]:[]),
      ...(row.status==="accepted"?[{label:"Convertir en commande",endpoint:`sales-v5/revisions/${row.id}/convert`,fields:[]}]:[])
    ];
    if(view==="orders") {
      if(row.fulfillment_mode!=="reservation_v2")return [];
      return [
        ...(row.status==="draft"?[{label:"Réserver le stock",endpoint:`sales-v5/orders/${row.id}/reserve`,fields:[]}]:[]),
        ...(["reserved","partially_shipped"].includes(row.status??"")?[
          {label:"Expédier un reliquat",endpoint:`sales-v5/orders/${row.id}/ship`,
            fields:[f("recipient","Destinataire"),f("carrier","Transporteur","textarea",false)]}]:[]),
        ...(["draft","reserved","partially_shipped"].includes(row.status??"")?[
          {label:"Annuler les quantités restantes",endpoint:`sales-v5/orders/${row.id}/cancel`,fields:[]}]:[])
      ];
    }
    if(view==="deliveries")return row.status==="issued"?[
      {label:"Confirmer la réception",endpoint:`sales-v5/deliveries/${row.id}/acknowledge`,fields:[f("proof_reference","Référence de preuve de remise")]}
    ]:[];
    if(view==="sales-contracts")return [
      ...(row.status==="draft"?[{label:"Publier le contrat",endpoint:`sales-v5/contracts/${row.id}/publish`,fields:[]}]:[]),
      ...(row.status==="active"?[
        {label:"Publier un avenant",endpoint:`sales-v5/contracts/${row.id}/amend`,
          fields:[f("effective_on","Date d'effet","date"),f("expires_on","Expiration","date"),f("terms","Nouvelles clauses","textarea")]},
        {label:"Résilier",endpoint:`sales-v5/contracts/${row.id}/terminate`,fields:[]}
      ]:[])
    ];
    if(view==="sales-returns")return [
      ...(row.status==="quarantine"?[
        {label:"Contrôle qualité",endpoint:`sales-v5/returns/${row.id}/inspect`,
          fields:[f("decision","Décision","select",true,["sellable","defective"]),
            f("credit_eligible","Éligible à l'avoir","select",true,["true","false"])]}
      ]:[]),
      ...(row.status==="inspected"?[{label:"Émettre l'avoir",endpoint:`sales-v5/returns/${row.id}/credit`,fields:[]}]:[])
    ];
    if(view==="pricing")return row.status==="draft"?[
      {label:"Publier la grille",endpoint:`sales-v5/pricing/${row.id}/publish`,fields:[]}
    ]:[];
    return [];
  };
  const newAction:FormAction|null = useMemo(()=>{
    if(view==="quotes")return {label:"Nouvelle révision",endpoint:"sales-v5/quotes/:quote_id/revisions",fields:[
      f("quote_id","Devis d'origine","select",true,undefined,"quotes"),
      f("discount_bps","Remise en points de base (100 = 1 %)","number"),
      f("valid_until","Valable jusqu'au","date")]};
    if(view==="sales-contracts")return {label:"Nouveau contrat",endpoint:"sales-v5/contracts",fields:[
      f("customer_id","Client","select",true,undefined,"customers"),
      f("establishment_id","Établissement (ID facultatif)","number",false),
      f("title","Titre"),f("currency","Devise","select",true,["EUR","USD","GBP"]),
      f("effective_on","Date d'effet","date"),f("expires_on","Expiration","date"),
      f("renewal_rule","Renouvellement",undefined,false),
      f("terms","Clauses","textarea",false)]};
    if(view==="pricing")return {label:"Nouvelle grille de prix",endpoint:"sales-v5/pricing",fields:[
      f("product_id","Produit","select",true,undefined,"products"),
      f("establishment_id","Établissement (ID facultatif)","number",false),
      f("title","Libellé"),f("currency","Devise","select",true,["EUR","USD","GBP"]),
      f("price_cents","Prix HT en centimes","number"),
      f("vat_bps","TVA en points de base","number"),
      f("discount_bps","Remise en points de base","number"),
      f("priority","Priorité","number"),
      f("starts_on","Début","date"),f("ends_on","Fin","date")]};
    if(view==="sales-returns")return {label:"Réceptionner un retour",endpoint:"sales-v5/returns",fields:[
      f("delivery_id","Bon de livraison","select",true,undefined,"deliveries"),f("reason","Motif","textarea")]};
    return null;
  },[view]);
  async function loadDetails(row:Entry){
    setFocus(row);setDetails(null);setError("");
    try {const p=view==="orders"?`orders/${row.id}`:`sales-v5/${view==="quotes"?"revisions":view==="sales-contracts"?"contracts":view==="sales-returns"?"returns":view}/${row.id}`;
      const data=await api<Record<string,unknown>>(p);setDetails(data);
    }catch(e){setError((e as Error).message);}
  }
  const defaults=():Record<string,string>=>({
    discount_bps:"0",valid_until:dateAfter(30),currency:"EUR",effective_on:today(),
    expires_on:dateAfter(365),starts_on:today(),ends_on:dateAfter(30),
    priority:"100",vat_bps:"2000",credit_eligible:"true"
  });
  async function perform(e:FormEvent){
    e.preventDefault();if(!form||busy||!canWrite)return;
    setBusy(true);setError("");setNotice("");
    try{
      const payload:Record<string,unknown>={};
      for(const field of form.fields){
        const raw=(values[field.name]??"").trim();
        if(!raw){if(field.required)throw Error("Champ obligatoire : "+field.label);continue;}
        payload[field.name]=field.type==="number"||field.lookup?Number(raw):
          raw==="true"?true:raw==="false"?false:raw;
      }
      const path=form.endpoint.replace(":quote_id",String(payload.quote_id??""));
      delete payload.quote_id;
      if(form.endpoint.includes("/ship")||form.endpoint==="sales-v5/returns") {
        const selected=lines.filter(x=>x.id&&Number(x.quantity)>0).map(x=>(
          form.endpoint.includes("/ship")?{order_item_id:Number(x.id),quantity:Number(x.quantity)}:
            {delivery_line_id:Number(x.id),quantity:Number(x.quantity)}
        ));
        if(!selected.length)throw Error("Ajoutez au moins une ligne et sa quantité.");
        if(new Set(selected.map(x=>Object.values(x)[0])).size!==selected.length)throw Error("Lignes dupliquées.");
        payload.lines=selected;
      }
      await api(path,{method:"POST",headers:{"Idempotency-Key":getUniqueId()},body:JSON.stringify(payload)});
      setForm(null);setFocus(null);setDetails(null);setRefresh(n=>n+1);
      setNotice("Opération métier enregistrée.");
    }catch(e){setError((e as Error).message);}
    finally{setBusy(false);}
  }
  function begin(action:FormAction,row?:Entry){
    open(action,defaults(),row??null);
  }
  const formLines=(form?.endpoint.includes("/ship")||form?.endpoint==="sales-v5/returns");
  const chosenDelivery=Number(values.delivery_id||0);
  const [deliveryChoices,setDeliveryChoices]=useState<Entry[]>([]);
  useEffect(()=>{
    if(!form||form.endpoint!=="sales-v5/returns"||!chosenDelivery){setDeliveryChoices([]);return;}
    let live=true;
    api<{lines:Entry[]}>(`sales-v5/deliveries/${chosenDelivery}`)
      .then(d=>{if(live)setDeliveryChoices(d.lines??[]);}).catch(()=>{if(live)setDeliveryChoices([]);});
    return()=>{live=false;};
  },[form,chosenDelivery]);
  const [orderChoices,setOrderChoices]=useState<Entry[]>([]);
  useEffect(()=>{
    if(!form?.endpoint.includes("/ship")||!focus){setOrderChoices([]);return;}
    let live=true;
    api<{items:Entry[]}>(`orders/${focus.id}`).then(d=>{if(live)setOrderChoices(d.items??[]);})
      .catch(()=>{if(live)setOrderChoices([]);});return()=>{live=false;};
  },[form,focus]);
  const chosenOptions=form?.endpoint.includes("/ship")?orderChoices:deliveryChoices;
  return <section className="atlas-domain" aria-label={names[view]}>
    <div className="atlas-domain-toolbar">
      <div><h2>{embedded?"Cycle commercial V5 · "+names[view]:names[view]}</h2>
        <p>{view==="orders"?"Les commandes historiques restent sous le mode legacy_debit. Seules les commandes issues d'une révision V5 utilisent la réservation.":"Workflow commercial isolé par entreprise, journalisé et contrôlé côté API."}</p>
      </div>
      {newAction&&canWrite&&<button className="button primary" type="button" onClick={()=>begin(newAction)}>{newAction.label}</button>}
    </div>
    {notice&&<p className="notice" role="status">{notice}</p>}
    {error&&<p className="error" role="alert">{error}</p>}
    {form&&<form className="atlas-domain-form" onSubmit={e=>void perform(e)}>
      <div className="atlas-domain-form-heading"><h3>{form.label}</h3><button type="button" className="button secondary" onClick={()=>setForm(null)}>Fermer</button></div>
      <div className="atlas-domain-fields">
        {form.fields.map(field=><label key={field.name}><span>{field.label}{field.required?" *":""}</span>
          {field.type==="textarea"?<textarea required={field.required} rows={3} value={values[field.name]??""} onChange={e=>setValues(x=>({...x,[field.name]:e.target.value}))}/>:
            field.type==="select"?<select required={field.required} value={values[field.name]??""} onChange={e=>setValues(x=>({...x,[field.name]:e.target.value}))}>
              <option value="">Sélectionner…</option>
              {(field.lookup?(lookups[field.lookup]??[]).map(row=>({value:String(row.id),text:labelFor(field.lookup!,row)})):(field.options??[]).map(x=>({value:x,text:x})))
                .map(opt=><option key={opt.value} value={opt.value}>{opt.text}</option>)}
            </select>:
            <input required={field.required} type={field.type==="date"?"date":field.type==="number"?"number":"text"}
              min={field.type==="number"?"0":undefined}
              value={values[field.name]??""} onChange={e=>setValues(x=>({...x,[field.name]:e.target.value}))}/>}
        </label>)}
      </div>
      {formLines&&<fieldset><legend>Articles et quantités</legend>
        {lines.map((line,i)=><div className="atlas-domain-fields" key={i}>
          <label>Ligne {i+1}<select required value={line.id} onChange={e=>setLines(prev=>prev.map((x,j)=>j===i?{...x,id:e.target.value}:x))}>
            <option value="">Sélectionner une ligne</option>
            {chosenOptions.map(x=><option key={x.id} value={x.id}>{String(x.name??x.reference??x.id)} · #{x.id} (Qté {String(x.quantity??"—")})</option>)}
          </select></label>
          <label>Quantité<input type="number" min="1" step="1" required value={line.quantity}
            onChange={e=>setLines(prev=>prev.map((x,j)=>j===i?{...x,quantity:e.target.value}:x))}/></label>
        </div>)}
        <button type="button" className="button secondary" onClick={()=>setLines(prev=>[...prev,{id:"",quantity:"1"}])}>Ajouter une ligne</button>
      </fieldset>}
      <button disabled={busy||!canWrite} className="button primary" type="submit">{busy?"Traitement…":"Confirmer"}</button>
    </form>}
    <div className="atlas-domain-list">
      <div className="atlas-domain-list-heading"><span>{pages.total} enregistrement(s)</span>
        <label>Filtrer <input type="search" aria-label="Rechercher dans le module Ventes" value={search}
          onChange={e=>{setPage(1);setSearch(e.target.value)}}/></label>
        {view!=="orders"&&<label>Statut <select value={status} onChange={e=>{setPage(1);setStatus(e.target.value)}}>
          <option value="">Tous</option>{["draft","sent","accepted","converted","pending","approved","rejected","reserved","issued","received","active","terminated","quarantine","inspected","credited","published"].map(s=><option key={s} value={s}>{s}</option>)}
        </select></label>}
      </div>
      <div className="table-scroll"><table><thead><tr>{columns[view].map(k=><th key={k}>{k.replaceAll("_"," ")}</th>)}<th>Actions</th></tr></thead>
        <tbody>{items.map(row=><tr key={row.id}>{columns[view].map(k=><td key={k}>{format(k,row[k])}</td>)}
          <td><div className="atlas-domain-actions">
            <button type="button" className="button secondary" onClick={()=>void loadDetails(row)}>Détail</button>
            {canWrite&&actions(row).map(action=><button type="button" className="button secondary" key={action.label} disabled={busy}
              onClick={()=>begin(action,row)}>{action.label}</button>)}
          </div></td></tr>)}</tbody></table></div>
      {loading?<p>Chargement des données…</p>:!items.length?<p>Aucun enregistrement.</p>:null}
      <div className="atlas-domain-pagination">
        <button type="button" className="button secondary" disabled={loading||page<=1} onClick={()=>setPage(x=>x-1)}>Précédent</button>
        <span>Page {page} / {Math.max(1,pages.last_page)}</span>
        <button type="button" className="button secondary" disabled={loading||page>=pages.last_page} onClick={()=>setPage(x=>x+1)}>Suivant</button>
      </div>
    </div>
    {view==="quotes"&&<div className="atlas-domain-list">
      <h3>Demandes d'approbation des remises</h3>
      <div className="table-scroll"><table><thead><tr><th>Révision</th><th>Remise</th><th>Statut</th><th>Décision</th></tr></thead><tbody>
        {approvals.map(a=><tr key={a.id}><td>{String(a.revision_id)}</td><td>{format("discount_bps",a.discount_bps)}</td>
          <td>{String(a.status??"—")}</td><td>{canApprove&&a.status==="pending"?
            <div className="atlas-domain-actions">
              <button type="button" className="button secondary" onClick={()=>begin({
                label:"Approuver la remise",endpoint:`sales-v5/approvals/${a.id}/decide`,
                fields:[f("reason","Justification","textarea")]
              }) || setValues(x=>({...x,decision:"approved"}))}>Approuver</button>
              <button type="button" className="button secondary" onClick={()=>begin({
                label:"Refuser la remise",endpoint:`sales-v5/approvals/${a.id}/decide`,
                fields:[f("reason","Justification du refus","textarea")]
              }) || setValues(x=>({...x,decision:"rejected"}))}>Refuser</button>
            </div>:"—"}</td></tr>)}
      </tbody></table></div>
      {!approvals.length&&<p>Aucune demande de remise.</p>}
    </div>}
    {focus&&details&&<div className="atlas-domain-form"><div className="atlas-domain-form-heading">
      <h3>Historique et détail #{focus.id}</h3>
      <button type="button" className="button secondary" onClick={()=>{setFocus(null);setDetails(null)}}>Fermer</button></div>
      <dl>{Object.entries(details).filter(([key,value])=>!["snapshot","items","lines","versions"].includes(key)&&typeof value!=="object")
        .map(([key,value])=><div key={key}><dt>{key.replaceAll("_"," ")}</dt><dd>{format(key,value)}</dd></div>)}</dl>
      {["lines","items","versions"].map(key=>Array.isArray(details[key])?<div key={key}><h4>{key}</h4>
        <div className="table-scroll"><table><tbody>{(details[key] as Entry[]).map(x=><tr key={x.id}>{Object.entries(x).filter(([,value])=>typeof value!=="object")
          .map(([k,v])=><td key={k}><strong>{k}</strong> {format(k,v)}</td>)}</tr>)}</tbody></table></div>
      </div>:null)}
    </div>}
  </section>;
}
