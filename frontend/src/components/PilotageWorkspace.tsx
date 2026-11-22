"use client";

import { useEffect, useState, type FormEvent } from "react";
import { api, send, fetchTenantBlob } from "@/lib/api";
import type { User } from "@/lib/types";

const sections: Record<string, { endpoint: string; description: string }> = {
  "direction-dashboard": {
    endpoint: "direction",
    description: "Consolidation au niveau entreprise et visibilité hôtelière sans attribution fictive de revenus.",
  },
  "sales-dashboard": {
    endpoint: "sales",
    description: "Volumes de devis, commandes et factures. Cohortes indépendantes.",
  },
  "finance-dashboard": {
    endpoint: "finance",
    description: "Encours et factures émises : montants en centimes calculés côté serveur.",
  },
  "logistics-dashboard": {
    endpoint: "logistics",
    description: "Stock physique, mouvements, seuils et risques de rupture.",
  },
  "alerts-dashboard": {
    endpoint: "alerts",
    description: "Règles datées, alertes courantes et accusés de réception traçables.",
  },
};

type Item = { key: string; value: number | null };
type Datum = Record<string, unknown>;
type Pilotage = {
  section: string;
  from: string;
  to: string;
  scope: string;
  cards: Item[];
  rows: Datum[];
  notes: string[];
  row_limit: number;
  generated_at?: string;
  currency?: string;
  data_status?: string;
};
type Target = {id:number;metric:string;unit:string;period_from:string;period_to:string;target_value:number;version:number;reason:string;created_at:string};
type Center = {id:number;code:string;name:string;active:boolean};
const labels: Record<string, string> = {
  issued_invoices_ht_cents: "Factures émises HT",
  issued_invoices_ttc_cents: "Factures émises TTC",
  issued_invoices_count: "Factures émises",
  validated_orders_count: "Commandes validées",
  prior_year_invoices_ht_cents: "Factures HT N-1",
  quotes_created: "Devis créés",
  quotes_converted: "Devis convertis",
  orders_created: "Commandes créées",
  orders_validated: "Commandes validées",
  invoices_issued: "Factures émises",
  invoices_ht_cents: "Factures HT",
  issued_ht_cents: "Factures HT",
  issued_ttc_cents: "Factures TTC",
  unpaid_ttc_cents: "À encaisser TTC",
  paid_ttc_cents: "Factures payées TTC",
  cancelled_count: "Factures annulées",
  product_count: "Références produits",
  physical_units: "Unités physiques",
  low_stock_count: "Produits sous seuil",
  movements_in_period: "Mouvements de période",
  rules_count: "Règles actives",
  alerts_count: "Alertes",
  unacknowledged_count: "À acquitter",
  code: "Code", name: "Nom", bookings_in_period: "Réservations chevauchant la période",
  adr_cents: "ADR", revpar_cents: "RevPAR", number: "Numéro",
  status: "Statut", total_cents: "TTC", subtotal_cents: "HT",
  issued_at: "Date émission", paid_at: "Date paiement", created_at: "Créé le",
  reference: "Référence", stock: "Stock", alert_threshold: "Seuil",
  rule: "Règle", label: "Élément", observed: "Valeur", threshold: "Seuil règle",
  acknowledged_at: "Acquittée le",
  resolved_at: "Résolution le", escalated_at: "Escalade le",
  is_active: "Anomalie présente", pending_resolution_count: "Résolutions attendues",
  rooms_current: "Chambres actuelles", theoretical_room_nights: "Nuitées théoriques",
  occupied_room_nights: "Nuitées occupées", indicative_occupancy_pct: "Occupation indicative %",
  target_issued_ht_cents: "Objectif de facturation HT",
  target_issued_ht_delta_cents: "Écart facturé - objectif",
  target_version: "Version de l’objectif",
};
const euro = (cents: number) => new Intl.NumberFormat("fr-FR", {
  style: "currency", currency: "EUR",
}).format(cents / 100);
const renderValue = (key: string, value: unknown) => {
  if (value === null || value === undefined) return "Non disponible";
  if (key.endsWith("_cents")) return euro(Number(value));
  if (key.endsWith("_pct")) return `${Number(value).toLocaleString("fr-FR")} %`;
  if (key === "acknowledged_at" && !value) return "En attente";
  if (typeof value === "boolean") return value ? "Oui" : "Non";
  if (typeof value === "object") return "—";
  return String(value);
};
function localDay() {
  const d = new Date();
  const year = d.getFullYear();
  const month = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return year + "-" + month + "-" + day;
}

export default function PilotageWorkspace({ view, user }: {
  view: string;
  user: User;
}) {
  const section = sections[view];
  const [from, setFrom] = useState(() => localDay().slice(0, 8) + "01");
  const [to, setTo] = useState(localDay);
  const [data, setData] = useState<Pilotage | null>(null);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [refresh, setRefresh] = useState(0);
  const [ruleName, setRuleName] = useState("");
  const [metric, setMetric] = useState("low_stock");
  const [threshold, setThreshold] = useState("5");
  const [start, setStart] = useState(localDay);
  const [end, setEnd] = useState("");
  const [targets, setTargets] = useState<Target[]>([]);
  const [centers, setCenters] = useState<Center[]>([]);
  const [targetMetric, setTargetMetric] = useState("issued_ht_cents");
  const [targetValue, setTargetValue] = useState("");
  const [targetReason, setTargetReason] = useState("");
  const [centerCode, setCenterCode] = useState("");
  const [centerName, setCenterName] = useState("");
  useEffect(() => {
    let active = true;
    setLoading(true);
    setData(null);
    setError("");
    api<Pilotage>("pilotage/" + section.endpoint + "?from=" + from + "&to=" + to)
      .then((result) => { if (active) setData(result); })
      .catch((e: Error) => { if (active) setError(e.message); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [section.endpoint, from, to, user.active_company_id, refresh]);

  const isAdmin = user.role.name === "admin";
  useEffect(() => {
    let alive = true;
    const items = view === "direction-dashboard" || view === "finance-dashboard"
      ? api<Target[]>("pilotage/targets?from=" + from + "&to=" + to) : Promise.resolve([]);
    const refs = view === "finance-dashboard"
      ? api<Center[]>("pilotage/centers") : Promise.resolve([]);
    Promise.all([items, refs]).then(([t, c]) => {
      if (alive) { setTargets(t); setCenters(c); }
    }).catch((e: Error) => { if (alive) setError(e.message); });
    return () => { alive = false; };
  }, [view, from, to, user.active_company_id, refresh]);

  async function createTarget(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (busy) return;
    setBusy(true); setError(""); setNotice("");
    try {
      await send("pilotage/targets", "POST", {
        metric: targetMetric, period_from: from, period_to: to,
        target_value: Number(targetValue), reason: targetReason.trim(),
      });
      setTargetValue(""); setTargetReason("");
      setNotice("Nouvelle version de l’objectif enregistrée et auditée.");
      setRefresh(n => n + 1);
    } catch (e) { setError((e as Error).message); }
    finally { setBusy(false); }
  }

  async function createCenter(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (busy) return;
    setBusy(true); setError(""); setNotice("");
    try {
      await send("pilotage/centers", "POST", {code:centerCode.trim().toUpperCase(),name:centerName.trim()});
      setCenterCode(""); setCenterName("");
      setNotice("Centre de coûts créé et audité.");
      setRefresh(n => n + 1);
    } catch (e) { setError((e as Error).message); }
    finally { setBusy(false); }
  }

  async function exportCsv() {
    if (busy) return;
    setBusy(true); setError("");
    try {
      const file = await fetchTenantBlob(
        "pilotage/" + section.endpoint + "/export?from=" + from + "&to=" + to, "text/csv");
      const blobUrl = URL.createObjectURL(file);
      const link = document.createElement("a");
      link.href = blobUrl;
      link.download = "atlas-m01-" + section.endpoint + "-" + from + "-" + to + ".csv";
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.setTimeout(() => URL.revokeObjectURL(blobUrl), 2000);
      setNotice("Export CSV demandé pour l’entreprise active.");
    } catch (e) { setError((e as Error).message); }
    finally { setBusy(false); }
  }

  async function transition(row: Datum, action: "escalated" | "resolved") {
    const reason = window.prompt(action === "escalated"
      ? "Motif de l’escalade (10 caractères minimum)"
      : "Preuve de résolution / pièce de référence (10 caractères minimum)");
    if (reason === null) return;
    if (reason.trim().length < 10) { setError("Motif de 10 caractères minimum requis."); return; }
    if (busy) return;
    setBusy(true); setError(""); setNotice("");
    try {
      await send("pilotage/alerts/transition", "POST", {
        rule_id:Number(row.rule_id),entity_type:String(row.entity_type),
        entity_id:Number(row.entity_id),action,reason:reason.trim(),
      });
      setNotice(action === "escalated" ? "Escalade historisée." : "Résolution vérifiée et historisée.");
      setRefresh(n => n + 1);
    } catch (e) { setError((e as Error).message); }
    finally { setBusy(false); }
  }

  async function createRule(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (busy) return;
    setBusy(true);
    setError("");
    try {
      await send("pilotage/alerts/rules", "POST", {
        name: ruleName, metric, threshold: Number(threshold),
        effective_from: start, effective_to: end || null,
        owner_id: user.id,
      });
      setRuleName("");
      setNotice("Règle créée. Elle est maintenant auditée.");
      setRefresh(n => n + 1);
    } catch (e) { setError((e as Error).message); }
    finally { setBusy(false); }
  }

  async function acknowledge(row: Datum) {
    const comment = window.prompt("Motif de prise en charge (5 caractères minimum) :");
    if (comment === null) return;
    if (comment.trim().length < 5) { setError("Motif trop court."); return; }
    setBusy(true);
    setError("");
    try {
      await send("pilotage/alerts/acknowledge", "POST", {
        rule_id: Number(row.rule_id), entity_type: String(row.entity_type),
        entity_id: Number(row.entity_id), comment: comment.trim(),
      });
      setNotice("Alerte acquittée et journalisée.");
      setRefresh(n => n + 1);
    } catch (e) { setError((e as Error).message); }
    finally { setBusy(false); }
  }

  const columns = data?.rows.length ? Object.keys(data.rows[0]).filter(key =>
    !["id", "rule_id", "entity_id", "entity_type"].includes(key)) : [];
  return (
    <section className="atlas-domain" aria-label="Pilotage de l'entreprise">
      <div className="atlas-domain-toolbar">
        <div>
          <h2>{view === "direction-dashboard" ? "Tableau de bord direction" :
              view === "sales-dashboard" ? "Pilotage commercial" :
              view === "finance-dashboard" ? "Pilotage financier" :
              view === "logistics-dashboard" ? "Pilotage logistique" : "Alertes et objectifs"}</h2>
          <p>{section.description}</p>
          <small>{data?.scope || "Entreprise active uniquement"}</small>
          {data?.generated_at && <p><small>Actualisé le {new Date(data.generated_at).toLocaleString("fr-FR")} · {data.currency || "EUR"} · {data.data_status || "live"}</small></p>}
        </div>
        <div className="date-filter">
          <label>Du <input type="date" value={from} max={to}
            onChange={e => setFrom(e.target.value)} /></label>
          <label>Au <input type="date" value={to} min={from}
            onChange={e => setTo(e.target.value)} /></label>
        </div>
      </div>
      <button type="button" className="button secondary" disabled={busy || loading || !data}
        onClick={() => void exportCsv()}>Exporter les données CSV</button>
      {notice && <p className="notice" role="status">{notice}</p>}
      {error && <p className="error" role="alert">{error}</p>}
      {loading && <p role="status">Chargement des indicateurs…</p>}
      {data && <>
        <div className="stats-grid">
          {data.cards.map(card => <article className="stat-card" key={card.key}>
            <span>{labels[card.key] || card.key}</span>
            <strong>{renderValue(card.key, card.value)}</strong>
          </article>)}
        </div>
        <div className="panel" style={{ padding: 20, marginTop: 20, overflowX: "auto" }}>
          <h3>Détail vérifiable</h3>
          <p>Affichage borné côté serveur · périmètre : {data.scope}</p>
          {!data.rows.length ? <p>Aucune donnée correspondante pour la période.</p> :
            <table style={{ width: "100%", borderCollapse: "collapse", textAlign: "left" }}>
              <thead><tr>
                {columns.map(column => <th key={column} scope="col" style={{ padding: 10 }}>
                  {labels[column] || column}
                </th>)}
                {(view === "direction-dashboard" || view === "finance-dashboard") &&
        <div className="panel" style={{ padding:20, marginTop:20 }}>
          <h3>Objectifs de gestion versionnés</h3>
          <p>Objectifs indicatifs saisis par la direction ; aucune écriture comptable n’est créée.</p>
          {!targets.length ? <p>Aucun objectif défini pour la période.</p> :
            <table style={{ width:"100%", textAlign:"left" }}><thead>
              <tr><th>Indicateur</th><th>Période</th><th>Valeur</th><th>Version</th><th>Motif</th></tr></thead>
              <tbody>{targets.map(t => <tr key={t.id}>
                <td>{labels[t.metric] || t.metric}</td>
                <td>{t.period_from} — {t.period_to}</td>
                <td>{t.unit === "EUR_CENTS" ? euro(t.target_value) : t.target_value.toLocaleString("fr-FR")}</td>
                <td>v{t.version}</td><td>{t.reason}</td>
              </tr>)}</tbody></table>}
          {isAdmin && <form onSubmit={e => void createTarget(e)} className="atlas-domain-fields">
            <label>Indicateur<select value={targetMetric} onChange={e => setTargetMetric(e.target.value)}>
              <option value="issued_ht_cents">Factures émises HT (centimes EUR)</option>
              <option value="issued_invoice_count">Nombre de factures émises</option>
              <option value="validated_order_count">Commandes validées</option>
              <option value="low_stock_count">Produits sous seuil</option>
            </select></label>
            <label>Objectif ({targetMetric.endsWith("_cents") ? "centimes EUR" : "quantité"})
              <input type="number" min="0" max="9000000000000000" required
                value={targetValue} onChange={e => setTargetValue(e.target.value)} /></label>
            <label>Motif / référence de décision
              <input required minLength={10} maxLength={300} value={targetReason}
                onChange={e => setTargetReason(e.target.value)} /></label>
            <button type="submit" className="button primary" disabled={busy}>Publier une nouvelle version</button>
          </form>}
        </div>}
      {view === "finance-dashboard" &&
        <div className="panel" style={{ padding:20, marginTop:20 }}>
          <h3>Centres de coûts</h3>
          <p>Référentiel de gestion indépendant des écritures comptables et des imputations non intégrées.</p>
          {centers.length ? <ul>{centers.map(c => <li key={c.id}>{c.code} · {c.name}</li>)}</ul>
            : <p>Aucun centre de coûts enregistré.</p>}
          {isAdmin && <form className="atlas-domain-fields" onSubmit={e => void createCenter(e)}>
            <label>Code
              <input required minLength={2} maxLength={40} pattern="[A-Z0-9][A-Z0-9_-]*"
                value={centerCode} onChange={e => setCenterCode(e.target.value.toUpperCase())} /></label>
            <label>Libellé
              <input required minLength={3} maxLength={160} value={centerName}
                onChange={e => setCenterName(e.target.value)} /></label>
            <button type="submit" className="button primary" disabled={busy}>Créer le centre</button>
          </form>}
        </div>}
      {view === "alerts-dashboard" && isAdmin && <th scope="col">Action</th>}
              </tr></thead>
              <tbody>{data.rows.map((row, index) => <tr key={String(row.id || row.rule_id) + ":" + String(row.entity_id || index)}>
                {columns.map(column => <td key={column} style={{ padding: 10, borderTop: "1px solid #ccc" }}>
                  {renderValue(column, row[column])}
                </td>)}
                {view === "alerts-dashboard" && isAdmin && <td>
                  <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                    {!row.acknowledged_at && <button type="button"
                      className="button secondary" disabled={busy} onClick={() => void acknowledge(row)}>
                      Acquitter
                    </button>}
                    {Boolean(row.is_active) && !row.escalated_at && <button type="button"
                      className="button secondary" disabled={busy} onClick={() => void transition(row, "escalated")}>
                      Escalader
                    </button>}
                    {!row.is_active && Boolean(row.acknowledged_at) && !row.resolved_at && <button type="button"
                      className="button secondary" disabled={busy} onClick={() => void transition(row, "resolved")}>
                      Clôturer avec preuve
                    </button>}
                    {row.resolved_at ? "Résolue" : null}
                  </div>
                </td>}
              </tr>)}</tbody>
            </table>}
        </div>
        <div className="panel" style={{ padding: 20, marginTop: 20 }}>
          <h3>Définitions et limites des indicateurs</h3>
          <ul>{data.notes.map(note => <li key={note}>{note}</li>)}</ul>
          <p>Sources : PostgreSQL ERP ; aucune consolidation inter-entreprises sans délégation.</p>
        </div>
      </>}
      {view === "alerts-dashboard" && isAdmin &&
        <form className="panel" style={{ padding: 20, marginTop: 20 }} onSubmit={e => void createRule(e)}>
          <h3>Nouvelle règle de surveillance</h3>
          <p>Propriétaire de la règle : votre compte administrateur.</p>
          <div className="atlas-domain-fields">
            <label>Nom<input required minLength={3} maxLength={120} value={ruleName}
              onChange={e => setRuleName(e.target.value)} /></label>
            <label>Indicateur<select value={metric} onChange={e => setMetric(e.target.value)}>
              <option value="low_stock">Stock sous quantité</option>
              <option value="unpaid_invoice_age_days">Facture impayée depuis (jours)</option>
            </select></label>
            <label>Seuil<input required type="number" min={0} max={1000000}
              value={threshold} onChange={e => setThreshold(e.target.value)} /></label>
            <label>Début de validité<input required type="date" value={start}
              onChange={e => setStart(e.target.value)} /></label>
            <label>Fin de validité (optionnelle)<input type="date" min={start} value={end}
              onChange={e => setEnd(e.target.value)} /></label>
          </div>
          <button className="button primary" disabled={busy} type="submit">
            {busy ? "Enregistrement…" : "Créer la règle"}
          </button>
        </form>}
    </section>
  );
}
