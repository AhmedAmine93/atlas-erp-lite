let csrf = "";
export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
  ) {
    super(message);
  }
}
export async function api<T = unknown>(
  path: string,
  options: RequestInit = {},
): Promise<T> {
  const method = options.method || "GET";
  if (!["GET", "HEAD"].includes(method) && !csrf) {
    const response = await fetch("/api/csrf", {
      credentials: "same-origin",
      cache: "no-store",
    });
    if (!response.ok)
      throw new ApiError("Impossible de joindre le serveur.", response.status);
    csrf = (await response.json()).token;
  }
  const response = await fetch(`/api/${path}`, {
    ...options,
    credentials: "same-origin",
    cache: "no-store",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      "X-CSRF-TOKEN": csrf,
      ...options.headers,
    },
  });
  if (!response.ok) {
    const data = await response
      .json()
      .catch(() => ({ message: "Le serveur est indisponible." }));
    if (response.status === 419) csrf = "";
    if (response.status === 401)
      window.dispatchEvent(new Event("atlas-session-expired"));
    throw new ApiError(
      data.errors
        ? Object.values(data.errors).flat().join(" ")
        : data.message || "Une erreur est survenue.",
      response.status,
    );
  }
  if (path === "login" || path === "logout") csrf = "";
  return response.status === 204 ? (undefined as T) : response.json();
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
  let page = 1,
    result: T[] = [];
  while (true) {
    const data = await api<{ data: T[]; last_page: number }>(
      `${path}?page=${page}`,
    );
    result = result.concat(data.data);
    if (page >= data.last_page) return result;
    page++;
  }
}
