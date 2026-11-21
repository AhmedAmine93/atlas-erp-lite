"use client";

import { useEffect, useState, type FormEvent } from "react";
import { api, send } from "@/lib/api";

type Company = {
  id: number;
  code: string;
  name: string;
  email: string | null;
  phone: string | null;
  address: string | null;
  establishments_count: number;
};
type Establishment = {
  id: number;
  company_id: number;
  code: string;
  name: string;
  city: string | null;
  address: string | null;
  active: boolean;
  company: { id: number; name: string; code: string };
};
type CompanyForm = {
  code: string; name: string; email: string; phone: string; address: string;
};
type EstablishmentForm = {
  company_id: string; code: string; name: string; city: string; address: string; active: boolean;
};
const blankCompany: CompanyForm = { code: "", name: "", email: "", phone: "", address: "" };
const blankEstablishment: EstablishmentForm = {
  company_id: "", code: "", name: "", city: "", address: "", active: true,
};

export default function OrganizationWorkspace({ onCompanySaved }: { onCompanySaved?: () => Promise<void> }) {
  const [companies, setCompanies] = useState<Company[]>([]);
  const [establishments, setEstablishments] = useState<Establishment[]>([]);
  const [tab, setTab] = useState<"companies" | "establishments">("companies");
  const [loading, setLoading] = useState(true);
  const [working, setWorking] = useState(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");
  const [companyId, setCompanyId] = useState<number | null>(null);
  const [establishmentId, setEstablishmentId] = useState<number | null>(null);
  const [companyForm, setCompanyForm] = useState<CompanyForm>(blankCompany);
  const [establishmentForm, setEstablishmentForm] = useState<EstablishmentForm>(blankEstablishment);

  async function reload() {
    const [newCompanies, newEstablishments] = await Promise.all([
      api<Company[]>("companies"), api<Establishment[]>("establishments"),
    ]);
    setCompanies(newCompanies);
    setEstablishments(newEstablishments);
  }

  useEffect(() => {
    let active = true;
    Promise.all([api<Company[]>("companies"), api<Establishment[]>("establishments")])
      .then(([newCompanies, newEstablishments]) => {
        if (!active) return;
        setCompanies(newCompanies);
        setEstablishments(newEstablishments);
      })
      .catch((err: Error) => { if (active) setError(err.message); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, []);

  function editCompany(company: Company) {
    setTab("companies");
    setCompanyId(company.id);
    setCompanyForm({
      code: company.code, name: company.name, email: company.email || "",
      phone: company.phone || "", address: company.address || "",
    });
    setSuccess("");
  }

  function editEstablishment(establishment: Establishment) {
    setTab("establishments");
    setEstablishmentId(establishment.id);
    setEstablishmentForm({
      company_id: String(establishment.company_id),
      code: establishment.code, name: establishment.name,
      city: establishment.city || "", address: establishment.address || "",
      active: establishment.active,
    });
    setSuccess("");
  }

  async function saveCompany(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setWorking(true); setError(""); setSuccess("");
    try {
      await send(
        companyId ? `companies/${companyId}` : "companies",
        companyId ? "PUT" : "POST",
        companyForm,
      );
      await reload();
      setCompanyId(null);
      setCompanyForm(blankCompany);
      setSuccess("Entreprise enregistrée.");
      if (onCompanySaved) {
        try {
          await onCompanySaved();
        } catch {
          setError("Entreprise enregistrée, mais la liste des entreprises actives n’a pas pu être actualisée. Rechargez la page.");
        }
      }
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setWorking(false);
    }
  }

  async function saveEstablishment(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setWorking(true); setError(""); setSuccess("");
    try {
      await send(
        establishmentId ? `establishments/${establishmentId}` : "establishments",
        establishmentId ? "PUT" : "POST",
        { ...establishmentForm, company_id: Number(establishmentForm.company_id) },
      );
      await reload();
      setEstablishmentId(null);
      setEstablishmentForm(blankEstablishment);
      setSuccess("Établissement enregistré.");
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setWorking(false);
    }
  }

  return (
    <div className="org-workspace">
      <p className="org-scope">
        Référentiel administratif : les sociétés et établissements sont gérés ici.
        Les données commerciales sont isolées par entreprise active. Seules vos entreprises affiliées sont affichées.
      </p>
      <div className="org-tabs" role="group" aria-label="Référentiels">
        <button type="button" className={tab === "companies" ? "selected" : ""} onClick={() => { setTab("companies"); setError(""); }}>
          Entreprises ({companies.length})
        </button>
        <button type="button" className={tab === "establishments" ? "selected" : ""} onClick={() => { setTab("establishments"); setError(""); }}>
          Établissements ({establishments.length})
        </button>
      </div>
      {loading && <p role="status">Chargement des référentiels…</p>}
      {error && <div role="alert" className="error">{error}</div>}
      {success && <div role="status" className="notice">{success}</div>}
      {!loading && tab === "companies" && (
        <div className="org-grid">
          <section className="panel org-panel">
            <h2>Entreprises</h2>
            {companies.length === 0 ? <p>Aucune entreprise. Créez la première fiche.</p> : (
              <div className="org-list">
                {companies.map((company) => (
                  <article key={company.id} className="org-list-row">
                    <div>
                      <strong>{company.name}</strong>
                      <small>{company.code} · {company.establishments_count} établissement(s)</small>
                    </div>
                    <button type="button" onClick={() => editCompany(company)} aria-label={`Modifier l’entreprise ${company.name}`}>Modifier</button>
                  </article>
                ))}
              </div>
            )}
          </section>
          <section className="panel org-panel">
            <h2>{companyId ? "Modifier l’entreprise" : "Nouvelle entreprise"}</h2>
            <form onSubmit={saveCompany} className="org-form">
              <label>Code entreprise <input required maxLength={30} pattern="[a-zA-Z0-9_-]+" value={companyForm.code} onChange={e => setCompanyForm(v => ({ ...v, code: e.target.value }))} /></label>
              <label>Raison sociale <input required maxLength={255} value={companyForm.name} onChange={e => setCompanyForm(v => ({ ...v, name: e.target.value }))} /></label>
              <label>E-mail <input type="email" value={companyForm.email} onChange={e => setCompanyForm(v => ({ ...v, email: e.target.value }))} /></label>
              <label>Téléphone <input maxLength={40} value={companyForm.phone} onChange={e => setCompanyForm(v => ({ ...v, phone: e.target.value }))} /></label>
              <label>Adresse <textarea rows={2} maxLength={2000} value={companyForm.address} onChange={e => setCompanyForm(v => ({ ...v, address: e.target.value }))} /></label>
              <div className="org-actions">
                {companyId !== null && <button type="button" onClick={() => { setCompanyId(null); setCompanyForm(blankCompany); }}>Annuler</button>}
                <button className="button primary" disabled={working} type="submit">{working ? "Enregistrement…" : "Enregistrer l’entreprise"}</button>
              </div>
            </form>
          </section>
        </div>
      )}
      {!loading && tab === "establishments" && (
        <div className="org-grid">
          <section className="panel org-panel">
            <h2>Établissements</h2>
            {establishments.length === 0 ? <p>Aucun établissement pour le moment.</p> : (
              <div className="org-list">
                {establishments.map((establishment) => (
                  <article key={establishment.id} className="org-list-row">
                    <div>
                      <strong>{establishment.name}</strong>
                      <small>{establishment.company.name} · {establishment.code} · {establishment.active ? "Actif" : "Inactif"}</small>
                    </div>
                    <button type="button" onClick={() => editEstablishment(establishment)} aria-label={`Modifier l’établissement ${establishment.name}`}>Modifier</button>
                  </article>
                ))}
              </div>
            )}
          </section>
          <section className="panel org-panel">
            <h2>{establishmentId ? "Modifier l’établissement" : "Nouvel établissement"}</h2>
            <form onSubmit={saveEstablishment} className="org-form">
              <label>Entreprise
                <select required value={establishmentForm.company_id} onChange={e => setEstablishmentForm(v => ({ ...v, company_id: e.target.value }))}>
                  <option value="">Choisir une entreprise</option>
                  {companies.map(c => <option key={c.id} value={c.id}>{c.name} ({c.code})</option>)}
                </select>
              </label>
              <label>Code établissement <input required pattern="[a-zA-Z0-9_-]+" maxLength={30} value={establishmentForm.code} onChange={e => setEstablishmentForm(v => ({ ...v, code: e.target.value }))} /></label>
              <label>Nom <input required maxLength={255} value={establishmentForm.name} onChange={e => setEstablishmentForm(v => ({ ...v, name: e.target.value }))} /></label>
              <label>Ville <input maxLength={120} value={establishmentForm.city} onChange={e => setEstablishmentForm(v => ({ ...v, city: e.target.value }))} /></label>
              <label>Adresse <textarea rows={2} maxLength={2000} value={establishmentForm.address} onChange={e => setEstablishmentForm(v => ({ ...v, address: e.target.value }))} /></label>
              <label className="org-checkbox"><input type="checkbox" checked={establishmentForm.active} onChange={e => setEstablishmentForm(v => ({ ...v, active: e.target.checked }))} /> Établissement actif</label>
              <div className="org-actions">
                {establishmentId !== null && <button type="button" onClick={() => { setEstablishmentId(null); setEstablishmentForm(blankEstablishment); }}>Annuler</button>}
                <button className="button primary" disabled={working || companies.length === 0} type="submit">{working ? "Enregistrement…" : "Enregistrer l’établissement"}</button>
              </div>
            </form>
          </section>
        </div>
      )}
    </div>
  );
}
