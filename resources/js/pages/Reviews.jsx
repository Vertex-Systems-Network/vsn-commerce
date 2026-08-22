import {useState} from 'react';
import {Link} from 'react-router-dom';
import {FaGift,FaTag} from 'react-icons/fa';
import SEO from '../components/SEO';
import {Badge,Card,EmptyState,Rating,SafeImage,SectionHeader} from '../components/Toolkit';
import {apiBackend} from '../platform/api';
import {useLaravelReviews} from '../platform/reviews';
import {useStore} from '../platform/store';

/** Handles reviews for the VSN Ecommerce interface. */
export default function Reviews(){
  const store=useStore();
  const laravel=useLaravelReviews();
  const [tab,setTab]=useState('pending');
  const pending=apiBackend==='laravel'?laravel.pending:store.pendingReviews;
  const mine=apiBackend==='laravel'?laravel.reviews:store.myReviews;
  const coupons=apiBackend==='laravel'?laravel.coupons:store.reviewCoupons;

  const reviewProduct=/** Handles review product for the VSN Ecommerce interface. */ (review)=>review.product||{name:review.productName,image:review.image};
  const reviewDate=/** Handles review date for the VSN Ecommerce interface. */ (review)=>review.submittedAt||review.createdAt;
  const reviewText=/** Handles review text for the VSN Ecommerce interface. */ (review)=>review.text||'';
  const reviewCoupon=/** Handles review coupon for the VSN Ecommerce interface. */ (review)=>review.coupon?.code||review.couponCode;

  return <>
    <SEO title="My Reviews | VSN Ecommerce" description="Review your verified purchases and unlock single-use 10% shopping coupons."/>
    <main className="reviews-page page-shell">
      <div className="reviews-hero">
        <div><span className="eyebrow">VERIFIED PURCHASE REVIEWS</span><h1>Reviews & rewards</h1><p>Share useful feedback on delivered products. Each eligible order line unlocks one account-bound 10% coupon after submission.</p></div>
        <Card className="review-reward-rule"><FaGift/><div><strong>10% next-order reward</strong><span>One coupon per eligible delivered order line · single use · account restricted</span></div></Card>
      </div>

      {apiBackend==='laravel'&&laravel.error&&<Card><p className="form-error">{laravel.error}</p></Card>}

      <div className="review-tabs" role="tablist">
        <button className={tab==='pending'?'active':''} onClick={/** Inline callback for this operation. */ ()=>setTab('pending')}>Pending reviews <b>{pending.length}</b></button>
        <button className={tab==='recent'?'active':''} onClick={/** Inline callback for this operation. */ ()=>setTab('recent')}>Recent reviews <b>{Math.min(3,mine.length)}</b></button>
        <button className={tab==='all'?'active':''} onClick={/** Inline callback for this operation. */ ()=>setTab('all')}>All reviews <b>{mine.length}</b></button>
        <button className={tab==='coupons'?'active':''} onClick={/** Inline callback for this operation. */ ()=>setTab('coupons')}>Review coupons <b>{coupons.length}</b></button>
      </div>

      {tab==='pending'&&<section>
        <SectionHeader title="Products waiting for your review" sub="Only delivered, non-fully-refunded purchases are eligible."/>
        {pending.length?<div className="pending-review-grid">{pending.map(/** Inline callback for this operation. */ item=>{
          const orderItemId=item.orderItemId??item.orderItemIndex;
          const href=`/product/${item.productSlug||item.productId}?review=1${orderItemId?`&orderItem=${encodeURIComponent(orderItemId)}`:''}`;
          return <Card className="pending-review-card" key={orderItemId||item.key}>
            <SafeImage src={item.image} alt={item.productName||item.name}/>
            <div className="pending-review-info"><Badge tone="success">Delivered</Badge><h3>{item.productName||item.name}</h3><p>Order {item.orderId}</p><div className="locked-coupon"><FaTag/><span><small>REWARD WAITING</small><strong>VSNREV-•••••••••• · 10% OFF</strong><small>Code unlocks after verified review submission</small></span></div><Link className="ui-btn ui-btn--primary" to={href}>Write review</Link></div>
          </Card>;
        })}</div>:<EmptyState title="You’re all caught up" sub={laravel.loading?'Checking delivered purchases…':'No delivered products are waiting for a review.'}/>} 
      </section>}

      {tab==='recent'&&<section>
        <SectionHeader title="Recent reviews" sub="Your latest verified-purchase feedback and moderation state."/>
        {mine.length?<div className="my-review-list">{mine.slice(0,3).map(/** Inline callback for this operation. */ r=>{
          const p=reviewProduct(r); return <Card key={r.id} className="my-review-card"><div className="my-review-product"><SafeImage src={p.image} alt={p.name}/><div><h3>{p.name}</h3><Rating value={r.rating}/><small>{new Date(reviewDate(r)).toLocaleDateString()} · Verified purchase</small><div><Badge tone={r.status==='rejected'?'danger':r.status==='approved'?'success':'warning'}>{r.status||'approved'}</Badge></div></div></div><p>{reviewText(r)}</p>{r.images?.length>0&&<div className="review-photo-strip">{r.images.map(/** Inline callback for this operation. */ (img,i)=><SafeImage key={img.id||i} src={img.url||img} alt={`Review upload ${i+1}`}/>)}</div>}{reviewCoupon(r)&&<div className="review-earned"><FaGift/> Reward issued: <b>{reviewCoupon(r)}</b></div>}</Card>;
        })}</div>:<EmptyState title="No reviews yet" sub="Your latest reviews will appear here."/>}
      </section>}

      {tab==='all'&&<section>
        <SectionHeader title="Your reviews" sub="Feedback submitted from verified purchases."/>
        {mine.length?<div className="my-review-list">{mine.map(/** Inline callback for this operation. */ r=>{
          const p=reviewProduct(r); return <Card key={r.id} className="my-review-card"><div className="my-review-product"><SafeImage src={p.image} alt={p.name}/><div><h3>{p.name}</h3><Rating value={r.rating}/><small>{new Date(reviewDate(r)).toLocaleDateString()} · Verified purchase</small><div><Badge tone={r.status==='rejected'?'danger':r.status==='approved'?'success':'warning'}>{r.status||'approved'}</Badge></div></div></div><p>{reviewText(r)}</p>{r.images?.length>0&&<div className="review-photo-strip">{r.images.map(/** Inline callback for this operation. */ (img,i)=><SafeImage key={img.id||i} src={img.url||img} alt={`Review upload ${i+1}`}/>)}</div>}{reviewCoupon(r)&&<div className="review-earned"><FaGift/> Reward issued: <b>{reviewCoupon(r)}</b></div>}</Card>;
        })}</div>:<EmptyState title="No reviews yet" sub="Your submitted reviews will appear here."/>}
      </section>}

      {tab==='coupons'&&<section>
        <SectionHeader title="Review reward coupons" sub="10% merchandise discount; shipping is excluded. Each code is account-bound and single-use."/>
        {coupons.length?<div className="coupon-grid">{coupons.map(/** Inline callback for this operation. */ c=>{
          const status=c.status||(c.used?'redeemed':'available');
          const inactive=!['available','reserved'].includes(status);
          return <Card key={c.code} className={`coupon-card ${inactive?'is-used':''}`}><FaTag/><div><small>{status.toUpperCase()}</small><strong>{c.code}</strong><span>{c.percent||10}% off next order</span><p>Earned from reviewing {c.productName||'a verified purchase'}</p>{c.expiresAt&&<small>Expires {new Date(c.expiresAt).toLocaleDateString()}</small>}</div></Card>;
        })}</div>:<EmptyState title="No review coupons yet" sub="Submit an eligible product review to unlock a 10% coupon."/>}
      </section>}
    </main>
  </>;
}
