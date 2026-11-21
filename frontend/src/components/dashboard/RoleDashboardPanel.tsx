"use client";

import { useEffect, useState } from "react";
import {
  AlertTriangle, ArrowUpRight, ClipboardList, Clock3, LifeBuoy,
  Package, ShoppingCart, Wallet,
} from "lucide-react";
import { api } from "@/lib/api";
import type { Dashboard, User } from "@/lib/types";
import type { View } from "@/lib/navigation";

type SupportSummary = {
  open: number;
  escalated_n3: number;
  response_sla_breached: number;
  resolution_sla_breached: number;
};

type Props = {
  user: User;
  onNavigate: (view: View) => void;
  refresh: number;
  dashboard?: Dashboard | null;
};

/**
 * P06: visible role-specific landing content from existing authorized APIs.
 * This is presentation, NOT authorization. Laravel remains the permission
 * authority. The active-company switch reloads Workspace and clears state.
 */
export default function RoleDashboardPanel(props: Props) {
  if (["admin", "support_n2", "support_n3"].includes(props.user.role.name)) {
    return <SupportSummaryPanel {...props} />;
  }
  if (props.user.role.name === "stock" && props.dashboard) {
    return <StockSummaryPanel dashboard={props.dashboard} onNavigate={props.onNavigate} />;
  }
  if (props.user.role.name === "commercial" && props.dashboard) {
    return <SalesSummaryPanel dashboard={props.dashboard} onNavigate={props.onNavigate} />;
  }
  return null;
}

function SupportSummaryPanel({ user, onNavigate, refresh }: Props) {
  const [summary, setSummary] = useState<SupportSummary | null>(null);
  const [error, setError] = useState(false);
  const [reload, setReload] = useState(0);

  useEffect(() => {
    let active = true;
    setSummary(null);
    setError(false);
    // Called only for roles entitled to Gate::authorize('support').
    api<SupportSummary>("support/incidents/summary")
      .then((result) => { if (active) setSummary(result); })
      .catch(() => { if (active) setError(true); });
    return () => { active = false; };
  }, [user.active_company_id, refresh, reload]);

  const tiles = summary ? [
    { title: "Incidents en cours", value: summary.open, icon: LifeBuoy },
    { title: "Escalades N3", value: summary.escalated_n3, icon: ClipboardList },
    { title: "SLA réponse dépassé", value: summary.response_sla_breached, icon: Clock3 },
    { title: "SLA résolution dépassé", value: summary.resolution_sla_breached, icon: AlertTriangle },
  ] : [];

  return (
    <section className="atlas-role-panel" aria-label="Pilotage support">
      <header className="atlas-role-header">
        <div>
          <span className="atlas-role-eyebrow">SUPPORT & ITSM</span>
          <h2>{user.role.name === "admin" ? "Vue support de l'entreprise" : "Votre centre de pilotage support"}</h2>
          <p>Incidents, escalades et SLA de la société active, selon vos autorisations.</p>
        </div>
        <button className="button secondary" type="button" onClick={() => onNavigate("support-incidents")}>
          Ouvrir les incidents <ArrowUpRight size={16} aria-hidden="true" />
        </button>
      </header>
      {error ? (
        <div className="atlas-role-status" role="alert">
          <span>Indicateurs support indisponibles.</span>
          <button className="button secondary" type="button" onClick={() => setReload(n => n + 1)}>
            Réessayer
          </button>
        </div>
      ) : !summary ? (
        <p className="atlas-role-status" role="status">Chargement des indicateurs support…</p>
      ) : (
        <div className="atlas-role-stats">
          {tiles.map(({ title, value, icon: Icon }) => (
            <article className="atlas-role-stat" key={title}>
              <span className="atlas-role-stat-top"><span>{title}</span><Icon size={17} aria-hidden="true" /></span>
              <strong>{value}</strong>
              <small>Données de la société active</small>
            </article>
          ))}
        </div>
      )}
    </section>
  );
}

function StockSummaryPanel({ dashboard, onNavigate }: {
  dashboard: Dashboard;
  onNavigate: (view: View) => void;
}) {
  return (
    <section className="atlas-role-panel" aria-label="Priorités stocks">
      <header className="atlas-role-header">
        <div>
          <span className="atlas-role-eyebrow">STOCKS & LOGISTIQUE</span>
          <h2>Vos priorités d'inventaire</h2>
          <p>Alertes courantes calculées sur les produits de la société active.</p>
        </div>
        <button className="button secondary" type="button" onClick={() => onNavigate("products")}>
          Ouvrir les stocks <ArrowUpRight size={16} aria-hidden="true" />
        </button>
      </header>
      <div className="atlas-role-stock">
        <div className="atlas-role-stock-total">
          <Package size={23} aria-hidden="true" />
          <strong>{dashboard.low_stock_count}</strong>
          <span>Produits au seuil d'alerte ou en dessous</span>
        </div>
        <div className="atlas-role-stock-list">
          <div className="atlas-role-stock-head">
            <strong>Produits à surveiller</strong>
            <button type="button" className="text-button" onClick={() => onNavigate("stock-movements")}>
              Voir les mouvements <ArrowUpRight size={14} aria-hidden="true" />
            </button>
          </div>
          {dashboard.low_stock.length === 0 ? (
            <p>Aucun produit au seuil d'alerte.</p>
          ) : (
            <ul>
              {dashboard.low_stock.slice(0, 5).map((product) => (
                <li key={product.id}>
                  <span><strong>{product.name}</strong><small>{product.reference}</small></span>
                  <span className="atlas-role-stock-quantity">{product.stock} / seuil {product.alert_threshold}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </section>
  );
}

const euro = (cents: number) => new Intl.NumberFormat("fr-FR", {
  style: "currency", currency: "EUR",
}).format(cents / 100);

function SalesSummaryPanel({ dashboard, onNavigate }: {
  dashboard: Dashboard;
  onNavigate: (view: View) => void;
}) {
  return (
    <section className="atlas-role-panel" aria-label="Priorités commerciales">
      <header className="atlas-role-header">
        <div>
          <span className="atlas-role-eyebrow">VENTES & COMMERCE</span>
          <h2>Votre suivi commercial</h2>
          <p>Commandes validées et factures de la période sélectionnée.</p>
        </div>
        <button className="button secondary" type="button" onClick={() => onNavigate("quotes")}>
          Voir les devis <ArrowUpRight size={16} aria-hidden="true" />
        </button>
      </header>
      <div className="atlas-role-stats atlas-role-sales-stats">
        {[
          { title: "Commandes validées", value: String(dashboard.orders_count), icon: ShoppingCart, view: "orders" as const },
          { title: "Chiffre d'affaires HT", value: euro(dashboard.revenue_cents), icon: ClipboardList, view: "invoices" as const },
          { title: "À encaisser TTC", value: euro(dashboard.outstanding_cents), icon: Wallet, view: "invoices" as const },
        ].map(({ title, value, icon: Icon, view }) => (
          <button key={title} className="atlas-role-stat atlas-role-stat-link" type="button"
            onClick={() => onNavigate(view)}>
            <span className="atlas-role-stat-top"><span>{title}</span><Icon size={17} aria-hidden="true" /></span>
            <strong>{value}</strong>
            <small>Accéder au module <ArrowUpRight size={12} aria-hidden="true" /></small>
          </button>
        ))}
      </div>
    </section>
  );
}
