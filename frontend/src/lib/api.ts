/**
 * P066.05 — all data requests belong to the last server-verified company.
 * In-memory only: no tenant ID in query parameters or browser persistence.
 * The backend validates the X-Atlas-Expected-Company header before executing
 * a business request. The epoch also drops replies issued before a switch.
 */
let csrf = "";
let verifiedCompanyId: number | null = null;
let contextEpoch = 0;

export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
  ) {
    super(message);
  }
}

export class StaleTenantContextError extends ApiError {
  constructor() {
    super("Le contexte entreprise a changé. Actualisez votre espace.", 409);
    this.name = "StaleTenantContextError";
  }
}

/** Stop all in-flight responses from being consumed by the previous workspace. */
export function resetTenantContext(): void {
  contextEpoch += 1;
  verifiedCompanyId = null;
  csrf = "";
}

export function setVerifiedCompanyContext(companyId: number): void {
  if (!Number.isSafeInteger(companyId) || companyId <= 0) {
    resetTenantContext();
    return;
  }
  if (verifiedCompanyId !== companyId) {
    contextEpoch += 1;
    verifiedCompanyId = companyId;
    csrf = "";
  }
}

export function getVerifiedCompanyContext(): number | null {
  return verifiedCompanyId;
}

const contextFreePaths = new Set(["me", "login", "logout", "tenant/switch", "csrf"]);

export async function api<T = unknown>(
  path: string,
  options: RequestInit = {},
): Promise<T> {
  const startedEpoch = contextEpoch;
  const expectedCompany = verifiedCompanyId;
  const method = (options.method || "GET").toUpperCase();
  const route = path.split("?")[0];
  const isBusinessRequest = !contextFreePaths.has(route);

  if (!["GET", "HEAD"].includes(method) && !csrf) {
    const tokenResponse = await fetch("/api/csrf", {
      credentials: "same-origin",
      cache: "no-store",
    });
    if (startedEpoch !== contextEpoch) throw new StaleTenantContextError();
    if (!tokenResponse.ok) {
      throw new ApiError("Impossible de joindre le serveur.", tokenResponse.status);
    }
    csrf = (await tokenResponse.json()).token;
    if (startedEpoch !== contextEpoch) throw new StaleTenantContextError();
  }

  const headers = new Headers(options.headers);
  headers.set("Accept", "application/json");
  headers.set("Content-Type", "application/json");
  if (csrf) headers.set("X-CSRF-TOKEN", csrf);
  if (isBusinessRequest && expectedCompany !== null) {
    headers.set("X-Atlas-Expected-Company", String(expectedCompany));
  }

  const response = await fetch(`/api/${path}`, {
    ...options,
    credentials: "same-origin",
    cache: "no-store",
    headers,
  });
  // A switch in another tab or a local route reconciliation invalidates data
  // even if the old request happened to complete successfully.
  if (startedEpoch !== contextEpoch) throw new StaleTenantContextError();

  if (!response.ok) {
    if (response.status === 409 &&
        response.headers.get("X-Atlas-Context-Stale") === "1") {
      resetTenantContext();
      window.dispatchEvent(new Event("atlas-tenant-stale"));
      throw new StaleTenantContextError();
    }
    const data = await response
      .json()
      .catch(() => ({ message: "Le serveur est indisponible." }));
    if (response.status === 419) csrf = "";
    if (response.status === 401) {
      resetTenantContext();
      window.dispatchEvent(new Event("atlas-session-expired"));
    }
    if (startedEpoch !== contextEpoch && response.status !== 401) {
      throw new StaleTenantContextError();
    }
    throw new ApiError(
      data.errors
        ? Object.values(data.errors).flat().join(" ")
        : data.message || "Une erreur est survenue.",
      response.status,
    );
  }

  if (path === "login" || path === "logout") csrf = "";
  const body: T = response.status === 204 ? (undefined as T) : await response.json();
  if (startedEpoch !== contextEpoch) throw new StaleTenantContextError();
  return body;
}

export const send = <T = unknown>(
  path: string,
  method: string,
  data?: unknown,
) =>
  api<T>(path, {
    method,
    body: data === undefined ? undefined : JSON.stringify(data),
  });

export async function all<T>(path: string): Promise<T[]> {
  let page = 1;
  const result: T[] = [];
  while (true) {
    const data = await api<{ data: T[]; last_page: number }>(
      `${path}?page=${page}`,
    );
    result.push(...data.data);
    if (page >= data.last_page) return result;
    page++;
  }
}
