"use client";

import {useCallback,useEffect,useMemo,useState,type FormEvent} from "react";
import {api,send,fetchTenantBlob} from "@/lib/api";
import type {Page} from "@/lib/types";

/**
 * M06 uses explicit server routes only. All sums, approvals, status changes,
 * currency checks and the active company are validated by Laravel.
 */
type RecordRow={id:number;status?:string;[key:string]:unknown};
type Field={key:string;label:string;kind?:"text"|"number"|"date"|"select"|"textarea";required?:boolean;options?:string[];source?:string};
type Config={title:string;description:string;endpoint:string;columns:string[];fields:Field[];newLabel?:string};
const f=(key:string,label:string,required=true,kind:Field["kind"]="text",source?:string,options?:string[]):Field=>({key,label,kind,required,source,options});
const currency=f("currency","Devise",true,"select",undefined,["EUR","USD","GBP"]);
const money=f("amount_cents","Montant (centimes)",true,"number");
const requestKey=()=>crypto.randomUUID().replace(/-/g,"");
const config:Record<string,Config>={
  "credit-notes":{title:"Avoirs",description:"Avoirs motivés, plafonnés au solde éligible ; facture émise préservée.",endpoint:"finance/credit-notes",columns:["number","invoice_id","amount_cents","currency","reason","issued_at"],fields:[f("invoice_id","Facture",true,"select","invoices"),money,f("reason","Justification",true,"textarea")]},
  "collections":{title:"Encaissements",description:"Versements enregistrés puis affectés explicitement aux factures. Aucun double rapprochement.",endpoint:"finance/collections",columns:["id","reference","amount_cents","currency","method","received_on"],fields:[money,currency,f("received_on","Date de réception",true,"date"),f("method","Moyen",true,"select",undefined,["bank","cash","card","transfer"]),f("reference","Référence",false),f("external_event_id","Identifiant paiement externe",false)]},
  "payment-reminders":{title:"Relances impayées",description:"Relances préparées localement uniquement : pas d'envoi e-mail automatisé sans connecteur validé.",endpoint:"finance/payment-reminders",columns:["invoice_id","outstanding_cents","channel","reminder_date","status","template_version"],fields:[f("invoice_id","Facture échue",true,"select","invoices"),f("channel","Canal",true,"select",undefined,["email","letter","phone"])]},
  "expenses":{title:"Dépenses",description:"Dépenses avec justificatif identifié, centre de coûts et approbation par un autre acteur.",endpoint:"finance/expenses",columns:["id","supplier_name","category","cost_center_id","amount_cents","status"],fields:[f("cost_center_id","Centre de coûts",true,"select","finance/cost-centers"),f("supplier_name","Fournisseur"),f("category","Catégorie"),f("receipt_reference","Référence justificatif"),f("receipt_digest","Empreinte SHA-256 du justificatif"),f("expense_date","Date",true,"date"),money,currency]},
  "budgets":{title:"Budgets",description:"Versions par exercice et centre de coûts, approbation séparée, suivi des consommés et engagés.",endpoint:"finance/budgets",columns:["id","cost_center_id","fiscal_year","revision","limit_cents","status"],fields:[f("cost_center_id","Centre de coûts",true,"select","finance/cost-centers"),f("fiscal_year","Exercice",true,"number"),f("limit_cents","Plafond (centimes)",true,"number"),f("forecast_cents","Prévision (centimes)",false,"number"),currency,f("revision_reason","Motif si nouvelle version",false,"textarea")]},
  "accounting-exports":{title:"Exports comptables",description:"Instantanés déterministes de périodes clôturées, débit = crédit, traçabilité par checksum.",endpoint:"finance/accounting-exports",columns:["id","period_id","format","row_count","debit_cents","credit_cents","checksum"],fields:[f("period_id","Période clôturée",true,"select","finance/periods")]},
};
const extra:Record<string,Config>={
  "finance/cost-centers":{title:"Centres de coûts",description:"Référentiel budgétaire.",endpoint:"finance/cost-centers",columns:["code","name","active"],fields:[f("code","Code"),f("name","Nom")]},
  "finance/disputes":{title:"Litiges",description:"Litiges approuvés suspendant les relances.",endpoint:"finance/disputes",columns:["invoice_id","status","reason","requested_by","decided_at"],fields:[f("invoice_id","Facture",true,"select","invoices"),f("reason","Motif",true,"textarea")]},
  "finance/allocations":{title:"Affectations de paiements",description:"Historique des encaissements ventilés.",endpoint:"finance/allocations",columns:["receipt_id","invoice_id","amount_cents","created_at"],fields:[]},
  "finance/accounts":{title:"Plan comptable",description:"Comptes internes mappés.",endpoint:"finance/accounts",columns:["code","name","active"],fields:[f("code","Code compte"),f("name","Libellé")]},
  "finance/journals":{title:"Journaux",description:"Journaux d'écritures comptables.",endpoint:"finance/journals",columns:["code","name"],fields:[f("code","Code"),f("name","Libellé")]},
  "finance/periods":{title:"Périodes et clôtures",description:"Périodes sans chevauchement et verrouillées à la clôture.",endpoint:"finance/periods",columns:["code","starts_on","ends_on","status"],fields:[f("code","Code période"),f("starts_on","Début",true,"date"),f("ends_on","Fin",true,"date")]},
  "finance/entries":{title:"Écritures de journal",description:"Débit/crédit équilibrés, approbation distincte et période ouverte.",endpoint:"finance/entries",columns:["reference","entry_date","journal_id","period_id","status"],fields:[f("journal_id","Journal",true,"select","finance/journals"),f("period_id","Période",true,"select","finance/periods"),f("entry_date","Date",true,"date"),f("reference","Référence"),f("description","Libellé")]},
  "finance/tax-rates":{title:"Taxes versionnées",description:"Barèmes par juridiction et dates d'effet.",endpoint:"finance/tax-rates",columns:["jurisdiction","tax_code","rate_bps","effective_from","effective_to"],fields:[f("jurisdiction","Juridiction"),f("tax_code","Code taxe"),f("rate_bps","Taux (points de base)",true,"number"),f("effective_from","Début",true,"date"),f("effective_to","Fin",false,"date")]},
  "finance/fx-rates":{title:"Cours de change",description:"Taux datés ; aucune conversion implicite entre devises.",endpoint:"finance/fx-rates",columns:["base_currency","quote_currency","rate","effective_on","source"],fields:[f("base_currency","Base",true,"select",undefined,["EUR","USD","GBP"]),f("quote_currency","Cotation",true,"select",undefined,["EUR","USD","GBP"]),f("rate","Taux",true),f("effective_on","Date",true,"date"),f("source","Source")]},
};
const sections:Record<string,string[]>={
  "credit-notes":["finance/disputes"],
  "collections":["finance/allocations"],
  "payment-reminders":[],
  "expenses":["finance/cost-centers"],
  "budgets":["finance/cost-centers"],
  "accounting-exports":["finance/entries","finance/accounts","finance/journals","finance/periods","finance/tax-rates","finance/fx-rates"]
};
const show=(v:unknown):string=>v===null||v===undefined?"—":typeof v==="boolean"?(v?"Oui":"Non"):typeof v==="object"?"—":String(v);
const currencyFormat=(cents:unknown,currencyCode="EUR")=>new Intl.NumberFormat("fr-FR",{style:"currency",currency:currencyCode}).format(Number(cents??0)/100);
const isCent=(name:string)=>name.endsWith("_cents");
const hasKey=(endpoint:string)=>["finance/credit-notes","finance/collections","finance/payment-reminders","finance/expenses","finance/entries","finance/accounting-exports"].includes(endpoint);

export default function FinanceWorkspace({view,activeCompanyId,canWrite}:{view:string;activeCompanyId:number|null;canWrite:boolean}){
  const [section,setSection]=useState(view);
  const [data,setData]=useState<Page<RecordRow>|null>(null);
  const [loading,setLoading]=useState(false);
  const [saving,setSaving]=useState(false);
  const [error,setError]=useState("");
  const [notice,setNotice]=useState("");
  const [page,setPage]=useState(1);
  const [status,setStatus]=useState("");
  const [reload,setReload]=useState(0);
  const [form,setForm]=useState(false);
  const [values,setValues]=useState<Record<string,string>>({});
  const [lookup,setLookup]=useState<Record<string,RecordRow[]>>({});
  const [selected,setSelected]=useState<RecordRow|null>(null);
  const [invoiceSummary,setInvoiceSummary]=useState<RecordRow|null>(null);
  const [budgetSummary,setBudgetSummary]=useState<RecordRow|null>(null);
  const [ledgerLines,setLedgerLines]=useState<RecordRow[]>([]);
  const [policy,setPolicy]=useState<Record<string,unknown>>({});
  const [entryLines,setEntryLines]=useState([{account_id:"",debit_cents:"",credit_cents:""},{account_id:"",debit_cents:"",credit_cents:""}]);
  const conf=section===view?config[view]:extra[section];
  useEffect(()=>{setSection(view);setPage(1);setStatus("");setForm(false);setSelected(null);},[view,activeCompanyId]);
  useEffect(()=>{
    if(!conf)return;
    let mounted=true;setLoading(true);setError("");
    const p=new URLSearchParams({page:String(page)});
    if(status)p.set("status",status);
    api<Page<RecordRow>>(conf.endpoint+"?"+p.toString()).then(x=>{if(mounted)setData(x);})
      .catch(e=>{if(mounted){setData(null);setError((e as Error).message);}})
      .finally(()=>{if(mounted)setLoading(false);});
    return()=>{mounted=false;};
  },[section,page,status,reload,activeCompanyId,conf?.endpoint]);
  useEffect(()=>{
    if(!form||!conf)return;
    let mounted=true;
    const sources=[...new Set(conf.fields.map(x=>x.source).filter((x):x is string=>!!x))];
    if(section==="finance/entries")sources.push("finance/accounts");
    Promise.all(sources.map(async source=>{
      const rows:RecordRow[]=[];let n=1;
      while(n<=10){
        const p=await api<Page<RecordRow>>(source+"?page="+n);
        rows.push(...p.data);if(n>=p.last_page)break;n++;
      }
      return [source,rows] as const;
    })).then(pairs=>{if(mounted)setLookup(Object.fromEntries(pairs));}).catch(e=>{if(mounted)setError((e as Error).message);});
    return()=>{mounted=false;};
  },[form,section,activeCompanyId,conf]);

  useEffect(()=>{
    if(section!=="payment-reminders")return;
    api<Record<string,unknown>>("finance/reminder-policy").then(setPolicy).catch(e=>setError((e as Error).message));
  },[section,activeCompanyId,reload]);
  const refresh=useCallback(()=>{setPage(1);setReload(x=>x+1);setForm(false);setSelected(null);},[]);
  const begin=()=>{setValues({});setEntryLines([{account_id:"",debit_cents:"",credit_cents:""},{account_id:"",debit_cents:"",credit_cents:""}]);setForm(true);setError("");setNotice("");};
  const payload=useMemo(()=>{
    if(!conf)return {};
    const obj:Record<string,unknown>={};
    for(const field of conf.fields){
      const val=values[field.key]??"";
      if(!val && !field.required)continue;
      obj[field.key]=field.kind==="number"||field.source?Number(val):val;
    }
    return obj;
  },[values,conf]);
  async function create(event:FormEvent<HTMLFormElement>){
    event.preventDefault();if(saving||!conf)return;setSaving(true);setError("");setNotice("");
    try{
      const body:Record<string,unknown>={...payload,...(hasKey(conf.endpoint)?{request_key:requestKey()}:{})};
      if(section==="finance/entries") {
        body.lines=entryLines.map(line=>({account_id:Number(line.account_id),debit_cents:Number(line.debit_cents||0),credit_cents:Number(line.credit_cents||0)}));
      }
      await send(conf.endpoint,"POST",body);
      setNotice("Enregistrement créé et contrôlé par le serveur.");
      refresh();
    }catch(e){setError((e as Error).message);}finally{setSaving(false);}
  }
  async function perform(url:string,body:Record<string,unknown>={},method="POST"){
    if(saving)return;setSaving(true);setError("");setNotice("");
    try{await send(url,method,body);setNotice("Opération enregistrée.");refresh();}
    catch(e){setError((e as Error).message);}finally{setSaving(false);}
  }
  async function download(id:number){
    try{
      setError("");
      const blob=await fetchTenantBlob("finance/accounting-exports/"+id+"/download","text/csv");
      const url=URL.createObjectURL(blob);
      const anchor=document.createElement("a");anchor.href=url;anchor.download="atlas-finance-"+id+".csv";
      anchor.click();URL.revokeObjectURL(url);
    }catch(e){setError((e as Error).message);}
  }
  async function select(row:RecordRow){
    setSelected(row);setInvoiceSummary(null);setBudgetSummary(null);setLedgerLines([]);
    try{
      if(section==="credit-notes"||section==="payment-reminders"||section==="finance/allocations"){
        const invoiceId=Number(row.invoice_id);
        if(invoiceId>0)setInvoiceSummary(await api<RecordRow>("finance/invoices/"+invoiceId+"/balance"));
      }
      if(section==="budgets")setBudgetSummary(await api<RecordRow>("finance/budgets/"+row.id+"/summary"));
      if(section==="finance/entries")setLedgerLines(await api<RecordRow[]>("finance/entries/"+row.id+"/lines"));
    }catch(e){setError((e as Error).message);}
  }
  const withRequest=(body:Record<string,unknown>)=>({...body,request_key:requestKey()});
  const promptAmount=(title:string)=>{const raw=window.prompt(title+" (montant entier en centimes)");if(raw===null)return null;const n=Number(raw);if(!Number.isSafeInteger(n)||n<=0){setError("Montant entier positif requis.");return null;}return n;};
  async function allocate(row:RecordRow){
    const invoiceId=window.prompt("ID de la facture émise à rapprocher");if(invoiceId===null)return;
    const n=Number(invoiceId);if(!Number.isSafeInteger(n)||n<=0){setError("Numéro de facture incorrect.");return;}
    const amount=promptAmount("Affecter l'encaissement");if(amount===null)return;
    await perform("finance/collections/"+row.id+"/allocate",withRequest({invoice_id:n,amount_cents:amount}));
  }
  function actions(row:RecordRow){
    if(!canWrite)return null;
    if(section==="collections")return <button className="button secondary" type="button" disabled={saving} onClick={()=>void allocate(row)}>Affecter</button>;
    if(section==="expenses"&&row.status==="draft")return <><button className="button secondary" type="button" disabled={saving} onClick={()=>void perform("finance/expenses/"+row.id+"/decision",{decision:"approved"})}>Approuver</button><button className="button secondary" type="button" disabled={saving} onClick={()=>void perform("finance/expenses/"+row.id+"/decision",{decision:"rejected",reason:"Non conforme"})}>Refuser</button></>;
    if(section==="budgets"&&row.status==="draft")return <button className="button secondary" type="button" disabled={saving} onClick={()=>void perform("finance/budgets/"+row.id+"/approve")}>Approuver</button>;
    if(section==="budgets"&&row.status==="approved")return <button className="button secondary" type="button" disabled={saving} onClick={()=>{const n=promptAmount("Engagement");if(n!==null){const ref=window.prompt("Référence métier de l'engagement");if(ref?.trim())void perform("finance/budgets/"+row.id+"/movements",withRequest({kind:"committed",amount_cents:n,source_reference:ref.trim()}));}}}>Engager</button>;
    if(section==="finance/disputes"&&row.status==="pending")return <><button className="button secondary" type="button" disabled={saving} onClick={()=>void perform("finance/disputes/"+row.id+"/decision",{decision:"approved"})}>Approuver litige</button><button className="button secondary" type="button" disabled={saving} onClick={()=>void perform("finance/disputes/"+row.id+"/decision",{decision:"rejected"})}>Rejeter</button></>;
    if(section==="finance/disputes"&&row.status==="approved")return <button className="button secondary" type="button" disabled={saving} onClick={()=>void perform("finance/disputes/"+row.id+"/decision",{decision:"resolved"})}>Résoudre</button>;
    if(section==="finance/entries"&&row.status==="draft")return <button className="button secondary" type="button" disabled={saving} onClick={()=>void perform("finance/entries/"+row.id+"/post")}>Comptabiliser</button>;
    if(section==="finance/periods"&&row.status==="open")return <button className="button secondary" type="button" disabled={saving} onClick={()=>void perform("finance/periods/"+row.id+"/close")}>Clôturer</button>;
    return null;
  }
  const labelFor=(row:RecordRow)=>show(row.number??row.reference??row.code??row.name??row.id);
  const entryExtra=section==="finance/entries"&&<fieldset><legend>Lignes débit / crédit</legend>
    {entryLines.map((line,i)=><div key={i} className="atlas-domain-toolbar" style={{flexWrap:"wrap"}}>
      <select aria-label={"Compte ligne "+(i+1)} required value={line.account_id} onChange={e=>setEntryLines(old=>old.map((x,j)=>j===i?{...x,account_id:e.target.value}:x))}>
        <option value="">Compte comptable</option>{(lookup["finance/accounts"]??[]).map(a=><option key={a.id} value={a.id}>{show(a.code)} — {show(a.name)}</option>)}
      </select>
      {(["debit_cents","credit_cents"] as const).map(k=><label key={k}>{k==="debit_cents"?"Débit (cts)":"Crédit (cts)"}
        <input type="number" min="0" step="1" value={line[k]} onChange={e=>setEntryLines(old=>old.map((x,j)=>j===i?{...x,[k]:e.target.value}:x))}/></label>)}
      <button type="button" disabled={entryLines.length<=2} className="button secondary" onClick={()=>setEntryLines(old=>old.filter((_,j)=>j!==i))}>Retirer</button>
    </div>)}
    <button type="button" className="button secondary" onClick={()=>setEntryLines(old=>[...old,{account_id:"",debit_cents:"",credit_cents:""}])}>+ Ligne</button>
  </fieldset>;
  if(!conf)return <p>Module financier inconnu.</p>;
  return <section className="atlas-domain" aria-label="Finance et facturation">
    <div className="atlas-domain-toolbar" style={{gap:12,flexWrap:"wrap",alignItems:"center"}}>
      <div><h2>{conf.title}</h2><p>{conf.description}</p></div>
      {canWrite&&conf.fields.length>0&&<button className="button primary" type="button" onClick={begin}>+ {conf.newLabel??"Créer"}</button>}
    </div>
    <nav aria-label="Outils financiers complémentaires" style={{display:"flex",flexWrap:"wrap",gap:8,marginBottom:16}}>
      {section!==view&&<button type="button" className="button secondary" onClick={()=>{setSection(view);setSelected(null);setPage(1);}}>Retour au menu</button>}
      {(sections[view]??[]).filter(s=>s!==section).map(s=><button key={s} className="button secondary" type="button" onClick={()=>{setSection(s);setPage(1);setForm(false);setSelected(null);}}>{extra[s]?.title}</button>)}
    </nav>
    {section==="payment-reminders"&&<details>
      <summary>Politique de relance</summary>
      <p>État : {show(policy.enabled)} · cadence {show(policy.cadence_days)} jours · version {show(policy.template_version)}</p>
      {canWrite&&<form onSubmit={e=>{e.preventDefault();const d=new FormData(e.currentTarget);void perform("finance/reminder-policy",{cadence_days:Number(d.get("cadence_days")),template_version:String(d.get("template_version")),enabled:d.get("enabled")==="on"},"PUT");}}>
        <label>Cadence (jours)<input name="cadence_days" type="number" min="1" max="365" required defaultValue={String(policy.cadence_days??14)}/></label>
        <label>Version du modèle<input name="template_version" required defaultValue={String(policy.template_version??"v1")}/></label>
        <label><input name="enabled" type="checkbox" defaultChecked={Boolean(policy.enabled)}/> Activer</label>
        <button className="button secondary" type="submit" disabled={saving}>Enregistrer la politique</button>
      </form>}
    </details>}
    {error&&<p role="alert" className="error">{error}</p>}
    {notice&&<p role="status" className="success">{notice}</p>}
    {form&&canWrite&&<form onSubmit={e=>void create(e)} style={{padding:"1rem",border:"1px solid var(--border, #ccc)",borderRadius:12,marginBottom:16}}>
      <h3>Nouvel enregistrement — {conf.title}</h3>
      {section==="expenses"&&<label style={{display:"grid",gap:4,marginBottom:12}}>
        Calculer l’empreinte du justificatif (fichier local, non transmis)
        <input type="file" accept=".pdf,.png,.jpg,.jpeg" onChange={async e=>{
          const file=e.target.files?.[0];if(!file)return;
          if(file.size>10*1024*1024){setError("Fichier de plus de 10 Mio non pris en charge.");return;}
          try{
            const digest=await crypto.subtle.digest("SHA-256",await file.arrayBuffer());
            const hex=Array.from(new Uint8Array(digest)).map(n=>n.toString(16).padStart(2,"0")).join("");
            setValues(old=>({...old,receipt_digest:hex}));
          }catch{setError("Impossible de calculer l’empreinte.")}
        }}/>
        <small>Seule l’empreinte est conservée, pas le document original. Ne pas utiliser pour une archive légale.</small>
      </label>}
      <div style={{display:"grid",gridTemplateColumns:"repeat(auto-fit,minmax(210px,1fr))",gap:12}}>
      {conf.fields.map(field=><label key={field.key} style={{display:"grid",gap:4}}>
        {field.label}
        {field.kind==="select"?<select required={field.required} value={values[field.key]??""} onChange={e=>setValues(v=>({...v,[field.key]:e.target.value}))}>
          <option value="">Choisir…</option>
          {field.source?(lookup[field.source]??[]).map(item=><option key={item.id} value={item.id}>{labelFor(item)}</option>):
          (field.options??[]).map(option=><option key={option} value={option}>{option}</option>)}
        </select>:field.kind==="textarea"?<textarea required={field.required} value={values[field.key]??""} onChange={e=>setValues(v=>({...v,[field.key]:e.target.value}))}/>:
        <input required={field.required} type={field.kind==="number"?"number":field.kind==="date"?"date":"text"}
          min={field.kind==="number"?"0":undefined} step={field.kind==="number"?"1":undefined}
          value={values[field.key]??""} onChange={e=>setValues(v=>({...v,[field.key]:e.target.value}))}/>}
      </label>)}
      </div>
      {entryExtra}
      <div style={{display:"flex",gap:12,marginTop:12}}>
        <button className="button primary" type="submit" disabled={saving}>{saving?"En cours…":"Enregistrer"}</button>
        <button className="button secondary" type="button" onClick={()=>setForm(false)}>Annuler</button>
      </div>
    </form>}
    <div className="atlas-domain-toolbar">
      <label>Statut
        <select value={status} onChange={e=>{setStatus(e.target.value);setPage(1);}}>
          <option value="">Tous</option>
          {["draft","issued","paid","approved","rejected","posted","open","closed","pending","prepared"].map(s=><option key={s} value={s}>{s}</option>)}
        </select>
      </label>
      <button className="button secondary" type="button" onClick={()=>setReload(n=>n+1)}>Actualiser</button>
    </div>
    <div style={{overflowX:"auto"}}>
      <table><thead><tr>{conf.columns.map(c=><th key={c} scope="col">{c.replaceAll("_"," ")}</th>)}<th scope="col">Actions</th></tr></thead>
      <tbody>
        {(data?.data??[]).map(row=><tr key={row.id}>
          {conf.columns.map(col=><td key={col}>{isCent(col)?currencyFormat(row[col],String(row.currency??"EUR")):show(row[col])}</td>)}
          <td><div style={{display:"flex",gap:4,flexWrap:"wrap"}}>
            <button className="button secondary" type="button" onClick={()=>void select(row)}>Détail</button>
            {actions(row)}
            {section==="accounting-exports"&&<button className="button secondary" type="button" onClick={()=>void download(row.id)}>CSV</button>}
          </div></td>
        </tr>)}
      </tbody></table>
      {!loading&&data?.data.length===0&&<p>Aucune donnée pour les filtres sélectionnés.</p>}
      {loading&&<p role="status">Chargement…</p>}
    </div>
    <div className="pagination"><span>Page {data?.current_page??page} / {data?.last_page??1}</span>
      <button type="button" className="button secondary" disabled={page<=1||loading} onClick={()=>setPage(x=>x-1)}>Précédente</button>
      <button type="button" className="button secondary" disabled={page>=(data?.last_page??1)||loading} onClick={()=>setPage(x=>x+1)}>Suivante</button>
    </div>
    {selected&&<aside style={{marginTop:16,padding:16,border:"1px solid var(--border, #ccc)",borderRadius:12}}>
      <h3>Détail #{selected.id}</h3>
      <dl style={{display:"grid",gridTemplateColumns:"minmax(120px,1fr) 2fr",gap:6}}>
        {Object.entries(selected).filter(([k])=>!["csv_snapshot"].includes(k)).map(([k,v])=><div key={k} style={{display:"contents"}}><dt>{k}</dt><dd>{show(v)}</dd></div>)}
      </dl>
      {invoiceSummary&&<p>Solde facture : <strong>{currencyFormat(invoiceSummary.outstanding_cents,String(invoiceSummary.currency??"EUR"))}</strong> · avoirs : {currencyFormat(invoiceSummary.credit_cents)} · paiements : {currencyFormat(invoiceSummary.allocated_cents)}</p>}
      {budgetSummary&&<p>Disponible : <strong>{currencyFormat(budgetSummary.available_cents)}</strong> · consommé : {currencyFormat(budgetSummary.consumed_cents)} · engagé : {currencyFormat(budgetSummary.committed_cents)} · réservé : {currencyFormat(budgetSummary.reserved_cents)}</p>}
      {ledgerLines.length>0&&<table><thead><tr><th>Compte</th><th>Débit</th><th>Crédit</th></tr></thead><tbody>{ledgerLines.map(l=><tr key={l.id}><td>{show(l.account_id)}</td><td>{currencyFormat(l.debit_cents)}</td><td>{currencyFormat(l.credit_cents)}</td></tr>)}</tbody></table>}
      <button className="button secondary" type="button" onClick={()=>setSelected(null)}>Fermer</button>
    </aside>}
  </section>;
}
