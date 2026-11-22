"use client";

import {useEffect,useState,type FormEvent} from "react";
import {api,send,all} from "@/lib/api";
import type {Page} from "@/lib/types";

type RecordRow = Record<string,unknown> & {id:number;status?:string};
type Field = {key:string;label:string;required?:boolean;type?:"number"|"date"|"select"|"textarea";lookup?:string;options?:string[]};
const f=(key:string,label:string,required=false,type?:Field["type"],lookup?:string,options?:string[]):Field=>({key,label,required,type,lookup,options});

const screens:Record<string,{title:string;endpoint:string;columns:string[];fields:Field[];description:string}>={
  suppliers:{
    title:"Fournisseurs",endpoint:"purchasing/suppliers",
    description:"Référentiel, blocage et validation indépendante des coordonnées bancaires.",
    columns:["name","email","tax_registration","procurement_status","blocked_reason"],
    fields:[f("name","Nom",true),f("email","Email",true),f("tax_registration","Identifiant fiscal"),f("phone","Téléphone"),f("address","Adresse")]
  },
  "purchase-requests":{
    title:"Demandes d’achat",endpoint:"purchasing/requests",
    description:"Approbation à quatre yeux, révision et lignes d’achat historisées.",
    columns:["reference","title","estimated_total_cents","currency","revision","status"],
    fields:[f("reference","Référence",true),f("title","Objet",true),
      f("justification","Justification",false,"textarea"),f("estimated_total_cents","Budget estimé (centimes)",true,"number"),
      f("currency","Devise",true,"select",undefined,["EUR","USD","GBP"])]
  },
  "supplier-quotes":{
    title:"Consultations fournisseurs",endpoint:"purchasing/quotes",
    description:"Comparaison des offres, validité, livraison et attribution motivée.",
    columns:["reference","purchase_request_id","supplier_id","total_cents","currency","delivery_days","valid_until","status"],
    fields:[f("purchase_request_id","Demande approuvée",true,"select","purchasing/requests"),
      f("supplier_id","Fournisseur",true,"select","purchasing/suppliers"),
      f("reference","Référence du devis",true),f("currency","Devise",true,"select",undefined,["EUR","USD","GBP"]),
      f("subtotal_cents","Montant HT (centimes)",true,"number"),
      f("tax_cents","TVA (centimes)",true,"number"),
      f("shipping_cents","Frais de livraison (centimes)",false,"number"),
      f("delivery_days","Délai de livraison (jours)",true,"number"),
      f("valid_until","Date de validité",true,"date")]
  },
  "purchase-orders":{
    title:"Commandes fournisseurs",endpoint:"purchasing/orders",
    description:"Création depuis une offre attribuée ; verrouillage de la commande envoyée.",
    columns:["reference","purchase_request_id","supplier_id","total_cents","currency","status","sent_at"],
    fields:[f("reference","Référence de commande",true),
      f("supplier_quote_id","Offre attribuée",true,"select","purchasing/quotes")]
  },
  "purchase-receipts":{
    title:"Réceptions fournisseurs",endpoint:"purchasing/receipts",
    description:"Livraisons partielles, refus de non-qualité et mouvements de stock des unités acceptées.",
    columns:["reference","purchase_order_id","received_at","received_by"],
    fields:[f("reference","Bon de réception",true),
      f("purchase_order_id","Commande envoyée",true,"select","purchasing/orders")]
  },
  "supplier-invoices":{
    title:"Factures fournisseurs",endpoint:"purchasing/invoices",
    description:"Rapprochement trois voies et blocage du bon à payer en cas d’écart.",
    columns:["reference","purchase_order_id","supplier_id","total_cents","matched_cents","dispute_cents","status"],
    fields:[f("purchase_order_id","Commande fournisseur",true,"select","purchasing/orders"),
      f("reference","Numéro de facture",true),f("total_cents","Montant TTC (centimes)",true,"number")]
  }
};
type Line = {description:string;line_type:string;quantity:string;unit_price_cents:string;vat_bps:string;product_id:string;
  purchase_order_line_id:string;received_quantity:string;accepted_quantity:string;rejected_quantity:string};
const blankLine=():Line=>({description:"",line_type:"goods",quantity:"1",unit_price_cents:"0",vat_bps:"0",product_id:"",
  purchase_order_line_id:"",received_quantity:"1",accepted_quantity:"1",rejected_quantity:"0"});
const label:Record<string,string>={
  reference:"Référence",name:"Nom",email:"Email",tax_registration:"Identifiant fiscal",procurement_status:"Statut",
  blocked_reason:"Motif de blocage",title:"Objet",estimated_total_cents:"Budget (centimes)",
  total_cents:"Total (centimes)",matched_cents:"Rapproché (centimes)",dispute_cents:"Litige (centimes)",
  purchase_request_id:"Demande",supplier_id:"Fournisseur",purchase_order_id:"Commande",
  delivery_days:"Délai (jours)",valid_until:"Validité",received_at:"Réception",status:"Statut"
};
const asText=(x:unknown)=>x==null?"—":String(x);
const number=(value:string)=>Number(value);
const money=(n:unknown,currency="EUR")=>new Intl.NumberFormat("fr-FR",{style:"currency",currency}).format(Number(n??0)/100);

export default function PurchasingWorkspace({view,activeCompanyId}:{view:string;activeCompanyId:number|null}) {
  const screen=screens[view];
  const [rows,setRows]=useState<RecordRow[]>([]);
  const [lookups,setLookups]=useState<Record<string,RecordRow[]>>({});
  const [page,setPage]=useState(1),[pages,setPages]=useState(1),[total,setTotal]=useState(0);
  const [loading,setLoading]=useState(true),[busy,setBusy]=useState(false),[refresh,setRefresh]=useState(0);
  const [error,setError]=useState(""),[notice,setNotice]=useState(""),[search,setSearch]=useState("");
  const [editing,setEditing]=useState(false),[values,setValues]=useState<Record<string,string>>({currency:"EUR"});
  const [lines,setLines]=useState<Line[]>([blankLine()]);
  const [editingRequestLines,setEditingRequestLines]=useState<number|null>(null);
  const [details,setDetails]=useState<{id:number;label:string;rows:RecordRow[]}|null>(null);
  const [comparison,setComparison]=useState<{purchase_request_id:number;currency:string;offers:RecordRow[]}|null>(null);
  useEffect(()=>{
    let live=true;setLoading(true);setError("");setDetails(null);
    api<Page<RecordRow>>(screen.endpoint+"?page="+page).then(p=>{if(live){setRows(p.data);setTotal(p.total);setPages(p.last_page);}})
      .catch((e:Error)=>{if(live)setError(e.message);}).finally(()=>{if(live)setLoading(false);});
    return()=>{live=false;};
  },[screen,page,refresh,activeCompanyId]);

  useEffect(()=>{
    let live=true;
    const paths=[...new Set(screen.fields.map(x=>x.lookup).filter((x):x is string=>Boolean(x)))];
    Promise.all(paths.map(async path=>[path,await all<RecordRow>(path)] as const))
      .then(result=>{if(live)setLookups(Object.fromEntries(result));})
      .catch((e:Error)=>{if(live)setError(e.message);});
    return()=>{live=false;};
  },[screen,refresh,activeCompanyId]);

  function openCreate() {
    setValues({currency:"EUR",delivery_days:"0",shipping_cents:"0"});
    setLines([blankLine()]);setEditingRequestLines(null);setComparison(null);setError("");setEditing(true);
  }
  function replaceLine(i:number,key:keyof Line,value:string) {
    setLines(current=>current.map((line,k)=>k===i?{...line,[key]:value}:line));
  }
  const reload=()=>{setRefresh(n=>n+1);setPage(1);};
  async function perform(path:string,data:unknown,method="POST") {
    if(busy)return;
    setBusy(true);setError("");setNotice("");
    try{await send(path,method,data);setNotice("Opération enregistrée et contrôlée côté serveur.");reload();setEditing(false);setDetails(null);}
    catch(e){setError((e as Error).message);}finally{setBusy(false);}
  }
  async function save(e:FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const payload:Record<string,unknown>={};
    for(const field of screen.fields) {
      const value=values[field.key]??"";
      if(!value&&!field.required)continue;
      payload[field.key]=field.type==="number"||field.lookup?number(value):value;
    }
    if(view==="purchase-orders") {
      payload.lines=lines.map(l=>({
        description:l.description,line_type:l.line_type,quantity:number(l.quantity),unit_price_cents:number(l.unit_price_cents),
        vat_bps:number(l.vat_bps),...(l.product_id?{product_id:number(l.product_id)}:{})
      }));
    }
    if(view==="purchase-receipts") {
      payload.lines=lines.map(l=>({
        purchase_order_line_id:number(l.purchase_order_line_id),
        received_quantity:number(l.received_quantity),accepted_quantity:number(l.accepted_quantity),
        rejected_quantity:number(l.rejected_quantity)
      }));
    }
    await perform(view==="suppliers"?"suppliers":screen.endpoint,payload);
  }
  async function editRequestLines(id:number) {
    setError("");try {
      const current=await all<RecordRow>("purchasing/requests/"+id+"/lines");
      setLines(current.length?current.map(r=>({...blankLine(),description:String(r.description??""),
        quantity:String(r.quantity??1),unit_price_cents:String(r.unit_price_cents??0),
        vat_bps:String(r.vat_bps??0)})):[blankLine()]);
      setEditingRequestLines(id);
      setEditing(false);
    }catch(e){setError((e as Error).message);}
  }
  async function saveRequestLines(event:FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if(editingRequestLines===null)return;
    const payload={lines:lines.map(l=>({description:l.description,quantity:number(l.quantity),
      unit_price_cents:number(l.unit_price_cents),vat_bps:number(l.vat_bps)}))};
    await perform("purchasing/requests/"+editingRequestLines+"/lines",payload,"PUT");
    setEditingRequestLines(null);
  }
  async function compareQuotes(requestId:number) {
    setError("");
    try {
      const data=await api<{purchase_request_id:number;currency:string;offers:RecordRow[]}>(
        "purchasing/requests/"+requestId+"/quotes/compare");
      setComparison(data);setDetails(null);
    }catch(e){setError((e as Error).message);}
  }
  function requestAmendment(orderId:number) {
    const line=window.prompt("Identifiant de la ligne (consultez Lignes)");
    if(!line)return;
    const qty=window.prompt("Nouvelle quantité totale (ne peut pas augmenter)");
    if(!qty)return;
    const reason=window.prompt("Justification de l’avenant (minimum 10 caractères)");
    if(!reason)return;
    void perform("purchasing/orders/"+orderId+"/amendments",{
      purchase_order_line_id:Number(line),new_quantity:Number(qty),reason:reason.trim()
    });
  }
  function acceptService(orderId:number) {
    const line=window.prompt("Identifiant de la ligne de prestation (voir Lignes)");
    if(!line)return;
    const reference=window.prompt("Référence unique du procès-verbal");
    if(!reference)return;
    const qty=window.prompt("Quantité de service acceptée");
    if(!qty)return;
    const evidence_reference=window.prompt("Référence du justificatif signé");
    if(!evidence_reference)return;
    void perform("purchasing/orders/"+orderId+"/services",{
      purchase_order_line_id:Number(line),reference,accepted_quantity:Number(qty),evidence_reference
    });
  }
  async function fetchLines(path:string,title:string,id:number) {
    setError("");try{const data=await all<RecordRow>(path);setDetails({label:title,id,rows:data});}
    catch(e){setError((e as Error).message);}
  }
  function action(labelText:string,call:()=>void) {
    return <button key={labelText} type="button" className="button secondary" disabled={busy}
      onClick={call}>{labelText}</button>;
  }
  function ask(path:string,key:string,promptText:string,min=1) {
    const answer=window.prompt(promptText);
    if(answer===null)return;
    if(answer.trim().length<min){setError("Saisie insuffisante.");return;}
    void perform(path,{[key]:answer.trim()});
  }
  function rowActions(row:RecordRow) {
    const id=row.id,status=row.status??"";
    if(view==="suppliers") return <>
      {row.procurement_status==="active"&&action("Bloquer",()=>{const reason=window.prompt("Motif du blocage");if(reason?.trim())void perform("purchasing/suppliers/"+id+"/status",{status:"blocked",reason:reason.trim()});})}
      {row.procurement_status==="blocked"&&action("Activer",()=>void perform("purchasing/suppliers/"+id+"/status",{status:"active"}))}
      {row.procurement_status!=="archived"&&action("Archiver",()=>{if(window.confirm("Archiver ce fournisseur ?"))void perform("purchasing/suppliers/"+id+"/status",{status:"archived"});})}
      {row.procurement_status==="active"&&action("IBAN : demande",()=>ask("purchasing/suppliers/"+id+"/bank-changes","iban","Nouvel IBAN (il sera chiffré côté serveur)",15))}
      {action("IBAN : décisions",()=>void fetchLines("purchasing/suppliers/"+id+"/bank-changes","Demandes bancaires",id))}
    </>;
    if(view==="purchase-requests")return <>
      {status==="draft"&&action("Soumettre",()=>void perform("purchasing/requests/"+id+"/submit",{}))}
      {status==="submitted"&&action("Approuver",()=>void perform("purchasing/requests/"+id+"/approve",{}))}
      {status==="submitted"&&action("Refuser",()=>ask("purchasing/requests/"+id+"/reject","reason","Motif de refus",5))}
      {action("Lignes",()=>void fetchLines("purchasing/requests/"+id+"/lines","Lignes de demande",id))}
      {["draft","submitted","rejected","approved"].includes(status)&&action("Réviser les lignes",()=>void editRequestLines(id))}
      {action("Historique",()=>void fetchLines("purchasing/requests/"+id+"/history","Historique de demande",id))}
    </>;
    if(view==="supplier-quotes")return <>
      {action("Comparer les offres",()=>void compareQuotes(Number(row.purchase_request_id)))}
      {status==="received"&&action("Attribuer / ré-arbitrer",()=>ask("purchasing/quotes/"+id+"/award","reason","Justification du choix (10 caractères minimum)",10))}
      {action("Historique des décisions",()=>void fetchLines("purchasing/requests/"+row.purchase_request_id+"/awards","Arbitrages fournisseurs",Number(row.purchase_request_id)))}
    </>;
    if(view==="purchase-orders")return <>
      {action("Lignes",()=>void fetchLines("purchasing/orders/"+id+"/lines","Lignes de commande",id))}
      {action("Avenants",()=>void fetchLines("purchasing/orders/"+id+"/amendments","Avenants de commande",id))}
      {["sent","confirmed"].includes(status)&&action("Proposer avenant",()=>requestAmendment(id))}
      {["sent","confirmed"].includes(status)&&action("Signer service fait",()=>acceptService(id))}
      {action("Services faits",()=>void fetchLines("purchasing/orders/"+id+"/services","Prestations signées",id))}
      {status==="draft"&&action("Envoyer",()=>void perform("purchasing/orders/"+id+"/transition",{action:"send"}))}
      {status==="sent"&&action("Confirmer",()=>void perform("purchasing/orders/"+id+"/transition",{action:"confirm"}))}
      {["sent","confirmed"].includes(status)&&action("Annuler reliquat",()=> {
        const reason=window.prompt("Motif d’annulation du reliquat");
        if(reason?.trim())void perform("purchasing/orders/"+id+"/transition",{action:"cancel_remainder",reason:reason.trim()});
      })}
    </>;
    if(view==="purchase-receipts")return action("Lignes",()=>void fetchLines("purchasing/receipts/"+id+"/lines","Lignes réceptionnées",id));
    if(view==="supplier-invoices")return <>
      {action("Rapprocher à nouveau",()=>void perform("purchasing/invoices/"+id+"/rematch",{}))}
      {action("Bon à payer",()=>{if(window.confirm("Autoriser uniquement le montant net rapproché, sans déclencher de virement ?"))void perform("purchasing/invoices/"+id+"/authorize-payment",{});})}
      {action("Historique bons à payer",()=>void fetchLines("purchasing/invoices/"+id+"/authorizations","Bons à payer autorisés",id))}
      {action("Avoirs",()=>void fetchLines("purchasing/invoices/"+id+"/credits","Avoirs de la facture",id))}
      {action("Créer avoir",()=>{
        const reference=window.prompt("Référence unique de l’avoir");
        if(!reference)return;
        const amount=window.prompt("Montant de l’avoir en centimes");
        if(!amount)return;
        void perform("purchasing/invoices/"+id+"/credits",{reference,amount_cents:Number(amount)});
      })}
    </>;
    return null;
  }
  const matches=rows.filter(r=>!search||screen.columns.some(k=>asText(r[k]).toLowerCase().includes(search.toLowerCase())));
  return <section className="atlas-domain">
    <div className="atlas-domain-toolbar">
      <div><h2>{screen.title}</h2><p>{screen.description}</p></div>
      <button className="button primary" type="button" onClick={openCreate}>+ Nouvelle fiche</button>
    </div>
    {notice&&<p role="status" className="notice">{notice}</p>}
    {error&&<p role="alert" className="error">{error}</p>}
    {editingRequestLines!==null&&<form className="atlas-domain-form" onSubmit={e=>void saveRequestLines(e)}>
      <div className="atlas-domain-form-heading"><h3>Révision des lignes — demande #{editingRequestLines}</h3>
        <button type="button" className="button secondary" onClick={()=>setEditingRequestLines(null)}>Fermer</button>
      </div>
      <p>Une révision remet la demande en brouillon et invalide l’approbation précédente.</p>
      {lines.map((line,i)=><div key={i} className="atlas-domain-fields">
        {(["description","quantity","unit_price_cents","vat_bps"] as const).map(key=>
          <label key={key}><span>{({description:"Description",quantity:"Quantité",unit_price_cents:"Prix unitaire (centimes)",vat_bps:"TVA (points de base)"})[key]}</span>
            <input type={key==="description"?"text":"number"} min={key==="quantity"?1:0} required
              value={line[key]} onChange={e=>replaceLine(i,key,e.target.value)}/></label>)}
        <button type="button" className="button secondary" disabled={lines.length<=1}
          onClick={()=>setLines(ls=>ls.filter((_,j)=>i!==j))}>Supprimer</button>
      </div>)}
      <button type="button" className="button secondary" onClick={()=>setLines(ls=>[...ls,blankLine()])}>Ajouter une ligne</button>
      <button type="submit" className="button primary" disabled={busy}>Enregistrer la nouvelle révision</button>
    </form>}
    {editing&&<form className="atlas-domain-form" onSubmit={e=>void save(e)}>
      <div className="atlas-domain-form-heading"><h3>Nouvelle opération</h3>
        <button type="button" className="button secondary" onClick={()=>setEditing(false)}>Fermer</button>
      </div>
      <div className="atlas-domain-fields">{screen.fields.map(field=><label key={field.key}>
        <span>{field.label}{field.required?" *":""}</span>
        {field.type==="select"?<select required={field.required} value={values[field.key]??""}
          onChange={e=>setValues(v=>({...v,[field.key]:e.target.value}))}>
          <option value="">Sélectionner…</option>
          {field.lookup?(lookups[field.lookup]??[]).filter(r=>{
            if(field.lookup==="purchasing/requests")return r.status==="approved";
            if(field.lookup==="purchasing/quotes")return r.status==="awarded";
            if(field.lookup==="purchasing/orders")return ["sent","confirmed"].includes(r.status??"");
            if(field.lookup==="purchasing/suppliers")return r.procurement_status==="active";
            return true;
          }).map(r=><option key={r.id} value={r.id}>#{r.id} — {asText(r.reference??r.name)} {r.total_cents!=null?money(r.total_cents,String(r.currency??"EUR")):""}</option>):
            (field.options??[]).map(value=><option key={value} value={value}>{value}</option>)}
        </select>:field.type==="textarea"?<textarea value={values[field.key]??""} rows={3}
          onChange={e=>setValues(v=>({...v,[field.key]:e.target.value}))}/>:
          <input type={field.type==="date"?"date":field.type==="number"?"number":"text"}
            required={field.required} min={field.type==="number"?0:undefined}
            value={values[field.key]??""} onChange={e=>setValues(v=>({...v,[field.key]:e.target.value}))}/>}
      </label>)}</div>
      {view==="purchase-orders"&&<div>
        <h3>Lignes de commande</h3>
        {lines.map((line,i)=><div key={i} className="atlas-domain-fields">
          <label><span>Nature de la ligne</span>
            <select value={line.line_type} onChange={e=>replaceLine(i,"line_type",e.target.value)}>
              <option value="goods">Article / bien</option><option value="service">Prestation / service fait</option>
            </select></label>
          {(["description","quantity","unit_price_cents","vat_bps","product_id"] as const).map(key=>
            <label key={key}><span>{({description:"Description",quantity:"Quantité",unit_price_cents:"Prix unitaire (centimes)",vat_bps:"TVA (points de base)",product_id:"Produit (ID, facultatif)"})[key]}</span>
              <input required={key!=="product_id"} type={key==="description"?"text":"number"}
                min={key==="quantity"?1:0} value={line[key]}
                onChange={e=>replaceLine(i,key,e.target.value)}/></label>)}
          <button type="button" className="button secondary" onClick={()=>setLines(l=>l.filter((_,j)=>j!==i))} disabled={lines.length<=1}>Supprimer la ligne</button>
        </div>)}
        <button type="button" className="button secondary" onClick={()=>setLines(l=>[...l,blankLine()])}>Ajouter une ligne</button>
      </div>}
      {view==="purchase-receipts"&&<div>
        <h3>Quantités livrées</h3>
        <button type="button" className="button secondary" onClick={async()=>{
          const id=Number(values.purchase_order_id);
          if(!id){setError("Sélectionnez d’abord une commande.");return;}
          try{const data=await all<RecordRow>("purchasing/orders/"+id+"/lines");
            setLines(data.map(item=>({...blankLine(),purchase_order_line_id:String(item.id),
              description:String(item.description),received_quantity:String(Number(item.quantity)-Number(item.received_quantity)),
              accepted_quantity:String(Number(item.quantity)-Number(item.received_quantity))})).filter(l=>Number(l.received_quantity)>0));
          }catch(e){setError((e as Error).message);}
        }}>Charger les reliquats de la commande</button>
        {lines.map((line,i)=><div key={i} className="atlas-domain-fields">
          {(["purchase_order_line_id","received_quantity","accepted_quantity","rejected_quantity"] as const).map(key=>
            <label key={key}><span>{({purchase_order_line_id:"Ligne commande ID",received_quantity:"Reçues",accepted_quantity:"Acceptées",rejected_quantity:"Rejetées"})[key]}</span>
              <input type="number" required min={key==="received_quantity"?1:0} value={line[key]}
                onChange={e=>replaceLine(i,key,e.target.value)}/></label>)}
          <button type="button" className="button secondary" disabled={lines.length<=1} onClick={()=>setLines(l=>l.filter((_,j)=>j!==i))}>Supprimer</button>
        </div>)}
        <button className="button secondary" type="button" onClick={()=>setLines(l=>[...l,blankLine()])}>Ajouter une ligne</button>
      </div>}
      <button className="button primary" type="submit" disabled={busy}>{busy?"Enregistrement…":"Enregistrer"}</button>
    </form>}
    {comparison&&<div className="atlas-domain-form">
      <div className="atlas-domain-form-heading"><h3>Comparaison des offres — demande #{comparison.purchase_request_id}</h3>
        <button className="button secondary" type="button" onClick={()=>setComparison(null)}>Fermer</button>
      </div>
      <p>Montants TTC, frais de livraison compris, en {comparison.currency}. Les devises différentes ne sont pas classées sans taux officiel validé.</p>
      <div className="table-scroll"><table><thead><tr><th>Fournisseur</th><th>Référence</th><th>Prix HT</th><th>Taxes</th><th>Livraison</th><th>Total TTC</th><th>Délai (jours)</th><th>Valable jusqu’au</th><th>Statut</th></tr></thead><tbody>
        {comparison.offers.map(offer=><tr key={offer.id}>
          <td>{asText(offer.supplier_name)}</td><td>{asText(offer.reference)}</td>
          <td>{money(offer.subtotal_cents,String(offer.currency))}</td>
          <td>{money(offer.tax_cents,String(offer.currency))}</td>
          <td>{money(offer.shipping_cents,String(offer.currency))}</td>
          <td>{money(offer.total_cents,String(offer.currency))}</td>
          <td>{asText(offer.delivery_days)}</td><td>{asText(offer.valid_until)}</td>
          <td>{offer.valid?"Valide":"Expirée"} · {asText(offer.status)}</td>
        </tr>)}
      </tbody></table></div>
    </div>}
    {details&&<div className="atlas-domain-form">
      <div className="atlas-domain-form-heading"><h3>{details.label} — #{details.id}</h3>
        <button type="button" className="button secondary" onClick={()=>setDetails(null)}>Fermer</button></div>
      {details.rows.length===0?<p>Aucune donnée.</p>:<div className="table-scroll"><table><thead>
        <tr>{Object.keys(details.rows[0]).filter(k=>k!=="snapshot"&&k!=="encrypted_iban").map(k=><th key={k}>{label[k]??k}</th>)}
        {["Demandes bancaires","Avenants de commande"].includes(details.label)&&<th>Validation</th>}</tr></thead><tbody>
        {details.rows.map(r=><tr key={r.id}>
          {Object.keys(details.rows[0]).filter(k=>k!=="snapshot"&&k!=="encrypted_iban").map(k=><td key={k}>{asText(r[k])}</td>)}
          {details.label==="Avenants de commande"&&<td>{r.status==="pending"&&<>
            {action("Approuver",()=>void perform("purchasing/amendments/"+r.id+"/decision",{decision:"approved"}))}
            {action("Refuser",()=>void perform("purchasing/amendments/"+r.id+"/decision",{decision:"rejected"}))}
          </>}</td>}
          {details.label==="Demandes bancaires"&&<td>{r.status==="pending"&&<>
            {action("Approuver",()=>void perform("purchasing/bank-changes/"+r.id+"/decision",{decision:"approved"}))}
            {action("Refuser",()=>{const reason=window.prompt("Motif du refus");if(reason)void perform("purchasing/bank-changes/"+r.id+"/decision",{decision:"rejected",reason});})}
          </>}</td>}
        </tr>)}</tbody></table></div>}
    </div>}
    <div className="atlas-domain-list">
      <div className="atlas-domain-list-heading"><span>{total} enregistrement(s)</span>
        <input type="search" aria-label="Filtrer la page" placeholder="Rechercher sur la page" value={search} onChange={e=>setSearch(e.target.value)}/></div>
      <div className="table-scroll"><table><thead><tr>{screen.columns.map(k=><th key={k}>{label[k]??k}</th>)}<th>Actions</th></tr></thead><tbody>
        {matches.map(row=><tr key={row.id}>{screen.columns.map(k=><td key={k}>{k.endsWith("_cents")?money(row[k],String(row.currency??"EUR")):asText(row[k])}</td>)}
          <td><div className="atlas-domain-actions">{rowActions(row)}</div></td></tr>)}
      </tbody></table></div>
      {loading?<p className="atlas-domain-empty">Chargement…</p>:matches.length===0?<p className="atlas-domain-empty">Aucune donnée sur cette page.</p>:null}
      <div className="atlas-domain-pagination">
        <button type="button" className="button secondary" disabled={page<=1||loading} onClick={()=>setPage(p=>p-1)}>Précédent</button>
        <span>Page {page} / {Math.max(1,pages)}</span>
        <button type="button" className="button secondary" disabled={page>=pages||loading} onClick={()=>setPage(p=>p+1)}>Suivant</button>
      </div>
    </div>
  </section>;
}
