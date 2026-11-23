"use client";

import { useEffect, useState, type FormEvent } from "react";
import { all, send } from "@/lib/api";

type Warehouse = { id: number; code: string; name: string; active: boolean };
type Location = { id: number; warehouse_id: number; code: string; name: string; condition_code: "normal" | "quarantine" | "regulated"; active: boolean };
type Props = { activeCompanyId: number | null; role: string };
type ProductOption = { id: number; reference: string; name: string; stock: number };
type LotOption = { id: number; product_id: number; code: string; status: string };
type Balance = { id: number; product_id: number; location_id: number; lot_id: number | null; quantity: number };

const conditions = { normal: "Normal", quarantine: "Quarantaine", regulated: "Réglementé" };

export default function InventoryWarehouseWorkspace({ activeCompanyId, role }: Props) {
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [locations, setLocations] = useState<Location[]>([]);
  const [products, setProducts] = useState<ProductOption[]>([]);
  const [lots, setLots] = useState<LotOption[]>([]);
  const [balances, setBalances] = useState<Balance[]>([]);
  const [stockMode, setStockMode] = useState<"allocate"|"receive">("allocate");
  const [stockProduct, setStockProduct] = useState("");
  const [stockLocation, setStockLocation] = useState("");
  const [stockLot, setStockLot] = useState("");
  const [stockQuantity, setStockQuantity] = useState("1");
  const [stockReference, setStockReference] = useState("");
  const [stockReason, setStockReason] = useState("");
  const [warehouseCode, setWarehouseCode] = useState("");
  const [warehouseName, setWarehouseName] = useState("");
  const [locationWarehouse, setLocationWarehouse] = useState("");
  const [locationCode, setLocationCode] = useState("");
  const [locationName, setLocationName] = useState("");
  const [locationCondition, setLocationCondition] = useState<Location["condition_code"]>("normal");
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [revision, setRevision] = useState(0);
  const canWrite = role === "admin" || role === "stock";

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError("");
    Promise.all([
      all<Warehouse>("inventory/warehouses"),
      all<Location>("inventory/locations"),
      all<ProductOption>("products"),
      all<LotOption>("inventory/lots"),
      all<Balance>("inventory/balances"),
    ]).then(([w, l, p, t, b]) => {
      if (!active) return;
      setWarehouses(w);
      setLocations(l);
      setProducts(p);
      setLots(t);
      setBalances(b);
      // Every page is loaded under the verified company context.
    }).catch((err: Error) => { if (active) setError(err.message); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [activeCompanyId, revision]);

  async function submitWarehouse(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (busy) return;
    setBusy(true); setError(""); setNotice("");
    try {
      await send("inventory/warehouses", "POST", { code: warehouseCode, name: warehouseName });
      setWarehouseCode(""); setWarehouseName("");
      setNotice("Dépôt créé. L’affectation des stocks sera activée après rapprochement du stock historique.");
      setRevision(x => x + 1);
    } catch (err) { setError((err as Error).message); } finally { setBusy(false); }
  }

  async function submitLocation(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (busy) return;
    setBusy(true); setError(""); setNotice("");
    try {
      await send("inventory/locations", "POST", {
        warehouse_id: Number(locationWarehouse), code: locationCode, name: locationName,
        condition_code: locationCondition,
      });
      setLocationCode(""); setLocationName("");
      setNotice("Emplacement créé.");
      setRevision(x => x + 1);
    } catch (err) { setError((err as Error).message); } finally { setBusy(false); }
  }

  async function disable(kind: "warehouses" | "locations", id: number, active: boolean) {
    if (busy || !canWrite || !window.confirm(active ? "Désactiver ce référentiel ?" : "Réactiver ce référentiel ?")) return;
    setBusy(true); setError(""); setNotice("");
    try {
      await send("inventory/" + kind + "/" + id, "PATCH", { active: !active });
      setNotice("Statut mis à jour avec historique d’audit.");
      setRevision(x => x + 1);
    } catch (err) { setError((err as Error).message); } finally { setBusy(false); }
  }


  async function saveStock(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!canWrite || busy) return;
    setBusy(true); setError(""); setNotice("");
    try {
      await send("inventory/" + stockMode, "POST", {
        product_id: Number(stockProduct), location_id: Number(stockLocation),
        ...(stockLot ? { lot_id: Number(stockLot) } : {}),
        quantity: Number(stockQuantity), source_reference: stockReference.trim(),
        reason: stockReason.trim(), operation_key: crypto.randomUUID(),
      });
      setStockReference(""); setStockReason("");
      setNotice(stockMode === "allocate" ? "Stock historique affecté sans quantité supplémentaire." : "Réception inscrite au journal et au solde global.");
      setRevision(x => x + 1);
    } catch (err) { setError((err as Error).message); } finally { setBusy(false); }
  }

  return (
    <div className="inventory-registry">
      <p className="inventory-help">Référentiel physique de l’entreprise active. Les stocks historiques ne sont pas attribués automatiquement à un dépôt : aucune opération de transfert n’est simulée.</p>
      {error && <p className="error" role="alert">{error}</p>}
      {notice && <p className="notice" role="status">{notice}</p>}
      {loading && <p role="status">Chargement des dépôts et emplacements…</p>}
      <div className="inventory-grid">
        <section className="panel inventory-panel" aria-label="Dépôts">
          <h2>Dépôts</h2>
          <div className="table-scroll"><table>
            <thead><tr><th>Code</th><th>Nom</th><th>Statut</th><th>Action</th></tr></thead>
            <tbody>
              {warehouses.map(w => <tr key={w.id}><td>{w.code}</td><td>{w.name}</td>
                <td>{w.active ? "Actif" : "Inactif"}</td>
                <td><button type="button" disabled={!canWrite || busy} onClick={() => void disable("warehouses", w.id, w.active)}>{w.active ? "Désactiver" : "Réactiver"}</button></td>
              </tr>)}
            </tbody>
          </table></div>
          {!loading && warehouses.length === 0 && <p>Aucun dépôt déclaré.</p>}
          {canWrite && <form className="inventory-form" onSubmit={submitWarehouse}>
            <h3>Créer un dépôt</h3>
            <label>Code <input required maxLength={32} pattern="[a-zA-Z0-9_-]+" value={warehouseCode} onChange={e => setWarehouseCode(e.target.value)} /></label>
            <label>Nom <input required maxLength={180} value={warehouseName} onChange={e => setWarehouseName(e.target.value)} /></label>
            <button type="submit" className="button primary" disabled={busy}>{busy ? "Enregistrement…" : "Créer le dépôt"}</button>
          </form>}
        </section>
        <section className="panel inventory-panel" aria-label="Emplacements">
          <h2>Emplacements</h2>
          <div className="table-scroll"><table>
            <thead><tr><th>Dépôt</th><th>Code</th><th>Condition</th><th>Statut</th><th>Action</th></tr></thead>
            <tbody>
              {locations.map(l => <tr key={l.id}>
                <td>{warehouses.find(w => w.id === l.warehouse_id)?.code ?? "Dépôt #" + l.warehouse_id}</td>
                <td>{l.code}<small>{l.name}</small></td><td>{conditions[l.condition_code]}</td>
                <td>{l.active ? "Actif" : "Inactif"}</td>
                <td><button type="button" disabled={!canWrite || busy} onClick={() => void disable("locations", l.id, l.active)}>{l.active ? "Désactiver" : "Réactiver"}</button></td>
              </tr>)}
            </tbody>
          </table></div>
          {!loading && locations.length === 0 && <p>Aucun emplacement déclaré.</p>}
          {canWrite && <form className="inventory-form" onSubmit={submitLocation}>
            <h3>Créer un emplacement</h3>
            <label>Dépôt <select required value={locationWarehouse} onChange={e => setLocationWarehouse(e.target.value)}>
              <option value="">Choisir un dépôt actif</option>
              {warehouses.filter(w => w.active).map(w => <option key={w.id} value={w.id}>{w.code} — {w.name}</option>)}
            </select></label>
            <label>Code <input required pattern="[a-zA-Z0-9_-]+" maxLength={32} value={locationCode} onChange={e => setLocationCode(e.target.value)} /></label>
            <label>Nom <input required maxLength={180} value={locationName} onChange={e => setLocationName(e.target.value)} /></label>
            <label>Condition <select value={locationCondition} onChange={e => setLocationCondition(e.target.value as Location["condition_code"])}>
              <option value="normal">Normal</option>
              <option value="quarantine">Quarantaine</option>
              {role === "admin" && <option value="regulated">Réglementé (administrateur)</option>}
            </select></label>
            <button type="submit" className="button primary" disabled={busy}>{busy ? "Enregistrement…" : "Créer l’emplacement"}</button>
          </form>}
        </section>
      </div>

      <section className="panel inventory-panel" aria-label="Allocation et réception des stocks">
        <h2>Stock physique par emplacement</h2>
        <p>Les anciens stocks restent globaux tant qu’une affectation explicite n’a pas été effectuée. Une affectation conserve le total ; une livraison externe augmente le stock.</p>
        {canWrite && <form className="inventory-form" onSubmit={saveStock}>
          <label>Opération
            <select value={stockMode} onChange={e => setStockMode(e.target.value as "allocate"|"receive")}>
              <option value="allocate">Affecter un stock existant</option><option value="receive">Recevoir une livraison externe</option>
            </select>
          </label>
          <label>Produit
            <select required value={stockProduct} onChange={e => {setStockProduct(e.target.value);setStockLot("");}}>
              <option value="">Choisir un produit</option>
              {products.map(p => <option key={p.id} value={p.id}>{p.reference} · {p.name} (stock global {p.stock})</option>)}
            </select>
          </label>
          <label>Emplacement
            <select required value={stockLocation} onChange={e => setStockLocation(e.target.value)}>
              <option value="">Choisir un emplacement actif</option>
              {locations.filter(l => l.active && (role === "admin" || l.condition_code !== "regulated") &&
                warehouses.some(w => w.id === l.warehouse_id && w.active)).map(l =>
                <option key={l.id} value={l.id}>{l.code} · {l.name} ({conditions[l.condition_code]})</option>)}
            </select>
          </label>
          <label>Lot (facultatif)
            <select value={stockLot} onChange={e => setStockLot(e.target.value)}>
              <option value="">Sans lot</option>
              {lots.filter(l => String(l.product_id) === stockProduct).map(l =>
                <option key={l.id} value={l.id}>{l.code} ({l.status})</option>)}
            </select>
          </label>
          <label>Quantité
            <input required type="number" min="1" max="1000000" step="1" value={stockQuantity} onChange={e => setStockQuantity(e.target.value)} />
          </label>
          <label>Référence du document source
            <input required maxLength={160} value={stockReference} onChange={e => setStockReference(e.target.value)} />
          </label>
          <label>Motif
            <input required minLength={5} maxLength={500} value={stockReason} onChange={e => setStockReason(e.target.value)} />
          </label>
          <button className="button primary" disabled={busy} type="submit">{busy?"Traitement…":stockMode==="allocate"?"Affecter":"Réceptionner"}</button>
        </form>}
        <div className="table-scroll"><table>
          <thead><tr><th>Produit</th><th>Emplacement</th><th>Lot</th><th>Quantité</th></tr></thead>
          <tbody>{balances.map(b => <tr key={b.id}>
            <td>{products.find(p => p.id === b.product_id)?.reference ?? "#" + b.product_id}</td>
            <td>{locations.find(l => l.id === b.location_id)?.code ?? "#" + b.location_id}</td>
            <td>{lots.find(l => l.id === b.lot_id)?.code ?? "—"}</td>
            <td>{b.quantity}</td>
          </tr>)}</tbody>
        </table></div>
      </section>
    </div>
  );
}
