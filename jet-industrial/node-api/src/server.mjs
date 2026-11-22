/**
 * ATLAS industrial local Node BFF (read cache + invalidation only).
 * Authorization, CSRF and business transactions always execute in Laravel.
 * Redis is disposable; the PostgreSQL schema/functions are the source of truth.
 */
import http from "node:http";
import { createClient } from "redis";

const PATH_PREFIX="/api/industrial-jet/";
const ROUTES=new Map([
 ["overview",new Set(["GET"])],
 ["meters",new Set(["GET","POST"])],
 ["templates",new Set(["GET","POST"])],
 ["inspections",new Set(["GET","POST"])],
]);
const MAX_BODY=32768;
const TTL=10;
const FETCH_TIMEOUT=8000;
const ownerHost=process.env.HOST??"127.0.0.1";
const port=Number(process.env.PORT??8300);
const laravelBase=process.env.ATLAS_LARAVEL_URL??"http://127.0.0.1:3100";
if(!/^https?:\/\/[A-Za-z0-9_.:-]+(?::\d+)?$/.test(laravelBase))throw Error("Invalid backend URL");

function reply(res,status,body,extra={}){
 res.writeHead(status,{"Content-Type":"application/json; charset=utf-8",
  "Cache-Control":"private, no-store","X-Content-Type-Options":"nosniff",...extra});
 res.end(JSON.stringify(body));
}
const idValid=value=>typeof value==="number"&&Number.isSafeInteger(value)&&value>0;
const tenantKey=(company,section,search,version)=>"jet:v2:"+company+":"+version+":"+section+":"+encodeURIComponent(search);
const VERSION_KEY=company=>"jet:v2:version:"+company;
const PUBLISH_TOPIC="atlas:jet:changes";

export function createHandler({upstream,redis=null}){
 if(typeof upstream!=="function")throw Error("upstream fetch required");
 const cacheGet=async key=>{try{return redis?.isReady?await redis.get(key):null;}catch{return null;}};
 const cacheSet=async(key,value)=>{try{if(redis?.isReady)await redis.set(key,value,{EX:TTL});}catch{/* fail-open */}};
 const version=async company=>{try{return redis?.isReady?(await redis.get(VERSION_KEY(company))??"1"):"0";}catch{return "0";}};
 const invalidate=async company=>{
  try{if(redis?.isReady){const next=await redis.incr(VERSION_KEY(company));
   await redis.publish(PUBLISH_TOPIC,JSON.stringify({company_id:company,revision:next,at:new Date().toISOString()}));
  }}catch{/* cache is best effort; TTL protects stale entries */}
 };
 return async(req,res)=>{
  if(req.url==="/health"&&req.method==="GET"){reply(res,200,{status:"ok"});return;}
  if(typeof req.url!=="string"||!req.url.startsWith(PATH_PREFIX)){
   reply(res,404,{message:"Route industrielle introuvable."});return;
  }
  const url=new URL(req.url,"http://127.0.0.1");
  const section=url.pathname.slice(PATH_PREFIX.length);
  const allowed=ROUTES.get(section);
  if(!allowed||!allowed.has(req.method)){
   reply(res,405,{message:"Méthode ou sous-menu non autorisé."});return;
  }
  if([...url.searchParams.keys()].some(key=>!["page","asset_id","meter_code","result"].includes(key))){
   reply(res,422,{message:"Filtre industriel inconnu."});return;
  }
  const expected=req.headers["x-atlas-expected-company"];
  if(typeof expected!=="string"||!/^[1-9][0-9]{0,17}$/.test(expected)){
   reply(res,409,{message:"Entreprise active non vérifiée."},{"X-Atlas-Context-Stale":"1"});return;
  }
  const cookie=typeof req.headers.cookie==="string"?req.headers.cookie:"";
  if(!cookie){reply(res,401,{message:"Session requise."});return;}
  const headers={Cookie:cookie,Accept:"application/json","X-Atlas-Expected-Company":expected};
  try{
   // Session checked on EVERY call including cache hits, never trusted from caller.
   const auth=await upstream(laravelBase+"/api/me",{headers,redirect:"manual",signal:AbortSignal.timeout(FETCH_TIMEOUT)});
   if(!auth.ok){reply(res,auth.status===409?409:401,
     {message:"Session ou contexte invalide."},auth.status===409?{"X-Atlas-Context-Stale":"1"}:{});return;}
   const profile=await auth.json();
   if(!idValid(profile.active_company_id)||String(profile.active_company_id)!==expected){
    reply(res,409,{message:"Le contexte entreprise a changé."},{"X-Atlas-Context-Stale":"1"});return;
   }
   if(profile.role?.name!=="admin"){reply(res,403,{message:"Droit GMAO administrateur requis."});return;}
   const company=profile.active_company_id;
   const target=laravelBase+"/api/industrial/"+section+url.search;
   if(req.method==="GET"){
    const key=tenantKey(company,section,url.search,await version(company));
    const hit=await cacheGet(key);
    if(hit){
      try {reply(res,200,JSON.parse(hit),{"X-Atlas-Cache":"HIT"});return;}
      catch { /* corrupted cached data: refetch */ }
    }
    const data=await upstream(target,{method:"GET",headers,redirect:"manual",signal:AbortSignal.timeout(FETCH_TIMEOUT)});
    const raw=await data.text();
    let value;
    try{value=JSON.parse(raw);}catch{reply(res,502,{message:"Réponse métier invalide."});return;}
    if(!data.ok){reply(res,data.status,value);return;}
    await cacheSet(key,JSON.stringify(value));
    reply(res,200,value,{"X-Atlas-Cache":"MISS"});return;
   }
   const origin=req.headers.origin;
   // Browser sends Origin for same-origin mutations; an absent Origin is allowed
   // for nonbrowser clients only because Laravel CSRF still strictly applies.
   if(origin && origin!==("http://127.0.0.1:"+(process.env.ATLAS_JET_GATEWAY_PORT??8100))
       && origin!==("http://localhost:"+(process.env.ATLAS_JET_GATEWAY_PORT??8100))){
     reply(res,403,{message:"Origine non autorisée."});return;
   }
   const csrf=req.headers["x-csrf-token"];
   if(typeof csrf!=="string"||!csrf){reply(res,419,{message:"Protection CSRF requise."});return;}
   const contentType=req.headers["content-type"];
   if(typeof contentType!=="string"||!contentType.toLowerCase().startsWith("application/json")){
     reply(res,415,{message:"JSON requis."});return;
   }
   const pieces=[];
   let size=0;
   for await(const chunk of req){
     size+=chunk.length;
     if(size>MAX_BODY){reply(res,413,{message:"Corps de requête trop volumineux."});return;}
     pieces.push(chunk);
   }
   const body=Buffer.concat(pieces).toString("utf8");
   try{
     const parsed=JSON.parse(body);
     if(!parsed||Array.isArray(parsed)||typeof parsed!=="object"
        ||Object.hasOwn(parsed,"company_id")||Object.hasOwn(parsed,"tenant_id")){
       reply(res,422,{message:"Contexte entreprise interdit dans le corps."});return;
     }
   }catch{reply(res,400,{message:"JSON invalide."});return;}
   const result=await upstream(target,{method:"POST",headers:{...headers,
     "Content-Type":"application/json","X-CSRF-TOKEN":csrf},
     body,redirect:"manual",signal:AbortSignal.timeout(FETCH_TIMEOUT)});
   const raw=await result.text();
   let value;
   try{value=JSON.parse(raw);}catch{reply(res,502,{message:"Réponse métier invalide."});return;}
   if(result.ok)await invalidate(company);
   reply(res,result.status,value);
  }catch(err){
   reply(res,502,{message:"API métier ou cache indisponible."});
  }
 };
}

export async function start(){
 const redis=createClient({url:process.env.REDIS_URL??"redis://127.0.0.1:6379",
  socket:{connectTimeout:1500,reconnectStrategy:retries=>Math.min(1000*retries,5000)}});
 redis.on("error",()=>{}); // Redis outage never bypasses Laravel or invents state.
 redis.connect().catch(()=>{});
 const handler=createHandler({upstream:fetch,redis});
 const server=http.createServer((req,res)=>void handler(req,res));
 server.requestTimeout=15000;
 server.headersTimeout=10000;
 server.listen(port,ownerHost,()=>console.log("Industrial Node BFF active on "+ownerHost+":"+port));
}
if(import.meta.url===new URL(process.argv[1]??"/", "file://").href){
 await start();
}
