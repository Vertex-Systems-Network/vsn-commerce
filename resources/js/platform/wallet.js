import { useCallback, useEffect, useState } from "react";
import { apiBackend, apiGet, apiPost } from "./api";

/** Handles use laravel wallet for the VSN Ecommerce interface. */
export function useLaravelWallet() {
  const [wallet, setWallet] = useState(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(apiBackend === "laravel");

  const refresh = useCallback(/** Inline callback for this operation. */ async () => {
    if (apiBackend !== "laravel") return null;
    const next = await apiGet("/wallet");
    setWallet(next); setError("");
    return next;
  }, []);

  useEffect(/** Inline callback for this operation. */ () => {
    if (apiBackend !== "laravel") { setLoading(false); return; }
    let live = true;
    apiGet("/wallet").then(/** Inline callback for this operation. */ (next) => live && setWallet(next)).catch(/** Inline callback for this operation. */ (err) => live && setError(err.message || "Wallet unavailable.")).finally(/** Inline callback for this operation. */ () => live && setLoading(false));
    return /** Inline callback for this operation. */ () => { live = false; };
  }, []);

  const checkIn = useCallback(/** Inline callback for this operation. */ async () => {
    const result = await apiPost("/wallet/check-in", {});
    await refresh();
    return result;
  }, [refresh]);

  return { wallet, loading, error, refresh, checkIn };
}
