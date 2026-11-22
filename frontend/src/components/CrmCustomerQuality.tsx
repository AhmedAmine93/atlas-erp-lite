"use client";

import {useCallback,useEffect,useState} from "react";
import {api,send} from "@/lib/api";

type CustomerMini={id:number;name:string;email:string|null};
type Candidate={left:CustomerMini;right:CustomerMini;match_on:"name"|"phone"};
type Candidates={candidates:Candidate[];scanned:number;truncated:boolean};
type CustomerLink={id:number;duplicate_customer_id:number;canonical_customer_id:number;duplicate_name:string;canonical_name:string;active:boolean;revision:number;reason:string};
type Links={data:CustomerLink[];total:number};

export default function CrmCustomerQuality({activeCompanyId,onChanged}:{
  activeCompanyId:number|null;onChanged:()=>void;
}){
  const [candidates,setCandidates]=useState<Candidates|null>(null);
  const [links,setLinks]=useState<CustomerLink[]>([]);
  const [busy,setBusy]=useState(false);
  const [error,setError]=useState("");
  const [notice,setNotice]=useState("");
  const [refresh,setRefresh]=useState(0);

  const load=useCallback(async()=>{
    const [c,l]=await Promise.all([
      api<Candidates>("crm/customers/duplicate-candidates"),
      api<Links>("crm/customers/duplicate-links?active=1"),
    ]);
    setCandidates(c);setLinks(l.data);
  },[]);

  useEffect(()=>{
    let live=true;
    setCandidates(null);setLinks([]);setError("");setNotice("");
    void Promise.all([
      api<Candidates>("crm/customers/duplicate-candidates"),
      api<Links>("crm/customers/duplicate-links?active=1")
    ]).then(([c,l])=>{if(live){setCandidates(c);setLinks(l.data);}})
      .catch((e:Error)=>{if(live)setError(e.message);});
    return ()=>{live=false;};
  },[activeCompanyId,refresh]);

  async function link(duplicateId:number,canonicalId:number){
    if(busy)return;
    const reason=window.prompt("Motif documenté du rapprochement (8 caractères minimum) :");
    if(reason===null)return;
    if(reason.trim().length<8){setError("Motif trop court.");return;}
    if(!window.confirm("Archiver uniquement la fiche doublon #"+duplicateId+" et conserver toutes les ventes, factures et données originales ?"))return;
    setBusy(true);setError("");setNotice("");
    try{
      await send("crm/customers/duplicate-links","POST",{
        duplicate_customer_id:duplicateId,canonical_customer_id:canonicalId,reason:reason.trim(),
      });
      setNotice("Rapprochement enregistré sans déplacer les documents historiques.");
      await load();onChanged();setRefresh(n=>n+1);
    }catch(e){setError((e as Error).message);}finally{setBusy(false);}
  }

  async function reverse(item:CustomerLink){
    if(busy)return;
    const reason=window.prompt("Motif de l'annulation (8 caractères minimum) :");
    if(reason===null)return;
    if(reason.trim().length<8){setError("Motif trop court.");return;}
    setBusy(true);setError("");setNotice("");
    try{
      await send("crm/customers/duplicate-links/"+item.id+"/reverse","POST",{
        reason:reason.trim(),expected_revision:item.revision,
      });
      setNotice("Association annulée ; l'état initial de la fiche a été restauré.");
      await load();onChanged();setRefresh(n=>n+1);
    }catch(e){setError((e as Error).message);}finally{setBusy(false);}
  }

  return <section className="atlas-domain" aria-label="Qualité des données clients">
    <div className="atlas-domain-toolbar"><div>
      <h3>Qualité et doublons clients</h3>
      <p>Suggestions sur noms ou téléphones identiques. Aucun regroupement automatique ni transfert d'historique.</p>
    </div><button type="button" className="button secondary" disabled={busy}
      onClick={()=>setRefresh(n=>n+1)}>Actualiser</button></div>
    {error&&<p role="alert" className="error">{error}</p>}
    {notice&&<p role="status" className="notice">{notice}</p>}
    {candidates&&<>
      <p>{candidates.candidates.length} suggestion(s) trouvée(s) sur {candidates.scanned} clients examinés.
        {candidates.truncated?" Analyse partielle : filtrer ou traiter par lots.":""}</p>
      <div className="table-scroll"><table><thead><tr>
        <th>Client A</th><th>Client B</th><th>Correspondance</th><th>Décision manuelle</th>
      </tr></thead><tbody>{candidates.candidates.map(pair=><tr key={pair.left.id+"-"+pair.right.id}>
        <td>{pair.left.name} (#{pair.left.id})</td><td>{pair.right.name} (#{pair.right.id})</td>
        <td>{pair.match_on==="phone"?"Téléphone":"Nom"}</td>
        <td><div className="row-actions">
          <button type="button" className="text-button" disabled={busy}
            onClick={()=>void link(pair.right.id,pair.left.id)}>Conserver A</button>
          <button type="button" className="text-button" disabled={busy}
            onClick={()=>void link(pair.left.id,pair.right.id)}>Conserver B</button>
        </div></td>
      </tr>)}</tbody></table></div>
    </>}
    <h3>Rapprochements actifs</h3>
    <div className="table-scroll"><table><thead><tr><th>Fiche de référence</th><th>Fiche archivée</th><th>Action</th></tr></thead>
      <tbody>{links.map(item=><tr key={item.id}><td>{item.canonical_name} (#{item.canonical_customer_id})</td>
        <td>{item.duplicate_name} (#{item.duplicate_customer_id})</td>
        <td><button type="button" className="text-button" disabled={busy}
          onClick={()=>void reverse(item)}>Annuler le rapprochement</button></td>
      </tr>)}</tbody></table></div>
    {links.length===0&&<p>Aucun rapprochement actif.</p>}
  </section>;
}
