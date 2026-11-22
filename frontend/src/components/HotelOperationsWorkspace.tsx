"use client";

import { useEffect, useState, type FormEvent } from "react";
import { api, send } from "@/lib/api";

type Row = Record<string, unknown> & { id: number };
type Page = { data: Row[] };
type Domain = "hotel-folios" | "hotel-channel" | "hotel-night-audit";
const config: Record<Domain, {title:string; intro:string}> = {
 "hotel-folios": {title:"Folios et facturation", intro:"Écritures historiques en centimes, sans suppression ni solde arbitraire."},
 "hotel-channel": {title:"Canaux et CRS/PMS", intro:"Imports fictifs uniquement. Aucune synchronisation externe en direct."},
 "hotel-night-audit": {title:"Clôture de journée", intro:"Instantané horodaté et anomalies conservées ; aucune recette fictive."},
};
const today = () => new Date().toISOString().slice(0, 10);
const str = (v:unknown) => String(v ?? "");
const pretty = (v:unknown) => typeof v === "object" && v !== null ? JSON.stringify(v) : str(v);

export default function HotelOperationsWorkspace({view,activeCompanyId}:{
 view:Domain; activeCompanyId:number|null
}) {
 const [hotels,setHotels] = useState<Row[]>([]);
 const [hotel,setHotel] = useState("");
 const [rows,setRows] = useState<Row[]>([]);
 const [detail,setDetail] = useState<Record<string,unknown>|null>(null);
 const [loading,setLoading] = useState(false);
 const [error,setError] = useState("");
 const [notice,setNotice] = useState("");
 const [working,setWorking] = useState(false);
 const [date,setDate] = useState(today());
 const [booking,setBooking] = useState("");
 const [folioNumber,setFolioNumber] = useState("");
 const [folioId,setFolioId] = useState("");
 const [transferTarget,setTransferTarget] = useState("");
 const [transferEntry,setTransferEntry] = useState("");
 const [transferAmount,setTransferAmount] = useState("");
 const [transferReason,setTransferReason] = useState("");
 const [kind,setKind] = useState("charge");
 const [sourceType,setSourceType] = useState("manual");
 const [amount,setAmount] = useState("");
 const [note,setNote] = useState("");
 const [refRulePrefix,setRefRulePrefix] = useState("4");
 const [separator,setSeparator] = useState("_");
 const [source,setSource] = useState("pms");
 const [payload,setPayload] = useState("reference,status,origin,amount_cents\n1234_RES001,active,channel,12500");
 const [pmsId,setPmsId] = useState("");
 const [crsId,setCrsId] = useState("");
 const [timezone,setTimezone] = useState("Europe/Paris");
 const [cutoff,setCutoff] = useState("04:00");

 useEffect(()=>{
   let live=true;
   api<Page>("hotels").then(result=>{
      if(live) {setHotels(result.data);setHotel(current=>current || str(result.data[0]?.id));}
   }).catch((e:Error)=>{if(live)setError(e.message);});
   return ()=>{live=false;};
 },[activeCompanyId]);
 const base = `hotels/${hotel}`;
 const endpoint = view==="hotel-folios" ? "folios" : view==="hotel-channel" ? "channel/imports" : "night-audits";
 useEffect(()=>{
  let live=true;setRows([]);setDetail(null);setError("");
  if(!hotel)return;
  setLoading(true);
  api<Page>(`${base}/${endpoint}`).then(result=>{if(live)setRows(result.data);})
    .catch((e:Error)=>{if(live)setError(e.message);})
    .finally(()=>{if(live)setLoading(false);});
  return ()=>{live=false;};
 },[activeCompanyId,hotel,view,endpoint,base,notice]);

 async function submit(e:FormEvent<HTMLFormElement>,path:string,body:Record<string,unknown>) {
   e.preventDefault();if(working || !hotel)return;
   setWorking(true);setError("");setNotice("");
   try {
    await send(path,"POST",body);
    setNotice("Opération enregistrée et historisée.");
   } catch(err) {setError((err as Error).message);}
   finally {setWorking(false);}
 }
 async function openFolio(id:number){
  setError("");
  try {setDetail(await api<Record<string,unknown>>(`${base}/folios/${id}`));setFolioId(String(id));}
  catch(e){setError((e as Error).message);}
 }
 async function reconcile(e:FormEvent<HTMLFormElement>){
   e.preventDefault();setError("");
   try {
     const query = new URLSearchParams({pms_import_id:pmsId,crs_import_id:crsId});
     setDetail(await api<Record<string,unknown>>(`${base}/channel/reconciliation?${query}`));
   } catch(err){setError((err as Error).message);}
 }
 function parsedRows(){
   const lines = payload.trim().split(/\r?\n/);
   if(lines[0]?.trim()!=="reference,status,origin,amount_cents")
     throw Error("Entête CSV attendu : reference,status,origin,amount_cents");
   if(lines.length<2 || lines.length>2001)throw Error("Entre 1 et 2000 lignes sont attendues.");
   return lines.slice(1).map(line=>{
     const [reference,status,origin,cents,...extra] = line.split(",");
     if(extra.length || !reference || !status || !origin || !/^\d+$/.test(cents))
       throw Error("Ligne CSV invalide : "+line);
     return {reference:reference.trim(), status:status.trim(), origin:origin.trim(), amount_cents:Number(cents)};
   });
 }
 async function importRows(e:FormEvent<HTMLFormElement>){
   e.preventDefault();setError("");
   try {
    const rowsToSend = parsedRows();
    await submit(e,`${base}/channel/imports`,{source,business_date:date,
      synthetic:true,request_id:crypto.randomUUID(),rows:rowsToSend});
   }catch(err){setError((err as Error).message);}
 }
 return <section className="atlas-domain">
  <div className="atlas-domain-toolbar"><div><h2>{config[view].title}</h2><p>{config[view].intro}</p></div></div>
  <label>Établissement <select value={hotel} onChange={e=>{setHotel(e.target.value);setNotice("");}}>
   {hotels.map(h=><option value={h.id} key={h.id}>{str(h.name)} · {str(h.code)}</option>)}
  </select></label>
  {notice && <p role="status" className="notice">{notice}</p>}
  {error && <p role="alert" className="error">{error}</p>}
  {view==="hotel-folios" && <div className="atlas-domain-form">
   <h3>Créer un folio</h3>
   <form onSubmit={e=>void submit(e,`${base}/folios`,{booking_id:Number(booking),folio_number:folioNumber})}>
    <label>Identifiant réservation <input required type="number" min="1" value={booking} onChange={e=>setBooking(e.target.value)}/></label>
    <label>Numéro de folio <input required maxLength={60} value={folioNumber} onChange={e=>setFolioNumber(e.target.value)}/></label>
    <button className="button primary" disabled={working || !hotel}>Créer</button>
   </form>
   <h3>Enregistrer une écriture</h3>
   <form onSubmit={e=>void submit(e,`${base}/folios/${folioId}/entries`,{
     business_date:date,kind,source_type:sourceType,amount_cents:Number(amount),
     note,request_id:crypto.randomUUID()})}>
    <label>Folio <input type="number" required min="1" value={folioId} onChange={e=>setFolioId(e.target.value)}/></label>
    <label>Date métier <input type="date" required value={date} onChange={e=>setDate(e.target.value)}/></label>
    <label>Type <select value={kind} onChange={e=>setKind(e.target.value)}>
      <option value="charge">Débit</option><option value="credit">Avoir</option><option value="payment">Paiement</option>
    </select></label>
    <label>Origine <select value={sourceType} onChange={e=>setSourceType(e.target.value)}>
     {["room","minibar","manual","adjustment","payment"].map(x=><option key={x}>{x}</option>)}
    </select></label>
    <label>Montant (centimes) <input type="number" min="1" required value={amount} onChange={e=>setAmount(e.target.value)}/></label>
    <label>Justification <input required minLength={3} maxLength={300} value={note} onChange={e=>setNote(e.target.value)}/></label>
    <button className="button primary" disabled={working || !hotel}>Ajouter sans écraser l’historique</button>
   </form>
   <h3>Transférer une prestation vers un autre folio du même séjour</h3>
   <form onSubmit={e=>void submit(e,`${base}/folios/${folioId}/transfer`,{
     destination_folio_id:Number(transferTarget),source_entry_id:Number(transferEntry),
     amount_cents:Number(transferAmount),business_date:date,
     reason:transferReason,request_id:crypto.randomUUID()})}>
    <label>Folio source <input type="number" min="1" required value={folioId} onChange={e=>setFolioId(e.target.value)}/></label>
    <label>Folio destinataire <input type="number" min="1" required value={transferTarget} onChange={e=>setTransferTarget(e.target.value)}/></label>
    <label>Identifiant ligne source <input type="number" min="1" required value={transferEntry} onChange={e=>setTransferEntry(e.target.value)}/></label>
    <label>Montant à transférer en centimes <input type="number" min="1" required value={transferAmount} onChange={e=>setTransferAmount(e.target.value)}/></label>
    <label>Motif <input required minLength={5} maxLength={300} value={transferReason} onChange={e=>setTransferReason(e.target.value)}/></label>
    <button className="button secondary" disabled={working || !hotel}>Transférer et tracer les deux écritures</button>
   </form>
  </div>}
  {view==="hotel-channel" && <div className="atlas-domain-form">
   <h3>Règle de normalisation versionnée</h3>
   <form onSubmit={e=>void submit(e,`${base}/channel/rules`,{
     effective_on:date,prefix_digits:Number(refRulePrefix),separator})}>
    <label>Début d’application <input type="date" required value={date} onChange={e=>setDate(e.target.value)}/></label>
    <label>Chiffres préfixe <input type="number" min="0" max="12" required value={refRulePrefix} onChange={e=>setRefRulePrefix(e.target.value)}/></label>
    <label>Séparateur <select value={separator} onChange={e=>setSeparator(e.target.value)}>
     <option value="_">_</option><option value="-">-</option><option value="/">/</option>
    </select></label>
    <button className="button primary" disabled={working || !hotel}>Versionner la règle</button>
   </form>
   <h3>Importer des lignes fictives PMS/CRS</h3>
   <form onSubmit={e=>void importRows(e)}>
    <label>Source <select value={source} onChange={e=>setSource(e.target.value)}><option value="pms">PMS</option><option value="crs">CRS</option></select></label>
    <label>Données CSV (sans guillemets ni champs avec virgules)
     <textarea rows={6} required value={payload} onChange={e=>setPayload(e.target.value)}/>
    </label>
    <button className="button primary" disabled={working || !hotel}>Importer en mode fictif</button>
   </form>
   <h3>Rapprochement manuel</h3>
   <form onSubmit={e=>void reconcile(e)}>
    <label>ID lot PMS <input type="number" min="1" required value={pmsId} onChange={e=>setPmsId(e.target.value)}/></label>
    <label>ID lot CRS <input type="number" min="1" required value={crsId} onChange={e=>setCrsId(e.target.value)}/></label>
    <button className="button secondary" disabled={!hotel}>Comparer les références et écarts</button>
   </form>
  </div>}
  {view==="hotel-night-audit" && <div className="atlas-domain-form">
    <h3>Calendrier métier hôtelier</h3>
    <form onSubmit={e=>void submit(e,`${base}/calendar/versions`,{
      effective_on:date,timezone,cutoff_time:cutoff})}>
     <label>Date d’effet <input type="date" required value={date} onChange={e=>setDate(e.target.value)}/></label>
     <label>Fuseau horaire <input required value={timezone} onChange={e=>setTimezone(e.target.value)}/></label>
     <label>Heure de coupure <input type="time" required value={cutoff} onChange={e=>setCutoff(e.target.value)}/></label>
     <button className="button primary" disabled={working || !hotel}>Enregistrer une version</button>
    </form>
    <h3>Clôturer une journée</h3>
    <form onSubmit={e=>void submit(e,`${base}/night-audits`,{business_date:date,request_id:crypto.randomUUID()})}>
     <label>Journée métier <input type="date" required value={date} onChange={e=>setDate(e.target.value)}/></label>
     <p>Clôture définitive de la journée. Les anomalies PMS externes restent à traiter.</p>
     <button className="button primary" disabled={working || !hotel}>Créer l’instantané de clôture</button>
    </form>
  </div>}
  <div className="atlas-domain-list"><h3>Historique des opérations</h3>
  {loading ? <p>Chargement…</p> : rows.length===0 ? <p>Aucune opération enregistrée.</p> :
   <div className="table-scroll"><table><thead><tr><th>ID</th><th>Date</th><th>État / origine</th><th>Action</th></tr></thead><tbody>
    {rows.map(r=><tr key={r.id}><td>{r.id}</td><td>{str(r.business_date??r.created_at)}</td>
     <td>{str(r.status??r.source??"clôturé")}</td>
     <td>{view==="hotel-folios" ? <button className="button secondary" onClick={()=>void openFolio(r.id)}>Voir détail et solde</button> : <span>Conservé</span>}</td>
    </tr>)}
   </tbody></table></div>}
  {detail && <div><h3>Détail / rapprochement</h3><pre style={{whiteSpace:"pre-wrap",overflowWrap:"anywhere"}}>{pretty(detail)}</pre></div>}
  </div>
 </section>;
}
