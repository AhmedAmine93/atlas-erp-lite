/**
 * Loopback-only same-origin gateway for local Oracle JET development.
 * /api/* -> existing Next.js port 3100, all other requests -> Oracle JET :8000.
 * Sign in at http://127.0.0.1:3100 then browse http://127.0.0.1:8100.
 */
import http from "node:http";
const host="127.0.0.1";
const nextPort=Number(process.env.ATLAS_WEB_PORT??3100);
const jetPort=Number(process.env.ATLAS_JET_PORT??8000);
const gatewayPort=Number(process.env.ATLAS_JET_GATEWAY_PORT??8100);
for(const port of [nextPort,jetPort,gatewayPort])if(!Number.isInteger(port)||port<1||port>65535)throw Error("Invalid ATLAS port");
http.createServer((req,res)=>{
 const target=(req.url||"/").startsWith("/api/")?nextPort:jetPort;
 const upstream=http.request({
  host,port:target,method:req.method,path:req.url,
  headers:{...req.headers,host:host+":"+target}
 },incoming=>{res.writeHead(incoming.statusCode??502,incoming.headers);incoming.pipe(res);});
 upstream.on("error",()=>{
  if(!res.headersSent)res.writeHead(502,{"Content-Type":"text/plain; charset=utf-8"});
  res.end("JET or ATLAS application unavailable.");
 });
 req.pipe(upstream);
}).listen(gatewayPort,host,()=>console.log("JET gateway: http://127.0.0.1:"+gatewayPort));
