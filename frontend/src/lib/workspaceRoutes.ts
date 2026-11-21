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

/** Frontend guard is only UX; the API must still authorize every request. */
export function canOpenWorkspaceView(view: View, role: Role["name"]): boolean {
  if (["users", "audit-logs", "admin-companies"].includes(view)) return role === "admin";
  if (view === "support-incidents") return ["admin", "support_n2", "support_n3"].includes(role);
  if (role === "support_n2" || role === "support_n3") return view === "dashboard";
  return true;
}
