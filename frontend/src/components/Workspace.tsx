"use client";

import { useEffect, useState } from "react";
import {
  LayoutDashboard, Users, Truck, Package, ArrowLeftRight, FileText,
  ShoppingBag, Receipt, ShieldCheck, History, Plus, LogOut,
  ChevronRight, X, Menu, Check,
} from "lucide-react";
import { api, send } from "@/lib/api";
import type { User, Contact, Sale, Invoice } from "@/lib/types";
import type { Row, Editor, View, DetailState } from "@/lib/erp-types";
import { labels } from "@/lib/format";
import Login from "@/components/auth/Login";
import DashboardView from "@/components/dashboard/DashboardView";
import ListView from "@/components/lists/ListView";
import EditorForm from "@/components/forms/EditorForm";
import Detail from "@/components/details/Detail";
import Modal from "@/components/ui/Modal";

const nav = [
  { id: "dashboard", label: "Vue d’ensemble", icon: LayoutDashboard },
  { id: "customers", label: "Clients", icon: Users },
  { id: "suppliers", label: "Fournisseurs", icon: Truck },
  { id: "products", label: "Produits & stocks", icon: Package },
  { id: "stock-movements", label: "Mouvements", icon: ArrowLeftRight },
  { id: "quotes", label: "Devis", icon: FileText },
  { id: "orders", label: "Commandes", icon: ShoppingBag },
  { id: "invoices", label: "Factures", icon: Receipt },
  { id: "users", label: "Utilisateurs", icon: ShieldCheck },
  { id: "audit-logs", label: "Journal d’activité", icon: History },
] as const;

export default function Workspace() {
  const [user, setUser] = useState<User | null>(null),
    [checking, setChecking] = useState(true),
    [view, setView] = useState<View>("dashboard"),
    [mobile, setMobile] = useState(false);
  const [notice, setNotice] = useState(""),
    [error, setError] = useState(""),
    [refresh, setRefresh] = useState(0),
    [editor, setEditor] = useState<Editor | null>(null),
    [detail, setDetail] = useState<DetailState | null>(null),
    [busy, setBusy] = useState(false);
  useEffect(() => {
    api<User>("me")
      .then(setUser)
      .catch(() => {})
      .finally(() => setChecking(false));
    const expired = () => setUser(null);
    window.addEventListener("atlas-session-expired", expired);
    return () => window.removeEventListener("atlas-session-expired", expired);
  }, []);
  useEffect(() => {
    if (!notice) return;
    const t = setTimeout(() => setNotice(""), 6000);
    return () => clearTimeout(t);
  }, [notice]);
  const can = (kind: string) =>
    user?.role.name === "admin" ||
    (["customers", "suppliers", "quotes", "orders", "invoices"].includes(
      kind,
    ) &&
      user?.role.name === "commercial") ||
    (["products", "stock-movements"].includes(kind) &&
      user?.role.name === "stock");
  const go = (id: View) => {
    setView(id);
    setMobile(false);
    setError("");
    setDetail(null);
  };
  async function action(path: string, method = "POST", data?: unknown) {
    setBusy(true);
    setError("");
    try {
      const result = await send<Row>(path, method, data);
      setNotice("Opération enregistrée.");
      setRefresh((n) => n + 1);
      setDetail(null);
      return result;
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  async function show(kind: View, row: { id: number }) {
    setError("");
    try {
      if (kind === "customers") {
        setDetail({ kind, row: await api<Contact>(`${kind}/${row.id}`) });
      } else if (kind === "invoices") {
        setDetail({ kind, row: await api<Invoice>(`${kind}/${row.id}`) });
      } else if (kind === "quotes" || kind === "orders") {
        setDetail({ kind, row: await api<Sale>(`${kind}/${row.id}`) });
      }
    } catch (e) {
      setError((e as Error).message);
    }
  }
  if (checking)
    return (
      <div className="loading-screen">
        <div className="brand-mark">A</div>
        <p>Ouverture de votre espace…</p>
      </div>
    );
  if (!user)
    return (
      <Login
        onLogin={(u) => {
          setUser(u);
          setView("dashboard");
          setError("");
        }}
      />
    );
  const current = nav.find((n) => n.id === view)!;
  return (
    <div className="app-shell">
      {mobile && (
        <div className="sidebar-scrim" onClick={() => setMobile(false)} />
      )}
      <aside className={`sidebar ${mobile ? "open" : ""}`}>
        <a
          className="brand"
          href="#"
          onClick={(e) => {
            e.preventDefault();
            go("dashboard");
          }}
        >
          <span className="brand-mark">A</span>
          <span>
            ATLAS<span className="brand-lite">ERP LITE</span>
          </span>
        </a>
        <div className="workspace-chip">
          <span className="workspace-avatar">A</span>
          <div>
            Atelier & Co.<small>Espace de démonstration</small>
          </div>
          <span className="online-dot" />
        </div>
        <div className="nav-caption">ESPACE DE TRAVAIL</div>
        <nav aria-label="Navigation principale">
          {nav
            .filter(
              (n) =>
                user.role.name === "admin" ||
                !["users", "audit-logs"].includes(n.id),
            )
            .map((n, i) => (
              <div key={n.id}>
                {[1, 5, 8].includes(i) && <div className="nav-separator" />}
                <button
                  onClick={() => go(n.id)}
                  className={view === n.id ? "active" : ""}
                  aria-current={view === n.id ? "page" : undefined}
                >
                  <n.icon size={18} />
                  <span>{n.label}</span>
                  {view === n.id && <span className="nav-active-dot" />}
                </button>
              </div>
            ))}
        </nav>
        <div className="sidebar-bottom">
          <div className="demo-card">
            <span className="demo-pill">PORTFOLIO</span>
            <p>
              Les idées prennent forme.
              <br />
              Votre activité aussi.
            </p>
            <span>ATLAS ERP Lite · v1.0</span>
          </div>
          <div className="profile">
            <span className="avatar">{user.name.slice(0, 1)}</span>
            <div>
              <strong>{user.name.split(" · ")[0]}</strong>
              <small>{labels[user.role.name]}</small>
            </div>
            <button
              className="icon-button"
              aria-label="Se déconnecter"
              onClick={async () => {
                try {
                  await send("logout", "POST");
                  setUser(null);
                } catch (e) {
                  setError((e as Error).message);
                }
              }}
            >
              <LogOut size={17} />
            </button>
          </div>
        </div>
      </aside>
      <main className="main">
        <header className="topbar">
          <div className="breadcrumb">
            <button
              className="icon-button mobile-menu"
              aria-label="Ouvrir le menu"
              onClick={() => setMobile(true)}
            >
              <Menu size={21} />
            </button>
            <span>Espace de travail</span>
            <ChevronRight size={14} />
            <strong>{current.label}</strong>
          </div>
          <div className="topbar-right">
            <span className="environment">
              <i />
              Données de démonstration
            </span>
            <span className="avatar small">{user.name[0]}</span>
          </div>
        </header>
        <div className="content">
          <div className="page-heading">
            <div>
              <div className="eyebrow">VOTRE ACTIVITÉ, EN CLAIR</div>
              <h1>
                {current.label}
                <span className="heading-dot">.</span>
              </h1>
              <p>
                {view === "dashboard"
                  ? `Bonjour ${user.name.split(" · ")[0]}, voici l’essentiel de votre activité.`
                  : (
                      {
                        customers:
                          "Des relations durables, un suivi simplifié.",
                        suppliers: "Vos partenaires, réunis au même endroit.",
                        products: "Le bon produit, au bon moment.",
                        "stock-movements":
                          "Chaque entrée et chaque sortie, en toute transparence.",
                        quotes: "De la première proposition à la commande.",
                        orders:
                          "Suivez vos ventes, de la création à la validation.",
                        invoices:
                          "Une facturation claire, du brouillon au règlement.",
                        users: "Les bonnes permissions pour chaque membre.",
                        "audit-logs": "La trace des opérations sensibles.",
                      } as Record<string, string>
                    )[view]}
              </p>
            </div>
            {can(view) &&
              ![
                "dashboard",
                "stock-movements",
                "audit-logs",
                "invoices",
              ].includes(view) && (
                <button
                  className="button primary"
                  onClick={() => setEditor({ kind: view })}
                >
                  <Plus size={17} />
                  {
                    (
                      {
                        customers: "Nouveau client",
                        suppliers: "Nouveau fournisseur",
                        products: "Nouveau produit",
                        quotes: "Nouveau devis",
                        orders: "Nouvelle commande",
                        users: "Nouvel utilisateur",
                      } as Record<string, string>
                    )[view]
                  }
                </button>
              )}
          </div>
          {notice && (
            <div className="notice" role="status">
              <Check size={18} />
              {notice}
            </div>
          )}
          {error && (
            <div className="error" role="alert">
              {error}
              <button
                className="icon-button"
                aria-label="Fermer l’erreur"
                onClick={() => setError("")}
              >
                <X size={17} />
              </button>
            </div>
          )}
          {view === "dashboard" ? (
            <DashboardView refresh={refresh} go={go} />
          ) : (
            <ListView
              key={view}
              view={view}
              refresh={refresh}
              can={can(view)}
              onEdit={(row) => setEditor({ kind: view, row })}
              onShow={(row) => show(view, row)}
              onStock={(row) => setEditor({ kind: "adjust", row })}
              onDelete={(row) =>
                setEditor({ kind: "delete", row: { ...row, category: view } })
              }
              busy={busy}
            />
          )}
          <footer className="page-footer">
            <span>ATLAS ERP Lite</span>
            <span>Un peu moins de gestion. Un peu plus de perspective.</span>
          </footer>
        </div>
      </main>
      {editor && (
        <Modal
          title={
            editor.kind === "delete"
              ? "Confirmer la suppression"
              : editor.kind === "adjust"
                ? "Ajuster le stock"
                : `${editor.row ? "Modifier" : "Créer"} · ${nav.find((n) => n.id === editor.kind)?.label}`
          }
          onClose={() => setEditor(null)}
        >
          <EditorForm
            editor={editor}
            onClose={() => setEditor(null)}
            onSaved={() => {
              setEditor(null);
              setRefresh((n) => n + 1);
              setNotice("Enregistrement effectué.");
            }}
          />
        </Modal>
      )}
      {detail && (
        <Modal
          title={detail.kind === "customers" ? detail.row.name : detail.row.number || "Facture"}
          onClose={() => setDetail(null)}
        >
          <Detail
            {...detail}
            can={can(detail.kind)}
            busy={busy}
            action={action}
          />
        </Modal>
      )}
    </div>
  );
}
