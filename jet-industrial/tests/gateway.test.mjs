import test from "node:test";
import assert from "node:assert/strict";
import http from "node:http";
import {spawn} from "node:child_process";
import {resolve} from "node:path";
import {setTimeout as delay} from "node:timers/promises";
async function listen(handler){
 const server=http.createServer(handler);
 await new Promise(done=>server.listen(0,"127.0.0.1",done));
 return {server,port:server.address().port};
}
const close=server=>new Promise(done=>server.close(done));
test("JET gateway sends /api to Laravel gateway and static pages to Oracle JET",async t=>{
 const received=[];
 const industrialRequests=[];
 const jet=await listen((_req,res)=>{res.writeHead(200,{"content-type":"text/html"});res.end("<main>Oracle JET</main>");});
 const backend=await listen((req,res)=>{
  received.push({url:req.url,cookie:req.headers.cookie,company:req.headers["x-atlas-expected-company"]});
  res.writeHead(200,{"content-type":"application/json","set-cookie":"atlas_session=test; Path=/; HttpOnly; SameSite=Lax"});
  res.end(JSON.stringify({name:"Test User",active_company_id:2}));
 });
 const industrial=await listen((req,res)=>{
  industrialRequests.push({url:req.url,cookie:req.headers.cookie,company:req.headers["x-atlas-expected-company"]});
  res.writeHead(200,{"content-type":"application/json"});
  res.end(JSON.stringify({meter_readings_30d:7}));
 });
 const reservation=await listen((_req,res)=>res.end("not used"));
 const gatewayPort=reservation.port;
 await close(reservation.server);
 const child=spawn(process.execPath,[resolve("jet-industrial/scripts/proxy.mjs")],{
  env:{...process.env,ATLAS_WEB_PORT:String(backend.port),ATLAS_JET_PORT:String(jet.port),ATLAS_INDUSTRIAL_NODE_PORT:String(industrial.port),ATLAS_JET_GATEWAY_PORT:String(gatewayPort)},
  stdio:["ignore","pipe","pipe"]
 });
 t.after(async()=>{child.kill();await Promise.all([close(jet.server),close(backend.server),close(industrial.server)]);});
 let ready=false;
 for(let i=0;i<60;i++){
  if(child.exitCode!==null)throw Error("Gateway process exited prematurely");
  try{const response=await fetch("http://127.0.0.1:"+gatewayPort+"/");ready=response.ok;break;}
  catch{await delay(100);}
 }
 assert.equal(ready,true);
 const page=await fetch("http://127.0.0.1:"+gatewayPort+"/");
 assert.match(await page.text(),/Oracle JET/);
 const response=await fetch("http://127.0.0.1:"+gatewayPort+"/api/me",{
  headers:{Cookie:"atlas_session=dev-only","X-Atlas-Expected-Company":"2"}
 });
 assert.equal(response.status,200);
 assert.equal((await response.json()).active_company_id,2);
 assert.match(response.headers.get("set-cookie")??"",/HttpOnly/);
 assert.deepEqual(received,[{url:"/api/me",cookie:"atlas_session=dev-only",company:"2"}]);
 const industrialResponse=await fetch("http://127.0.0.1:"+gatewayPort+"/api/industrial-jet/overview",{
  headers:{Cookie:"atlas_session=dev-only","X-Atlas-Expected-Company":"2"}
 });
 assert.equal(industrialResponse.status,200);
 assert.equal((await industrialResponse.json()).meter_readings_30d,7);
 assert.deepEqual(industrialRequests,[{
  url:"/api/industrial-jet/overview",cookie:"atlas_session=dev-only",company:"2"
 }]);
});
