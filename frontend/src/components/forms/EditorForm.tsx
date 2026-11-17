
"use client";

import {
  useState,
  useEffect,
  type FormEvent,
} from "react";

import { Package, Trash2, Plus } from "lucide-react";

import { api, send, all } from "@/lib/api";
import { euro, labels } from "@/lib/format";

import type {
  Contact,
  Product,
  Role,
} from "@/lib/types";

import type { Editor } from "@/lib/erp-types";


export default function EditorForm({
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
