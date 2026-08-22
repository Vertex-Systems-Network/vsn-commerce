import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { apiGet, apiPost } from "./api";

const AuthContext = createContext(null);

export const ADMIN_AREA_ROLES = ["support", "finance", "moderator", "admin", "super_admin"];
export const FULL_ADMIN_ROLES = ["admin", "super_admin"];
export const SELLER_ROLES = ["seller"];

/** Handles default home for for the VSN Ecommerce interface. */
export function defaultHomeFor(user) {
  const permissions = new Set(user?.permissions || []);
  if (permissions.has("*") || permissions.has("admin.overview.view")) return "/admin";
  if (permissions.has("seller.overview.view")) return "/vendor";
  return "/account";
}

/** Handles auth provider for the VSN Ecommerce interface. */
export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback(/** Inline callback for this operation. */ async () => {
    try {
      const current = await apiGet("/auth/me");
      setUser(current || null);
      return current || null;
    } catch (error) {
      if (error?.status !== 401) console.error("Unable to load session", error);
      setUser(null);
      return null;
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(/** Inline callback for this operation. */ () => { refresh(); }, [refresh]);

  const acceptAuth = useCallback(/** Inline callback for this operation. */ (nextUser) => {
    setUser(nextUser || null);
    setLoading(false);
    return nextUser || null;
  }, []);

  const logout = useCallback(/** Inline callback for this operation. */ async () => {
    try { await apiPost("/auth/logout", {}); }
    finally { setUser(null); setLoading(false); }
  }, []);

  const value = useMemo(/** Inline callback for this operation. */ () => ({
    user,
    loading,
    authenticated: Boolean(user),
    refresh,
    acceptAuth,
    logout,
    hasRole: /** Inline callback for this operation. */ (...roles) => Boolean(user && roles.flat().includes(user.role)),
    hasPermission: /** Inline callback for this operation. */ (...permissions) => {
      const owned = new Set(user?.permissions || []);
      return owned.has("*") || permissions.flat().every(/** Inline callback for this operation. */ (permission) => owned.has(permission));
    },
  }), [user, loading, refresh, acceptAuth, logout]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

/** Handles use auth for the VSN Ecommerce interface. */
export function useAuth() {
  const value = useContext(AuthContext);
  if (!value) throw new Error("useAuth must be used inside AuthProvider");
  return value;
}
