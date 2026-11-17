"use client";
import {
  useEffect,
  useState,
  useRef,
  type FormEvent,
  type ReactNode,
} from "react";
import {
  LayoutDashboard,
  Users,
  Truck,
  Package,
  ArrowLeftRight,
  FileText,
  ShoppingBag,
  Receipt,
  ShieldCheck,
  History,
  Search,
  Plus,
  ArrowUpRight,
  ArrowRight,
  LogOut,
  ChevronLeft,
  ChevronRight,
  X,
  Menu,
  SlidersHorizontal,
  Check,
  Download,
  Pencil,
  Trash2,
  AlertTriangle,
  Boxes,
  Wallet,
  TrendingUp,
} from "lucide-react";
import { api, send, all } from "@/lib/api";
import type {
  User,
  Contact,
  Product,
  Sale,
  Invoice,
  Movement,
  Audit,
  Dashboard,
  Page,
  Role,
} from "@/lib/types";
import Login from "@/components/auth/Login";
import Badge from "@/components/ui/Badge";
import Empty from "@/components/ui/Empty";

import DashboardView from "@/components/dashboard/DashboardView";

import ListView from "@/components/lists/ListView";
import type { Row, Editor } from "@/lib/erp-types";
import EditorForm from "@/components/forms/EditorForm";
// const euro = (n: number) =>
//   new Intl.NumberFormat("fr-FR", {
//     style: "currency",
//     currency: "EUR",
//     maximumFractionDigits: 2,
//   }).format(Number(n) / 100);
// const date = (s: string) =>
//   new Date(s).toLocaleDateString("fr-FR", {
//     day: "2-digit",
//     month: "short",
//     year: "numeric",
//   });
// const labels: Record<string, string> = {
//   draft: "Brouillon",
//   converted: "Converti",
//   validated: "Validée",
//   issued: "Émise",
//   paid: "Payée",
//   cancelled: "Annulée",
//   admin: "Administrateur",
//   commercial: "Commercial",
//   stock: "Stock",
//   reader: "Lecture seule",
// };
import { euro, date, labels } from "@/lib/format";
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
];
type View = (typeof nav)[number]["id"];

// function Badge({ status }: { status: string }) {
//   return <span className={`badge ${status}`}>{labels[status] || status}</span>;
// }
// function Empty({ text = "Aucun résultat pour le moment." }: { text?: string }) {
//   return (
//     <div className="empty">
//       <Boxes size={32} />
//       <p>{text}</p>
//     </div>
//   );
// }
function Modal({
  title,
  children,
  onClose,
}: {
  title: string;
  children: ReactNode;
  onClose: () => void;
}) {
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const previous = document.activeElement as HTMLElement;
    ref.current?.focus();
    const handler = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
      if (e.key === "Tab") {
        const items = ref.current?.querySelectorAll<HTMLElement>(
          "button:not(:disabled),input,select,textarea,a[href]",
        );
        if (!items?.length) return;
        const first = items[0],
          last = items[items.length - 1];
        if (
          e.shiftKey &&
          (document.activeElement === first ||
            document.activeElement === ref.current)
        ) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      }
    };
    document.addEventListener("keydown", handler);
    const overflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.removeEventListener("keydown", handler);
      document.body.style.overflow = overflow;
      previous?.focus();
    };
  }, [onClose]);
  return (
    <div
      className="modal-backdrop"
      onMouseDown={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div
        className="modal"
        ref={ref}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-label={title}
      >
        <header>
          <h2>{title}</h2>
          <button className="icon-button" aria-label="Fermer" onClick={onClose}>
            <X size={20} />
          </button>
        </header>
        {children}
      </div>
    </div>
  );
}
export default function Workspace() {
  const [user, setUser] = useState<User | null>(null),
    [checking, setChecking] = useState(true),
    [view, setView] = useState<View>("dashboard"),
    [mobile, setMobile] = useState(false);
  const [notice, setNotice] = useState(""),
    [error, setError] = useState(""),
    [refresh, setRefresh] = useState(0),
    [editor, setEditor] = useState<Editor | null>(null),
    [detail, setDetail] = useState<{ kind: string; row: Row } | null>(null),
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
  async function show(kind: string, row: Row) {
    setError("");
    try {
      setDetail({ kind, row: await api<Row>(`${kind}/${row.id}`) });
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
          title={(detail.row.number || detail.row.name || "Facture") as string}
          onClose={() => setDetail(null)}
        >
          <Detail
            kind={detail.kind}
            row={detail.row}
            can={can(detail.kind)}
            busy={busy}
            action={action}
          />
        </Modal>
      )}
    </div>
  );
}

// function Login({ onLogin }: { onLogin: (u: User) => void }) {
//   const [email, setEmail] = useState("admin@atlas.example"),
//     [password, setPassword] = useState(""),
//     [error, setError] = useState(""),
//     [busy, setBusy] = useState(false);
//   async function submit(e: FormEvent) {
//     e.preventDefault();
//     setError("");
//     setBusy(true);
//     try {
//       onLogin(await send<User>("login", "POST", { email, password }));
//     } catch (e) {
//       setError((e as Error).message);
//     } finally {
//       setBusy(false);
//     }
//   }
//   return (
//     <div className="login-page">
//       <section className="login-story">
//         <div className="brand">
//           <span className="brand-mark">A</span>
//           <span>
//             ATLAS<span className="brand-lite">ERP LITE</span>
//           </span>
//         </div>
//         <div>
//           <span className="demo-pill">L’ESSENTIEL, BIEN PENSÉ</span>
//           <h1>
//             Une vision claire.
//             <br />
//             Une activité
//             <br />
//             <em>qui avance.</em>
//           </h1>
//           <p>
//             Clients, stocks, ventes et factures.
//             <br />
//             Tout se retrouve. Tout se suit.
//           </p>
//           <div className="login-art">
//             <div className="art-orbit" />
//             <div className="art-block one">
//               Clients <Users />
//             </div>
//             <div className="art-block two">
//               Ventes <TrendingUp />
//             </div>
//             <div className="art-block three">
//               Stocks <Package />
//             </div>
//           </div>
//         </div>
//         <small>Projet portfolio · PHP / Next.js / PostgreSQL</small>
//       </section>
//       <section className="login-form">
//         <div className="eyebrow">BIENVENUE DANS VOTRE ESPACE</div>
//         <h2>Heureux de vous retrouver.</h2>
//         <p>Connectez-vous pour garder le cap sur votre activité.</p>
//         <form onSubmit={submit}>
//           <label>
//             Adresse e-mail
//             <input
//               type="email"
//               autoComplete="username"
//               required
//               value={email}
//               onChange={(e) => setEmail(e.target.value)}
//             />
//           </label>
//           <label>
//             Mot de passe
//             <input
//               type="password"
//               autoComplete="current-password"
//               required
//               value={password}
//               onChange={(e) => setPassword(e.target.value)}
//             />
//           </label>
//           {error && (
//             <div className="error" role="alert">
//               {error}
//             </div>
//           )}
//           <button className="button primary" disabled={busy}>
//             {busy ? "Connexion…" : "Se connecter"}
//             <ArrowRight size={18} />
//           </button>
//         </form>
//         <p className="login-note">
//           <ShieldCheck size={18} />
//           Données fictives · Aucun paiement bancaire réel.
//           <br />
//           Les accès de démonstration sont dans le README.
//         </p>
//       </section>
//     </div>
//   );
// }







function Detail({
  kind,
  row,
  can,
  busy,
  action,
}: {
  kind: string;
  row: Row;
  can: boolean;
  busy: boolean;
  action: (p: string, m?: string, d?: unknown) => Promise<Row | undefined>;
}) {
  const [error, setError] = useState("");
  if (kind === "customers")
    return (
      <div className="detail-content">
        <p>
          {row.email}
          <br />
          {row.address}
        </p>
        <h3>Historique des commandes</h3>
        {row.orders?.length ? (
          <table>
            <thead>
              <tr>
                <th>Commande</th>
                <th>Date</th>
                <th>Total TTC</th>
                <th>Statut</th>
              </tr>
            </thead>
            <tbody>
              {row.orders.map((o) => (
                <tr key={o.id}>
                  <td>{o.number}</td>
                  <td>{date(o.created_at)}</td>
                  <td>{euro(o.total_cents)}</td>
                  <td>
                    <Badge status={o.status} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <Empty text="Ce client n’a pas encore de commande." />
        )}
        <p className="form-hint">Les 100 commandes les plus récentes.</p>
      </div>
    );
  const invoice = kind === "invoices",
    customer = invoice ? row.snapshot.customer : row.customer,
    items = invoice ? row.snapshot.items : row.items;
  async function download() {
    setError("");
    try {
      const res = await fetch(`/api/invoices/${row.id}/pdf`, {
        headers: { Accept: "application/pdf" },
      });
      if (!res.ok) throw new Error("La génération du PDF a échoué.");
      const url = URL.createObjectURL(await res.blob());
      const a = document.createElement("a");
      a.href = url;
      a.download = `${row.number || "BROUILLON-" + row.id}.pdf`;
      a.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
      setError((e as Error).message);
    }
  }
  return (
    <div className="detail-content">
      <div className="document-summary">
        <div>
          <span className="eyebrow">CLIENT</span>
          <h3>{customer.name}</h3>
          <p>
            {customer.email}
            <br />
            {customer.address}
          </p>
        </div>
        <Badge status={row.status} />
      </div>
      <div className="table-scroll">
        <table>
          <thead>
            <tr>
              <th>Produit</th>
              <th>Qté</th>
              <th>Prix HT</th>
              <th>TVA</th>
              <th>Total HT</th>
            </tr>
          </thead>
          <tbody>
            {items?.map((i, n) => (
              <tr key={n}>
                <td>
                  {i.name}
                  <small>{i.reference}</small>
                </td>
                <td>{i.quantity}</td>
                <td>{euro(i.price_cents)}</td>
                <td>{i.vat_bps / 100} %</td>
                <td>{euro(i.subtotal_cents)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="totals">
        <span>
          Total HT<b>{euro(row.subtotal_cents)}</b>
        </span>
        <span>
          TVA<b>{euro(row.tax_cents)}</b>
        </span>
        <strong>
          Total TTC<b>{euro(row.total_cents)}</b>
        </strong>
      </div>
      {row.notes && <p>{row.notes}</p>}
      {invoice && (
        <p className="form-hint">
          Facture de démonstration. Le statut « Payée » est une déclaration
          manuelle. L’annulation ne réintègre pas le stock.
        </p>
      )}
      {error && (
        <div className="error" role="alert">
          {error}
        </div>
      )}
      <div className="form-actions wrap">
        {invoice && (
          <button className="button secondary" onClick={download}>
            <Download size={16} />
            Télécharger le PDF
          </button>
        )}
        {can && kind === "quotes" && row.status === "draft" && (
          <button
            className="button primary"
            disabled={busy}
            onClick={() => action(`quotes/${row.id}/convert`)}
          >
            Convertir en commande
            <ArrowRight size={16} />
          </button>
        )}
        {can && kind === "orders" && row.status === "draft" && (
          <button
            className="button primary"
            disabled={busy}
            onClick={() => action(`orders/${row.id}/validate`)}
          >
            Valider la commande
            <Check size={16} />
          </button>
        )}
        {can && kind === "orders" && row.status === "validated" && (
          <button
            className="button primary"
            disabled={busy}
            onClick={() => action("invoices", "POST", { order_id: row.id })}
          >
            Créer la facture
            <Receipt size={16} />
          </button>
        )}
        {can && invoice && row.status === "draft" && (
          <button
            className="button primary"
            disabled={busy}
            onClick={() =>
              action(`invoices/${row.id}`, "PATCH", { status: "issued" })
            }
          >
            Émettre la facture
          </button>
        )}
        {can && invoice && row.status === "issued" && (
          <button
            className="button primary"
            disabled={busy}
            onClick={() =>
              action(`invoices/${row.id}`, "PATCH", { status: "paid" })
            }
          >
            Marquer comme payée
          </button>
        )}
        {can && invoice && ["draft", "issued"].includes(row.status) && (
          <button
            className="button secondary danger"
            disabled={busy}
            onClick={() => {
              if (
                window.confirm(
                  "Annuler cette facture ? Cette action est définitive et ne remet pas les produits en stock.",
                )
              )
                action(`invoices/${row.id}`, "PATCH", { status: "cancelled" });
            }}
          >
            Annuler la facture
          </button>
        )}
      </div>
    </div>
  );
}
