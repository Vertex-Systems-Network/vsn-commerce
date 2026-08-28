import { useCallback, useEffect, useState } from "react";
import { apiGet, apiPost } from "./api";

/** Handles use laravel reviews for the VSN Ecommerce interface. */
export function useLaravelReviews() {
  const [dashboard, setDashboard] = useState({ pending: [], reviews: [], coupons: [] });
  const [productReviews, setProductReviews] = useState({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const refresh = useCallback(/** Inline callback for this operation. */ async () => {
    const next = await apiGet("/reviews");
    setDashboard(next || { pending: [], reviews: [], coupons: [] });
    setError("");
    return next;
  }, []);

  useEffect(/** Inline callback for this operation. */ () => {
    let live = true;
    apiGet("/reviews")
      .then(/** Inline callback for this operation. */ (next) => live && setDashboard(next || { pending: [], reviews: [], coupons: [] }))
      .catch(/** Inline callback for this operation. */ (err) => { if (!live) return; const message=err.message||"Reviews unavailable."; if (/unauthenticated|sign in/i.test(message)) { setDashboard({ pending: [], reviews: [], coupons: [] }); setError(""); } else setError(message); })
      .finally(/** Inline callback for this operation. */ () => live && setLoading(false));
    return /** Inline callback for this operation. */ () => { live = false; };
  }, []);

  const submit = useCallback(/** Inline callback for this operation. */ async ({ orderItemId, rating, text, images = [] }) => {
    const form = new FormData();
    form.append("orderItemId", String(orderItemId));
    form.append("rating", String(rating));
    form.append("text", text);
    images.slice(0, 4).forEach(/** Inline callback for this operation. */ (image) => form.append("images[]", image));
    const result = await apiPost("/reviews", form);
    await refresh();
    return result;
  }, [refresh]);

  const loadProductReviews = useCallback(/** Inline callback for this operation. */ async (productId) => {
    if (!productId) return [];
    const response = await apiGet(`/products/${productId}/reviews?perPage=20`);
    const items = response?.items || [];
    setProductReviews(/** Inline callback for this operation. */ (current) => ({ ...current, [productId]: items }));
    return items;
  }, []);

  return {
    dashboard,
    pending: dashboard?.pending || [],
    reviews: dashboard?.reviews || [],
    coupons: dashboard?.coupons || [],
    productReviews,
    loading,
    error,
    refresh,
    submit,
    loadProductReviews,
  };
}
