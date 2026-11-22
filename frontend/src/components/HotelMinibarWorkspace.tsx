"use client";

import {useEffect,useState,type FormEvent} from "react";
import MinibarReports from "@/components/MinibarReports";
import {all,api,send,ApiError} from "@/lib/api";

type Hotel={id:number;name:string;timezone:string};
type Room={id:number;room_number:string};
type Product={id:number;sku:string;name:string;active:boolean;unit_cents:number|null};
type Cell={quantity:string;requestId:string;submitted:boolean};
const euros=(cents:number)=>new Intl.NumberFormat("fr-FR",{style:"currency",currency:"EUR"}).format(cents/100);
const dateIn=(zone:string)=>new Intl.DateTimeFormat("sv-SE",{timeZone:zone,year:"numeric",month:"2-digit",day:"2-digit"}).format(new Date());

export default function HotelMinibarWorkspace(){
  const [hotels,setHotels]=useState<Hotel[]>([]),[hotel,setHotel]=useState(0),[day,setDay]=useState("");
  const [error,setError]=useState("");
  useEffect(()=>{
    let live=true;
    all<Hotel>("hotels").then(items=>{
      if(!live)return;
      setHotels(items);
      if(items[0]){setHotel(items[0].id);setDay(dateIn(items[0].timezone));}
    }).catch(e=>{if(live)setError(e.message);});
    return ()=>{live=false;};
  },[]);
  return <section><h2>Mini-bar</h2>
    <label>Établissement <select value={hotel} onChange={e=>{
      const selected=hotels.find(h=>h.id===Number(e.target.value));
      if(selected){setHotel(selected.id);setDay(dateIn(selected.timezone));}
    }}>{hotels.map(h=><option key={h.id} value={h.id}>{h.name}</option>)}</select></label>
    <label> Journée <input type="date" value={day} onChange={e=>setDay(e.target.value)}/></label>
    {error&&<p role="alert">{error}</p>}
    {hotel>0&&day&&<DayGrid key={hotel+"-"+day} hotel={hotel} day={day}/>}
  </section>;
}

function DayGrid({hotel,day}:{hotel:number;day:string}){
  const base="hotels/"+hotel+"/minibar";
  const [rooms,setRooms]=useState<Room[]>([]),[products,setProducts]=useState<Product[]>([]);
  const [cells,setCells]=useState<Record<string,Cell>>({}),[refresh,setRefresh]=useState(0);
  const [busy,setBusy]=useState(false),[error,setError]=useState(""),[notice,setNotice]=useState("");
  useEffect(()=>{
    let live=true;
    Promise.all([api<{rooms:Room[]}>("hotels/room-day?hotel_id="+hotel+"&business_date="+day),
      api<Product[]>(base+"/products?business_date="+day)]).then(([summary,catalog])=>{
      if(live){setRooms(summary.rooms);setProducts(catalog);}
    }).catch(e=>{if(live)setError(e.message);});
    return ()=>{live=false;};
  },[hotel,day,base,refresh]);
  const active=products.filter(p=>p.active&&p.unit_cents!==null);
  function quantity(key:string,value:string){
    setCells(previous=>({...previous,[key]:{quantity:value,requestId:previous[key]?.requestId??crypto.randomUUID(),submitted:false}}));
  }
  async function save(e:FormEvent){
    e.preventDefault();if(busy)return;setBusy(true);setError("");setNotice("");
    let saved=0;
    try{
      for(const [key,cell] of Object.entries(cells)){
        if(!cell.quantity)continue;
        const [room_id,product_id]=key.split("/").map(Number);
        setCells(previous=>({...previous,[key]:{...cell,submitted:true}}));
        try {
          await send(base+"/consumptions","POST",{room_id,product_id,business_date:day,
            quantity:Number(cell.quantity),request_id:cell.requestId});
        } catch (failure) {
          if(failure instanceof ApiError && failure.status>=400 && failure.status<500){
            setCells(previous=>({...previous,[key]:{...cell,submitted:false}}));
          }
          throw failure;
        }
        saved++;setCells(previous=>{const next={...previous};delete next[key];return next;});
      }
    }catch(e){setError((e as Error).message);}
    finally{if(saved){setNotice(saved+" ligne(s) enregistrée(s).");setRefresh(n=>n+1);}setBusy(false);}
  }
  async function catalog(e:FormEvent<HTMLFormElement>,price:boolean){
    e.preventDefault();if(busy)return;
    const form=e.currentTarget,fields=new FormData(form),id=Number(fields.get("product"));
    setBusy(true);setError("");
    try{
      const payload={effective_on:String(fields.get("effective_on")),unit_cents:Number(fields.get("unit_cents"))};
      await send(price?base+"/products/"+id+"/prices":base+"/products","POST",price?payload:
        {...payload,sku:String(fields.get("sku")),name:String(fields.get("name"))});
      form.reset();setRefresh(n=>n+1);setNotice("Catalogue enregistré.");
    }catch(e){setError((e as Error).message);}finally{setBusy(false);}
  }
  return <>
    {error&&<p role="alert" className="error">{error}</p>}{notice&&<p role="status">{notice}</p>}
    <details><summary>Paramétrer les articles et tarifs</summary>
      <form onSubmit={e=>void catalog(e,false)}>
        <label>Code article <input name="sku" required pattern="[A-Z0-9][A-Z0-9._\-]{0,39}" maxLength={40}/></label>
        <label>Libellé <input name="name" required maxLength={120}/></label>
        <label>Prix en centimes <input type="number" min={0} max={1000000} required name="unit_cents"/></label>
        <label>Applicable le <input type="date" name="effective_on" defaultValue={day} required/></label>
        <button disabled={busy}>Ajouter l’article</button>
      </form>
      {products.length>0&&<form onSubmit={e=>void catalog(e,true)}>
        <label>Article à retarifer <select name="product">{products.map(p=><option key={p.id} value={p.id}>{p.sku} · {p.name}</option>)}</select></label>
        <label>Nouveau prix en centimes <input type="number" min={0} max={1000000} required name="unit_cents"/></label>
        <label>À partir du <input type="date" name="effective_on" defaultValue={day} required/></label>
        <button disabled={busy}>Ajouter le tarif</button>
      </form>}
      {products.length>0&&<ul>{products.map(product=><li key={product.id}>
        {product.name} · {product.sku} <button type="button" disabled={busy} onClick={async()=>{
          setBusy(true);setError("");
          try {
            await send(base+"/products/"+product.id,"PATCH",{active:!product.active});
            setRefresh(n=>n+1);
          } catch(e){setError((e as Error).message);} finally{setBusy(false);}
        }}>{product.active?"Désactiver":"Réactiver"}</button>
      </li>)}</ul>}
    </details>
    <h3>Saisie des consommations</h3>
    {active.length>0&&rooms.length>0?<form onSubmit={save}><div className="table-scroll"><table>
      <thead><tr><th>Chambre</th>{active.map(p=><th key={p.id}>{p.name} · {euros(p.unit_cents!)}</th>)}</tr></thead>
      <tbody>{rooms.map(room=><tr key={room.id}><th>{room.room_number}</th>{active.map(p=>{
        const key=room.id+"/"+p.id;
        return <td key={p.id}><input type="number" min={1} max={999} step={1} aria-label={room.room_number+" "+p.sku}
          disabled={busy||cells[key]?.submitted} value={cells[key]?.quantity??""} onChange={e=>quantity(key,e.target.value)}/></td>;
      })}</tr>)}</tbody>
    </table></div><button disabled={busy||!Object.values(cells).some(c=>Boolean(c.quantity))}>Enregistrer les consommations</button></form>
      :<p>La saisie nécessite une chambre et un article tarifé pour cette journée.</p>}
    <MinibarReports hotel={hotel} day={day} refresh={refresh}/>
  </>;
}
