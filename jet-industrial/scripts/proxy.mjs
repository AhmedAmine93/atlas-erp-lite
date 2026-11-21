/**
 * Loopback-only same-origin gateway for local Oracle JET development.
 * /api/* -> existing Next.js port 3100, all other requests -> Oracle JET :8000.
 * Sign in at http://127.0.0.1:3100 then browse http://127.0.0.1:8100.
 */
import http from "node:http";
const host="127.0.0.1";
const nextPort=Number(process.env.ATLAS_WEB_PORT??3100);
if(!Number.isInteger(nextPort)||nextPort<1||nextPort>65535)throw Error("Invalid ATLAS_WEB_PORT");
http.createServer((req,res)=>{
 const target=(req.url||"/").startsWith("/api/")?nextPort:8000;
 const upstream=http.request({
  host,port:target,method:req.method,path:req.url,
  headers:{...req.headers,host:host+":"+target}
 },incoming=>{res.writeHead(incoming.statusCode??502,incoming.headers);incoming.pipe(res);});
 upstream.on("error",()=>{
  if(!res.headersSent)res.writeHead(502,{"Content-Type":"text/plain; charset=utf-8"});
  res.end("JET or ATLAS application unavailable.");
 });
 req.pipe(upstream);
}).listen(8100,host,()=>console.log("JET gateway: http://127.0.0.1:8100"));
