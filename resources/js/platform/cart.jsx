import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { apiDelete, apiGet, apiPatch, apiPost } from "./api";

const CartContext = createContext(null);
const TOKEN_KEY = "vsn-cart-token";

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

  const refresh = useCallback(/** Inline callback for this operation. */ async () => {
    try {
      const next = await apiGet("/cart", cartOptions());
      return accept(next);
    } catch (err) {
      setError(err.message || "Cart could not be loaded.");
      throw err;
    } finally {
      setLoading(false);
    }
  }, [accept]);

  useEffect(/** Inline callback for this operation. */ () => {
    refresh().catch(/** Inline callback for this operation. */ () => {});
  }, [refresh]);

  const addItem = useCallback(/** Inline callback for this operation. */ async (product, quantity = 1) => {
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
  }, [accept]);

  const updateItem = useCallback(/** Inline callback for this operation. */ async (itemId, quantity) => {
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
  }, [accept]);

  const removeItem = useCallback(/** Inline callback for this operation. */ async (itemId) => {
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
  }, [accept]);

  const clearCart = useCallback(/** Inline callback for this operation. */ async () => {
    setBusyItemId("clear");
    try {
      const next = await apiDelete("/cart", cartOptions());
      return accept(next);
    } finally {
      setBusyItemId(null);
    }
  }, [accept]);

  const mergeGuestCart = useCallback(/** Inline callback for this operation. */ async () => {
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
