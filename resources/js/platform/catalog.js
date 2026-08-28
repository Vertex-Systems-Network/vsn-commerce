import { useCallback, useEffect, useState } from "react";
import { apiDelete, apiGet, apiPost } from "./api";
export const normalizeLaravelProduct=/** Handles normalize laravel product for the VSN Ecommerce interface. */ (p)=>{ const rawVariants=p.variants||[]; const variantLabels=[...new Set(rawVariants.map(/** Inline callback for this operation. */ v=>v.options?.variant||v.name).filter(Boolean))]; return ({
  id:p.slug||p.publicId||p.id, publicId:p.publicId||p.id, slug:p.slug, name:p.name,
  image:p.images?.[0]?.url||"", images:(p.images||[]).map(/** Inline callback for this operation. */ x=>x.url), vendor:p.vendor?.name||"Marketplace seller", category:p.category?.slug||p.category?.name||"", categoryName:p.category?.name||p.category?.slug||"",
  price:Math.round(Number(p.priceMinor||0)/100), old:Math.round(Number(p.compareAtPriceMinor||p.priceMinor||0)/100), rating:Number(p.rating||0), reviews:Number(p.reviewsCount||0), sold:Number(p.soldCount||0),
  shortDescription:p.shortDescription||"", currency:p.currency||"PKR", metadata:p.metadata||{},
  installment:Boolean(p.installmentEnabled), game:Boolean(p.gameEnabled), stock:Number(p.stock||0), inStock:Boolean(p.inStock),
  variants:variantLabels, rawVariants, colors:[...new Set(rawVariants.map(/** Inline callback for this operation. */ v=>v.options?.color).filter(Boolean))],
  storage:variantLabels, raw:p
}); };
/** Handles use laravel product alerts for the VSN Ecommerce interface. */
export function useLaravelProductAlerts(){
 const [alerts,setAlerts]=useState([]),[loading,setLoading]=useState(true),[error,setError]=useState('');
 const load=useCallback(/** Inline callback for this operation. */ async()=>{try{const x=await apiGet('/product-alerts');const rows=Array.isArray(x)?x:[];setAlerts(rows);setError('');return rows}catch(e){setError(e.message);return []}finally{setLoading(false)}},[]);
 useEffect(/** Inline callback for this operation. */ ()=>{load()},[load]);
 const create=useCallback(/** Inline callback for this operation. */ async(product,type,targetPriceMinor=null,variantId=null)=>{const id=product?.raw?.slug||product?.slug||product?.raw?.publicId||product?.publicId||product?.id;const row=await apiPost(`/products/${encodeURIComponent(id)}/alerts`,{type,variantId,targetPriceMinor});await load();return row},[load]);
 const remove=useCallback(/** Inline callback for this operation. */ async(id)=>{await apiDelete(`/product-alerts/${encodeURIComponent(id)}`);await load()},[load]);
 return {alerts,loading,error,load,create,remove,isActive:/** Inline callback for this operation. */ (product,type)=>alerts.some(/** Inline callback for this operation. */ a=>(a.product?.slug===(product.slug||product.raw?.slug)||a.product?.id===(product.publicId||product.raw?.publicId))&&a.type===type&&a.status==='active')};
}
