"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { AlertTriangle, CheckCircle2, Plus, RefreshCcw, Search, Wrench } from "lucide-react";
import { api, send } from "@/lib/api";
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

export default function SupportIncidents() {
  const [items, setItems] = useState<Incident[]>([]);
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
      const result = await api<Page<Incident>>(`support/incidents?${params}`);
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
        <div className="table-scroll" aria-busy={loading}>
          <table>
            <thead><tr><th>Référence</th><th>Incident</th><th>Priorité</th><th>Statut</th><th>Environnement</th><th>Créé le</th><th>Action</th></tr></thead>
            <tbody>
              {items.map(incident => (
                <tr key={incident.id}>
                  <td className="strong">{incident.reference}</td>
                  <td>{incident.title}</td>
                  <td><span className="badge">{severityLabels[incident.severity]}</span></td>
                  <td><span className="badge">{statusLabels[incident.status]}</span></td>
                  <td>{incident.environment}</td>
                  <td>{safeDate(incident.created_at)}</td>
                  <td><button className="text-button" type="button" onClick={() => {
                    setSelected(incident); setCreating(false); setResolution(incident.resolution || ""); setError("");
                  }}>Consulter</button></td>
                </tr>
              ))}
              {!loading && items.length === 0 && <tr><td colSpan={7}>Aucun incident ne correspond aux filtres.</td></tr>}
              {loading && <tr><td colSpan={7}>Chargement des incidents…</td></tr>}
            </tbody>
          </table>
        </div>
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
              <label>Titre<input required maxLength={180} value={form.title} onChange={e => setForm({ ...form, title: e.target.value })} /></label>
              <label>Description (sans secret ni données réelles)<textarea required rows={5} maxLength={10000} value={form.description} onChange={e => setForm({ ...form, description: e.target.value })} /></label>
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
              {selected.resolution && <><h3>Résolution</h3><p>{selected.resolution}</p></>}
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
