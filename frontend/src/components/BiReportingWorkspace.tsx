"use client";

import { useEffect, useState, type FormEvent } from "react";
import { api, send, fetchTenantBlob } from "@/lib/api";

type Dataset = "sales" | "finance" | "stock" | "suppliers";
type View = "analytics-sales" | "analytics-finance" | "analytics-stock" | "analytics-suppliers" | "analytics-custom" | "analytics-exports";
type Analytics = { dataset: Dataset; from: string; to: string; cards: Record<string,number|null>; rows: Record<string,unknown>[]; columns: string[]; notes: string[]; row_limit: number; cutoff_at: string };
type Definition = { id:number; title:string; dataset:Dataset; columns:string; filters:string; version:number; report_uid:string; created_at:string };
type Export = { id:number; dataset:Dataset; row_count:number; classification:string; expires_at:string; sha256:string; period_from:string; period_to:string };
type Pagination<T> = { data:T[]; current_page:number; last_page:number };
const names:Record<Dataset,string>={sales:"Rapports commerciaux",finance:"Analyse financière",stock:"Analyse des stocks",suppliers:"Performance fournisseurs"};
const descriptions:Record<Dataset,string>={
  sales:"Devis, commandes et factures séparés. Le chiffre d’affaires provient des factures.",
  finance:"Factures et encours déclarés. Les écritures comptables et les paiements réels ne sont pas encore disponibles.",
  stock:"Stocks courants, mouvements et seuils. Ne pas confondre stock actuel et stock historique.",
  suppliers:"Référentiel fournisseurs. Les délais et réceptions ne sont pas encore modélisés.",
};
const textLabel:Record<string,string>={
  quotes_count:"Devis",validated_orders_count:"Commandes validées",issued_invoices_count:"Factures émises",
  revenue_ht_cents:"Chiffre d’affaires HT",revenue_ttc_cents:"Chiffre d’affaires TTC",
  gross_invoiced_ttc_cents:"Facturation brute TTC",outstanding_declared_ttc_cents:"Encours déclaré TTC",
  marked_paid_ttc_cents:"Factures marquées payées",credit_notes_ttc_cents:"Avoirs",
  net_revenue_ttc_cents:"Revenu net",actual_cash_ttc_cents:"Encaissements réels",
  margin_cents:"Marge",products_count:"Références",units_on_hand:"Unités en stock",
  low_stock_count:"Références sous seuil",movement_count_period:"Mouvements pendant la période",
  historical_stock_balance:"Stock historique",suppliers_count:"Fournisseurs",
  on_time_delivery_rate:"Livraisons ponctuelles",quality_acceptance_rate:"Conformité qualité",
  number:"Document",status:"État",name:"Libellé",reference:"Référence",category:"Catégorie",
  stock:"Stock",alert_threshold:"Seuil",subtotal_cents:"Montant HT",
  total_cents:"Montant TTC",created_at:"Création",issued_at:"Émission",paid_at:"Paiement",
};
const fmt=(key:string, value:unknown):string=>{
  if(value===null||value===undefined||value==="") return "Non disponible";
  if(key.endsWith("_cents"))return new Intl.NumberFormat("fr-FR",{style:"currency",currency:"EUR"}).format(Number(value)/100);
  return String(value);
};
const today=()=>{const d=new Date();return d.getFullYear()+"-"+String(d.getMonth()+1).padStart(2,"0")+"-"+String(d.getDate()).padStart(2,"0");};
const datasets:Dataset[]=["sales","finance","stock","suppliers"];

export default function BiReportingWorkspace({view,activeCompanyId}:{view:View;activeCompanyId:number|null}) {
  const [from,setFrom]=useState(()=>today().slice(0,8)+"01");
  const [to,setTo]=useState(today);
  const [data,setData]=useState<Analytics|null>(null);
  const [catalog,setCatalog]=useState<{dataset:Dataset;columns:string[]}[]>([]);
  const [definitions,setDefinitions]=useState<Definition[]>([]);
  const [exports,setExports]=useState<Export[]>([]);
  const [dataset,setDataset]=useState<Dataset>("sales");
  const [title,setTitle]=useState("");
  const [columns,setColumns]=useState<string[]>(["number","status","total_cents"]);
  const [busy,setBusy]=useState(false);
  const [error,setError]=useState("");
  const [notice,setNotice]=useState("");
  const [refresh,setRefresh]=useState(0);
  const selected:Dataset|null=view.startsWith("analytics-")&&!["analytics-custom","analytics-exports"].includes(view)?
    view.replace("analytics-","") as Dataset:null;
  useEffect(()=>{
    let valid=true;setError("");setData(null);
    if(!selected)return;
    api<Analytics>("analytics/"+selected+"?from="+from+"&to="+to).then(d=>{if(valid)setData(d);})
      .catch((e:Error)=>{if(valid)setError(e.message);});
    return()=>{valid=false;};
  },[selected,from,to,activeCompanyId,refresh]);
  useEffect(()=>{
    if(view!=="analytics-custom"&&view!=="analytics-exports")return;
    let valid=true;setError("");
    Promise.all([
      api<{dataset:Dataset;columns:string[]}[]>("analytics/catalog"),
      api<Pagination<Definition>>("analytics/reports"),
      api<Pagination<Export>>("analytics/exports"),
    ]).then(([c,d,e])=>{if(valid){setCatalog(c);setDefinitions(d.data);setExports(e.data);}})
      .catch((e:Error)=>{if(valid)setError(e.message);});
    return()=>{valid=false;};
  },[view,activeCompanyId,refresh]);
  useEffect(()=>{
    const published=catalog.find(c=>c.dataset===dataset)?.columns??[];
    setColumns(published.slice(0,Math.min(3,published.length)));
  },[dataset,catalog]);

  async function submitDefinition(e:FormEvent<HTMLFormElement>){
    e.preventDefault();if(busy)return;
    setBusy(true);setError("");setNotice("");
    try{
      const result=await send<{id:number;version:number}>("analytics/reports","POST",{
        title,dataset,columns,filters:{from,to},idempotency_key:crypto.randomUUID(),
      });
      setNotice("Rapport créé : version "+result.version+".");setTitle("");setRefresh(n=>n+1);
    }catch(e){setError((e as Error).message);}finally{setBusy(false);}
  }
  async function run(id:number){
    setBusy(true);setError("");
    try{
      const result=await send<Analytics>("analytics/reports/"+id+"/run","POST",{});
      setData(result);setNotice("Rapport exécuté depuis les pièces de la société active.");
    }catch(e){setError((e as Error).message);}finally{setBusy(false);}
  }
  async function makeExport(name:Dataset,definitionId?:number){
    setBusy(true);setError("");
    try{
      const result=await send<{id:number;row_count:number}>("analytics/exports","POST",{
        dataset:name,from,to,...(definitionId?{definition_id:definitionId}:{})
      });
      setNotice("Export n°"+result.id+" créé avec "+result.row_count+" lignes. Expiration après une heure.");
      setRefresh(n=>n+1);
    }catch(e){setError((e as Error).message);}finally{setBusy(false);}
  }
  async function download(id:number){
    setBusy(true);setError("");
    try{
      const blob=await fetchTenantBlob("analytics/exports/"+id+"/download","text/csv");
      const url=URL.createObjectURL(blob);
      const link=document.createElement("a");link.href=url;link.download="atlas-bi-"+id+".csv";
      document.body.appendChild(link);link.click();link.remove();URL.revokeObjectURL(url);
    }catch(e){setError((e as Error).message);}finally{setBusy(false);}
  }
  const published=catalog.find(c=>c.dataset===dataset)?.columns??[];
  return <section className="atlas-domain" aria-label="BI et reporting">
    <div className="atlas-domain-toolbar">
      <div>
        <h2>{selected?names[selected]:view==="analytics-custom"?"Rapports personnalisés":"Exports de données"}</h2>
        <p>{selected?descriptions[selected]:"Exécutions bornées, sources autorisées et historique d'accès."}</p>
        <small>Entreprise active uniquement · données serveur · les valeurs indisponibles ne sont pas estimées</small>
      </div>
      <div className="date-filter">
        <label>Du <input type="date" value={from} max={to} onChange={e=>setFrom(e.target.value)}/></label>
        <label>Au <input type="date" value={to} min={from} onChange={e=>setTo(e.target.value)}/></label>
      </div>
    </div>
    {error&&<p className="error" role="alert">{error}</p>}
    {notice&&<p className="notice" role="status">{notice}</p>}
    {selected&&!data&&!error&&<p role="status">Chargement des indicateurs…</p>}
    {selected&&data&&<ReportTable data={data}/>}
    {view==="analytics-custom"&&<>
      <form className="panel" onSubmit={e=>void submitDefinition(e)} style={{padding:20}}>
        <h3>Nouvelle définition de rapport</h3>
        <p>Les colonnes sont limitées au catalogue publié. Pas de requête SQL libre.</p>
        <div className="atlas-domain-fields">
          <label>Titre<input required minLength={3} maxLength={150} value={title} onChange={e=>setTitle(e.target.value)}/></label>
          <label>Source<select value={dataset} onChange={e=>setDataset(e.target.value as Dataset)}>
            {catalog.map(item=><option key={item.dataset} value={item.dataset}>{names[item.dataset]}</option>)}
          </select></label>
        </div>
        <fieldset style={{border:0,padding:0,marginBottom:16}}><legend>Champs visibles</legend>
          {published.map(column=><label key={column} style={{marginRight:16,display:"inline-block"}}>
            <input type="checkbox" checked={columns.includes(column)}
              onChange={e=>setColumns(old=>e.target.checked?[...old,column]:old.filter(c=>c!==column))}/>
            {" "+(textLabel[column]||column)}
          </label>)}
        </fieldset>
        <button className="button primary" type="submit" disabled={busy||!title.trim()||!columns.length||!published.length}>Enregistrer la définition</button>
      </form>
      <div className="panel" style={{padding:20,marginTop:20}}>
        <h3>Définitions versionnées</h3>
        {!definitions.length?<p>Aucun rapport enregistré.</p>:
          <table style={{width:"100%"}}><thead><tr><th scope="col">Rapport</th><th scope="col">Source</th><th scope="col">Version</th><th scope="col">Actions</th></tr></thead>
          <tbody>{definitions.map(d=><tr key={d.id}><td>{d.title}</td><td>{names[d.dataset]}</td><td>{d.version}</td>
            <td><button type="button" disabled={busy} onClick={()=>void run(d.id)}>Exécuter</button>{" "}
              <button type="button" disabled={busy} onClick={()=>void makeExport(d.dataset,d.id)}>Exporter</button></td></tr>)}</tbody></table>}
      </div>
      {data&&<ReportTable data={data}/>}
    </>}
    {view==="analytics-exports"&&<div className="panel" style={{padding:20}}>
      <h3>Créer un export</h3>
      <label>Rapport<select value={dataset} onChange={e=>setDataset(e.target.value as Dataset)}>
        {catalog.map(item=><option key={item.dataset} value={item.dataset}>{names[item.dataset]}</option>)}
      </select></label>{" "}
      <button type="button" className="button primary" disabled={busy||!catalog.length} onClick={()=>void makeExport(dataset)}>Générer le CSV</button>
      <h3 style={{marginTop:24}}>Exports personnels</h3>
      <p>Chaque export est figé, signé SHA-256, lié à votre entreprise et à votre compte. Les droits sont revérifiés au téléchargement.</p>
      {!exports.length?<p>Aucun export enregistré.</p>:
        <table style={{width:"100%"}}><thead><tr><th scope="col">Source</th><th scope="col">Lignes</th><th scope="col">Expiration</th><th scope="col">Téléchargement</th></tr></thead>
        <tbody>{exports.map(e=><tr key={e.id}><td>{names[e.dataset]}</td><td>{e.row_count}</td><td>{new Date(e.expires_at).toLocaleString("fr-FR")}</td>
          <td><button type="button" disabled={busy||new Date(e.expires_at).getTime()<=Date.now()} onClick={()=>void download(e.id)}>CSV n°{e.id}</button></td>
        </tr>)}</tbody></table>}
    </div>}
  </section>;
}
function ReportTable({data}:{data:Analytics}){
  return <>
    <div className="stats-grid">{Object.entries(data.cards).map(([key,value])=>
      <article className="stat-card" key={key}><span>{textLabel[key]||key}</span><strong>{fmt(key,value)}</strong></article>)}</div>
    <div className="panel" style={{padding:20,marginTop:20,overflowX:"auto"}}>
      <h3>Détail contrôlable · {data.rows.length} lignes affichées (limite {data.row_limit})</h3>
      {!data.rows.length?<p>Aucune donnée sur ce périmètre.</p>:
        <table style={{width:"100%"}}><thead><tr>{data.columns.map(c=><th key={c} scope="col">{textLabel[c]||c}</th>)}</tr></thead>
          <tbody>{data.rows.map((row,i)=><tr key={i}>{data.columns.map(c=><td key={c}>{fmt(c,row[c])}</td>)}</tr>)}</tbody></table>}
    </div>
    <div className="panel" style={{padding:20,marginTop:20}}>
      <h3>Traçabilité et limites</h3>
      <p>Date de coupe : {new Date(data.cutoff_at).toLocaleString("fr-FR")}</p>
      <ul>{data.notes.map(note=><li key={note}>{note}</li>)}</ul>
    </div>
  </>;
}
