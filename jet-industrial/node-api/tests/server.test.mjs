import test from "node:test";
import assert from "node:assert/strict";
import http from "node:http";
import {createHandler} from "../src/server.mjs";

async function boot(handler){
 const server=http.createServer((req,res)=>void handler(req,res));
 await new Promise(resolve=>server.listen(0,"127.0.0.1",resolve));
 return {port:server.address().port,close:()=>new Promise(resolve=>server.close(resolve))};
}
function json(value,status=200){
 return new Response(JSON.stringify(value),{status,headers:{"Content-Type":"application/json"}});
}
const fakeRedis=()=>{
 const cache=new Map();const emitted=[];
 return {
  isReady:true,emitted,cache,
  async get(key){return cache.get(key)??null;},
  async set(key,value){cache.set(key,value);return "OK";},
  async incr(key){const next=Number(cache.get(key)??0)+1;cache.set(key,String(next));return next;},
  async publish(channel,value){emitted.push([channel,JSON.parse(value)]);return 1;}
 };
};
function profile(company=5,role="admin"){return {active_company_id:company,role:{name:role}};}
async function call(port,path,options={}){
 return fetch("http://127.0.0.1:"+port+path,{...options,
  headers:{Cookie:"laravel_session=fake", "X-Atlas-Expected-Company":"5",...options.headers}
 });
}
test("Node BFF authenticates even cache HIT, prevents tenant confusion, then invalidates after mutation",async()=>{
 let role="admin",company=5,sourceCount=0,authCount=0;
 const redis=fakeRedis();
 const handler=createHandler({redis,upstream:async(url,opt)=>{
  if(url.endsWith("/api/me")){authCount++;return json(profile(company,role));}
  if(url.endsWith("/api/industrial/overview")){sourceCount++;return json({count:sourceCount,company_id:5});}
  if(url.endsWith("/api/industrial/meters")&&opt.method==="POST")return json({id:99,replayed:false},201);
  throw Error("Unexpected "+url);
 }});
 const {port,close}=await boot(handler);
 try{
  let response=await call(port,"/api/industrial-jet/overview");
  assert.equal(response.status,200);
  assert.equal(response.headers.get("X-Atlas-Cache"),"MISS");
  assert.equal((await response.json()).count,1);
  response=await call(port,"/api/industrial-jet/overview");
  assert.equal(response.headers.get("X-Atlas-Cache"),"HIT");
  assert.equal((await response.json()).count,1);
  assert.equal(sourceCount,1);
  assert.equal(authCount,2);
  role="reader";
  response=await call(port,"/api/industrial-jet/overview");
  assert.equal(response.status,403);
  role="admin";company=9;
  response=await call(port,"/api/industrial-jet/overview");
  assert.equal(response.status,409);
  assert.equal(response.headers.get("X-Atlas-Context-Stale"),"1");
  company=5;
  response=await call(port,"/api/industrial-jet/meters",{
   method:"POST",headers:{"Content-Type":"application/json","X-CSRF-TOKEN":"token"},
   body:JSON.stringify({asset_id:4,meter_code:"HOURS",reading_value:100,idempotency_key:"key-12345678"})
  });
  assert.equal(response.status,201);
  assert.equal(redis.emitted.length,1);
  assert.equal(redis.emitted[0][1].company_id,5);
  response=await call(port,"/api/industrial-jet/overview");
  assert.equal((await response.json()).count,2);
  assert.equal(sourceCount,2);
 }finally{await close();}
});
test("Node BFF rejects missing session, bad tenant, CSRF omission and invalid route",async()=>{
 let upstreamCount=0;
 const handler=createHandler({upstream:async url=>{
  upstreamCount++;return url.endsWith("/api/me")?json(profile()):json({ok:true});
 }});
 const {port,close}=await boot(handler);
 try{
  let r=await fetch("http://127.0.0.1:"+port+"/api/industrial-jet/overview");
  assert.equal(r.status,409);
  r=await fetch("http://127.0.0.1:"+port+"/api/industrial-jet/overview",{
   headers:{"X-Atlas-Expected-Company":"5"}
  });
  assert.equal(r.status,401);
  r=await call(port,"/api/industrial-jet/unknown");
  assert.equal(r.status,405);
  r=await call(port,"/api/industrial-jet/overview?company_id=999");
  assert.equal(r.status,422);
  r=await call(port,"/api/industrial-jet/meters",{
   method:"POST",headers:{"Content-Type":"application/json"},body:"{}"
  });
  assert.equal(r.status,419);
  r=await call(port,"/api/industrial-jet/meters",{
   method:"POST",headers:{"Content-Type":"application/json","X-CSRF-TOKEN":"token"},
   body:JSON.stringify({company_id:8})
  });
  assert.equal(r.status,422);
  assert.equal(upstreamCount,2);
 }finally{await close();}
});
test("Redis failure never skips authentication or business source, write errors are not published",async()=>{
 let authorization=true,calls=0;
 const redis={isReady:true,async get(){throw Error("redis down");},async set(){throw Error("redis down");},
  async incr(){throw Error("redis down");},async publish(){throw Error("should not publish");}};
 const handler=createHandler({redis,upstream:async url=>{
  if(url.endsWith("/api/me"))return authorization?json(profile()):json({message:"unauthorized"},401);
  calls++;return json({value:1});
 }});
 const {port,close}=await boot(handler);
 try{
  let r=await call(port,"/api/industrial-jet/overview");
  assert.equal(r.status,200);
  authorization=false;
  r=await call(port,"/api/industrial-jet/overview");
  assert.equal(r.status,401);
  assert.equal(calls,1);
 }finally{await close();}
});
