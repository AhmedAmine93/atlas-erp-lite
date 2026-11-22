"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { AlertTriangle, CheckCircle2, Plus, RefreshCcw, Search, Wrench } from "lucide-react";
import { api, send } from "@/lib/api";
import DataTableFrame from "@/components/ui/DataTableFrame";
import FormField from "@/components/ui/FormField";
import type { Page } from "@/lib/types";

type Severity = "P1" | "P2" | "P3" | "P4";
type Status = "open" | "investigating" | "resolved" | "closed";
type Incident = {
  id: number;
  reference: string;
  title: string;
  description: string;
  severity: Severity;
  status: Status;
  environment: string;
  resolution: string | null;
  created_at: string;
  resolved_at: string | null;
  response_due_at: string | null;
  resolution_due_at: string | null;
  response_sla_status: "pending" | "met" | "breached" | "not_configured";
  resolution_sla_status: "pending" | "met" | "breached" | "not_configured";
  escalation_level: number;
  events?: { id: number; action: string; message: string | null; created_at: string; actor?: { name: string } }[];
  reporter?: { id: number; name: string };
  assignee?: { id: number; name: string } | null;
};
const statusLabels: Record<Status, string> = {
  open: "Ouvert",
  investigating: "En investigation",
  resolved: "Résolu",
  closed: "Clôturé",
};
const nextStatus: Partial<Record<Status, Status>> = {
  open: "investigating",
  investigating: "resolved",
  resolved: "closed",
};
const severityLabels: Record<Severity, string> = {
  P1: "P1 — Critique", P2: "P2 — Majeur", P3: "P3 — Normal", P4: "P4 — Mineur",
};
const safeDate = (value: string) => new Date(value).toLocaleString("fr-FR");
type DiagnosticRun = { id: number; check_name: string; status: string; duration_ms: number; created_at: string };
type DiagnosticResult = {
  check: string;
  status: "ok" | "degraded";
  duration_ms: number;
  metrics: Record<string, number | boolean>;
};
type SupportSummary = {
  open: number;
  escalated_n3: number;
  response_sla_breached: number;
  resolution_sla_breached: number;
};

export default function SupportIncidents({ canDiagnose }: { canDiagnose: boolean }) {
  const [items, setItems] = useState<Incident[]>([]);
  const [summary, setSummary] = useState<SupportSummary | null>(null);
  const [diagnosticCheck, setDiagnosticCheck] = useState("database");
  const [diagnosticResult, setDiagnosticResult] = useState<DiagnosticResult | null>(null);
  const [diagnosticRuns, setDiagnosticRuns] = useState<DiagnosticRun[]>([]);
  const [comment, setComment] = useState("");
  const [escalationReason, setEscalationReason] = useState("");
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [status, setStatus] = useState("");
  const [severity, setSeverity] = useState("");
  const [search, setSearch] = useState("");
  const [submittedSearch, setSubmittedSearch] = useState("");
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [creating, setCreating] = useState(false);
  const [selected, setSelected] = useState<Incident | null>(null);
  const [resolution, setResolution] = useState("");
  const [revision, setRevision] = useState(0);
  const [form, setForm] = useState({
    title: "", description: "", severity: "P3" as Severity, environment: "demo",
  });

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    const params = new URLSearchParams({ page: String(page) });
    if (status) params.set("status", status);
    if (severity) params.set("severity", severity);
    if (submittedSearch) params.set("search", submittedSearch);
    try {
      const [result, stats] = await Promise.all([
        api<Page<Incident>>(`support/incidents?${params}`),
        api<SupportSummary>("support/incidents/summary"),
      ]);
      setSummary(stats);
      setItems(result.data);
      setTotal(result.total);
      setLastPage(result.last_page);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setLoading(false);
    }
  }, [page, status, severity, submittedSearch, revision]);

  useEffect(() => { void load(); }, [load]);

  async function create(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setSaving(true);
    setError("");
    try {
      await send("support/incidents", "POST", form);
      setCreating(false);
      setForm({ title: "", description: "", severity: "P3", environment: "demo" });
      setNotice("Incident créé.");
      setPage(1);
      setRevision(n => n + 1);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setSaving(false);
    }
  }

  async function transition() {
    if (!selected) return;
    const next = nextStatus[selected.status];
    if (!next) return;
    if (next === "resolved" && !resolution.trim()) {
      setError("Renseignez la résolution avant de terminer l'incident.");
      return;
    }
    setSaving(true);
    setError("");
    try {
      const updated = await send<Incident>(`support/incidents/${selected.id}`, "PATCH", {
        status: next, ...(next === "resolved" ? { resolution } : {}),
      });
      setSelected(updated);
      setNotice("Statut modifié.");
      setRevision(n => n + 1);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setSaving(false);
    }
  }

  async function showDetail(incident: Incident, resetDrafts = true) {
    setError("");
    setCreating(false);
    // Preserve user-entered drafts during a background detail refresh.
    // Only clear them when explicitly opening a different incident.
    if (resetDrafts) {
      setComment("");
      setEscalationReason("");
      setDiagnosticResult(null);
      setDiagnosticRuns([]);
    }
    try {
      const detail = await api<Incident>(`support/incidents/${incident.id}`);
      setSelected(detail);
      if (canDiagnose && detail.escalation_level === 3) {
        setDiagnosticRuns(await api<DiagnosticRun[]>(`support/incidents/${incident.id}/diagnostics`));
      } else {
        setDiagnosticRuns([]);
      }
    } catch (e) {
      setError((e as Error).message);
    }
  }

  async function addComment() {
    if (!selected || comment.trim().length < 3) return;
    setSaving(true);
    setError("");
    try {
      await send(`support/incidents/${selected.id}/comments`, "POST", { message: comment.trim() });
      setComment("");
      await showDetail(selected, false);
      setRevision(n => n + 1);
      setNotice("Commentaire ajouté.");
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setSaving(false);
    }
  }

  async function escalate() {
    if (!selected || escalationReason.trim().length < 5) return;
    setSaving(true);
    setError("");
    try {
      await send(`support/incidents/${selected.id}/escalate`, "POST", { reason: escalationReason.trim() });
      await showDetail(selected, false);
      setRevision(n => n + 1);
      setNotice("Incident escaladé au niveau N3.");
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setSaving(false);
    }
  }

  async function runDiagnostic() {
    if (!selected || !canDiagnose || selected.escalation_level !== 3 || selected.status === "closed") return;
    setSaving(true);
    setError("");
    try {
      const result = await send<DiagnosticResult>(
        `support/incidents/${selected.id}/diagnostics`, "POST", { check: diagnosticCheck },
      );
      setDiagnosticResult(result);
      setDiagnosticRuns(await api<DiagnosticRun[]>(`support/incidents/${selected.id}/diagnostics`));
      setNotice("Diagnostic N3 exécuté.");
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <section aria-label="Centre de support applicatif">
      <div className="page-heading">
        <div>
          <div className="eyebrow">SUPPORT APPLICATIF · N2/N3</div>
          <h2>Centre des incidents</h2>
          <p>Suivi des anomalies techniques — données de démonstration uniquement.</p>
        </div>
        <button className="button primary" onClick={() => { setCreating(true); setSelected(null); setError(""); }}>
          <Plus size={16} /> Déclarer un incident
        </button>
      </div>
      {summary && (
        <div className="panel" aria-label="Synthèse des incidents" style={{ padding: "1rem", marginBottom: "1rem" }}>
          <strong>Ouverts : {summary.open}</strong>
          {" · "}Escaladés N3 : {summary.escalated_n3}
          {" · "}Réponse hors SLA : {summary.response_sla_breached}
          {" · "}Résolution hors SLA : {summary.resolution_sla_breached}
        </div>
      )}
      {notice && <div role="status" className="notice"><CheckCircle2 size={16} />{notice}</div>}
      {error && <div role="alert" className="error"><AlertTriangle size={16} />{error}</div>}

      <div className="panel">
        <form className="list-tools" onSubmit={e => { e.preventDefault(); setPage(1); setSubmittedSearch(search.trim()); }}>
          <div className="search-field">
            <Search size={15} />
            <input aria-label="Rechercher un incident" placeholder="Référence ou titre" value={search}
              maxLength={100} onChange={e => setSearch(e.target.value)} />
          </div>
          <button type="submit" className="button secondary">Rechercher</button>
          <select aria-label="Filtrer par statut" value={status} onChange={e => { setStatus(e.target.value); setPage(1); }}>
            <option value="">Tous les statuts</option>
            {Object.entries(statusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
          <select aria-label="Filtrer par priorité" value={severity} onChange={e => { setSeverity(e.target.value); setPage(1); }}>
            <option value="">Toutes les priorités</option>
            {Object.entries(severityLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
          <button type="button" className="icon-button" aria-label="Actualiser" onClick={() => setRevision(n => n + 1)}>
            <RefreshCcw size={17} />
          </button>
          <span className="result-count">{total} incident(s)</span>
        </form>
        <DataTableFrame label="Liste des incidents" busy={loading}>
          <table>
            <thead><tr><th>Référence</th><th>Incident</th><th>Priorité</th><th>Statut</th><th>Environnement</th><th>Créé le</th><th>Action</th></tr></thead>
            <tbody>
              {items.map(incident => (
                <tr key={incident.id}>
                  <td className="strong">{incident.reference}</td>
                  <td>{incident.title}</td>
                  <td><span className="badge">{severityLabels[incident.severity]}</span></td>
                  <td><span className="badge">{statusLabels[incident.status]}</span></td>
                  <td>{incident.environment}{incident.resolution_sla_status === "breached" && <span className="badge"> · SLA dépassé</span>}</td>
                  <td>{safeDate(incident.created_at)}</td>
                  <td><button className="text-button" type="button" onClick={() => {
                    setResolution(incident.resolution || "");
                    void showDetail(incident);
                  }}>Consulter</button></td>
                </tr>
              ))}
              {!loading && items.length === 0 && <tr><td colSpan={7}>Aucun incident ne correspond aux filtres.</td></tr>}
              {loading && <tr><td colSpan={7}>Chargement des incidents…</td></tr>}
            </tbody>
          </table>
        </DataTableFrame>
        <div className="pagination">
          <span>Page {page} / {lastPage}</span>
          <div>
            <button className="button secondary" disabled={loading || page <= 1} onClick={() => setPage(n => n - 1)}>Précédent</button>
            <button className="button secondary" disabled={loading || page >= lastPage} onClick={() => setPage(n => n + 1)}>Suivant</button>
          </div>
        </div>
      </div>

      {creating && (
        <div className="modal-backdrop" onMouseDown={e => { if (e.target === e.currentTarget) setCreating(false); }}>
          <div className="modal" role="dialog" aria-modal="true" aria-label="Déclarer un incident">
            <header><h2>Nouvel incident</h2><button aria-label="Fermer" className="icon-button" onClick={() => setCreating(false)}>✕</button></header>
            <form className="editor-form" onSubmit={create}>
              <FormField label="Titre"><input required maxLength={180} value={form.title} onChange={e => setForm({ ...form, title: e.target.value })} /></FormField>
              <FormField label="Description (sans secret ni données réelles)"><textarea required rows={5} maxLength={10000} value={form.description} onChange={e => setForm({ ...form, description: e.target.value })} /></FormField>
              <div className="form-grid">
                <label>Priorité<select value={form.severity} onChange={e => setForm({ ...form, severity: e.target.value as Severity })}>
                  {Object.entries(severityLabels).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select></label>
                <label>Environnement<select value={form.environment} onChange={e => setForm({ ...form, environment: e.target.value })}>
                  {["demo", "dev", "staging", "production"].map(v => <option key={v} value={v}>{v}</option>)}
                </select></label>
              </div>
              <div className="form-actions"><button className="button secondary" type="button" onClick={() => setCreating(false)}>Annuler</button>
                <button className="button primary" disabled={saving} type="submit">Créer l'incident</button></div>
            </form>
          </div>
        </div>
      )}

      {selected && (
        <div className="modal-backdrop" onMouseDown={e => { if (e.target === e.currentTarget) setSelected(null); }}>
          <div className="modal" role="dialog" aria-modal="true" aria-label="Détail de l'incident">
            <header><h2>{selected.reference}</h2><button className="icon-button" aria-label="Fermer" onClick={() => setSelected(null)}>✕</button></header>
            <div className="detail-content">
              <h3>{selected.title}</h3>
              <p>{selected.description}</p>
              <p>Statut : {statusLabels[selected.status]} · {severityLabels[selected.severity]} · {selected.environment}</p>
              <p>Déclaré le : {safeDate(selected.created_at)}</p>
              <p>
                Délai première réponse : {selected.response_due_at ? safeDate(selected.response_due_at) : "Non défini"}
                {" · "}SLA : {selected.response_sla_status}
              </p>
              <p>
                Délai résolution : {selected.resolution_due_at ? safeDate(selected.resolution_due_at) : "Non défini"}
                {" · "}SLA : {selected.resolution_sla_status}
              </p>
              <p>Niveau d'escalade : N{selected.escalation_level}</p>
              {selected.resolution && <><h3>Résolution</h3><p>{selected.resolution}</p></>}
              {selected.events && (
                <div>
                  <h3>Historique</h3>
                  <ul>
                    {selected.events.map(event => (
                      <li key={event.id}>
                        {safeDate(event.created_at)} · {event.actor?.name || "Système"} · {event.action}
                        {event.message && <p>{event.message}</p>}
                      </li>
                    ))}
                  </ul>
                </div>
              )}
              <label>Commentaire support
                <textarea rows={2} value={comment} onChange={e => setComment(e.target.value)}
                  placeholder="Résumé du diagnostic ou de la communication..." />
              </label>
              <button className="button secondary" disabled={saving || comment.trim().length < 3}
                onClick={() => void addComment()}>Ajouter un commentaire</button>
              {selected.escalation_level < 3 && selected.status !== "closed" && (
                <>
                  <label>Motif d'escalade N3
                    <textarea rows={2} value={escalationReason} onChange={e => setEscalationReason(e.target.value)}
                      placeholder="Motif technique de l'escalade..." />
                  </label>
                  <button className="button secondary" disabled={saving || escalationReason.trim().length < 5}
                    onClick={() => void escalate()}>Escalader vers N3</button>
                </>
              )}
              {canDiagnose && selected.escalation_level === 3 && (
                <section aria-label="Diagnostics N3" className="panel" style={{ padding: "1rem", marginTop: "1rem" }}>
                  <h3>Diagnostics N3 — lecture seule</h3>
                  <p>Contrôles prédéfinis et limités, sans SQL libre ni journaux bruts.</p>
                  {selected.status !== "closed" && (
                    <>
                      <label>Contrôle prédéfini
                        <select aria-label="Contrôle N3" value={diagnosticCheck}
                          onChange={e => setDiagnosticCheck(e.target.value)}>
                          <option value="database">Disponibilité SQL (SELECT 1)</option>
                          <option value="cache">Lecture cache</option>
                          <option value="tenant_incidents">Indicateurs incidents entreprise active</option>
                        </select>
                      </label>
                      <button className="button secondary" disabled={saving}
                        onClick={() => void runDiagnostic()}>Exécuter le diagnostic</button>
                    </>
                  )}
                  {diagnosticResult && (
                    <p>Résultat du diagnostic : {diagnosticResult.status}. Durée : {diagnosticResult.duration_ms} ms.
                      {Object.entries(diagnosticResult.metrics).map(([key, val]) =>
                        <span key={key}> {key} : {String(val)}.</span>)}
                    </p>
                  )}
                  <h3>Historique de contrôles</h3>
                  {diagnosticRuns.length === 0 && <p>Aucun contrôle exécuté.</p>}
                  <ul>{diagnosticRuns.map(run => (
                    <li key={run.id}>{safeDate(run.created_at)} · {run.check_name} · {run.status} · {run.duration_ms} ms</li>
                  ))}</ul>
                </section>
              )}
              {selected.status === "investigating" && <label>Compte rendu de résolution
                <textarea rows={4} value={resolution} onChange={e => setResolution(e.target.value)}
                  placeholder="Diagnostic, cause racine et correction appliquée..." />
              </label>}
              <div className="form-actions">
                <button className="button secondary" onClick={() => setSelected(null)}>Fermer</button>
                {nextStatus[selected.status] && <button className="button primary" disabled={saving} onClick={transition}>
                  <Wrench size={16} /> Passer à : {statusLabels[nextStatus[selected.status]!]}
                </button>}
              </div>
            </div>
          </div>
        </div>
      )}
    </section>
  );
}
