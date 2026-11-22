"use client";

import {useEffect,useState,type FormEvent} from "react";
import {api,fetchTenantBlob} from "@/lib/api";

type Line={room_number:string;sku:string;name:string;unit_cents:number;quantity:number;total_cents:number};
type Daily={lines:Line[];total_cents:number;by_room:Record<string,number>;by_sku:Record<string,number>};
type Mapping={mode:string;delimiter:string;amount_unit:string;date_header:string;room_header:string;amount_header:string;sku_header?:string};
const initial:Mapping={mode:"room",delimiter:";",amount_unit:"cents",date_header:"business_date",room_header:"room_number",amount_header:"total_cents",sku_header:"sku"};
const money=(value:number)=>new Intl.NumberFormat("fr-FR",{style:"currency",currency:"EUR"}).format(value/100);

export default function MinibarReports({hotel,day,refresh}:{hotel:number;day:string;refresh:number}){
  const base="hotels/"+hotel+"/minibar",query="?business_date="+day;
  const [report,setReport]=useState<Daily|null>(null),[mapping,setMapping]=useState<Mapping>(initial);
  const [error,setError]=useState(""),[notice,setNotice]=useState(""),[busy,setBusy]=useState(false);
  const [,setRevision]=useState(0);
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
