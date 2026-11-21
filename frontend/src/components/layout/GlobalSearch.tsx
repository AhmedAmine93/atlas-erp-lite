"use client";

import { useEffect, useRef, useState } from "react";
import { ArrowUpRight, Search, X } from "lucide-react";
import { api } from "@/lib/api";
import type { User } from "@/lib/types";
import type { View } from "@/lib/navigation";

type SearchItem = { id: number; type: View; category: string; title: string };
type SearchResponse = { query: string; groups: { type: View; label: string; items: SearchItem[] }[] };
type Props = {
  user: User;
  onSelect: (type: View, id: number) => void;
};

/** P066.03 — global search, backend remains the sole tenant/RBAC authority. */
export default function GlobalSearch({ user, onSelect }: Props) {
  const [open, setOpen] = useState(false);
  const [term, setTerm] = useState("");
  const [result, setResult] = useState<SearchResponse | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const inputRef = useRef<HTMLInputElement>(null);
  const requestSeq = useRef(0);
  const tenantKey = `${user.id}:${user.active_company_id}:${user.role.name}`;

  useEffect(() => {
    const shortcut = (event: KeyboardEvent) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "k") {
        event.preventDefault();
        setOpen(true);
        window.requestAnimationFrame(() => inputRef.current?.focus());
      }
      if (event.key === "Escape") {
        setOpen(false);
        inputRef.current?.blur();
      }
    };
    window.addEventListener("keydown", shortcut);
    return () => window.removeEventListener("keydown", shortcut);
  }, []);

  useEffect(() => {
    // Clear all results immediately on identity, company or membership change.
    requestSeq.current += 1;
    setResult(null);
    setTerm("");
    setError("");
    setLoading(false);
    setOpen(false);
  }, [tenantKey]);

  useEffect(() => {
    const q = term.trim();
    const current = ++requestSeq.current;
    setError("");
    if (!open || q.length < 2) {
      setResult(null);
      setLoading(false);
      return;
    }
    const abort = new AbortController();
    const timer = window.setTimeout(() => {
      setLoading(true);
      api<SearchResponse>(`search?q=${encodeURIComponent(q)}`, { signal: abort.signal })
        .then((payload) => {
          if (requestSeq.current === current) setResult(payload);
        })
        .catch((err: Error) => {
          if (requestSeq.current === current && err.name !== "AbortError") {
            setError("Recherche indisponible : " + err.message);
            setResult(null);
          }
        })
        .finally(() => {
          if (requestSeq.current === current) setLoading(false);
        });
    }, 250);
    return () => {
      window.clearTimeout(timer);
      abort.abort();
    };
  }, [term, open, tenantKey]);

  const select = (item: SearchItem) => {
    setOpen(false);
    setTerm("");
    setResult(null);
    onSelect(item.type, item.id);
  };
  const count = result?.groups.reduce((sum, group) => sum + group.items.length, 0) ?? 0;

  return (
    <div className="atlas-global-search" role="search" aria-label="Recherche transverse">
      <button
        type="button"
        className="atlas-global-search-trigger"
        onClick={() => {
          setOpen((value) => !value);
          if (!open) window.requestAnimationFrame(() => inputRef.current?.focus());
        }}
        aria-expanded={open}
        aria-controls="atlas-global-search-panel"
      >
        <Search size={17} aria-hidden="true" />
        <span>Rechercher dans ATLAS ONE</span>
        <kbd>Ctrl K</kbd>
      </button>
      {open && (
        <section className="atlas-global-search-panel" id="atlas-global-search-panel" aria-label="Résultats de recherche">
          <div className="atlas-global-search-inputrow">
            <Search size={17} aria-hidden="true" />
            <input
              ref={inputRef}
              type="search"
              aria-label="Recherche globale"
              placeholder="Client, produit, devis, commande, facture, incident…"
              value={term}
              maxLength={100}
              autoComplete="off"
              onChange={(e) => setTerm(e.target.value)}
              onKeyDown={(e) => { if (e.key === "Escape") setOpen(false); }}
            />
            <button type="button" className="icon-button" aria-label="Fermer la recherche" onClick={() => setOpen(false)}>
              <X size={16} aria-hidden="true" />
            </button>
          </div>
          {term.trim().length < 2 ? (
            <p className="atlas-global-search-state">Saisissez au moins deux caractères.</p>
          ) : loading ? (
            <p className="atlas-global-search-state" role="status">Recherche en cours…</p>
          ) : error ? (
            <p className="atlas-global-search-state" role="alert">{error}</p>
          ) : result && count === 0 ? (
            <p className="atlas-global-search-state">Aucun résultat dans votre société active.</p>
          ) : result ? (
            <div className="atlas-global-search-groups" aria-live="polite">
              {result.groups.map((group) => (
                <div className="atlas-global-search-group" key={group.type}>
                  <h3>{group.label}</h3>
                  {group.items.map((item) => (
                    <button type="button" className="atlas-global-search-item"
                      key={`${item.type}:${item.id}`} onClick={() => select(item)}>
                      <span>{item.title}</span>
                      <small>{item.category}</small>
                      <ArrowUpRight size={15} aria-hidden="true" />
                    </button>
                  ))}
                </div>
              ))}
            </div>
          ) : null}
          <p className="atlas-global-search-footer">Résultats limités à votre rôle et à l’entreprise active.</p>
        </section>
      )}
    </div>
  );
}
