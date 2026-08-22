import { Navigate, useLocation } from "react-router-dom";
import { defaultHomeFor, useAuth } from "../platform/auth";

/** Handles loading screen for the VSN Ecommerce interface. */
function LoadingScreen() {
  return <main className="route-state"><div className="route-state-card"><span className="route-spinner"/><h2>Loading your account…</h2></div></main>;
}

/** Handles require auth for the VSN Ecommerce interface. */
export function RequireAuth({ children }) {
  const auth = useAuth();
  const location = useLocation();
  if (auth.loading) return <LoadingScreen/>;
  if (!auth.user) {
    const next = `${location.pathname}${location.search || ""}`;
    return <Navigate to={`/login?next=${encodeURIComponent(next)}`} replace/>;
  }
  return children;
}

/** Handles require role for the VSN Ecommerce interface. */
export function RequireRole({ roles, children }) {
  const auth = useAuth();
  const location = useLocation();
  if (auth.loading) return <LoadingScreen/>;
  if (!auth.user) {
    const next = `${location.pathname}${location.search || ""}`;
    return <Navigate to={`/login?next=${encodeURIComponent(next)}`} replace/>;
  }
  if (!roles.includes(auth.user.role)) return <Navigate to="/access-denied" state={{ from: location.pathname }} replace/>;
  return children;
}

/** Handles require permission for the VSN Ecommerce interface. */
export function RequirePermission({ permission, children }) {
  const auth = useAuth();
  const location = useLocation();
  if (auth.loading) return <LoadingScreen/>;
  if (!auth.user) {
    const next = `${location.pathname}${location.search || ""}`;
    return <Navigate to={`/login?next=${encodeURIComponent(next)}`} replace/>;
  }
  if (!auth.hasPermission(permission)) return <Navigate to="/access-denied" state={{ from: location.pathname, permission }} replace/>;
  return children;
}

/** Handles guest only for the VSN Ecommerce interface. */
export function GuestOnly({ children }) {
  const auth = useAuth();
  if (auth.loading) return <LoadingScreen/>;
  if (auth.user) return <Navigate to={defaultHomeFor(auth.user)} replace/>;
  return children;
}
