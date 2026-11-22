"use client";

import { useEffect, useState } from "react";
import { Search, ChevronRight, LogOut } from "lucide-react";
import { navigationSections, type View } from "@/lib/navigation";
import { canOpenWorkspaceView } from "@/lib/workspaceRoutes";
import type { User } from "@/lib/types";

type SidebarProps = {
  user: User;
  view: View;
  mobile: boolean;
  busy: boolean;
  onNavigate: (view: View) => void;
  onTenantSwitch: (companyId: number) => void;
  onLogout: () => void;
};

const roleLabels: Record<string, string> = {
  admin: "Administrateur",
  commercial: "Commercial",
  stock: "Stock",
  reader: "Lecture seule",
  support_n2: "Support N2",
  support_n3: "Support N3",
};

/** Sidebar owns menu-search/expansion state; authentication and tenant state live in Workspace. */
export default function WorkspaceSidebar({
  user, view, mobile, busy, onNavigate, onTenantSwitch, onLogout,
}: SidebarProps) {
  const [navSearch, setNavSearch] = useState("");
  const [showPlanned, setShowPlanned] = useState(false);
  // Local gateway only, never advertise the development proxy to production users.
  const [localJetLink, setLocalJetLink] = useState(false);
  useEffect(() => {
    setLocalJetLink(["127.0.0.1", "localhost"].includes(window.location.hostname));
  }, []);
  const [expandedSections, setExpandedSections] = useState<string[]>(() =>
    navigationSections
      .filter((section) => section.items.some((item) => item.ready))
      .map((section) => section.id),
  );
  const searchTerm = navSearch.trim().toLocaleLowerCase("fr");
  const accessibleSections = navigationSections
    .filter((section) =>
      (!section.adminOnly || user.role.name === "admin") &&
      (section.id !== "itsm" || ["admin", "support_n2", "support_n3"].includes(user.role.name))
    )
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => {
        // Keep the everyday workspace concise. A search still discovers
        // planned features, clearly disabled until their API exists.
        if (item.ready && !canOpenWorkspaceView(item.id as View, user.role.name)) return false;
        if (!item.ready && !showPlanned && !searchTerm) return false;
        const matches =
          !searchTerm ||
          section.label.toLocaleLowerCase("fr").includes(searchTerm) ||
          item.label.toLocaleLowerCase("fr").includes(searchTerm);
        return matches;
      }),
    }))
    .filter((section) => section.items.length > 0);

  return (
      <aside className={`sidebar ${mobile ? "open" : ""}`}>
        <a
          className="brand"
          href="#"
          onClick={(e) => {
            e.preventDefault();
            onNavigate("dashboard");
          }}
        >
          <span className="brand-mark">A</span>
          <span>
            ATLAS<span className="brand-lite">ONE · WORKSPACE</span>
          </span>
        </a>
        <div className="workspace-chip">
          <span className="workspace-avatar">A</span>
          <div className="tenant-picker">
            <label htmlFor="active-company">Entreprise active</label>
            <select
              id="active-company"
              value={user.active_company_id ?? ""}
              aria-label="Entreprise active"
              disabled={!user.companies?.length || busy}
              onChange={(event) => onTenantSwitch(Number(event.target.value))}
            >
              {(user.companies ?? []).map((company) => (
                <option key={company.id} value={company.id}>{company.name}</option>
              ))}
            </select>
            <small>Données isolées par entreprise</small>
          </div>
          <span className="online-dot" />
        </div>
        <div className="atlas-nav-toolbar">
          <span className="nav-caption">VOS MODULES</span>
          <button
            type="button"
            className="atlas-planned-toggle"
            aria-pressed={showPlanned}
            onClick={() => setShowPlanned((previous) => !previous)}
          >
            {showPlanned ? "Masquer les modules prévus" : "Voir les modules prévus"}
          </button>
        </div>
        <div className="erp-sidebar-search">
          <Search size={15} aria-hidden="true" />
          <input
            type="search"
            aria-label="Rechercher un menu"
            placeholder="Rechercher un menu…"
            value={navSearch}
            onChange={(e) => setNavSearch(e.target.value)}
          />
        </div>
        <nav aria-label="Navigation principale" className="erp-module-nav">
          {accessibleSections.length === 0 && (
            <p className="nav-empty">Aucun menu trouvé.</p>
          )}
          {accessibleSections.map((section) => {
            const expanded =
              searchTerm.length > 0 || expandedSections.includes(section.id);
            return (
              <div className="nav-group" key={section.id}>
                <button
                  type="button"
                  className="nav-group-toggle"
                  aria-expanded={expanded}
                  aria-controls={`erp-nav-${section.id}`}
                  onClick={() =>
                    setExpandedSections((previous) =>
                      previous.includes(section.id)
                        ? previous.filter((id) => id !== section.id)
                        : [...previous, section.id],
                    )
                  }
                >
                  <section.icon size={17} aria-hidden="true" />
                  <span>{section.label}</span>
                  <ChevronRight
                    size={14}
                    aria-hidden="true"
                    className={expanded ? "expanded" : ""}
                  />
                </button>
                {expanded && (
                  <div className="nav-group-children" id={`erp-nav-${section.id}`}>
                    {section.items.map((item) => (
                      <button
                        type="button"
                        key={item.id}
                        disabled={!item.ready}
                        className={`nav-entry ${view === item.id ? "active" : ""}`}
                        aria-current={view === item.id ? "page" : undefined}
                        onClick={() => item.ready && onNavigate(item.id as View)}
                      >
                        <span>{item.label}</span>
                        {!item.ready && (
                          <span className="nav-coming-soon">À venir</span>
                        )}
                        {view === item.id && (
                          <span className="nav-active-dot" />
                        )}
                      </button>
                    ))}
                  </div>
                )}
              </div>
            );
          })}
        </nav>
        {localJetLink && user.role.name === "admin" && (
          <a
            className="nav-entry"
            href="http://127.0.0.1:8100"
            target="_blank"
            rel="noopener noreferrer"
            aria-label="Oracle JET GMAO — passerelle locale"
            title="Nécessite le serveur Oracle JET et la passerelle locale"
          >
            Oracle JET GMAO · local
          </a>
        )}
        <div className="sidebar-bottom">
          <div className="demo-card">
            <span className="demo-pill">ENVIRONNEMENT DÉMO</span>
            <p>
              Un espace de travail pour vos opérations,
              <br />
              vos équipes et vos données.
            </p>
            <span>ATLAS ONE · Version de développement</span>
          </div>
          <div className="profile">
            <span className="avatar">{user.name.slice(0, 1)}</span>
            <div>
              <strong>{user.name.split(" · ")[0]}</strong>
              <small>{roleLabels[user.role.name]}</small>
            </div>
            <button
              className="icon-button"
              aria-label="Se déconnecter"
              onClick={onLogout}
            >
              <LogOut size={17} />
            </button>
          </div>
        </div>
      </aside>
  );
}
