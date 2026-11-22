import { nav, type View } from "@/lib/navigation";
import type { Role } from "@/lib/types";

/**
 * P066.02 / M04.04: a deliberately small URL contract for existing screens.
 * Never accept arbitrary server endpoints, module names or tenant IDs from URL.
 */
export type WorkspaceRoute = { view: View; detailId?: number };
const allowedViews = new Set<string>(nav.map(({ id }) => id));
const detailViews = new Set<View>(["customers", "quotes", "orders", "invoices"]);

export function workspaceHref(view: View, detailId?: number): string {
  if (!allowedViews.has(view)) return "/workspace/dashboard";
  const base = `/workspace/${view}`;
  return detailId && detailViews.has(view) && Number.isSafeInteger(detailId) && detailId > 0
    ? `${base}/${detailId}`
    : base;
}

/** Recognize only the existing, enabled ERP modules and detail endpoints. */
export function parseWorkspacePath(pathname: string): WorkspaceRoute | null {
  if (pathname === "/" || pathname === "/workspace" || pathname === "/workspace/") {
    return { view: "dashboard" };
  }
  const segments = pathname.split("/").filter(Boolean);
  if (segments[0] !== "workspace" || segments.length < 2 || segments.length > 3) return null;
  const view = segments[1];
  if (!allowedViews.has(view)) return null;
  if (segments.length === 2) return { view };
  if (!detailViews.has(view)) return null;
  if (!/^[1-9]\d{0,9}$/.test(segments[2])) return null;
  const detailId = Number(segments[2]);
  if (!Number.isSafeInteger(detailId) || detailId <= 0) return null;
  return { view, detailId };
}

/**
 * Frontend visibility/interaction matrix mirrors Laravel's per-endpoint Gates.
 * This is never an authorization boundary; every API request is checked again.
 */
export function canOpenWorkspaceView(view: View, role: Role["name"]): boolean {
  if (!allowedViews.has(view)) return false;
  if (view === "dashboard") return true;
  if (["direction-dashboard", "sales-dashboard", "finance-dashboard"].includes(view))
    return ["admin", "commercial", "reader"].includes(role);
  if (view === "logistics-dashboard")
    return ["admin", "stock", "reader"].includes(role);
  if (view === "alerts-dashboard")
    return ["admin", "commercial", "stock", "reader"].includes(role);
  if (["purchase-requests", "maintenance-dashboard", "maintenance-sites", "maintenance-assets", "maintenance-requests", "maintenance-workorders", "maintenance-planning", "maintenance-preventive", "maintenance-spares", "maintenance-contracts", "maintenance-mobile", "hotel-properties", "hotel-rooms", "hotel-bookings", "hotel-checkin", "hotel-minibar", "hotel-availability", "hotel-housekeeping", "data-sources", "data-batches"].includes(view)) return role === "admin";
  if (["users", "audit-logs", "admin-companies"].includes(view)) return role === "admin";
  if (view === "support-incidents") return ["admin", "support_n2", "support_n3"].includes(role);
  if (role === "admin") return true;
  if (role === "commercial") return ["customers", "suppliers", "products", "quotes", "orders", "invoices"].includes(view);
  if (role === "stock") return ["products", "stock-movements"].includes(view);
  if (role === "reader") return ["customers", "suppliers", "products", "stock-movements", "quotes", "orders", "invoices"].includes(view);
  return false;
}

/** Existing UI edit actions mirror Laravel's write Gates. */
export function canEditWorkspaceView(view: View, role: Role["name"]): boolean {
  if (role === "admin") return ["customers", "suppliers", "products", "quotes", "orders", "invoices", "users"].includes(view);
  if (role === "commercial") return ["customers", "suppliers", "quotes", "orders", "invoices"].includes(view);
  if (role === "stock") return view === "products";
  return false;
}
