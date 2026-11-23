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
  if (["prospects","crm-contacts","opportunities","crm-activities","customer-segments"].includes(view)) return ["admin","commercial","reader"].includes(role);
  if (view.startsWith("ops-")) return role === "admin";
  if (["analytics-custom", "analytics-exports"].includes(view)) return role === "admin";
  if (["analytics-sales", "analytics-finance", "analytics-suppliers"].includes(view)) return ["admin", "commercial", "reader"].includes(role);
  if (view === "analytics-stock") return ["admin", "stock", "reader"].includes(role);
  if (["ai-assistant", "ai-search", "ai-anomalies", "ai-forecasts", "ai-suggestions"].includes(view)) return role === "admin";
  if (["direction-dashboard", "sales-dashboard", "finance-dashboard"].includes(view))
    return ["admin", "commercial", "reader"].includes(role);
  if (view === "logistics-dashboard")
    return ["admin", "stock", "reader"].includes(role);
  if (view === "alerts-dashboard")
    return ["admin", "commercial", "stock", "reader"].includes(role);
  if (view.startsWith("hr-")) return ["admin", "hr"].includes(role);
  if (["credit-notes","collections","payment-reminders","expenses","budgets","accounting-exports"].includes(view)) return ["admin","commercial","reader"].includes(role);
  if (view.startsWith("integrations-")) return role === "admin";
  if (["purchase-requests", "maintenance-mobile", "maintenance-contracts", "maintenance-spares", "maintenance-preventive", "maintenance-planning", "maintenance-dashboard", "supplier-quotes", "purchase-orders", "purchase-receipts", "supplier-invoices", "maintenance-sites", "maintenance-assets", "maintenance-requests", "maintenance-workorders", "hotel-properties", "hotel-rooms", "hotel-bookings", "hotel-checkin", "hotel-minibar", "hotel-availability", "hotel-housekeeping", "hotel-folios", "hotel-channel", "hotel-night-audit", "data-sources", "data-batches"].includes(view)) return role === "admin";
  if (["quality-controls", "nonconformities", "corrective-actions", "quality-audits", "compliance-docs"].includes(view)) return ["admin", "quality_manager", "quality_auditor", "quality_operator"].includes(role);
  if (["warehouses","stock-inventories","stock-transfers","lots-serials","replenishment"].includes(view)) return ["admin", "stock", "reader"].includes(role);
  if (["users", "audit-logs", "admin-companies", "admin-roles", "admin-settings", "admin-documents", "admin-security"].includes(view)) return role === "admin";
  if (view === "support-incidents") return ["admin", "support_n1", "support_n2", "support_n3", "support_manager", "support_cab"].includes(role);
  if (view.startsWith("support-")) return ["admin", "support_n1", "support_n2", "support_n3", "support_manager", "support_cab"].includes(role);
  if (role === "admin") return true;
  if (role === "commercial") return ["customers", "suppliers", "products", "quotes", "orders", "invoices", "deliveries", "sales-contracts", "sales-returns", "pricing"].includes(view);
  if (role === "stock") return ["products", "stock-movements"].includes(view);
  if (role === "reader") return ["customers", "suppliers", "products", "stock-movements", "quotes", "orders", "invoices", "deliveries", "sales-contracts", "sales-returns", "pricing"].includes(view);
  return false;
}

/** Existing UI edit actions mirror Laravel's write Gates. */
export function canEditWorkspaceView(view: View, role: Role["name"]): boolean {
  if (view.startsWith("hr-")) return ["admin", "hr"].includes(role);
  if (["credit-notes","collections","payment-reminders","expenses","budgets","accounting-exports"].includes(view)) return role === "admin";
  if (role === "admin") return ["customers", "suppliers", "products", "quotes", "orders", "invoices", "users", "deliveries", "sales-contracts", "sales-returns", "pricing"].includes(view);
  if (role === "commercial") return ["customers", "suppliers", "quotes", "orders", "invoices", "deliveries", "sales-contracts", "sales-returns", "pricing"].includes(view);
  if (role === "stock") return ["products", "stock-movements", "warehouses", "stock-inventories", "stock-transfers", "lots-serials", "replenishment"].includes(view);
  return false;
}
