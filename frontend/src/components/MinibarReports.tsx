"use client";

import {useEffect,useState,type FormEvent} from "react";
import {api,fetchTenantBlob,ApiError} from "@/lib/api";

type Line={room_number:string;sku:string;name:string;unit_cents:number;quantity:number;total_cents:number};
type Daily={lines:Line[];total_cents:number;by_room:Record<string,number>;by_sku:Record<string,number>};
type Mapping={mode:string;delimiter:string;amount_unit:string;date_header:string;room_header:string;amount_header:string;sku_header?:string};
type ReconciliationLine={room_number:string;sku:string|null;atlas_cents:number;pms_cents:number;delta_cents:number;pms_line_count:number;status:string;possible_breakfast_difference:boolean};
type Reconciliation={import_id:number;import_mode:string;atlas_total_cents:number;pms_total_cents:number;delta_cents:number;counts:Record<string,number>;manual_review_required:boolean;lines:ReconciliationLine[]};
type MonthDay={business_date:string;atlas_total_cents:number;pms_total_cents:number|null;delta_cents:number|null;import_id:number|null;anomalies_count:number|null};
type Monthly={month:string;total_quantity:number;by_article:{sku:string;name:string;quantity:number;total_cents:number}[];atlas_total_cents:number;compared_atlas_cents:number;compared_pms_cents:number;compared_delta_cents:number;compared_days:number;uncompared_days:number;anomalies_count:number;days:MonthDay[]};
const reconciliationLabels:Record<string,string>={matched:"Conforme",amount_mismatch:"Écart",missing_in_pms:"Absent du PMS",missing_in_atlas:"Absent d’ATLAS",duplicate_pms:"Doublon PMS"};
const initial:Mapping={mode:"room",delimiter:";",amount_unit:"cents",date_header:"business_date",room_header:"room_number",amount_header:"total_cents",sku_header:"sku"};
const money=(value:number)=>new Intl.NumberFormat("fr-FR",{style:"currency",currency:"EUR"}).format(value/100);

export default function MinibarReports({hotel,day,refresh}:{hotel:number;day:string;refresh:number}){
  const base="hotels/"+hotel+"/minibar",query="?business_date="+day;
  const [report,setReport]=useState<Daily|null>(null),[mapping,setMapping]=useState<Mapping>(initial);
  const [reconciliation,setReconciliation]=useState<Reconciliation|null>(null);
  const [monthly,setMonthly]=useState<Monthly|null>(null);
  const [error,setError]=useState(""),[notice,setNotice]=useState(""),[busy,setBusy]=useState(false);
  const [revision,setRevision]=useState(0);
  useEffect(()=>{
    let live=true;
    api<Daily>(base+"/report"+query).then(value=>{if(live)setReport(value);}).catch(e=>{if(live)setError(e.message);});
    return ()=>{live=false;};
  },[base,query,refresh]);
  useEffect(()=>{
    let live=true;
    api<{mapping:Mapping|null}>(base+"/pms/mapping").then(value=>{if(live&&value.mapping)setMapping({...initial,...value.mapping});})
      .catch(e=>{if(live)setError(e.message);});
    return ()=>{live=false;};
  },[base]);
  useEffect(()=>{
    let live=true;setReconciliation(null);
    api<Reconciliation>(base+"/reconciliation"+query).then(value=>{if(live)setReconciliation(value);})
      .catch(e=>{if(live&&!(e instanceof ApiError&&e.status===404))setError(e.message);});
    return ()=>{live=false;};
  },[base,query,refresh,revision]);
  useEffect(()=>{
    let live=true;setMonthly(null);
    api<Monthly>(base+"/monthly?month="+day.slice(0,7)).then(value=>{if(live)setMonthly(value);})
      .catch(e=>{if(live)setError(e.message);});
    return ()=>{live=false;};
  },[base,day,refresh,revision]);
  async function download(path:string,name:string){
    setError("");
    try{
      const data=await fetchTenantBlob(base+path,"text/csv");
      const url=URL.createObjectURL(data),a=document.createElement("a");
      a.href=url;a.download=name;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
    }catch(e){setError((e as Error).message);}
  }
  async function upload(e:FormEvent<HTMLFormElement>){
    e.preventDefault();if(busy)return;
    const body=new FormData(e.currentTarget);
    Object.entries(mapping).forEach(([key,value])=>{if(value)body.set(key,value);});
    body.set("business_date",day);setBusy(true);setError("");setNotice("");
    try{
      const data=await api<{id:number;row_count:number}>(base+"/pms/imports",{method:"POST",body});
      setNotice("Import "+data.id+" enregistré · "+data.row_count+" ligne(s).");setRevision(v=>v+1);
    }catch(e){setError((e as Error).message);}finally{setBusy(false);}
  }
  return <section><h3>Rapport du jour</h3>
    {error&&<p role="alert">{error}</p>}{notice&&<p role="status">{notice}</p>}
    {report&&<><p>Total hôtel <strong>{money(report.total_cents)}</strong></p>
      {report.lines.length>0&&<div className="table-scroll"><table><thead><tr>{["Chambre","Article","Quantité","Prix unitaire","Total"].map(k=><th key={k}>{k}</th>)}</tr></thead>
        <tbody>{report.lines.map(l=><tr key={JSON.stringify([l.room_number,l.sku,l.unit_cents])}>
          <td>{l.room_number}</td><td>{l.name} · {l.sku}</td><td>{l.quantity}</td><td>{money(l.unit_cents)}</td><td>{money(l.total_cents)}</td>
        </tr>)}</tbody></table>
        <details><summary>Totaux par chambre et par article</summary>
          <h4>Par chambre</h4>{Object.entries(report.by_room).map(([k,v])=><p key={k}>{k} · {money(v)}</p>)}
          <h4>Par article</h4>{Object.entries(report.by_sku).map(([k,v])=><p key={k}>{k} · {money(v)}</p>)}
        </details></div>}
      <button onClick={()=>void download("/export"+query,"minibar-"+hotel+"-"+day+".csv")}>Exporter le journal CSV</button></>}
    <section aria-label="Rapprochement minibar PMS"><h3>Rapprochement ATLAS / PMS</h3>
      {!reconciliation&&<p>Aucun import PMS sélectionné pour cette journée.</p>}
      {reconciliation&&<>
        <p>Import #{reconciliation.import_id} · {reconciliation.import_mode==="room"?"Par chambre":"Par chambre et article"}</p>
        <p>ATLAS : <strong>{money(reconciliation.atlas_total_cents)}</strong> · PMS : <strong>{money(reconciliation.pms_total_cents)}</strong> · Écart ATLAS − PMS : <strong>{money(reconciliation.delta_cents)}</strong></p>
        <p role="status">{reconciliation.manual_review_required?"Revue manuelle obligatoire : aucune écriture n’est modifiée automatiquement.":"Montants rapprochés, sans correction automatique."}</p>
        <div className="table-scroll"><table><thead><tr>{["Chambre","Article","ATLAS","PMS","Écart","Contrôle"].map(k=><th key={k}>{k}</th>)}</tr></thead>
          <tbody>{reconciliation.lines.map((line,i)=><tr key={line.room_number+"-"+(line.sku??"")+"-"+i}>
            <td>{line.room_number}</td><td>{line.sku??"Total chambre"}</td>
            <td>{money(line.atlas_cents)}</td><td>{money(line.pms_cents)}</td><td>{money(line.delta_cents)}</td>
            <td>{reconciliationLabels[line.status]??line.status}{line.possible_breakfast_difference&&" · Hypothèse petit-déjeuner (+/- 5 €)"}</td>
          </tr>)}</tbody></table></div>
        <button type="button" onClick={()=>void download("/reconciliation/export"+query+"&import_id="+reconciliation.import_id,"rapprochement-minibar-"+hotel+"-"+day+".csv")}>Exporter les écarts CSV</button>
      </>}
    </section>
    <details><summary>Tableau de bord mensuel · {day.slice(0,7)}</summary>
      {monthly&&<>
        <p>Total ATLAS : <strong>{money(monthly.atlas_total_cents)}</strong> · Articles consommés : <strong>{monthly.total_quantity}</strong> · Journées rapprochées : {monthly.compared_days} · Journées sans import : {monthly.uncompared_days} · Anomalies : {monthly.anomalies_count}</p>
        {monthly.compared_days>0&&<p>Écart sur journées comparables : {money(monthly.compared_delta_cents)} (ATLAS {money(monthly.compared_atlas_cents)} / PMS {money(monthly.compared_pms_cents)})</p>}
        <details><summary>Consommations mensuelles par article</summary>
          <div className="table-scroll"><table><thead><tr><th>Référence</th><th>Article</th><th>Quantité</th><th>Chiffre d'affaires</th></tr></thead>
            <tbody>{monthly.by_article.map(a=><tr key={a.sku}><td>{a.sku}</td><td>{a.name}</td><td>{a.quantity}</td><td>{money(a.total_cents)}</td></tr>)}</tbody>
          </table></div>
        </details>
        <div aria-label="Tendance quotidienne des consommations mini-bar" style={{display:"flex",height:110,alignItems:"end",gap:3}}>
          {monthly.days.map(d=><div key={d.business_date} title={d.business_date+" · "+money(d.atlas_total_cents)}
            style={{flex:1,minWidth:2,height:Math.max(2,100*d.atlas_total_cents/Math.max(1,...monthly.days.map(x=>x.atlas_total_cents)))+"%",background:"currentColor",opacity:d.import_id===null ? 0.3 : 0.85}} />)}
        </div>
        <div className="table-scroll"><table><thead><tr><th>Journée</th><th>ATLAS</th><th>PMS</th><th>Écart</th><th>Anomalies</th></tr></thead>
          <tbody>{monthly.days.map(d=><tr key={d.business_date}>
            <td>{d.business_date}</td><td>{money(d.atlas_total_cents)}</td>
            <td>{d.pms_total_cents===null?"Non importé":money(d.pms_total_cents)}</td>
            <td>{d.delta_cents===null?"Non rapproché":money(d.delta_cents)}</td>
            <td>{d.anomalies_count??"Non rapproché"}</td>
          </tr>)}</tbody></table></div>
      </>}
    </details>
    <details><summary>Importer un CSV PMS fictif</summary><form onSubmit={upload}>
      <label>Rapprochement <select value={mapping.mode} onChange={e=>setMapping({...mapping,mode:e.target.value})}>
        <option value="room">Total par chambre</option><option value="room_product">Total par chambre et article</option></select></label>
      <label>Séparateur <select value={mapping.delimiter} onChange={e=>setMapping({...mapping,delimiter:e.target.value})}>
        <option value=";">Point-virgule</option><option value=",">Virgule</option></select></label>
      <label>Montants <select value={mapping.amount_unit} onChange={e=>setMapping({...mapping,amount_unit:e.target.value})}>
        <option value="cents">Centimes</option><option value="euros">Euros</option></select></label>
      {([["date_header","Colonne de date (AAAA-MM-JJ)"],["room_header","Colonne chambre"],["amount_header","Colonne montant"],
        ...(mapping.mode==="room_product"?[["sku_header","Colonne code article"]]:[])] as [keyof Mapping,string][]).map(([key,title])=>
        <label key={key}>{title} <input required value={mapping[key]??""} maxLength={80} onChange={e=>setMapping({...mapping,[key]:e.target.value})}/></label>)}
      <label>Fichier PMS <input name="file" type="file" accept=".csv,text/csv" required/></label>
      <label><input type="checkbox" name="synthetic" value="1" required/>Le fichier contient des données fictives.</label>
      <button disabled={busy}>Importer la journée</button>
    </form></details>
  </section>;
}
