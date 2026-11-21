"use client";

import { ArrowUpRight, BarChart3, LifeBuoy, Package, ShoppingCart, UsersRound } from "lucide-react";
import type { User } from "@/lib/types";
import type { View } from "@/lib/navigation";

type QuickLink = {
  view: View;
  title: string;
  description: string;
  icon: typeof UsersRound;
};

const allLinks: QuickLink[] = [
  { view: "customers", title: "Clients", description: "Répertoire CRM", icon: UsersRound },
  { view: "orders", title: "Commandes", description: "Cycle commercial", icon: ShoppingCart },
  { view: "products", title: "Stocks", description: "Catalogue & alertes", icon: Package },
  { view: "support-incidents", title: "Incidents", description: "Support N2 / N3", icon: LifeBuoy },
];

/** An authenticated, tenant-aware launchpad. No new API or simulated KPI. */
export default function WorkspaceWelcome({
  user,
  onNavigate,
}: {
  user: User;
  onNavigate: (view: View) => void;
}) {
  const company = user.companies?.find((item) => item.id === user.active_company_id);
  const quickLinks = allLinks.filter((item) =>
    item.view !== "support-incidents" ||
    ["admin", "support_n2", "support_n3"].includes(user.role.name)
  );

  return (
    <section className="atlas-welcome" aria-labelledby="atlas-welcome-title">
      <div className="atlas-welcome-heading">
        <div className="atlas-welcome-eyebrow">
          <span className="atlas-welcome-pulse" aria-hidden="true" />
          ATLAS ONE / ESPACE ENTREPRISE
        </div>
        <h2 id="atlas-welcome-title">Pilotez votre activité avec une seule vue.</h2>
        <p>
          Retrouvez vos opérations, vos données et vos équipes.
          Les indicateurs ci-dessous proviennent de votre société active.
        </p>
        <div className="atlas-welcome-meta">
          <span><BarChart3 size={15} aria-hidden="true" /> Tableau de bord opérationnel</span>
          <span className="atlas-welcome-tenant" title={company?.name ?? "Entreprise active"}>
            {company?.name ?? "Entreprise active"}
          </span>
        </div>
      </div>
      <section className="atlas-launchpad" aria-label="Accès rapides">
        <div className="atlas-launchpad-heading">
          <strong>Accès rapides</strong>
          <small>Modules disponibles</small>
        </div>
        <div className="atlas-launchpad-grid">
          {quickLinks.map(({ view, title, description, icon: Icon }) => (
            <button
              className="atlas-launchpad-button"
              type="button"
              key={view}
              onClick={() => onNavigate(view)}
            >
              <span className="atlas-launchpad-icon"><Icon size={18} aria-hidden="true" /></span>
              <span className="atlas-launchpad-text"><strong>{title}</strong><small>{description}</small></span>
              <ArrowUpRight size={16} aria-hidden="true" className="atlas-launchpad-arrow" />
            </button>
          ))}
        </div>
      </section>
    </section>
  );
}
