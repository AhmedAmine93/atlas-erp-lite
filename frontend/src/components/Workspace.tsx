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
  LifeBuoy,
} from "lucide-react";
import { api, send, all } from "@/lib/api";
import SupportIncidents from "@/components/SupportIncidents";
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

const euro = (n: number) =>
  new Intl.NumberFormat("fr-FR", {
    style: "currency",
    currency: "EUR",
    maximumFractionDigits: 2,
  }).format(Number(n) / 100);
const date = (s: string) =>
  new Date(s).toLocaleDateString("fr-FR", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  });
const labels: Record<string, string> = {
  draft: "Brouillon",
  converted: "Converti",
  validated: "Validée",
  issued: "Émise",
  paid: "Payée",
  cancelled: "Annulée",
  admin: "Administrateur",
  commercial: "Commercial",
  stock: "Stock",
  reader: "Lecture seule",
};
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
  { id: "support", label: "Support N2/N3", icon: LifeBuoy },
];
type View = (typeof nav)[number]["id"];
type Row = Contact & Product & Sale & Invoice & Movement & Audit & User;
type Editor = { kind: string; row?: Partial<Row> };
function Badge({ status }: { status: string }) {
  return <span className={`badge ${status}`}>{labels[status] || status}</span>;
}
function Empty({ text = "Aucun résultat pour le moment." }: { text?: string }) {
  return (
    <div className="empty">
      <Boxes size={32} />
      <p>{text}</p>
    </div>
  );
}
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
                !["users", "audit-logs", "support"].includes(n.id),
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
                        support: "Incidents, investigations et résolutions techniques.",
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
                "support",
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
          ) : view === "support" ? (
            <SupportIncidents />
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

function Login({ onLogin }: { onLogin: (u: User) => void }) {
  const [email, setEmail] = useState("admin@atlas.example"),
    [password, setPassword] = useState(""),
    [error, setError] = useState(""),
    [busy, setBusy] = useState(false);
  async function submit(e: FormEvent) {
    e.preventDefault();
    setError("");
    setBusy(true);
    try {
      onLogin(await send<User>("login", "POST", { email, password }));
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="login-page">
      <section className="login-story">
        <div className="brand">
          <span className="brand-mark">A</span>
          <span>
            ATLAS<span className="brand-lite">ERP LITE</span>
          </span>
        </div>
        <div>
          <span className="demo-pill">L’ESSENTIEL, BIEN PENSÉ</span>
          <h1>
            Une vision claire.
            <br />
            Une activité
            <br />
            <em>qui avance.</em>
          </h1>
          <p>
            Clients, stocks, ventes et factures.
            <br />
            Tout se retrouve. Tout se suit.
          </p>
          <div className="login-art">
            <div className="art-orbit" />
            <div className="art-block one">
              Clients <Users />
            </div>
            <div className="art-block two">
              Ventes <TrendingUp />
            </div>
            <div className="art-block three">
              Stocks <Package />
            </div>
          </div>
        </div>
        <small>Projet portfolio · PHP / Next.js / PostgreSQL</small>
      </section>
      <section className="login-form">
        <div className="eyebrow">BIENVENUE DANS VOTRE ESPACE</div>
        <h2>Heureux de vous retrouver.</h2>
        <p>Connectez-vous pour garder le cap sur votre activité.</p>
        <form onSubmit={submit}>
          <label>
            Adresse e-mail
            <input
              type="email"
              autoComplete="username"
              required
              value={email}
              onChange={(e) => setEmail(e.target.value)}
            />
          </label>
          <label>
            Mot de passe
            <input
              type="password"
              autoComplete="current-password"
              required
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
          </label>
          {error && (
            <div className="error" role="alert">
              {error}
            </div>
          )}
          <button className="button primary" disabled={busy}>
            {busy ? "Connexion…" : "Se connecter"}
            <ArrowRight size={18} />
          </button>
        </form>
        <p className="login-note">
          <ShieldCheck size={18} />
          Données fictives · Aucun paiement bancaire réel.
          <br />
          Les accès de démonstration sont dans le README.
        </p>
      </section>
    </div>
  );
}

function DashboardView({
  refresh,
  go,
}: {
  refresh: number;
  go: (v: View) => void;
}) {
  const localToday = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  };
  const [to, setTo] = useState(localToday),
    [from, setFrom] = useState(() => localToday().slice(0, 8) + "01"),
    [data, setData] = useState<Dashboard | null>(null),
    [error, setError] = useState("");
  useEffect(() => {
    let active = true;
    setError("");
    api<Dashboard>(`dashboard?from=${from}&to=${to}`)
      .then((d) => {
        if (active) setData(d);
      })
      .catch((e) => {
        if (active) setError(e.message);
      });
    return () => {
      active = false;
    };
  }, [from, to, refresh]);
  return (
    <>
      <div className="dashboard-tools">
        <span className="section-kicker">
          <i /> Vue d’ensemble de l’entreprise
        </span>
        <div className="date-filter">
          <SlidersHorizontal size={15} />
          <input
            aria-label="Début de période"
            type="date"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
          />
          <span>—</span>
          <input
            aria-label="Fin de période"
            type="date"
            value={to}
            min={from}
            onChange={(e) => setTo(e.target.value)}
          />
        </div>
      </div>
      {error && (
        <div className="error" role="alert">
          {error}
        </div>
      )}
      {!data ? (
        <Empty text="Chargement des indicateurs…" />
      ) : (
        <>
          <div className="stats-grid">
            {[
              {
                title: "Chiffre d’affaires",
                value: euro(data.revenue_cents),
                note: "HT · Factures émises et payées",
                icon: TrendingUp,
                featured: true,
              },
              {
                title: "Commandes validées",
                value: String(data.orders_count).padStart(2, "0"),
                note: "Validées sur la période",
                icon: ShoppingBag,
              },
              {
                title: "À encaisser",
                value: euro(data.outstanding_cents),
                note: "TTC · Factures émises non payées",
                icon: Wallet,
              },
              {
                title: "Alertes de stock",
                value: String(data.low_stock_count).padStart(2, "0"),
                note: "Produits au seuil ou en dessous",
                icon: Package,
              },
            ].map((s, i) => (
              <article
                className={`stat-card ${s.featured ? "featured" : ""}`}
                key={s.title}
              >
                <div className="stat-top">
                  <span>{s.title}</span>
                  <s.icon size={18} />
                </div>
                <strong>{s.value}</strong>
                <div className="stat-note">
                  {i === 3 ? (
                    <span className="amber-dot" />
                  ) : (
                    <span className="tiny-line" />
                  )}
                  {s.note}
                </div>
              </article>
            ))}
          </div>
          <div className="dashboard-grid">
            <section className="panel revenue-panel">
              <div className="panel-heading">
                <div>
                  <h2>Le rythme de vos ventes</h2>
                  <p>Chiffre d’affaires HT par jour d’émission</p>
                </div>
                <span className="legend">
                  <i />
                  Chiffre d’affaires
                </span>
              </div>
              <RevenueChart data={data.daily_revenue} />
              <div className="chart-foot">
                <span>Sur la période sélectionnée</span>
                <strong>
                  {euro(data.revenue_cents)} <span>HT</span>
                </strong>
              </div>
            </section>
            <section className="panel top-products">
              <div className="panel-heading">
                <div>
                  <h2>Vos meilleures ventes</h2>
                  <p>Les produits qui font la différence</p>
                </div>
                <span className="mini-label">TOP 5</span>
              </div>
              {data.top_products.length ? (
                data.top_products.map((p, i) => (
                  <div className="ranking" key={p.product_id}>
                    <span className="rank">0{i + 1}</span>
                    <span className="product-icon">
                      <Package size={20} />
                    </span>
                    <div>
                      <strong>{p.name}</strong>
                      <small>
                        {p.quantity} unité{Number(p.quantity) > 1 ? "s" : ""}{" "}
                        vendue{Number(p.quantity) > 1 ? "s" : ""}
                      </small>
                    </div>
                    <span>{euro(p.revenue_cents)}</span>
                  </div>
                ))
              ) : (
                <Empty />
              )}
              <button
                className="text-button panel-link"
                onClick={() => go("products")}
              >
                Explorer le catalogue
                <ArrowRight size={16} />
              </button>
            </section>
            <section className="panel recent-orders">
              <div className="panel-heading">
                <div>
                  <h2>Les dernières commandes</h2>
                  <p>Les derniers échanges devenus projets</p>
                </div>
                <button className="text-button" onClick={() => go("orders")}>
                  Tout voir
                  <ArrowUpRight size={16} />
                </button>
              </div>
              <div className="table-scroll">
                <table>
                  <thead>
                    <tr>
                      <th>Commande</th>
                      <th>Client</th>
                      <th>Montant TTC</th>
                      <th>Statut</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.recent_orders.map((o) => (
                      <tr key={o.id}>
                        <td className="strong">
                          {o.number}
                          <small>{date(o.created_at)}</small>
                        </td>
                        <td>{o.customer.name}</td>
                        <td className="money">{euro(o.total_cents)}</td>
                        <td>
                          <Badge status={o.status} />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                {!data.recent_orders.length && <Empty />}
              </div>
            </section>
            <section className="panel stock-alerts">
              <div className="panel-heading">
                <div>
                  <h2>
                    <span className="amber-dot" /> Un œil sur vos stocks
                  </h2>
                  <p>À réapprovisionner prochainement</p>
                </div>
                <span className="count-pill">{data.low_stock_count}</span>
              </div>
              {data.low_stock.length ? (
                data.low_stock.slice(0, 5).map((p) => (
                  <div className="stock-alert" key={p.id}>
                    <span className="product-icon">
                      <Package size={20} />
                    </span>
                    <div>
                      <strong>{p.name}</strong>
                      <small>
                        {p.reference} · Seuil : {p.alert_threshold}
                      </small>
                    </div>
                    <span className="stock-quantity">
                      {p.stock}
                      <small>en stock</small>
                    </span>
                  </div>
                ))
              ) : (
                <Empty text="Tous les stocks sont au-dessus du seuil." />
              )}
              <button
                className="text-button panel-link"
                onClick={() => go("products")}
              >
                Gérer les stocks
                <ArrowRight size={16} />
              </button>
            </section>
          </div>
          <div className="insight-strip">
            <span className="insight-icon">
              <FileText size={22} />
            </span>
            <div>
              <strong>
                La prochaine vente commence par une bonne proposition.
              </strong>
              <p>
                Retrouvez vos devis et transformez-les en commandes en quelques
                clics.
              </p>
            </div>
            <button className="button secondary" onClick={() => go("quotes")}>
              Voir les devis
              <ArrowUpRight size={16} />
            </button>
          </div>
        </>
      )}
    </>
  );
}
function RevenueChart({ data }: { data: Dashboard["daily_revenue"] }) {
  if (!data.length)
    return <Empty text="Aucune facture émise sur cette période." />;
  const max = Math.max(...data.map((d) => Number(d.revenue_cents)), 100),
    width = 640,
    height = 190,
    left = 60,
    bottom = 165,
    plot = 560,
    bar = Math.min(46, (plot / data.length) * 0.55);
  return (
    <div className="chart">
      <svg
        viewBox={`0 0 ${width} ${height}`}
        role="img"
        aria-label="Chiffre d’affaires HT par jour"
      >
        <title>Chiffre d’affaires HT par jour d’émission</title>
        {[0, 0.25, 0.5, 0.75, 1].map((f) => (
          <g key={f}>
            <line
              x1={left}
              x2={635}
              y1={bottom - f * 140}
              y2={bottom - f * 140}
              stroke="#edf0ed"
              strokeDasharray="3 4"
            />
            <text
              x={left - 10}
              y={bottom - f * 140 + 4}
              textAnchor="end"
              fill="#89948c"
              fontSize="10"
            >
              {Math.round((max * f) / 100)} €
            </text>
          </g>
        ))}
        {data.map((d, i) => {
          const x = left + ((i + 0.5) * plot) / data.length;
          return (
            <g key={d.day}>
              <rect
                x={x - bar / 2}
                y={bottom - (Number(d.revenue_cents) / max) * 140}
                width={bar}
                height={Math.max(1, (Number(d.revenue_cents) / max) * 140)}
                rx="5"
                fill={i === data.length - 1 ? "#0c7568" : "#b5d6c9"}
              >
                <title>
                  {d.day} : {euro(d.revenue_cents)}
                </title>
              </rect>
              <text
                x={x}
                y={185}
                textAnchor="middle"
                fill="#89948c"
                fontSize="10"
              >
                {d.day.slice(8, 10)}/{d.day.slice(5, 7)}
              </text>
            </g>
          );
        })}
      </svg>
    </div>
  );
}

function ListView({
  view,
  refresh,
  can,
  onEdit,
  onShow,
  onStock,
  onDelete,
  busy,
}: {
  view: string;
  refresh: number;
  can: boolean;
  onEdit: (r: Row) => void;
  onShow: (r: Row) => void;
  onStock: (r: Row) => void;
  onDelete: (r: Row) => void;
  busy: boolean;
}) {
  const [search, setSearch] = useState(""),
    [status, setStatus] = useState(""),
    [page, setPage] = useState(1),
    [data, setData] = useState<Page<Row> | null>(null),
    [error, setError] = useState(""),
    [loading, setLoading] = useState(true);
  useEffect(() => {
    let active = true;
    setLoading(true);
    const t = setTimeout(() => {
      const query = new URLSearchParams({ page: String(page), search });
      if (status)
        query.set(view === "products" ? "low_stock" : "status", status);
      api<Page<Row>>(`${view}?${query}`)
        .then((d) => {
          if (active) {
            setData(d);
            setError("");
          }
        })
        .catch((e) => {
          if (active) setError(e.message);
        })
        .finally(() => {
          if (active) setLoading(false);
        });
    }, 180);
    return () => {
      active = false;
      clearTimeout(t);
    };
  }, [view, refresh, page, search, status]);
  const contact = ["customers", "suppliers"].includes(view),
    sale = ["quotes", "orders"].includes(view),
    product = view === "products";
  const columns = contact
    ? ["Nom / entreprise", "Contact", "Adresse", "Actions"]
    : product
      ? ["Produit", "Catégorie", "Prix HT", "TVA", "Stock", "Actions"]
      : sale
        ? ["Document", "Client", "Date", "Total TTC", "Statut", "Actions"]
        : view === "invoices"
          ? ["Facture", "Client", "Commande", "Total TTC", "Statut", "Actions"]
          : view === "users"
            ? ["Nom", "E-mail", "Rôle", "Actions"]
            : view === "stock-movements"
              ? [
                  "Produit",
                  "Mouvement",
                  "Stock après",
                  "Motif",
                  "Auteur",
                  "Date",
                ]
              : ["Opération", "Entité", "Auteur", "Date"];
  return (
    <section className="panel list-panel">
      <div className="list-tools">
        <div className="search-field">
          <Search size={17} />
          <input
            aria-label="Rechercher"
            placeholder="Rechercher…"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
            disabled={[
              "invoices",
              "users",
              "stock-movements",
              "audit-logs",
            ].includes(view)}
          />
        </div>
        {(sale || product || view === "invoices") && (
          <select
            aria-label="Filtrer les résultats"
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
          >
            <option value="">
              {product ? "Tous les stocks" : "Tous les statuts"}
            </option>
            {(product
              ? ["1"]
              : view === "quotes"
                ? ["draft", "converted"]
                : view === "orders"
                  ? ["draft", "validated"]
                  : ["draft", "issued", "paid", "cancelled"]
            ).map((s) => (
              <option value={s} key={s}>
                {product ? "Stock à réapprovisionner" : labels[s]}
              </option>
            ))}
          </select>
        )}
        <span className="result-count">
          {data?.total ?? 0} résultat{data?.total !== 1 ? "s" : ""}
        </span>
      </div>
      {error && (
        <div className="error" role="alert">
          {error}
        </div>
      )}
      <div
        className={`table-scroll ${loading ? "is-loading" : ""}`}
        aria-busy={loading}
      >
        <table>
          <thead>
            <tr>
              {columns.map((c) => (
                <th key={c}>{c}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {data?.data.map((r) => (
              <tr key={r.id}>
                {contact ? (
                  <>
                    <td className="strong">
                      <span className="contact-initial">{r.name[0]}</span>
                      {r.name}
                    </td>
                    <td>
                      {r.email}
                      <small>{r.phone || "—"}</small>
                    </td>
                    <td className="address-cell">{r.address || "—"}</td>
                    <td>
                      <div className="row-actions">
                        {view === "customers" && (
                          <button
                            className="text-button"
                            onClick={() => onShow(r)}
                          >
                            Historique
                          </button>
                        )}
                        {can && (
                          <>
                            <button
                              className="icon-button"
                              aria-label={`Modifier ${r.name}`}
                              onClick={() => onEdit(r)}
                            >
                              <Pencil size={16} />
                            </button>
                            <button
                              className="icon-button danger"
                              aria-label={`Supprimer ${r.name}`}
                              onClick={() => onDelete(r)}
                            >
                              <Trash2 size={16} />
                            </button>
                          </>
                        )}
                      </div>
                    </td>
                  </>
                ) : product ? (
                  <>
                    <td className="strong">
                      {r.name}
                      <small>{r.reference}</small>
                    </td>
                    <td>{r.category}</td>
                    <td className="money">{euro(r.price_cents)}</td>
                    <td>{r.vat_bps / 100} %</td>
                    <td>
                      <span
                        className={`stock-badge ${r.stock <= r.alert_threshold ? "low" : ""}`}
                      >
                        {r.stock <= r.alert_threshold && (
                          <AlertTriangle size={13} />
                        )}{" "}
                        {r.stock} unités
                      </span>
                      <small>Seuil : {r.alert_threshold}</small>
                    </td>
                    <td>
                      <div className="row-actions">
                        {can && (
                          <>
                            <button
                              className="text-button"
                              onClick={() => onStock(r)}
                            >
                              Ajuster
                            </button>
                            <button
                              className="icon-button"
                              aria-label={`Modifier ${r.name}`}
                              onClick={() => onEdit(r)}
                            >
                              <Pencil size={16} />
                            </button>
                            <button
                              className="icon-button danger"
                              aria-label={`Supprimer ${r.name}`}
                              onClick={() => onDelete(r)}
                            >
                              <Trash2 size={16} />
                            </button>
                          </>
                        )}
                      </div>
                    </td>
                  </>
                ) : sale ? (
                  <>
                    <td className="strong">{r.number}</td>
                    <td>{r.customer.name}</td>
                    <td>{date(r.created_at)}</td>
                    <td className="money">{euro(r.total_cents)}</td>
                    <td>
                      <Badge status={r.status} />
                    </td>
                    <td>
                      <button className="text-button" onClick={() => onShow(r)}>
                        Ouvrir
                        <ArrowUpRight size={15} />
                      </button>
                    </td>
                  </>
                ) : view === "invoices" ? (
                  <>
                    <td className="strong">
                      {r.number || `Brouillon #${r.id}`}
                    </td>
                    <td>{r.snapshot.customer.name}</td>
                    <td>{r.order.number}</td>
                    <td className="money">{euro(r.total_cents)}</td>
                    <td>
                      <Badge status={r.status} />
                    </td>
                    <td>
                      <button className="text-button" onClick={() => onShow(r)}>
                        Ouvrir
                        <ArrowUpRight size={15} />
                      </button>
                    </td>
                  </>
                ) : view === "users" ? (
                  <>
                    <td className="strong">{r.name}</td>
                    <td>{r.email}</td>
                    <td>
                      <Badge status={r.role.name} />
                    </td>
                    <td>
                      <div className="row-actions">
                        <button
                          className="icon-button"
                          aria-label={`Modifier ${r.name}`}
                          onClick={() => onEdit(r)}
                        >
                          <Pencil size={16} />
                        </button>
                        <button
                          className="icon-button danger"
                          aria-label={`Supprimer ${r.name}`}
                          onClick={() => onDelete(r)}
                        >
                          <Trash2 size={16} />
                        </button>
                      </div>
                    </td>
                  </>
                ) : view === "stock-movements" ? (
                  <>
                    <td className="strong">{r.product.name}</td>
                    <td>
                      <span
                        className={r.quantity > 0 ? "positive" : "negative"}
                      >
                        {r.quantity > 0 ? "+" : ""}
                        {r.quantity}
                      </span>
                    </td>
                    <td>{r.balance_after}</td>
                    <td>{r.reason}</td>
                    <td>{r.user.name}</td>
                    <td>{date(r.created_at)}</td>
                  </>
                ) : (
                  <>
                    <td className="strong">{r.action}</td>
                    <td>
                      {r.entity_type} #{r.entity_id ?? "—"}
                    </td>
                    <td>{r.user?.name || "Système"}</td>
                    <td>{date(r.created_at)}</td>
                  </>
                )}
              </tr>
            ))}
          </tbody>
        </table>
        {!data?.data.length && (
          <Empty
            text={
              loading
                ? "Chargement…"
                : "Aucun résultat. Modifiez vos filtres ou créez votre premier élément."
            }
          />
        )}
      </div>
      <div className="pagination">
        <span>
          Page {data?.current_page || 1} sur {data?.last_page || 1}
        </span>
        <div>
          <button
            className="icon-button"
            disabled={page <= 1 || loading || busy}
            aria-label="Page précédente"
            onClick={() => setPage((p) => p - 1)}
          >
            <ChevronLeft size={18} />
          </button>
          <button
            className="icon-button"
            disabled={page >= (data?.last_page || 1) || loading || busy}
            aria-label="Page suivante"
            onClick={() => setPage((p) => p + 1)}
          >
            <ChevronRight size={18} />
          </button>
        </div>
      </div>
    </section>
  );
}

function EditorForm({
  editor,
  onClose,
  onSaved,
}: {
  editor: Editor;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { kind, row } = editor;
  const [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [customers, setCustomers] = useState<Contact[]>([]),
    [products, setProducts] = useState<Product[]>([]),
    [roles, setRoles] = useState<Role[]>([]),
    [lines, setLines] = useState([{ product_id: "", quantity: 1 }]);
  const sale = ["quotes", "orders"].includes(kind),
    contact = ["customers", "suppliers"].includes(kind);
  useEffect(() => {
    if (sale)
      Promise.all([all<Contact>("customers"), all<Product>("products")])
        .then(([c, p]) => {
          setCustomers(c);
          setProducts(p);
        })
        .catch((e) => setError(e.message));
    if (kind === "users")
      api<Role[]>("roles")
        .then(setRoles)
        .catch((e) => setError(e.message));
  }, [sale, kind]);
  const totals = lines.reduce(
    (t, l) => {
      const p = products.find((p) => p.id === Number(l.product_id));
      const net = (p?.price_cents || 0) * l.quantity,
        tax = Math.floor((net * (p?.vat_bps || 0) + 5000) / 10000);
      return { net: t.net + net, tax: t.tax + tax };
    },
    { net: 0, tax: 0 },
  );
  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (busy) return;
    setBusy(true);
    setError("");
    const form = new FormData(e.currentTarget);
    const data: Record<string, unknown> = Object.fromEntries(form.entries());
    try {
      if (kind === "delete")
        await send(`${row?.category}/${row?.id}`, "DELETE");
      else if (kind === "adjust")
        await send(`products/${row?.id}/stock`, "POST", {
          quantity: Number(data.quantity),
          reason: data.reason,
        });
      else {
        if (kind === "products") {
          data.price_cents = Math.round(Number(data.price) * 100);
          data.vat_bps = Math.round(Number(data.vat) * 100);
          data.alert_threshold = Number(data.alert_threshold);
          delete data.price;
          delete data.vat;
        }
        if (kind === "users") {
          data.role_id = Number(data.role_id);
          if (!data.password) delete data.password;
        }
        if (sale) {
          data.customer_id = Number(data.customer_id);
          data.items = lines.map((l) => ({
            product_id: Number(l.product_id),
            quantity: Number(l.quantity),
          }));
        }
        await send(
          `${kind}${row ? "/" + row.id : ""}`,
          row ? "PUT" : "POST",
          data,
        );
      }
      onSaved();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <form className="editor-form" onSubmit={submit}>
      {kind === "delete" ? (
        <p>
          Supprimer <strong>{row?.name}</strong> ? Les éléments déjà utilisés
          dans des documents ou mouvements sont protégés et ne peuvent pas être
          supprimés.
        </p>
      ) : kind === "adjust" ? (
        <>
          <div className="form-callout">
            <Package size={22} />
            <div>
              <strong>{row?.name}</strong>
              <p>Stock actuel : {row?.stock} unités</p>
            </div>
          </div>
          <label>
            Quantité à ajouter ou retirer
            <input
              name="quantity"
              type="number"
              required
              min="-1000000"
              max="1000000"
              step="1"
              placeholder="Ex. 10 ou -3"
            />
          </label>
          <label>
            Motif
            <input
              name="reason"
              required
              minLength={3}
              maxLength={255}
              placeholder="Réception fournisseur, correction d’inventaire…"
            />
          </label>
        </>
      ) : contact ? (
        <>
          <label>
            Nom / entreprise
            <input
              name="name"
              required
              maxLength={255}
              defaultValue={row?.name}
            />
          </label>
          <div className="form-grid">
            <label>
              Adresse e-mail
              <input
                name="email"
                type="email"
                required
                maxLength={255}
                defaultValue={row?.email}
              />
            </label>
            <label>
              Téléphone
              <input
                name="phone"
                maxLength={40}
                defaultValue={row?.phone || ""}
              />
            </label>
          </div>
          <label>
            Adresse
            <textarea
              name="address"
              maxLength={2000}
              rows={3}
              defaultValue={row?.address || ""}
            />
          </label>
        </>
      ) : kind === "products" ? (
        <>
          <div className="form-grid">
            <label>
              Référence
              <input
                name="reference"
                required
                maxLength={50}
                defaultValue={row?.reference}
              />
            </label>
            <label>
              Catégorie
              <input
                name="category"
                required
                maxLength={100}
                defaultValue={row?.category}
              />
            </label>
          </div>
          <label>
            Nom du produit
            <input
              name="name"
              required
              maxLength={255}
              defaultValue={row?.name}
            />
          </label>
          <div className="form-grid three">
            <label>
              Prix HT (€)
              <input
                name="price"
                type="number"
                step="0.01"
                min="0"
                max="1000000"
                required
                defaultValue={
                  row?.price_cents !== undefined ? row.price_cents / 100 : ""
                }
              />
            </label>
            <label>
              TVA (%)
              <input
                name="vat"
                type="number"
                step="0.01"
                min="0"
                max="100"
                required
                defaultValue={
                  row?.vat_bps !== undefined ? row.vat_bps / 100 : 20
                }
              />
            </label>
            <label>
              Seuil d’alerte
              <input
                name="alert_threshold"
                type="number"
                step="1"
                min="0"
                max="1000000"
                required
                defaultValue={row?.alert_threshold ?? 5}
              />
            </label>
          </div>
          <p className="form-hint">
            Le stock initial est à zéro. Utilisez « Ajuster » pour enregistrer
            chaque entrée et sortie.
          </p>
        </>
      ) : kind === "users" ? (
        <>
          <label>
            Nom
            <input
              name="name"
              required
              maxLength={255}
              defaultValue={row?.name}
            />
          </label>
          <label>
            Adresse e-mail
            <input
              name="email"
              type="email"
              required
              maxLength={255}
              defaultValue={row?.email}
            />
          </label>
          <label>
            Rôle
            <select name="role_id" required defaultValue={row?.role_id || ""}>
              <option value="" disabled>
                Sélectionner un rôle
              </option>
              {roles.map((r) => (
                <option key={r.id} value={r.id}>
                  {labels[r.name]}
                </option>
              ))}
            </select>
          </label>
          <label>
            {row ? "Nouveau mot de passe (facultatif)" : "Mot de passe"}
            <input
              name="password"
              type="password"
              autoComplete="new-password"
              minLength={12}
              required={!row}
            />
            <small>12 caractères minimum, avec lettres et chiffres.</small>
          </label>
        </>
      ) : sale ? (
        <>
          <label>
            Client
            <select name="customer_id" required defaultValue="">
              <option value="" disabled>
                Sélectionner un client
              </option>
              {customers.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </label>
          <div className="line-editor">
            <h3>Articles</h3>
            {lines.map((l, i) => (
              <div className="line-form" key={i}>
                <label>
                  Produit {i + 1}
                  <select
                    aria-label={`Produit ${i + 1}`}
                    required
                    value={l.product_id}
                    onChange={(e) =>
                      setLines((ls) =>
                        ls.map((line, n) =>
                          n === i
                            ? { ...line, product_id: e.target.value }
                            : line,
                        ),
                      )
                    }
                  >
                    <option value="" disabled>
                      Choisir un produit
                    </option>
                    {products.map((p) => (
                      <option
                        value={p.id}
                        key={p.id}
                        disabled={lines.some(
                          (l, n) => n !== i && Number(l.product_id) === p.id,
                        )}
                      >
                        {p.name} · {euro(p.price_cents)} HT · stock {p.stock}
                      </option>
                    ))}
                  </select>
                </label>
                <label>
                  Quantité
                  <input
                    aria-label={`Quantité ${i + 1}`}
                    type="number"
                    min="1"
                    max="10000"
                    step="1"
                    required
                    value={l.quantity}
                    onChange={(e) =>
                      setLines((ls) =>
                        ls.map((line, n) =>
                          n === i
                            ? { ...line, quantity: Number(e.target.value) }
                            : line,
                        ),
                      )
                    }
                  />
                </label>
                <button
                  type="button"
                  className="icon-button danger"
                  aria-label={`Retirer la ligne ${i + 1}`}
                  disabled={lines.length === 1}
                  onClick={() => setLines((ls) => ls.filter((_, n) => n !== i))}
                >
                  <Trash2 size={17} />
                </button>
              </div>
            ))}
            <button
              className="text-button"
              type="button"
              disabled={lines.length >= 100}
              onClick={() =>
                setLines((ls) => [...ls, { product_id: "", quantity: 1 }])
              }
            >
              <Plus size={16} />
              Ajouter une ligne
            </button>
          </div>
          <div className="totals">
            <span>
              Total HT <b>{euro(totals.net)}</b>
            </span>
            <span>
              TVA <b>{euro(totals.tax)}</b>
            </span>
            <strong>
              Total TTC <b>{euro(totals.net + totals.tax)}</b>
            </strong>
          </div>
          <label>
            Notes
            <textarea name="notes" maxLength={2000} rows={2} />
          </label>
          <p className="form-hint">
            Prix et TVA recalculés par le serveur. Le stock est débité
            uniquement à la validation de la commande.
          </p>
        </>
      ) : null}
      {error && (
        <div className="error" role="alert">
          {error}
        </div>
      )}
      <div className="form-actions">
        <button
          type="button"
          className="button secondary"
          onClick={onClose}
          disabled={busy}
        >
          Annuler
        </button>
        <button
          className={`button ${kind === "delete" ? "destructive" : "primary"}`}
          disabled={busy}
        >
          {busy
            ? "Enregistrement…"
            : kind === "delete"
              ? "Supprimer"
              : "Enregistrer"}
        </button>
      </div>
    </form>
  );
}

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
