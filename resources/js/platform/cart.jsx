import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { apiBackend, apiDelete, apiGet, apiPatch, apiPost } from "./api";

const CartContext = createContext(null);
const TOKEN_KEY = "vsn-cart-token";
const LEGACY_CART_KEY = "vsn-legacy-cart";

/** Handles slugify product for the VSN Ecommerce interface. */
function slugifyProduct(value = "") {
  return String(value).toLowerCase().trim().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
}

const EMPTY_CART = {
  id: null,
  status: "active",
  currency: "PKR",
  guestToken: null,
  items: [],
  summary: {
    distinctItems: 0,
    quantity: 0,
    subtotalMinor: 0,
    hasStockIssues: false,
    hasPriceChanges: false,
  },
};

/** Handles stored token for the VSN Ecommerce interface. */
function storedToken() {
  if (typeof window === "undefined") return "";
  return window.localStorage.getItem(TOKEN_KEY) || "";
}

/** Handles remember token for the VSN Ecommerce interface. */
function rememberToken(cart) {
  if (typeof window === "undefined") return;
  if (cart?.guestToken) window.localStorage.setItem(TOKEN_KEY, cart.guestToken);
}

/** Handles forget token for the VSN Ecommerce interface. */
function forgetToken() {
  if (typeof window !== "undefined") window.localStorage.removeItem(TOKEN_KEY);
}

/** Handles cart options for the VSN Ecommerce interface. */
function cartOptions() {
  const token = storedToken();
  return token ? { headers: { "X-Cart-Token": token } } : {};
}

/** Handles legacy load for the VSN Ecommerce interface. */
function legacyLoad() {
  if (typeof window === "undefined") return [];
  try {
    const parsed = JSON.parse(window.localStorage.getItem(LEGACY_CART_KEY) || "[]");
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

/** Handles legacy save for the VSN Ecommerce interface. */
function legacySave(items) {
  if (typeof window !== "undefined") window.localStorage.setItem(LEGACY_CART_KEY, JSON.stringify(items));
}

/** Handles legacy cart for the VSN Ecommerce interface. */
function legacyCart(items) {
  const lines = items.map(/** Inline callback for this operation. */ (line) => ({
    ...line,
    lineTotalMinor: line.unitPriceMinor * line.quantity,
  }));
  return {
    ...EMPTY_CART,
    id: "legacy-local-cart",
    items: lines,
    summary: {
      ...EMPTY_CART.summary,
      distinctItems: lines.length,
      quantity: lines.reduce(/** Inline callback for this operation. */ (sum, item) => sum + item.quantity, 0),
      subtotalMinor: lines.reduce(/** Inline callback for this operation. */ (sum, item) => sum + item.lineTotalMinor, 0),
    },
  };
}

/** Handles legacy line for the VSN Ecommerce interface. */
function legacyLine(product, quantity = 1) {
  const variantName = product.selectedVariant || product.variants?.[0] || "Default";
  return {
    id: `legacy:${product.id}:${variantName}`,
    quantity,
    currency: "PKR",
    unitPriceMinor: Number(product.price || 0) * 100,
    priceSnapshotMinor: Number(product.price || 0) * 100,
    compareAtPriceMinor: product.old ? Number(product.old) * 100 : null,
    priceChanged: false,
    stockAvailable: Number(product.stock || 99),
    stockIssue: false,
    selectedOptions: {
      ...(product.selectedColor ? { color: product.selectedColor } : {}),
      variant: variantName,
    },
    product: {
      id: product.id,
      slug: String(product.id),
      name: product.name,
      image: product.image,
      vendor: product.vendor,
    },
    variant: {
      id: null,
      sku: null,
      name: variantName,
    },
  };
}

/** Handles cart provider for the VSN Ecommerce interface. */
export function CartProvider({ children }) {
  const [cart, setCart] = useState(EMPTY_CART);
  const [loading, setLoading] = useState(true);
  const [busyItemId, setBusyItemId] = useState(null);
  const [error, setError] = useState("");

  const accept = useCallback(/** Inline callback for this operation. */ (next) => {
    const normalized = next?.items ? next : EMPTY_CART;
    rememberToken(normalized);
    setCart(normalized);
    setError("");
    return normalized;
  }, []);

  const acceptLegacy = useCallback(/** Inline callback for this operation. */ (items) => {
    legacySave(items);
    const next = legacyCart(items);
    setCart(next);
    setError("");
    return next;
  }, []);

  const refresh = useCallback(/** Inline callback for this operation. */ async () => {
    if (apiBackend !== "laravel") {
      const next = acceptLegacy(legacyLoad());
      setLoading(false);
      return next;
    }

    try {
      const next = await apiGet("/cart", cartOptions());
      return accept(next);
    } catch (err) {
      setError(err.message || "Cart could not be loaded.");
      throw err;
    } finally {
      setLoading(false);
    }
  }, [accept, acceptLegacy]);

  useEffect(/** Inline callback for this operation. */ () => {
    refresh().catch(/** Inline callback for this operation. */ () => {});
  }, [refresh]);

  const addItem = useCallback(/** Inline callback for this operation. */ async (product, quantity = 1) => {
    if (apiBackend !== "laravel") {
      const current = legacyLoad();
      const candidate = legacyLine(product, Number(quantity) || 1);
      const existing = current.find(/** Inline callback for this operation. */ (item) => item.id === candidate.id);
      if (existing) existing.quantity = Math.min(99, existing.quantity + candidate.quantity);
      else current.push(candidate);
      return acceptLegacy(current);
    }

    setBusyItemId(`product:${product.id}`);
    setError("");
    try {
      const next = await apiPost(
        "/cart/items",
        {
          variantId: product.selectedVariantId || null,
          productSlug: product.slug || slugifyProduct(product.name),
          selectedVariant: product.selectedVariant || null,
          selectedOptions: {
            ...(product.selectedColor ? { color: product.selectedColor } : {}),
            ...(product.selectedVariant ? { variant: product.selectedVariant } : {}),
          },
          quantity: Number(quantity) || 1,
        },
        cartOptions()
      );
      return accept(next);
    } catch (err) {
      setError(err.message);
      throw err;
    } finally {
      setBusyItemId(null);
    }
  }, [accept, acceptLegacy]);

  const updateItem = useCallback(/** Inline callback for this operation. */ async (itemId, quantity) => {
    if (apiBackend !== "laravel") {
      const current = legacyLoad();
      const item = current.find(/** Inline callback for this operation. */ (entry) => entry.id === itemId);
      if (!item) return acceptLegacy(current);
      if (Number(quantity) <= 0) return acceptLegacy(current.filter(/** Inline callback for this operation. */ (entry) => entry.id !== itemId));
      item.quantity = Math.min(99, Number(quantity));
      return acceptLegacy(current);
    }

    setBusyItemId(itemId);
    setError("");
    try {
      const next = await apiPatch(
        `/cart/items/${itemId}`,
        { quantity: Number(quantity) },
        cartOptions()
      );
      return accept(next);
    } catch (err) {
      setError(err.message);
      throw err;
    } finally {
      setBusyItemId(null);
    }
  }, [accept, acceptLegacy]);

  const removeItem = useCallback(/** Inline callback for this operation. */ async (itemId) => {
    if (apiBackend !== "laravel") {
      return acceptLegacy(legacyLoad().filter(/** Inline callback for this operation. */ (entry) => entry.id !== itemId));
    }

    setBusyItemId(itemId);
    setError("");
    try {
      const next = await apiDelete(`/cart/items/${itemId}`, cartOptions());
      return accept(next);
    } catch (err) {
      setError(err.message);
      throw err;
    } finally {
      setBusyItemId(null);
    }
  }, [accept, acceptLegacy]);

  const clearCart = useCallback(/** Inline callback for this operation. */ async () => {
    if (apiBackend !== "laravel") return acceptLegacy([]);

    setBusyItemId("clear");
    try {
      const next = await apiDelete("/cart", cartOptions());
      return accept(next);
    } finally {
      setBusyItemId(null);
    }
  }, [accept, acceptLegacy]);

  const mergeGuestCart = useCallback(/** Inline callback for this operation. */ async () => {
    if (apiBackend !== "laravel") return null;

    const guestToken = storedToken();
    try {
      const next = await apiPost("/cart/merge", { guestToken: guestToken || null });
      forgetToken();
      return accept(next);
    } catch (err) {
      // Authentication succeeded even if merge failed. Keep the token so a retry is possible.
      setError(err.message || "Guest cart could not be merged.");
      throw err;
    }
  }, [accept]);

  const value = useMemo(/** Inline callback for this operation. */ () => ({
    cart,
    loading,
    busyItemId,
    error,
    refresh,
    addItem,
    updateItem,
    removeItem,
    clearCart,
    mergeGuestCart,
  }), [cart, loading, busyItemId, error, refresh, addItem, updateItem, removeItem, clearCart, mergeGuestCart]);

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

/** Handles use cart for the VSN Ecommerce interface. */
export function useCart() {
  const value = useContext(CartContext);
  if (!value) throw new Error("useCart must be used inside CartProvider.");
  return value;
}

/** Handles money from minor for the VSN Ecommerce interface. */
export function moneyFromMinor(minor = 0) {
  return Number(minor || 0) / 100;
}
