import {apiDelete,apiGet,apiPost} from './api';
export const productKey=/** Handles product key for the VSN Ecommerce interface. */ p=>p?.slug||p?.publicId||p?.id;
/** Handles get wishlist for the VSN Ecommerce interface. */
export async function getWishlist(){return apiGet('/wishlist');}
/** Handles wishlist status for the VSN Ecommerce interface. */
export async function wishlistStatus(product){return apiGet(`/wishlist/products/${encodeURIComponent(productKey(product))}`);}
/** Handles save wishlist for the VSN Ecommerce interface. */
export async function saveWishlist(product,variantId=null){return apiPost(`/wishlist/products/${encodeURIComponent(productKey(product))}`,variantId?{variantId}:{});}
/** Handles remove wishlist for the VSN Ecommerce interface. */
export async function removeWishlist(id){return apiDelete(`/wishlist/${encodeURIComponent(id)}`);}
/** Handles record product view for the VSN Ecommerce interface. */
export async function recordProductView(product,variantId=null){return apiPost(`/products/${encodeURIComponent(productKey(product))}/views`,{variantId:variantId||null,source:'product_detail'});}
/** Handles get recommendations for the VSN Ecommerce interface. */
export async function getRecommendations(limit=12){return apiGet(`/recommendations?limit=${limit}`);}
/** Handles get recently viewed for the VSN Ecommerce interface. */
export async function getRecentlyViewed(limit=12){return apiGet(`/recently-viewed?limit=${limit}`);}
/** Handles clear recently viewed for the VSN Ecommerce interface. */
export async function clearRecentlyViewed(){return apiDelete('/recently-viewed');}
/** Handles get buy again for the VSN Ecommerce interface. */
export async function getBuyAgain(limit=12){return apiGet(`/buy-again?limit=${limit}`);}
