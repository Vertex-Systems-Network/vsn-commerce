import {useState} from 'react';
import SEO from '../components/SEO';
import {Card,SafeImage,Status} from '../components/Toolkit';
import {Link} from 'react-router-dom';
import {useStore} from '../platform/store';
import {moneyFromMinor} from '../platform/cart';
import {apiBackend} from '../platform/api';

/** Handles cart for the VSN Ecommerce interface. */
export function Cart({cart,onRemove,onUpdate,loading,busyItemId,cartError}){
  const store=useStore();
  const [coupon,setCoupon]=useState('');
  const [applied,setApplied]=useState(null);
  const [couponMsg,setCouponMsg]=useState('');
  const items=cart?.items||[];
  const subtotal=moneyFromMinor(cart?.summary?.subtotalMinor||0);
  const discount=applied?Math.round(subtotal*(applied.percent/100)):0;
  const total=Math.max(0,subtotal-discount);
  const applyCoupon=/** Handles apply coupon for the VSN Ecommerce interface. */ ()=>{
    if(apiBackend==='laravel'){setApplied(null);setCouponMsg('Review coupons are validated, reserved and redeemed server-side at checkout. Enter this code on the checkout screen.');return;}
    const found=store.reviewCoupons.find(/** Inline callback for this operation. */ c=>c.code.toUpperCase()===coupon.trim().toUpperCase()&&!c.used);
    if(!found){setApplied(null);setCouponMsg('Coupon is invalid, already used, or unavailable for this account.');return;}
    setApplied(found);setCouponMsg('10% review reward preview applied. Checkout will revalidate it server-side.');
  };
  const update=/** Handles update for the VSN Ecommerce interface. */ (item,quantity)=>onUpdate?.(item.id,quantity)?.catch?.(/** Inline callback for this operation. */ ()=>{});
  const remove=/** Handles remove for the VSN Ecommerce interface. */ (item)=>onRemove?.(item.id)?.catch?.(/** Inline callback for this operation. */ ()=>{});

  return <>
    <SEO title="Cart | VSN Ecommerce"/>
    <div className="simple-page">
      <div className="page-title"><h1>Your cart</h1><p>{cart?.summary?.quantity||0} items</p></div>
      <Card>
        {cartError&&<Status>{cartError}</Status>}
        {cart?.summary?.hasStockIssues&&<Status>One or more items no longer have enough stock. Update the quantity before checkout.</Status>}
        {cart?.summary?.hasPriceChanges&&<Status>One or more prices changed since they were added. Current server prices are shown below.</Status>}
        {loading?<p>Loading cart…</p>:<div className="server-cart-list">
          {items.length?items.map(/** Inline callback for this operation. */ item=><div className={`server-cart-item ${item.stockIssue?'has-issue':''}`} key={item.id}>
            <Link className="server-cart-image" to={`/product/${item.product.slug||item.product.id}`}><SafeImage src={item.product.image} alt={item.product.name}/></Link>
            <div className="server-cart-copy">
              <Link to={`/product/${item.product.slug||item.product.id}`}><b>{item.product.name}</b></Link>
              <small>{item.product.vendor||'Marketplace seller'} · {item.variant.name}</small>
              {item.priceChanged&&<small className="cart-price-change">Price updated since this item was added.</small>}
              {item.stockIssue&&<small className="cart-stock-issue">Only {item.stockAvailable} currently available.</small>}
            </div>
            <div className="server-cart-qty" aria-label={`Quantity for ${item.product.name}`}>
              <button disabled={busyItemId===item.id} onClick={/** Inline callback for this operation. */ ()=>update(item,Math.max(0,item.quantity-1))}>−</button>
              <span>{item.quantity}</span>
              <button disabled={busyItemId===item.id||item.quantity>=item.stockAvailable} onClick={/** Inline callback for this operation. */ ()=>update(item,item.quantity+1)}>+</button>
            </div>
            <div className="server-cart-price"><b>Rs. {moneyFromMinor(item.lineTotalMinor).toLocaleString()}</b><small>Rs. {moneyFromMinor(item.unitPriceMinor).toLocaleString()} each</small></div>
            <button className="server-cart-remove" disabled={busyItemId===item.id} onClick={/** Inline callback for this operation. */ ()=>remove(item)}>Remove</button>
          </div>):<p>Your cart is empty.</p>}
        </div>}

        {items.length>0&&<div className="cart-coupon"><div><label htmlFor="review-coupon">Coupon code</label><div><input id="review-coupon" value={coupon} onChange={/** Inline callback for this operation. */ e=>setCoupon(e.target.value)} placeholder="VSNREV-XXXXXXXXXX"/><button onClick={applyCoupon}>Apply</button></div>{couponMsg&&<small className={applied?'ok':'error'}>{couponMsg}</small>}</div>{(apiBackend==='laravel'||store.reviewCoupons.some(/** Inline callback for this operation. */ c=>!c.used))&&<Link to="/reviews">View your review coupons</Link>}</div>}
        <div className="cart-totals"><div><span>Server subtotal</span><b>Rs. {subtotal.toLocaleString()}</b></div>{applied&&<div className="discount"><span>Review coupon preview ({applied.code})</span><b>- Rs. {discount.toLocaleString()}</b></div>}<div className="cart-total"><span>Estimated total</span><strong>Rs. {total.toLocaleString()}</strong></div></div>
        {items.length&&!cart?.summary?.hasStockIssues?<Link className="ui-btn ui-btn--primary" to="/checkout">Proceed to checkout</Link>:null}
      </Card>
    </div>
  </>;
}
/** Handles admin for the VSN Ecommerce interface. */
export function Admin(){return <><SEO title="Admin Control Center | VSN Ecommerce"/><div className="simple-page"><div className="page-title"><span>ADMIN</span><h1>System control center</h1><p>Monitor commerce, games, rewards, affiliates, gifts and verification.</p></div><div className="metric-grid">{['Orders','Games','Coin ledger','Affiliate payouts','KYC queue','Support'].map(/** Inline callback for this operation. */ (x,i)=><Card key={x}><small>{x}</small><strong>{[1284,14,18492,327,86,42][i].toLocaleString()}</strong><span>live system metric</span></Card>)}</div><Card><h2>System health</h2><div className="status-stack"><span className="ui-status is-ok">REST API online</span><span className="ui-status is-ok">Commerce sync ready</span><span className="ui-status is-ok">Coin ledger auditable</span><span className="ui-status is-warn">KYC provider integration required</span></div></Card></div></>}
/** Handles not found for the VSN Ecommerce interface. */
export function NotFound(){return <div className="simple-page"><Card><h1>Page not found</h1><a href="/">Return home</a></Card></div>}
