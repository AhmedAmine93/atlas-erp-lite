"use client";

import { useEffect, useState } from "react";

import {
  SlidersHorizontal,
  TrendingUp,
  ShoppingBag,
  Wallet,
  Package,
  ArrowRight,
  ArrowUpRight,
  FileText,
} from "lucide-react";

import { api } from "@/lib/api";
import type { Dashboard } from "@/lib/types";
import { euro, date } from "@/lib/format";

import Badge from "@/components/ui/Badge";
import Empty from "@/components/ui/Empty";
import RevenueChart from "./RevenueChart";

export default function DashboardView({
  refresh,
  go,
}: {
  refresh: number;
  go: (view: string) => void;
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