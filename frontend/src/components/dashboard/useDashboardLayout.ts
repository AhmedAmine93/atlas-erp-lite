"use client";

import { useEffect, useState } from "react";
import type { User } from "@/lib/types";

export const dashboardWidgets = [
  { id: "revenue", label: "Chiffre d’affaires", group: "kpis" },
  { id: "validated-orders", label: "Commandes validées", group: "kpis" },
  { id: "outstanding", label: "À encaisser", group: "kpis" },
  { id: "low-stock", label: "Alertes de stock", group: "kpis" },
  { id: "revenue-chart", label: "Le rythme de vos ventes", group: "panels" },
  { id: "top-products", label: "Vos meilleures ventes", group: "panels" },
  { id: "recent-orders", label: "Les dernières commandes", group: "panels" },
  { id: "stock-alerts", label: "Un œil sur vos stocks", group: "panels" },
] as const;

export type DashboardWidgetId = (typeof dashboardWidgets)[number]["id"];
type WidgetState = { id: DashboardWidgetId; enabled: boolean };
export type DashboardLayout = WidgetState[];

const DEFAULT_LAYOUT: DashboardLayout = dashboardWidgets.map(({ id }) => ({ id, enabled: true }));
const STORAGE_PREFIX = "atlas-one:p06-dashboard:v1:";
const ids = new Set<string>(dashboardWidgets.map((w) => w.id));

/** Safely normalize untrusted localStorage content using a fixed allowlist. */
export function normalizeLayout(candidate: unknown): DashboardLayout {
  if (!Array.isArray(candidate) || candidate.length > 64) return DEFAULT_LAYOUT.map((x) => ({ ...x }));
  const seen = new Set<string>();
  const valid: DashboardLayout = [];
  for (const item of candidate) {
    if (!item || typeof item !== "object" || Array.isArray(item)) continue;
    const value = item as Record<string, unknown>;
    if (typeof value.id !== "string" || !ids.has(value.id) || seen.has(value.id)) continue;
    if (typeof value.enabled !== "boolean") continue;
    seen.add(value.id);
    valid.push({ id: value.id as DashboardWidgetId, enabled: value.enabled });
  }
  // A widget cannot cross the KPIs/panels display group.
  const byId = new Map(valid.map((v) => [v.id, v.enabled]));
  const ordered = (group: "kpis" | "panels") =>
    dashboardWidgets
      .filter((w) => w.group === group)
      .map((w) => w.id)
      .sort((a, b) => {
        const aIndex = valid.findIndex((v) => v.id === a);
        const bIndex = valid.findIndex((v) => v.id === b);
        return (aIndex < 0 ? 999 : aIndex) - (bIndex < 0 ? 999 : bIndex);
      })
      .map((id) => ({ id, enabled: byId.get(id) ?? true }));
  return [...ordered("kpis"), ...ordered("panels")];
}

export function useDashboardLayout(user: User) {
  // Local non-sensitive presentation preference, scoped to the signed-in user
  // AND active company AND current membership role.
  const key = `${STORAGE_PREFIX}${user.id}:${user.active_company_id ?? "none"}:${user.role.name}`;
  const [state, setState] = useState<{ key: string; layout: DashboardLayout } | null>(null);
  const [storageAvailable, setStorageAvailable] = useState(true);

  useEffect(() => {
    let layout = DEFAULT_LAYOUT.map((x) => ({ ...x }));
    try {
      const raw = window.localStorage.getItem(key);
      if (raw && raw.length <= 4096) layout = normalizeLayout(JSON.parse(raw) as unknown);
    } catch {
      setStorageAvailable(false);
    }
    setState({ key, layout });
  }, [key]);

  const layout = state?.key === key ? state.layout : DEFAULT_LAYOUT;
  const save = (next: DashboardLayout) => {
    const normalized = normalizeLayout(next);
    setState({ key, layout: normalized });
    try {
      window.localStorage.setItem(key, JSON.stringify(normalized));
      setStorageAvailable(true);
    } catch {
      // UI still works in memory; accurately report that storage is blocked.
      setStorageAvailable(false);
    }
  };
  const visible = (id: DashboardWidgetId) => layout.some((x) => x.id === id && x.enabled);
  const order = (id: DashboardWidgetId) => layout.findIndex((x) => x.id === id);
  const toggle = (id: DashboardWidgetId) =>
    save(layout.map((x) => x.id === id ? { ...x, enabled: !x.enabled } : x));
  const move = (id: DashboardWidgetId, direction: -1 | 1) => {
    const group = dashboardWidgets.find((w) => w.id === id)?.group;
    const indexes = layout.map((x, i) =>
      dashboardWidgets.find((w) => w.id === x.id)?.group === group ? i : -1
    ).filter((i) => i >= 0);
    const at = indexes.findIndex((i) => layout[i].id === id);
    if (at < 0 || at + direction < 0 || at + direction >= indexes.length) return;
    const next = layout.map((x) => ({ ...x }));
    const destination = indexes[at + direction];
    [next[indexes[at]], next[destination]] = [next[destination], next[indexes[at]]];
    save(next);
  };
  const reset = () => save(DEFAULT_LAYOUT.map((x) => ({ ...x })));

  return { layout, visible, order, toggle, move, reset, storageAvailable };
}
