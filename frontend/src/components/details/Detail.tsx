import { useState } from "react";
import { ArrowRight, Check, Download, Receipt } from "lucide-react";
import Badge from "@/components/ui/Badge";
import Empty from "@/components/ui/Empty";
import { euro, date } from "@/lib/format";
import type { DetailProps } from "@/lib/erp-types";

export default function Detail({
  kind,
  row,
  can,
  busy,
  action,
}: DetailProps) {
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
    if (kind !== "invoices") return;
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
      {"notes" in row && row.notes && <p>{row.notes}</p>}
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
