/**
 * ATLAS V5 — 120 simultaneously active HTTP users (Node.js 24+, no packages).
 *
 * Safety: loopback target only, read-only API workload, 150 synthetic accounts.
 * Pre-login sessions are prepared in small batches to isolate login cost from
 * the measured 120-user synchronized request burst.
 *
 * PowerShell:
 *   $env:DEMO_PASSWORD=(... read securely from .env ...)
 *   node scripts/load-120-users.mjs --users=120 --rounds=5 --base=http://127.0.0.1:3100
 */
import { performance } from "node:perf_hooks";
import { writeFileSync, mkdirSync } from "node:fs";
import { resolve } from "node:path";

const args = new Map(process.argv.slice(2).map(s => {
  const p = s.replace(/^--/, "").split("=");
  return [p[0], p.slice(1).join("=") || "true"];
}));
const users = Number(args.get("users") ?? 120);
const rounds = Number(args.get("rounds") ?? 5);
const baseUrl = new URL(args.get("base") ?? "http://127.0.0.1:3100");
const password = process.env.DEMO_PASSWORD;
if (![baseUrl.hostname].every(h => ["localhost", "127.0.0.1", "::1", "[::1]"].includes(h))) {
  throw new Error("Load test refused: only localhost/127.0.0.1/::1 accepted.");
}
if (baseUrl.protocol !== "http:" || baseUrl.username || baseUrl.password) {
  throw new Error("Use an unauthenticated http:// localhost URL only.");
}
if (!Number.isInteger(users) || users < 101 || users > 150 ||
    !Number.isInteger(rounds) || rounds < 1 || rounds > 20) {
  throw new Error("--users must be 101..150 and --rounds must be 1..20.");
}
if (!password || password.length < 12) {
  throw new Error("Set DEMO_PASSWORD with the local test password (minimum 12 chars).");
}

const samples = [];
const failures = [];
const sessions = [];
function cookieEntries(headers) {
  // Node >= 24: getSetCookie() preserves individual Set-Cookie headers.
  const lines = headers.getSetCookie?.() ?? [];
  return lines.map(line => line.split(";")[0]).filter(s => /^[^=;\s]+=.*/.test(s));
}
function updateCookies(state, headers) {
  for (const entry of cookieEntries(headers)) {
    const eq = entry.indexOf("=");
    state.cookies.set(entry.slice(0, eq), entry.slice(eq + 1));
  }
}
function cookieString(state) {
  return [...state.cookies].map(([k, v]) => k + "=" + v).join("; ");
}
async function request(state, path, init = {}, record = true) {
  const started = performance.now();
  let status = 0;
  try {
    const headers = new Headers(init.headers);
    headers.set("Accept", "application/json");
    if (state?.cookies?.size) headers.set("Cookie", cookieString(state));
    if (state?.companyId && path !== "/api/me") {
      headers.set("X-Atlas-Expected-Company", String(state.companyId));
    }
    const response = await fetch(new URL(path, baseUrl), {
      ...init, headers, redirect: "manual",
      signal: AbortSignal.timeout(20_000),
    });
    status = response.status;
    updateCookies(state, response.headers);
    const text = await response.text();
    const data = text ? (() => { try { return JSON.parse(text); } catch { return null; } })() : null;
    if (record) samples.push({path, status, ms: performance.now() - started});
    if (!response.ok || response.status >= 300) {
      const error = new Error("HTTP " + status + " on " + path + " : " + (data?.message ?? text.slice(0, 160)));
      error.status = status;
      throw error;
    }
    return data;
  } catch (e) {
    if (record && status === 0) samples.push({path, status, ms: performance.now() - started});
    throw e;
  }
}
const rolePaths = {
  admin: ["/api/dashboard","/api/purchasing/requests","/api/hotels","/api/data/sources","/api/maintenance/work-orders"],
  commercial: ["/api/dashboard","/api/customers","/api/quotes","/api/products"],
  stock: ["/api/dashboard","/api/products","/api/stock-movements"],
  reader: ["/api/dashboard","/api/customers","/api/invoices"],
  support_n2: ["/api/support/incidents","/api/support/incidents/summary"],
  support_n3: ["/api/support/incidents","/api/support/incidents/summary"],
};
async function login(number) {
  const session = {number, companyId:null, cookies: new Map()};
  const csrf = await request(session, "/api/csrf", {}, false);
  if (!csrf?.token) throw new Error("CSRF token missing");
  const profile = await request(session, "/api/login", {
    method: "POST",
    headers: {"Content-Type":"application/json","X-CSRF-TOKEN":csrf.token},
    body: JSON.stringify({
      email: "load"+String(number).padStart(3,"0")+"@atlas.example",
      password,
    }),
  }, false);
  if (!profile?.active_company_id || !rolePaths[profile.role?.name]) {
    throw new Error("Unexpected role or tenant for synthetic user #"+number);
  }
  session.companyId = profile.active_company_id;
  session.role = profile.role.name;
  return session;
}
async function withLimit(items, limit, callback) {
  let index = 0;
  const output = Array(items.length);
  await Promise.all(Array.from({length:Math.min(items.length,limit)}, async () => {
    while (index < items.length) {
      const n = index++;
      try { output[n] = await callback(items[n]); }
      catch (e) { output[n] = {error:String(e)}; }
    }
  }));
  return output;
}
const startedAt = new Date().toISOString();
const start = performance.now();
process.stdout.write("Preparing "+users+" independent authenticated sessions (max 12 simultaneous logins)...\n");
const prepared = await withLimit(Array.from({length:users},(_,i)=>i+1),12,login);
for (let i=0; i<prepared.length; i++) {
  const item = prepared[i];
  if (item?.error) failures.push({phase:"login",user:i+1,error:item.error});
  else sessions.push(item);
}
if (sessions.length !== users) {
  throw new Error("Cannot run synchronized burst: "+sessions.length+"/"+users+
    " users authenticated. First error: "+JSON.stringify(failures[0]));
}
const preparedAt = performance.now();
process.stdout.write("Authenticated: "+sessions.length+" distinct synthetic users. Starting synchronized burst...\n");
const burstStart = performance.now();
const tenantMismatches = [];
for (let round=0; round<rounds; round++) {
  // A Promise barrier starts 120 first requests before awaiting completion.
  await Promise.all(sessions.map(async session => {
    const list = rolePaths[session.role];
    const path = list[(round + session.number) % list.length];
    try {
      const data = await request(session, path);
      if (round === 0) {
        const me = await request(session,"/api/me");
        if (me?.active_company_id !== session.companyId ||
            me?.email !== "load"+String(session.number).padStart(3,"0")+"@atlas.example") {
          tenantMismatches.push({user:session.number,expected:session.companyId,received:me?.active_company_id});
        }
      }
      if (!data || typeof data !== "object") {
        failures.push({phase:"read",round,user:session.number,path,error:"Invalid JSON payload"});
      }
    } catch (e) {
      failures.push({phase:"read",round,user:session.number,path,error:String(e)});
    }
  }));
}
const elapsed = (performance.now()-burstStart)/1000;
function percentile(p) {
  const n = samples.map(x=>x.ms).sort((a,b)=>a-b);
  return n.length ? Number(n[Math.min(n.length-1, Math.ceil(n.length*p)-1)].toFixed(1)) : null;
}
const statusCodes = {};
const endpoints = {};
for (const s of samples) {
  statusCodes[s.status]=(statusCodes[s.status]??0)+1;
  const stat = endpoints[s.path]??{total:0,errors:0,maxMs:0};
  stat.total++;if (s.status>=400||s.status===0) stat.errors++;
  stat.maxMs=Math.round(Math.max(stat.maxMs,s.ms));
  endpoints[s.path]=stat;
}
const result = {
  scenario:"ATLAS-V5-120-users-readonly", startedAt, base:baseUrl.origin,
  usersRequested:users, authenticatedDistinctUsers:sessions.length, rounds,
  authSetupSeconds:Number(((preparedAt-start)/1000).toFixed(2)),
  burstSeconds:Number(elapsed.toFixed(2)),
  httpRequests:samples.length,
  requestsPerSecond:Number((samples.length/Math.max(.001,elapsed)).toFixed(2)),
  latencyMs:{p50:percentile(.5),p95:percentile(.95),p99:percentile(.99)},
  httpCodes:statusCodes,
  failureCount:failures.length,
  tenantMismatchCount:tenantMismatches.length,
  failures:failures.slice(0,20),
  tenantMismatches:tenantMismatches.slice(0,10),
  endpoints,
  conclusion: failures.length===0&&tenantMismatches.length===0 ? "PASS" : "FAIL",
};
mkdirSync(resolve("reports"),{recursive:true});
const output = resolve("reports","load-120-users-"+Date.now()+".json");
writeFileSync(output,JSON.stringify(result,null,2)+"\n");
process.stdout.write(JSON.stringify({...result,failures:result.failures.slice(0,5)},null,2)+"\n");
process.stdout.write("Evidence: "+output+"\n");
if(result.conclusion!=="PASS")process.exitCode=1;
