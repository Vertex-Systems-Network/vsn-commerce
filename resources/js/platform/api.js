const BASE = (import.meta.env.VITE_VSN_API_BASE || "").replace(/\/$/, "");
const prefix = "/api/v1";

/** Handles device id for the VSN Ecommerce interface. */
function deviceId() {
  try {
    const key = "vsn-device-id";
    let value = localStorage.getItem(key);
    if (!value) { value = globalThis.crypto?.randomUUID?.() || `dev-${Date.now()}-${Math.random().toString(16).slice(2)}`; localStorage.setItem(key, value); }
    return value;
  } catch { return ""; }
}

/** Handles cookie for the VSN Ecommerce interface. */
function cookie(name) {
  const found = document.cookie
    .split("; ")
    .find(/** Inline callback for this operation. */ (part) => part.startsWith(`${name}=`));

  return found ? decodeURIComponent(found.slice(name.length + 1)) : "";
}

/** Handles api url for the VSN Ecommerce interface. */
export function apiUrl(path) {
  return `${BASE}${prefix}${path.startsWith("/") ? path : `/${path}`}`;
}

/** Handles ensure csrf for the VSN Ecommerce interface. */
async function ensureCsrf() {
  await fetch(`${BASE}/sanctum/csrf-cookie`, {
    method: "GET",
    credentials: "include",
    headers: { Accept: "application/json" },
  });
}

/** Handles api request for the VSN Ecommerce interface. */
export async function apiRequest(path, options = {}) {
  const method = (options.method || "GET").toUpperCase();
  const mutating = !["GET", "HEAD", "OPTIONS"].includes(method);

  if (mutating) await ensureCsrf();

  const currentDeviceId = deviceId();
  const headers = {
    Accept: "application/json",
    ...(options.body instanceof FormData ? {} : { "Content-Type": "application/json" }),
    ...(options.headers || {}),
    ...(currentDeviceId ? { "X-Device-Id": currentDeviceId } : {}),
  };

  if (mutating) {
    const xsrf = cookie("XSRF-TOKEN");
    if (xsrf) headers["X-XSRF-TOKEN"] = xsrf;
  }

  const response = await fetch(apiUrl(path), {
    ...options,
    method,
    credentials: "include",
    headers,
  });

  const data = await response.json().catch(/** Inline callback for this operation. */ () => ({}));

  if (!response.ok) {
    const validation = data?.errors
      ? Object.values(data.errors).flat().filter(Boolean)[0]
      : null;
    const error = new Error(validation || data?.message || data?.error?.message || "Request failed");
    error.status = response.status;
    error.payload = data;
    throw error;
  }

  return data?.data ?? data;
}

/** Handles api get for the VSN Ecommerce interface. */
export function apiGet(path, options = {}) {
  return apiRequest(path, { ...options, method: "GET" });
}

/** Handles api post for the VSN Ecommerce interface. */
export function apiPost(path, body, options = {}) {
  return apiRequest(path, {
    ...options,
    method: "POST",
    body: body instanceof FormData ? body : JSON.stringify(body ?? {}),
  });
}

/** Handles api patch for the VSN Ecommerce interface. */
export function apiPatch(path, body, options = {}) {
  return apiRequest(path, {
    ...options,
    method: "PATCH",
    body: body instanceof FormData ? body : JSON.stringify(body ?? {}),
  });
}

/** Handles api put for the VSN Ecommerce interface. */
export function apiPut(path, body, options = {}) {
  return apiRequest(path, {
    ...options,
    method: "PUT",
    body: body instanceof FormData ? body : JSON.stringify(body ?? {}),
  });
}

/** Handles api delete for the VSN Ecommerce interface. */
export function apiDelete(path, options = {}) {
  return apiRequest(path, { ...options, method: "DELETE" });
}
