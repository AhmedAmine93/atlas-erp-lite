"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { api, send, fetchTenantBlob } from "@/lib/api";
import type { Page, Role } from "@/lib/types";

type CaseKind = "ticket" | "problem" | "service" | "change";
type View = "support-tickets" | "support-problems" | "support-services" | "support-changes" | "support-sla" | "support-knowledge";
type Case = {
  id: number; kind: CaseKind; reference: string; title: string;
  description: string; priority: string; status: string; version: number;
  root_cause?: string | null; workaround?: string | null; permanent_fix?: string | null;
  result_evidence?: string | null; verification?: string | null;
  pipeline_url?: string | null; risk_level?: string | null;
  rollback_plan?: string | null; window_start?: string | null; window_end?: string | null;
  catalog_id?: number | null; service_name?: string | null; created_at: string;
  requester?: { name: string }; assignee?: { name: string };
  events?: Array<{ id: number; action: string; from_status?: string; to_status: string; version: number; created_at: string; actor?: { name: string } }>;
  incidents?: Array<{ id: number; reference: string; title: string }>;
};
type Assignee = { id: number; name: string; role: string };
type Inbox = { id: number; kind: string; reference: string; title: string; status: string; priority: string; created_at: string };
type Catalog = { id: number; code: string; title: string; sensitive: boolean; active: boolean; requires_approval: boolean };
type SLA = { id: number; severity: string; version: number; response_minutes: number; resolution_minutes: number; calendar: string; published: boolean; effective_from: string };
type Escalation = { id: number; incident_id: number; target_level: number; status: string; reason: string; acknowledged_at: string | null };
type Metrics = { id: number; reference: string; severity: string; response_minutes: number | null; resolution_minutes: number | null; response_breached: boolean; resolution_breached: boolean };
type Article = { id: number; title: string; content: string; classification: string; status: string; version: number; critical: boolean; next_review_at: string | null; owner_id: number; reviewer_id: number | null };

const kinds: Record<CaseKind, { label: string; route: View }> = {
  ticket: { label: "Centre de tickets", route: "support-tickets" },
  problem: { label: "Problèmes", route: "support-problems" },
  service: { label: "Demandes de service", route: "support-services" },
  change: { label: "Changements", route: "support-changes" },
};
const transitions: Record<CaseKind, Record<string, string[]>> = {
  ticket: { open: ["investigating", "cancelled"], investigating: ["resolved", "cancelled"], resolved: ["closed", "investigating"] },
  problem: { open: ["investigating", "cancelled"], investigating: ["known_error", "resolved", "cancelled"], known_error: ["resolved", "investigating"], resolved: ["closed", "investigating"] },
  service: { open: ["awaiting_approval", "approved", "cancelled"], awaiting_approval: ["approved", "rejected", "cancelled"], approved: ["fulfilling", "cancelled"], fulfilling: ["completed", "cancelled"], completed: ["closed"] },
  change: { open: ["awaiting_approval", "cancelled"], awaiting_approval: ["approved", "rejected", "cancelled"], approved: ["implementing", "cancelled"], implementing: ["completed", "rolled_back"], completed: ["closed"], rolled_back: ["closed"] },
};
const labels: Record<string, string> = {
  open: "Ouvert", investigating: "Investigation", known_error: "Erreur connue",
  resolved: "Résolu", closed: "Clôturé", cancelled: "Annulé", awaiting_approval: "En attente d'approbation",
  approved: "Approuvé", rejected: "Rejeté", fulfilling: "Exécution",
  completed: "Terminé", implementing: "Déploiement", rolled_back: "Retour arrière",
};
function errorText(e: unknown) { return e instanceof Error ? e.message : "Une erreur est survenue."; }
function newKey() { return crypto.randomUUID(); }
async function write<T>(path: string, method: "POST" | "PATCH", payload: unknown): Promise<T> {
  return api<T>(path, {
    method, headers: { "Idempotency-Key": newKey() },
    body: JSON.stringify(payload),
  });
}
function Field({ title, value, onChange, multiline = false, type = "text", required = false }:
  { title: string; value: string; onChange: (next: string) => void; multiline?: boolean; type?: string; required?: boolean }) {
  return <label style={{ display: "grid", gap: 6 }}>
    <span>{title}</span>
    {multiline
      ? <textarea rows={3} required={required} value={value} onChange={e => onChange(e.target.value)} />
      : <input type={type} required={required} value={value} onChange={e => onChange(e.target.value)} />}
  </label>;
}
function Panel({ title, children }: { title: string; children: React.ReactNode }) {
  return <section className="panel" aria-label={title} style={{ marginBlock: 16, padding: 18 }}>
    <h2>{title}</h2>{children}
  </section>;
}

function Cases({ kind, role }: { kind: CaseKind; role: Role["name"] }) {
  const [rows, setRows] = useState<Case[]>([]);
  const [queue, setQueue] = useState<Inbox[]>([]);
  const [assignees, setAssignees] = useState<Assignee[]>([]);
  const [assignedTo, setAssignedTo] = useState("");
  const [bulkIds, setBulkIds] = useState<number[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [selected, setSelected] = useState<Case | null>(null);
  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [priority, setPriority] = useState("P3");
  const [catalogId, setCatalogId] = useState("");
  const [catalog, setCatalog] = useState<Catalog[]>([]);
  const [risk, setRisk] = useState("medium");
  const [windowStart, setWindowStart] = useState("");
  const [windowEnd, setWindowEnd] = useState("");
  const [rollback, setRollback] = useState("");
  const [rootCause, setRootCause] = useState("");
  const [workaround, setWorkaround] = useState("");
  const [permanentFix, setPermanentFix] = useState("");
  const [evidence, setEvidence] = useState("");
  const [pipeline, setPipeline] = useState("");
  const [verification, setVerification] = useState("");
  const [incidentId, setIncidentId] = useState("");
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");

  const refresh = useCallback(async () => {
    const qs = new URLSearchParams({ page: String(page) });
    if (search) qs.set("search", search);
    if (status) qs.set("status", status);
    const result = await api<Page<Case>>("support/cases/" + kind + "?" + qs.toString());
    setRows(result.data);
    setTotal(result.total);
    if (kind === "ticket") {
      const q = await api<Page<Inbox>>("support/queue?search="+encodeURIComponent(search));
      setQueue(q.data);
    }
  }, [kind, page, search, status]);
  useEffect(() => { let active = true; refresh().catch(e => { if (active) setError(errorText(e)); }); return () => { active = false; }; }, [refresh]);
  useEffect(() => {
    api<Assignee[]>("support/assignees").then(setAssignees).catch(e => setError(errorText(e)));
  }, []);
  useEffect(() => {
    if (kind === "service") {
      api<Page<Catalog>>("support/catalog").then(r => setCatalog(r.data.filter(c => c.active))).catch(e => setError(errorText(e)));
    }
  }, [kind]);

  async function download() {
    setError("");
    try {
      const qs = new URLSearchParams();
      if (search) qs.set("search", search);
      if (status) qs.set("status", status);
      const csv = await fetchTenantBlob("support/cases/"+kind+"/export?"+qs.toString(), "text/csv");
      const href = URL.createObjectURL(csv);
      const a = document.createElement("a"); a.href = href;
      a.download = "atlas-itsm-"+kind+".csv"; document.body.appendChild(a); a.click(); a.remove();
      URL.revokeObjectURL(href);
    } catch (e) { setError(errorText(e)); }
  }
  async function details(id: number) {
    try {
      const item = await api<Case>("support/cases/" + kind + "/" + id);
      setSelected(item); setRootCause(item.root_cause || ""); setWorkaround(item.workaround || "");
      setPermanentFix(item.permanent_fix || ""); setEvidence(item.result_evidence || "");
      setPipeline(item.pipeline_url || ""); setVerification(item.verification || "");
      setReason(""); setIncidentId(""); setAssignedTo(item.assignee ? String(assignees.find(a => a.name === item.assignee?.name)?.id || "") : "");
    } catch (e) { setError(errorText(e)); }
  }
  async function mutate<T>(fn: () => Promise<T>, result?: (r: T) => void) {
    setBusy(true); setError(""); setMessage("");
    try { const value = await fn(); result?.(value); await refresh(); setMessage("Opération enregistrée et journalisée."); }
    catch (e) { setError(errorText(e)); }
    finally { setBusy(false); }
  }
  async function create(e: FormEvent) {
    e.preventDefault();
    const payload: Record<string, unknown> = { title, description, priority, ...(assignedTo ? { assigned_to: Number(assignedTo) } : {}) };
    if (kind === "service") payload.catalog_id = Number(catalogId);
    if (kind === "change") {
      Object.assign(payload, { risk_level: risk, window_start: new Date(windowStart).toISOString(),
        window_end: new Date(windowEnd).toISOString(), rollback_plan: rollback });
    }
    await mutate(() => write<Case>("support/cases/" + kind, "POST", payload), () => {
      setTitle(""); setDescription(""); setRollback(""); setWindowStart(""); setWindowEnd(""); setAssignedTo("");
    });
  }
  async function update() {
    if (!selected) return;
    const payload: Record<string, unknown> = { version: selected.version, assigned_to: assignedTo ? Number(assignedTo) : null };
    if (kind === "problem") Object.assign(payload, { root_cause: rootCause, workaround, permanent_fix: permanentFix });
    if (kind === "service" || kind === "ticket") payload.result_evidence = evidence;
    if (kind === "change") Object.assign(payload, { pipeline_url: pipeline || null, verification });
    await mutate(() => write<Case>("support/cases/" + kind + "/" + selected.id, "PATCH", payload), r => void details(r.id));
  }
  async function changeStatus(next: string) {
    if (!selected) return;
    await mutate(() => write<Case>("support/cases/" + kind + "/" + selected.id + "/transition", "POST",
      { status: next, version: selected.version, reason: reason || undefined }), r => void details(r.id));
  }
  async function link() {
    if (!selected) return;
    await mutate(() => write<Case>("support/cases/" + kind + "/" + selected.id + "/incidents", "POST", { incident_id: Number(incidentId) }), r => void details(r.id));
  }
  const canApprove = kind !== "change" || ["admin","support_cab"].includes(role);

  return <div>
    {error && <p role="alert" className="error">{error}</p>}
    {message && <p role="status">{message}</p>}
    {kind === "ticket" && <Panel title="Boîte de travail unifiée">
      <p>Vue consolidée des incidents, tickets, problèmes, demandes et changements autorisés pour votre entreprise.</p>
      <div style={{ overflowX:"auto" }}><table><thead><tr><th>Référence</th><th>Type</th><th>Titre</th><th>Priorité</th><th>État</th></tr></thead>
        <tbody>{queue.map(q => <tr key={q.kind+"-"+q.id}>
          <td>{q.reference}</td><td>{q.kind}</td><td>{q.title}</td><td>{q.priority}</td><td>{labels[q.status] || q.status}</td>
        </tr>)}</tbody></table></div>
    </Panel>}
    <Panel title={"Créer : " + kinds[kind].label}>
      <form onSubmit={create} style={{ display: "grid", gap: 12, maxWidth: 720 }}>
        <Field title="Titre" value={title} onChange={setTitle} required />
        <Field title="Description" value={description} onChange={setDescription} multiline required />
        <label>Priorité
          <select value={priority} onChange={e => setPriority(e.target.value)}>
            {["P1","P2","P3","P4"].map(x => <option key={x} value={x}>{x}</option>)}
          </select>
        </label>
        <label>Attribuer à
          <select value={assignedTo} onChange={e => setAssignedTo(e.target.value)}>
            <option value="">Non attribué</option>
            {assignees.map(a => <option key={a.id} value={a.id}>{a.name} ({a.role})</option>)}
          </select>
        </label>
        {kind === "service" && <label>Prestation du catalogue
          <select required value={catalogId} onChange={e => setCatalogId(e.target.value)}>
            <option value="">Choisir une prestation</option>
            {catalog.map(item => <option key={item.id} value={item.id}>{item.code} — {item.title}{item.sensitive ? " (sensible)" : ""}</option>)}
          </select>
        </label>}
        {kind === "change" && <>
          <label>Risque
            <select value={risk} onChange={e => setRisk(e.target.value)}>
              {["low","medium","high","critical"].map(x => <option value={x} key={x}>{x}</option>)}
            </select>
          </label>
          <Field title="Fenêtre de début" type="datetime-local" value={windowStart} onChange={setWindowStart} required />
          <Field title="Fenêtre de fin" type="datetime-local" value={windowEnd} onChange={setWindowEnd} required />
          <Field title="Plan de restauration" value={rollback} onChange={setRollback} multiline required />
        </>}
        <button className="button primary" disabled={busy || (kind === "service" && !catalogId)}>Créer et journaliser</button>
      </form>
    </Panel>
    <Panel title={"Liste : " + kinds[kind].label}>
      <div style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
        <label>Recherche <input value={search} onChange={e => { setSearch(e.target.value); setPage(1); }} /></label>
        <label>État <select value={status} onChange={e => { setStatus(e.target.value); setPage(1); }}>
          <option value="">Tous</option>
          {Object.keys(transitions[kind]).map(k => <option value={k} key={k}>{labels[k] || k}</option>)}
        </select></label>
        <button className="button" onClick={() => void refresh()}>Actualiser</button>
        <button className="button" onClick={() => void download()}>Exporter les dossiers filtrés (CSV)</button>
      </div>
      <p>{total} dossier(s)</p>
      <div style={{ overflowX: "auto" }}>
        <table><thead><tr>{kind === "ticket" && <th>Sélection</th>}<th>Référence</th><th>Titre</th><th>Priorité</th><th>État</th><th>Version</th><th>Action</th></tr></thead>
          <tbody>{rows.map(item => <tr key={item.id}>
            {kind === "ticket" && <td><input type="checkbox" aria-label={"Sélectionner "+item.reference}
              checked={bulkIds.includes(item.id)} onChange={e => setBulkIds(ids =>
                e.target.checked ? [...ids, item.id] : ids.filter(id => id !== item.id))} /></td>}
            <td>{item.reference}</td><td>{item.title}</td><td>{item.priority}</td>
            <td>{labels[item.status] || item.status}</td><td>{item.version}</td>
            <td><button className="button" onClick={() => void details(item.id)}>Détail</button></td>
          </tr>)}</tbody>
        </table>
      </div>
      {kind === "ticket" && <button className="button" disabled={!bulkIds.length || busy} onClick={() => void mutate(
        () => write("support/cases/ticket/bulk-close", "POST", {
          tickets: rows.filter(r => bulkIds.includes(r.id)).map(r => ({ id: r.id, version: r.version })),
        }),
        () => setBulkIds([])
      )}>Clôturer les tickets sélectionnés (validation obligatoire)</button>}
      <button className="button" disabled={page === 1} onClick={() => setPage(p => p-1)}>Précédent</button>
      <span> Page {page} </span>
      <button className="button" disabled={page*20 >= total} onClick={() => setPage(p => p+1)}>Suivant</button>
    </Panel>
    {selected && <Panel title={"Détail " + selected.reference}>
      <h3>{selected.title}</h3><p>{selected.description}</p>
      <p>Statut : {labels[selected.status] || selected.status} · version : {selected.version} · priorité : {selected.priority}</p>
      {kind === "problem" && <div style={{ display:"grid",gap:8,maxWidth:720 }}>
        <Field title="Cause racine" value={rootCause} onChange={setRootCause} multiline />
        <Field title="Contournement (ne vaut pas correction)" value={workaround} onChange={setWorkaround} multiline />
        <Field title="Correction permanente" value={permanentFix} onChange={setPermanentFix} multiline />
      </div>}
      <label>Attribuer à
        <select value={assignedTo} onChange={e => setAssignedTo(e.target.value)}>
          <option value="">Non attribué</option>
          {assignees.map(a => <option key={a.id} value={a.id}>{a.name} ({a.role})</option>)}
        </select>
      </label>
      {(kind === "ticket" || kind === "service") && <Field title="Preuve de résultat et validation" value={evidence} onChange={setEvidence} multiline />}
      {kind === "change" && <>
        <p>Risque : {selected.risk_level} · début : {selected.window_start} · fin : {selected.window_end}</p>
        <p>Plan de retour : {selected.rollback_plan}</p>
        <Field title="URL du pipeline de livraison" value={pipeline} onChange={setPipeline} />
        <Field title="Vérification post-installation" value={verification} onChange={setVerification} multiline />
      </>}
      <div style={{ display:"flex",gap:8,flexWrap:"wrap",marginBlock:12 }}>
        <button className="button" onClick={() => void update()} disabled={busy || ["closed","cancelled","rejected","completed","rolled_back"].includes(selected.status)}>Enregistrer les précisions</button>
        {(transitions[kind][selected.status] || []).filter(x => x !== "approved" || canApprove).map(next =>
          <button key={next} className="button" disabled={busy} onClick={() => void changeStatus(next)}>
            {labels[next] || next}
          </button>)}
      </div>
      <Field title="Motif pour refus, annulation ou retour arrière" value={reason} onChange={setReason} multiline />
      <div style={{ display:"flex",gap:8,alignItems:"end",flexWrap:"wrap",marginBlock:12 }}>
        <Field title="Identifiant de l'incident à associer" type="number" value={incidentId} onChange={setIncidentId} />
        <button className="button" disabled={busy || !/^[1-9]\d*$/.test(incidentId)} onClick={() => void link()}>Associer l'incident</button>
      </div>
      {selected.incidents && <p>Incidents associés : {selected.incidents.map(x => x.reference).join(", ") || "Aucun"}</p>}
      <h3>Historique immuable</h3>
      <ol>{selected.events?.map(evt => <li key={evt.id}>
        {new Date(evt.created_at).toLocaleString("fr-FR")} — {evt.action} — {evt.actor?.name || "Utilisateur"} — v{evt.version}
      </li>)}</ol>
      <button className="button" onClick={() => setSelected(null)}>Fermer le détail</button>
    </Panel>}
  </div>;
}

function ServiceCatalog() {
  const [catalog, setCatalog] = useState<Catalog[]>([]);
  const [code, setCode] = useState(""); const [title, setTitle] = useState("");
  const [approval, setApproval] = useState(true); const [sensitive, setSensitive] = useState(false);
  const [error, setError] = useState("");
  const refresh = useCallback(() => api<Page<Catalog>>("support/catalog").then(r => setCatalog(r.data)).catch(e => setError(errorText(e))), []);
  useEffect(() => { void refresh(); }, [refresh]);
  return <Panel title="Catalogue de services">
    {error && <p role="alert">{error}</p>}
    <ul>{catalog.map(c => <li key={c.id}>{c.code} — {c.title}{c.sensitive ? " · droit sensible" : ""}</li>)}</ul>
    <form style={{ display:"grid",gap:8,maxWidth:500 }} onSubmit={async e => {
      e.preventDefault();setError("");
      try { await write("support/catalog","POST",{ code: code.toUpperCase(), title, requires_approval: approval, sensitive });setCode("");setTitle("");await refresh(); }
      catch(e) { setError(errorText(e)); }
    }}>
      <Field title="Code catalogue" value={code} onChange={setCode} required />
      <Field title="Nom prestation" value={title} onChange={setTitle} required />
      <label><input type="checkbox" checked={approval} onChange={e => setApproval(e.target.checked)} /> Approbation obligatoire</label>
      <label><input type="checkbox" checked={sensitive} onChange={e => setSensitive(e.target.checked)} /> Droit sensible</label>
      <button className="button primary">Ajouter une prestation</button>
    </form>
  </Panel>;
}

function SlaDashboard({ role }: { role: Role["name"] }) {
  const [policies, setPolicies] = useState<SLA[]>([]);
  const [metrics, setMetrics] = useState<Metrics[]>([]);
  const [escalations, setEscalations] = useState<Escalation[]>([]);
  const [severity, setSeverity] = useState("P2");
  const [responseMinutes, setResponseMinutes] = useState("60");
  const [resolutionMinutes, setResolutionMinutes] = useState("480");
  const [effectiveFrom, setEffectiveFrom] = useState("");
  const [incidentId, setIncidentId] = useState("");
  const [targetLevel, setTargetLevel] = useState("3");
  const [reason, setReason] = useState("");
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const isAdmin = ["admin","support_manager"].includes(role);
  const refresh = useCallback(async () => {
    const [p,m,e] = await Promise.all([
      api<Page<SLA>>("support/sla/policies"),
      api<Page<Metrics>>("support/sla/metrics"),
      api<Page<Escalation>>("support/sla/escalations"),
    ]);
    setPolicies(p.data); setMetrics(m.data); setEscalations(e.data);
  }, []);
  useEffect(() => { void refresh().catch(e => setError(errorText(e))); }, [refresh]);
  async function action(fn: () => Promise<unknown>) {
    setError("");setMessage("");
    try { await fn(); await refresh(); setMessage("Opération enregistrée."); }
    catch(e) { setError(errorText(e)); }
  }
  return <div>
    {error && <p role="alert">{error}</p>}{message && <p role="status">{message}</p>}
    <Panel title="Horloges SLA des incidents">
      <p>Les valeurs sont calculées côté serveur en minutes, sur le calendrier continu.</p>
      <div style={{ overflowX:"auto" }}><table><thead><tr><th>Incident</th><th>Priorité</th><th>Réponse (min)</th><th>Résolution (min)</th><th>Dépassement</th></tr></thead>
        <tbody>{metrics.map(m => <tr key={m.id}>
          <td>{m.reference}</td><td>{m.severity}</td><td>{m.response_minutes ?? "En cours"}</td>
          <td>{m.resolution_minutes ?? "En cours"}</td>
          <td>{m.response_breached || m.resolution_breached ? "Oui" : "Non"}</td>
        </tr>)}</tbody></table></div>
    </Panel>
    <Panel title="Escalades à acquitter">
      <ul>{escalations.map(e => <li key={e.id}>Incident #{e.incident_id} — N{e.target_level} — {e.status}
        {e.status === "pending" && <button className="button" onClick={() => void action(() => send("support/sla/escalations/"+e.id+"/acknowledge","POST"))}>Acquitter</button>}
      </li>)}</ul>
      <form style={{ display:"grid",gap:8,maxWidth:540 }} onSubmit={e => {
        e.preventDefault();
        void action(() => send("support/sla/escalations","POST",{ incident_id:Number(incidentId), target_level:Number(targetLevel), reason }));
      }}>
        <Field title="Identifiant incident" type="number" value={incidentId} onChange={setIncidentId} required />
        <label>Niveau <select value={targetLevel} onChange={e => setTargetLevel(e.target.value)}>
          <option value="2">N2</option><option value="3">N3</option>
        </select></label>
        <Field title="Motif d'escalade" value={reason} onChange={setReason} required />
        <button className="button primary">Déclencher une escalade</button>
      </form>
    </Panel>
    <Panel title="Versions contractuelles des SLA">
      <ul>{policies.map(p => <li key={p.id}>
        {p.severity} · version {p.version} · réponse {p.response_minutes} min · résolution {p.resolution_minutes} min · {p.published ? "Publié" : "Brouillon"}
        {isAdmin && !p.published && <button className="button" onClick={() => void action(() => send("support/sla/policies/"+p.id+"/publish","POST"))}>Publier</button>}
      </li>)}</ul>
      {isAdmin && <form style={{ display:"grid",gap:8,maxWidth:540 }} onSubmit={e => {
        e.preventDefault();
        void action(() => send("support/sla/policies","POST", {
          severity, response_minutes:Number(responseMinutes), resolution_minutes:Number(resolutionMinutes),
          calendar:"continuous", effective_from:new Date(effectiveFrom).toISOString(),
        }));
      }}>
        <label>Priorité <select value={severity} onChange={e => setSeverity(e.target.value)}>
          {["P1","P2","P3","P4"].map(p => <option key={p}>{p}</option>)}
        </select></label>
        <Field title="Délai de réponse en minutes" type="number" value={responseMinutes} onChange={setResponseMinutes} required />
        <Field title="Délai de résolution en minutes" type="number" value={resolutionMinutes} onChange={setResolutionMinutes} required />
        <Field title="Application à partir du" type="datetime-local" value={effectiveFrom} onChange={setEffectiveFrom} required />
        <button className="button primary">Créer une version SLA</button>
      </form>}
    </Panel>
  </div>;
}

function Knowledge() {
  const [rows, setRows] = useState<Article[]>([]);
  const [selected, setSelected] = useState<Article | null>(null);
  const [title, setTitle] = useState("");
  const [content, setContent] = useState("");
  const [classification, setClassification] = useState("support");
  const [critical, setCritical] = useState(false);
  const [nextReview, setNextReview] = useState("");
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [search, setSearch] = useState("");
  const refresh = useCallback(async () => {
    const r = await api<Page<Article>>("support/knowledge?search="+encodeURIComponent(search));
    setRows(r.data);
  }, [search]);
  useEffect(() => { void refresh().catch(e => setError(errorText(e))); }, [refresh]);
  async function action(fn:()=>Promise<unknown>) {
    setError("");setMessage("");
    try { await fn();await refresh();setMessage("Opération enregistrée."); }
    catch(e) { setError(errorText(e)); }
  }
  async function show(id:number) {
    try { setSelected(await api<Article>("support/knowledge/"+id)); }
    catch(e) { setError(errorText(e)); }
  }
  return <div>
    {error && <p role="alert">{error}</p>}{message && <p role="status">{message}</p>}
    <Panel title="Rechercher une procédure">
      <Field title="Rechercher par titre" value={search} onChange={setSearch} />
      <ul>{rows.map(a => <li key={a.id}>
        <button className="button" onClick={() => void show(a.id)}>{a.title}</button>
        {" "}— {a.status} — v{a.version} — {a.classification}
      </li>)}</ul>
    </Panel>
    {selected && <Panel title={"Article : "+selected.title}>
      <p style={{whiteSpace:"pre-wrap"}}>{selected.content}</p>
      <p>Version {selected.version} · {selected.status} · relecture : {selected.next_review_at || "À définir"}</p>
      {selected.status === "draft" && <button className="button" onClick={() => void action(async () => {
        await send("support/knowledge/"+selected.id+"/decision","POST",{ version:selected.version,action:"publish" });
        await show(selected.id);
      })}>Publier après revue</button>}
      {selected.status === "published" && <button className="button" onClick={() => void action(async () => {
        await send("support/knowledge/"+selected.id+"/decision","POST",{ version:selected.version,action:"retire" });
        await show(selected.id);
      })}>Retirer de la recherche</button>}
      <button className="button" onClick={() => setSelected(null)}>Fermer</button>
    </Panel>}
    <Panel title="Rédiger une fiche de connaissance">
      <form style={{ display:"grid",gap:8,maxWidth:720 }} onSubmit={e => {
        e.preventDefault();
        void action(() => send("support/knowledge","POST",{
          title,content,classification,critical,next_review_at:new Date(nextReview).toISOString(),
        }));
      }}>
        <Field title="Titre" value={title} onChange={setTitle} required />
        <Field title="Procédure" value={content} onChange={setContent} multiline required />
        <label>Classification <select value={classification} onChange={e => setClassification(e.target.value)}>
          <option value="support">Support</option><option value="n3">Réservé N3</option>
        </select></label>
        <label><input type="checkbox" checked={critical} onChange={e => setCritical(e.target.checked)} /> Procédure critique : relecteur indépendant</label>
        <Field title="Date de relecture" type="date" value={nextReview} onChange={setNextReview} required />
        <button className="button primary">Enregistrer le brouillon</button>
      </form>
    </Panel>
  </div>;
}

export default function SupportITSM({ view, role }: { view: View | string; role: Role["name"] }) {
  if (view === "support-sla") return <SlaDashboard role={role} />;
  if (view === "support-knowledge") return <Knowledge />;
  const kind = (Object.keys(kinds) as CaseKind[]).find(k => kinds[k].route === view);
  if (!kind) return <p>Module indisponible.</p>;
  return <>
    {kind === "service" && ["admin","support_manager"].includes(role) && <ServiceCatalog />}
    <Cases key={kind} kind={kind} role={role} />
  </>;
}
