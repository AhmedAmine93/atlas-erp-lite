"use client";

import { useEffect, useState } from "react";
import {
  AlertTriangle, ArrowUpRight, ClipboardList, Clock3, LifeBuoy,
  Package, ShoppingCart, Wallet,
} from "lucide-react";
import { api } from "@/lib/api";
import type { Dashboard, User } from "@/lib/types";
import type { View } from "@/lib/navigation";
import MetricCard from "@/components/ui/MetricCard";
import SectionHeading from "@/components/ui/SectionHeading";
import FeedbackState from "@/components/ui/FeedbackState";

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
 * Role-aware display only: Laravel remains the permission authority.
 * Each card uses existing authorized, active-tenant API data.
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
    api<SupportSummary>("support/incidents/summary")
      .then((result) => { if (active) setSummary(result); })
      .catch(() => { if (active) setError(true); });
    return () => { active = false; };
  }, [user.active_company_id, refresh, reload]);

  const metrics = summary ? [
    { label: "Incidents en cours", value: summary.open, icon: LifeBuoy },
    { label: "Escalades N3", value: summary.escalated_n3, icon: ClipboardList },
    { label: "SLA réponse dépassé", value: summary.response_sla_breached, icon: Clock3 },
    { label: "SLA résolution dépassé", value: summary.resolution_sla_breached, icon: AlertTriangle },
  ] : [];

  return (
    <section className="atlas-role-panel" aria-label="Pilotage support">
      <SectionHeading
        eyebrow="SUPPORT & ITSM"
        title={user.role.name === "admin" ? "Vue support de l'entreprise" : "Votre centre de pilotage support"}
        description="Incidents, escalades et SLA de la société active, selon vos autorisations."
        action={
          <button className="button secondary" type="button" onClick={() => onNavigate("support-incidents")}>
            Ouvrir les incidents <ArrowUpRight size={16} aria-hidden="true" />
          </button>
        }
      />
      {error ? (
        <FeedbackState kind="error" onRetry={() => setReload((n) => n + 1)}>
          Indicateurs support indisponibles.
        </FeedbackState>
      ) : !summary ? (
        <FeedbackState kind="loading">Chargement des indicateurs support…</FeedbackState>
      ) : (
        <div className="atlas-role-stats">
          {metrics.map(({ label, value, icon }) => (
            <MetricCard
              key={label}
              label={label}
              value={value}
              icon={icon}
              hint="Données de la société active"
            />
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
      <SectionHeading
        eyebrow="STOCKS & LOGISTIQUE"
        title="Vos priorités d'inventaire"
        description="Alertes courantes calculées sur les produits de la société active."
        action={
          <button className="button secondary" type="button" onClick={() => onNavigate("products")}>
            Ouvrir les stocks <ArrowUpRight size={16} aria-hidden="true" />
          </button>
        }
      />
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
            <FeedbackState kind="empty">Aucun produit au seuil d'alerte.</FeedbackState>
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
  const metrics = [
    { label: "Commandes validées", value: String(dashboard.orders_count), icon: ShoppingCart, view: "orders" as const },
    { label: "Chiffre d'affaires HT", value: euro(dashboard.revenue_cents), icon: ClipboardList, view: "invoices" as const },
    { label: "À encaisser TTC", value: euro(dashboard.outstanding_cents), icon: Wallet, view: "invoices" as const },
  ];

  return (
    <section className="atlas-role-panel" aria-label="Priorités commerciales">
      <SectionHeading
        eyebrow="VENTES & COMMERCE"
        title="Votre suivi commercial"
        description="Commandes validées et factures de la période sélectionnée."
        action={
          <button className="button secondary" type="button" onClick={() => onNavigate("quotes")}>
            Voir les devis <ArrowUpRight size={16} aria-hidden="true" />
          </button>
        }
      />
      <div className="atlas-role-stats atlas-role-sales-stats">
        {metrics.map(({ label, value, icon, view }) => (
          <MetricCard
            key={label}
            label={label}
            value={value}
            icon={icon}
            hint="Accéder au module ↗"
            onClick={() => onNavigate(view)}
          />
        ))}
      </div>
    </section>
  );
}
