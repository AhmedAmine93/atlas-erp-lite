
"use client";

import { useEffect, useState } from "react";

import {
  Search,
  Pencil,
  Trash2,
  AlertTriangle,
  ChevronLeft,
  ChevronRight,
  ArrowUpRight,
} from "lucide-react";

import { api } from "@/lib/api";
import { euro, date, labels } from "@/lib/format";

import type { Page } from "@/lib/types";
import type { Row } from "@/lib/erp-types";

import Badge from "@/components/ui/Badge";
import Empty from "@/components/ui/Empty";


export default function ListView({
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