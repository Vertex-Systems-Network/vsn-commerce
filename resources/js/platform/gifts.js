import { useCallback, useEffect, useState } from "react";
import { apiGet, apiPost } from "./api";

const key = /** Handles key for the VSN Ecommerce interface. */ (prefix) => `${prefix}-${globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`}`;

/** Handles use laravel gifts for the VSN Ecommerce interface. */
export function useLaravelGifts() {
  const [dashboard, setDashboard] = useState({ profile: null, rewards: [], sent: [], received: [] });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const refresh = useCallback(/** Inline callback for this operation. */ async () => {
    const next = await apiGet("/gifts");
    setDashboard(next || { profile: null, rewards: [], sent: [], received: [] });
    setError("");
    return next;
  }, []);

  useEffect(/** Inline callback for this operation. */ () => {
    let live = true;
    apiGet("/gifts")
      .then(/** Inline callback for this operation. */ (next) => live && setDashboard(next || { profile: null, rewards: [], sent: [], received: [] }))
      .catch(/** Inline callback for this operation. */ (err) => live && setError(err.message || "Gifts unavailable."))
      .finally(/** Inline callback for this operation. */ () => live && setLoading(false));
    return /** Inline callback for this operation. */ () => { live = false; };
  }, []);

  const createProductGift = useCallback(/** Inline callback for this operation. */ async (payload) => {
    const result = await apiPost("/gifts/checkouts", { ...payload, idempotencyKey: payload.idempotencyKey || key("product-gift") });
    return result;
  }, []);

  const placeGiftOrder = useCallback(/** Inline callback for this operation. */ async (checkoutId) => {
    const order = await apiPost(`/checkout/sessions/${checkoutId}/order`, {});
    await refresh();
    return order;
  }, [refresh]);

  const startCardPayment = useCallback(/** Inline callback for this operation. */ async (checkoutId) => {
    const intent = await apiPost(`/checkout/sessions/${checkoutId}/payments`, { idempotencyKey: key("gift-payment") });
    await refresh();
    return intent;
  }, [refresh]);

  const completeSandboxPayment = useCallback(/** Inline callback for this operation. */ async (paymentIntentId) => {
    const intent = await apiPost(`/payments/${paymentIntentId}/sandbox/complete`, {});
    await refresh();
    return intent;
  }, [refresh]);

  const cancelGift = useCallback(/** Inline callback for this operation. */ async (giftId) => {
    const result = await apiPost(`/gifts/${giftId}/cancel`, {});
    await refresh();
    return result;
  }, [refresh]);

  const level = dashboard?.profile?.level || { name: "Starter Gifter", progress: 0, nextReward: "Free gift wrap" };
  const lifetimeGiftCoins = Number(dashboard?.profile?.lifetimeGiftCoins || 0);

  return {
    dashboard, sent: dashboard?.sent || [], received: dashboard?.received || [], rewards: dashboard?.rewards || [],
    level, lifetimeGiftCoins, loading, error, refresh, createProductGift, placeGiftOrder, startCardPayment, completeSandboxPayment, cancelGift,
  };
}
