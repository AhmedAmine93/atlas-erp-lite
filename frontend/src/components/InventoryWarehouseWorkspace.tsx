"use client";

import { useEffect, useState, type FormEvent } from "react";
import { api, send } from "@/lib/api";
import type { Page } from "@/lib/types";

type Warehouse = { id: number; code: string; name: string; active: boolean };
type Location = { id: number; warehouse_id: number; code: string; name: string; condition_code: "normal" | "quarantine" | "regulated"; active: boolean };
type Props = { activeCompanyId: number | null; role: string };

const conditions = { normal: "Normal", quarantine: "Quarantaine", regulated: "Réglementé" };

export default function InventoryWarehouseWorkspace({ activeCompanyId, role }: Props) {
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [locations, setLocations] = useState<Location[]>([]);
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
      api<Page<Warehouse>>("inventory/warehouses"),
      api<Page<Location>>("inventory/locations"),
    ]).then(([w, l]) => {
      if (!active) return;
      setWarehouses(w.data);
      setLocations(l.data);
      // API is paginated: counts reflect current page, never imply all are loaded.
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
    </div>
  );
}
