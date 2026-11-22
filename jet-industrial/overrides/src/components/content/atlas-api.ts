/* Shared Laravel session, explicit tenant header and CSRF; no tokens in localStorage. */
export interface Page<T> {data:T[];current_page:number;last_page:number;total:number;}
export interface Site {id:number;code:string;name:string;active:boolean;}
export interface Asset {id:number;asset_code:string;name:string;site_id:number;status:string;}
export interface Intervention {id:number;reference:string;title:string;description?:string|null;asset_id:number;priority:string;status:string;}
export interface WorkOrder {id:number;maintenance_request_id:number;asset_id:number;status:string;priority:string;summary:string;due_at?:string|null;resolution?:string|null;}
export interface MaintenanceTeam {id:number;code:string;name:string;active:boolean;}
export interface MaintenanceTechnician {id:number;code:string;name:string;team_id:number|null;active:boolean;}
export interface MaintenanceSlot {id:number;work_order_id:number;technician_id:number;starts_at:string;ends_at:string;status:"scheduled"|"cancelled";cancellation_reason?:string|null;technician?:MaintenanceTechnician;work_order?:WorkOrder;}
export interface MaintenanceReport {period:{from:string;to:string};generated_at:string;work_orders_by_status:Record<string,number>;requests_by_priority:Record<string,number>;overdue_work_orders:number;schedules_by_status:Record<string,number>;technicians_scheduled:{technician_id:number;name:string;scheduled:number}[];}
export interface SessionProfile {name:string;email:string;active_company_id:number;role:{name:string};companies:{id:number;name:string;}[];}
export class HttpError extends Error {
  constructor(public readonly status:number,message:string,public readonly stale=false){super(message);}
}
let tenantId:number|null=null;
let revision=0;
let csrf="";
export function resetSession(){tenantId=null;csrf="";revision++;}
export function setSession(profile:SessionProfile){
  const next=profile.active_company_id;
  if(!Number.isInteger(next)||next<1)throw new Error("Entreprise active absente.");
  if(tenantId!==next){csrf="";revision++;}
  tenantId=next;
}
function pathCheck(endpoint:string):string{
  if(!/^[a-z0-9/?=&_-]+$/i.test(endpoint)||endpoint.startsWith("/")||endpoint.includes("..")){
    throw new Error("Invalid API path.");
  }
  return "/api/"+endpoint;
}
export async function api<T>(endpoint:string,options:RequestInit={}):Promise<T>{
  const epoch=revision;
  const route=endpoint.split("?")[0];
  const free=route==="me"||route==="csrf";
  if(!free&&tenantId===null)throw new HttpError(401,"Session GMAO non initialisée.");
  const headers=new Headers(options.headers);
  headers.set("Accept","application/json");
  if(!free&&tenantId!==null)headers.set("X-Atlas-Expected-Company",String(tenantId));
  const method=(options.method??"GET").toUpperCase();
  if(!["GET","HEAD"].includes(method)){
    if(!csrf){
      const response=await fetch("/api/csrf",{credentials:"same-origin",cache:"no-store"});
      if(!response.ok)throw new HttpError(response.status,"Session CSRF indisponible.");
      csrf=(await response.json()).token;
      if(epoch!==revision)throw new HttpError(409,"Entreprise modifiée.",true);
    }
    headers.set("Content-Type","application/json");
    headers.set("X-CSRF-TOKEN",csrf);
  }
  const response=await fetch(pathCheck(endpoint),{...options,method,headers,credentials:"same-origin",cache:"no-store"});
  if(epoch!==revision)throw new HttpError(409,"Réponse obsolète après changement de société.",true);
  if(response.status===401||response.status===419){resetSession();throw new HttpError(response.status,"Session expirée. Reconnectez-vous au portail ATLAS.");}
  if(response.status===409&&response.headers.get("X-Atlas-Context-Stale")==="1"){
    resetSession();throw new HttpError(409,"Entreprise changée dans un autre onglet.",true);
  }
  const body=await response.json().catch(()=>({message:"Réponse API invalide."}));
  if(!response.ok){
    const validation=body.errors?Object.values(body.errors).flat().join(" "):"";
    throw new HttpError(response.status,validation||body.message||"Erreur serveur");
  }
  return body as T;
}
export async function fetchPages<T>(endpoint:string,limit=12):Promise<T[]>{
  const rows:T[]=[];
  for(let page=1;page<=limit;page++){
    const data=await api<Page<T>>(endpoint+"?page="+page);
    rows.push(...data.data);
    if(page>=data.last_page)return rows;
  }
  throw new Error("Trop de résultats : activer une pagination explicite.");
}
export function post<T>(path:string,data:Record<string,unknown>={}):Promise<T>{
  return api<T>(path,{method:"POST",body:JSON.stringify(data)});
}
