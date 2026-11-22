import test from "node:test";
import assert from "node:assert/strict";
import {api, post, resetSession, setSession, HttpError} from "../overrides/src/components/content/atlas-api.ts";

const originalFetch=globalThis.fetch;
const profile=id=>({
 name:"Compte fictif",email:"test@atlas.example",active_company_id:id,
 role:{name:"admin"},companies:[{id,name:"Fictive"}]
});
function json(body,status=200,headers={}){
 return new Response(JSON.stringify(body),{status,headers:{"Content-Type":"application/json",...headers}});
}
test.after(()=>{globalThis.fetch=originalFetch;resetSession();});
test("JET API sends the verified tenant header and CSRF token on real business writes",async()=>{
 resetSession();setSession(profile(7));
 const captures=[];
 globalThis.fetch=async(url,init={})=>{
  captures.push({url,method:init.method??"GET",headers:new Headers(init.headers),credentials:init.credentials});
  if(url==="/api/csrf")return json({token:"csrf-test"});
  if(url==="/api/maintenance/sites")return json({data:[],last_page:1,total:0});
  return json({id:10,code:"TEST"});
 };
 await api("maintenance/sites");
 await post("maintenance/sites",{code:"TEST",name:"Site fictif"});
 assert.equal(captures.length,3);
 assert.equal(captures[0].headers.get("X-Atlas-Expected-Company"),"7");
 assert.equal(captures[1].url,"/api/csrf");
 assert.equal(captures[2].headers.get("X-CSRF-TOKEN"),"csrf-test");
 assert.equal(captures[2].headers.get("X-Atlas-Expected-Company"),"7");
 assert.equal(captures[2].credentials,"same-origin");
});
test("JET API refuses to consume replies from the previous tenant",async()=>{
 resetSession();setSession(profile(2));
 let complete;
 globalThis.fetch=()=>new Promise(resolve=>{complete=resolve;});
 const pending=api("maintenance/sites");
 setSession(profile(3));
 complete(json({data:[{company_id:2}],last_page:1,total:1}));
 await assert.rejects(pending,err=>err instanceof HttpError&&err.stale===true);
});
test("JET API fails closed on stale-tenant 409 and session remains invalid",async()=>{
 resetSession();setSession(profile(5));
 globalThis.fetch=async()=>json({message:"Contexte changé"},409,{"X-Atlas-Context-Stale":"1"});
 await assert.rejects(api("maintenance/assets"),err=>err instanceof HttpError&&err.stale===true);
 await assert.rejects(api("maintenance/assets"),err=>err instanceof HttpError&&err.status===401);
});
test("JET API does not let callers use external or traversal URLs",async()=>{
 resetSession();setSession(profile(1));
 await assert.rejects(api("../api/users"),/Invalid API path/);
 await assert.rejects(api("https://evil.example/test"),/Invalid API path/);
});
