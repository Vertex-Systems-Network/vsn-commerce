import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { FaBolt, FaCreditCard, FaGamepad, FaGift, FaMinus, FaPlus, FaShieldAlt, FaTruck, FaUndo, FaHeart, FaShareAlt, FaBoxOpen, FaCamera, FaStar, FaTag, FaBell, FaThumbsUp, FaFlag } from 'react-icons/fa';
import SEO from '../components/SEO';
import { SafeImage, Rating, Countdown, Button, Badge, Card, Field, Select, Status, SectionHeader } from '../components/Toolkit';
import { apiGet, apiPost } from '../platform/api';
import {recordProductView,removeWishlist,saveWishlist,wishlistStatus} from '../platform/personalization';
import { useLaravelWallet } from '../platform/wallet';
import { useLaravelGames } from '../platform/games';
import { useLaravelGifts } from '../platform/gifts';
import { useLaravelReviews } from '../platform/reviews';
import { normalizeLaravelProduct, useLaravelProductAlerts } from '../platform/catalog';

const EMPTY_PRODUCT = {
  id: null,
  publicId: null,
  slug: '',
  name: '',
  image: '',
  images: [],
  price: 0,
  old: 0,
  rating: 0,
  reviews: 0,
  sold: 0,
  shortDescription: '',
  currency: 'PKR',
  colors: [],
  variants: [],
  rawVariants: [],
  vendor: '',
  category: '',
  categoryName: '',
  stock: 0,
  inStock: false,
  game: false,
  installment: false,
  metadata: {},
};

/** Handles product for the VSN Ecommerce interface. */
export default function Product({ onAdd }) {
  const { id } = useParams();
  const navigate = useNavigate();
  const location = useLocation();
  const laravelWallet = useLaravelWallet();
  const laravelGames = useLaravelGames();
  const laravelGifts = useLaravelGifts();
  const laravelReviews = useLaravelReviews();
  const alerts = useLaravelProductAlerts();
  const visibleCoinBalance = Number(laravelWallet.wallet?.balanceCoins || 0);
  const coinsPerRupee = Number(laravelWallet.wallet?.coinsPerRupee || 0);
  const [remoteProduct,setRemoteProduct]=useState(null);
  const [remoteLoading,setRemoteLoading]=useState(true);
  const [remoteError,setRemoteError]=useState('');
  const [wishlist,setWishlist]=useState({saved:false,itemId:null,busy:false});
  const product = remoteProduct || EMPTY_PRODUCT;
  const images = useMemo(/** Uses only server-authorized product media. */ () => remoteProduct?.images || [], [remoteProduct]);
  const [activeImage, setActiveImage] = useState(0);
  const [color, setColor] = useState('');
  const [storage, setStorage] = useState('');
  const [qty, setQty] = useState(1);
  const [tab, setTab] = useState('full');
  const [recipient, setRecipient] = useState('');
  const [giftMessage, setGiftMessage] = useState('');
  const [giftWrap, setGiftWrap] = useState(true);
  const [anonymous, setAnonymous] = useState(false);
  const [schedule, setSchedule] = useState('');
  const [giftSavedMethods, setGiftSavedMethods] = useState([]);
  const [giftSavedMethodId, setGiftSavedMethodId] = useState('');
  const [notice, setNotice] = useState('');
  const priceAlertActive = remoteProduct ? alerts.isActive(product,'price_drop') : false;
  const stockAlertActive = remoteProduct ? alerts.isActive(product,'back_in_stock') : false;
  const [reviewRating, setReviewRating] = useState(0);
  const [reviewText, setReviewText] = useState('');
  const [reviewImages, setReviewImages] = useState([]);
  const [reviewResult, setReviewResult] = useState(null);
  const requestedOrderItemId = Number(new URLSearchParams(location.search).get('orderItem') || 0);
  const productRouteKey = remoteProduct?.slug || id || '';
  const eligibleReview = laravelReviews.pending.find(/** Inline callback for this operation. */ r => (r.productSlug === productRouteKey || r.productId === product.publicId || r.productId === product.id) && (!requestedOrderItemId || r.orderItemId === requestedOrderItemId));
  const reviewEligible = Boolean(eligibleReview);
  const submittedProductReviews = laravelReviews.reviews.filter(/** Inline callback for this operation. */ r => r.product?.slug === productRouteKey || r.product?.id === product.publicId || r.product?.id === product.id);
  const reviewProductKey = productRouteKey;
  const publicProductReviews = laravelReviews.productReviews[reviewProductKey] || [];
  const visiblePublicReviews = publicProductReviews.filter(/** Inline callback for this operation. */ review => !submittedProductReviews.some(/** Inline callback for this operation. */ own => own.id === review.id));
  const liveReviewCount = Number(product.reviews || 0);
  const liveReviewRating = Number(product.rating || 0);
  const selectedRemoteVariant = remoteProduct?.rawVariants?.find(/** Inline callback for this operation. */ v => { const o=v.options||{}; return (!o.color || o.color===color) && (!o.variant || o.variant===storage) && (o.color || o.variant || v.name===storage); }) || remoteProduct?.rawVariants?.find(/** Inline callback for this operation. */ v=>v.isDefault) || remoteProduct?.rawVariants?.[0] || null;
  const remoteSelectedOptions = selectedRemoteVariant?.options || {};
  const discount = Math.max(0, product.old - product.price);
  const freeGiftWrapReward = laravelGifts.rewards.some(/** Inline callback for this operation. */ r => r.code === 'free_gift_wrap' && r.status === 'available');
  const categoryLabel = product.categoryName || product.category || 'Products';
  const warrantyLabel = typeof product.metadata?.warranty === 'string' && product.metadata.warranty.trim() ? product.metadata.warranty : 'Seller terms apply';

  useEffect(/** Loads the public product exclusively from the Laravel catalog API. */ () => {
    let live=true;
    setRemoteLoading(true);
    setRemoteError('');
    setRemoteProduct(null);
    apiGet(`/products/${encodeURIComponent(id)}`)
      .then(/** Inline callback for this operation. */ p=>{
        if(!live)return;
        const next=normalizeLaravelProduct(p);
        setRemoteProduct(next);
        setColor(next.colors?.[0]||'');
        setStorage(next.storage?.[0]||next.variants?.[0]||'');
        setActiveImage(0);
      })
      .catch(/** Inline callback for this operation. */ e=>{if(live)setRemoteError(e.message||'Product could not be loaded.')})
      .finally(/** Inline callback for this operation. */ ()=>{if(live)setRemoteLoading(false)});
    return/** Inline callback for this operation. */ ()=>{live=false};
  }, [id]);

  useEffect(/** Records server product personalization without creating a local authority fallback. */ () => {
    if (!remoteProduct) return;
    recordProductView(remoteProduct, selectedRemoteVariant?.id || null).catch(/** Inline callback for this operation. */ ()=>{});
    wishlistStatus(remoteProduct).then(/** Inline callback for this operation. */ x=>setWishlist(/** Inline callback for this operation. */ w=>({...w,saved:Boolean(x.saved),itemId:x.itemId||null}))).catch(/** Inline callback for this operation. */ ()=>{});
  }, [remoteProduct?.id, selectedRemoteVariant?.id]);

  const toggleWishlist = /** Handles toggle wishlist for the VSN Ecommerce interface. */ async () => {
    if (!remoteProduct) return;
    setWishlist(/** Inline callback for this operation. */ w=>({...w,busy:true}));
    try {
      if (wishlist.saved && wishlist.itemId) {
        await removeWishlist(wishlist.itemId);
        setWishlist({saved:false,itemId:null,busy:false});
        setNotice('Removed from wishlist.');
      } else {
        const row=await saveWishlist(product,selectedRemoteVariant?.id||null);
        setWishlist({saved:true,itemId:row.id,busy:false});
        setNotice('Saved to wishlist.');
      }
    } catch(e) {
      setWishlist(/** Inline callback for this operation. */ w=>({...w,busy:false}));
      setNotice(e.message||'Wishlist could not be updated.');
    }
  };

  useEffect(/** Loads public reviews for the current server product. */ () => {
    if (!reviewProductKey) return;
    laravelReviews.loadProductReviews(reviewProductKey).catch(/** Inline callback for this operation. */ () => {});
  }, [reviewProductKey, laravelReviews.loadProductReviews]);

  useEffect(/** Loads provider-backed saved payment methods when available. */ () => {
    apiGet('/payment-methods').then(/** Inline callback for this operation. */ data => {
      const rows = data?.items || [];
      setGiftSavedMethods(rows);
      const preferred = rows.find(/** Inline callback for this operation. */ x => x.default) || rows[0];
      if (preferred) setGiftSavedMethodId(preferred.id);
    }).catch(/** Inline callback for this operation. */ () => {});
  }, []);

  const addCart = /** Handles add cart for the VSN Ecommerce interface. */ async (goCheckout = false) => {
    if (!remoteProduct || !product.inStock) {
      setNotice('This product is not currently available to add to cart.');
      return;
    }
    try {
      await onAdd?.({
        ...product,
        selectedVariantId:selectedRemoteVariant?.id||null,
        selectedColor:remoteSelectedOptions.color||null,
        selectedVariant:remoteSelectedOptions.variant||selectedRemoteVariant?.name||null,
      }, qty);
      setNotice(`${qty} item${qty > 1 ? 's' : ''} added to cart.`);
      if (goCheckout) navigate('/checkout');
    } catch (error) {
      setNotice(error.message || 'Could not add this item to cart.');
    }
  };

  const activeLaravelGame = laravelGames.games.find(/** Inline callback for this operation. */ g => g.product?.slug === reviewProductKey && g.status === 'open') || null;
  const liveGameCost = activeLaravelGame?.entryCoins ?? null;
  const liveAnnouncementAt = activeLaravelGame?.announcementAt || null;

  const joinGame = /** Handles join game for the VSN Ecommerce interface. */ async () => {
    if (!product.game) { setNotice('This product is not currently Game Win eligible.'); return; }
    if (!activeLaravelGame) { setNotice('No server-authorized Game Win campaign is currently accepting entries for this product.'); return; }
    try {
      const entry = await laravelGames.join(activeLaravelGame.id, 1);
      await laravelWallet.refresh().catch(/** Inline callback for this operation. */ () => {});
      setNotice(`Game entry confirmed · ${Number(entry.coinsSpent || liveGameCost || 0).toLocaleString()} coins used.`);
      navigate('/games');
    } catch (error) {
      setNotice(error.message || 'Could not join this Game Win campaign.');
    }
  };

  const handleReviewImages = /** Handles handle review images for the VSN Ecommerce interface. */ async (files) => {
    const list = Array.from(files || []).slice(0, 4);
    const prepared = list.map(/** Inline callback for this operation. */ file => ({ name: file.name, file, url: URL.createObjectURL(file) }));
    setReviewImages(prepared);
  };

  const submitProductReview = /** Handles submit product review for the VSN Ecommerce interface. */ async (e) => {
    e.preventDefault();
    if (!eligibleReview) { setReviewResult({ok:false,msg:'No eligible delivered purchase is waiting for review.'}); return; }
    try {
      const review = await laravelReviews.submit({ orderItemId: eligibleReview.orderItemId, rating: reviewRating, text: reviewText, images: reviewImages.map(/** Inline callback for this operation. */ x => x.file).filter(Boolean) });
      await laravelReviews.loadProductReviews(reviewProductKey).catch(/** Inline callback for this operation. */ () => {});
      setReviewResult({ok:true,msg:`Review submitted. Your 10% coupon is ${review.coupon?.code || 'now available'}.`});
      setReviewRating(0);
      setReviewText('');
      setReviewImages([]);
    } catch (error) {
      setReviewResult({ok:false,msg:error.message || 'Could not submit review.'});
    }
  };

  const sendGift = /** Handles send gift for the VSN Ecommerce interface. */ async (withCoins = false) => {
    if (!recipient.trim()) { setNotice('Enter the recipient email or verified phone number.'); return; }
    if (!remoteProduct) { setNotice('Product data is unavailable.'); return; }
    try {
      setNotice('Preparing secure gift checkout…');
      const result = await laravelGifts.createProductGift({
        recipient: recipient.trim(),
        productSlug: reviewProductKey,
        variantId: selectedRemoteVariant?.id || null,
        selectedOptions: remoteSelectedOptions,
        message: giftMessage,
        giftWrap,
        anonymous,
        scheduledFor: schedule ? new Date(schedule).toISOString() : null,
        shippingMethod: 'standard',
        paymentMethod: withCoins ? 'coins' : 'card',
        savedPaymentMethodId: withCoins ? null : (giftSavedMethodId || null),
      });
      if (withCoins) {
        const order = await laravelGifts.placeGiftOrder(result.checkout.id);
        await laravelWallet.refresh().catch(/** Inline callback for this operation. */ () => {});
        setNotice(`Gift order ${order.id} confirmed. Recipient address stayed private.`);
        navigate('/gifts');
        return;
      }
      const intent = await laravelGifts.startCardPayment(result.checkout.id);
      setNotice(`Gift reserved. Secure payment ${intent.status}. Open Gifts to complete or refresh payment.`);
      navigate('/gifts');
    } catch (error) {
      setNotice(error.message || 'Could not prepare this gift.');
    }
  };

  const helpfulReview = /** Handles helpful review for the VSN Ecommerce interface. */ async (review) => { try { await apiPost(`/reviews/${review.id}/helpful`,{}); await laravelReviews.loadProductReviews(reviewProductKey); } catch(e) { setNotice(e.message||'Sign in to mark a review helpful.'); } };
  const reportReview = /** Handles report review for the VSN Ecommerce interface. */ async (review) => { const details=globalThis.prompt?.('Why are you reporting this review?')||''; if(!details.trim())return; try { await apiPost(`/reviews/${review.id}/report`,{reason:'other',details}); setNotice('Review report submitted for moderation.'); } catch(e) { setNotice(e.message||'Review could not be reported.'); } };

  if (remoteLoading) return <><SEO title="Loading product | VSN Ecommerce"/><div className="simple-page"><Card><p>Loading product…</p></Card></div></>;
  if (remoteError || !remoteProduct) return <><SEO title="Product unavailable | VSN Ecommerce"/><div className="simple-page"><Status>{remoteError || 'Product is unavailable.'}</Status></div></>;

  return <>
    <SEO title={`${product.name} | VSN Ecommerce`} description={product.shortDescription || `Buy ${product.name} from ${product.vendor || 'a marketplace seller'} on VSN Ecommerce.`} />
    <main className="pdp-page">
      <nav className="pdp-breadcrumb" aria-label="Breadcrumb"><button onClick={/** Inline callback for this operation. */ () => navigate('/')}>Home</button><span>/</span><button onClick={/** Inline callback for this operation. */ () => navigate(`/search?q=${encodeURIComponent(product.category)}`)}>{categoryLabel}</button><span>/</span><strong>{product.name}</strong></nav>

      <section className="pdp-main">
        <div className="pdp-media">
          <div className="pdp-thumbs" aria-label="Product images">
            {images.map(/** Inline callback for this operation. */ (src, i) => <button key={`${src}-${i}`} className={activeImage === i ? 'active' : ''} onClick={/** Inline callback for this operation. */ () => setActiveImage(i)} aria-label={`View image ${i + 1}`}><SafeImage src={src} alt={`${product.name} view ${i + 1}`} /></button>)}
          </div>
          <div className="pdp-hero-image"><SafeImage src={images[activeImage]} alt={product.name} /><button className={`pdp-wishlist ${wishlist.saved?"active":""}`} disabled={wishlist.busy} aria-label={wishlist.saved?"Remove from wishlist":"Add to wishlist"} onClick={toggleWishlist}><FaHeart /></button></div>
        </div>

        <div className="pdp-info">
          <div className="pdp-badges"><Badge>Marketplace listing</Badge>{product.game && <Badge tone="game">Game Win eligible</Badge>}</div>
          <p className="pdp-brand">{product.vendor || 'Marketplace seller'}</p>
          <h1>{product.name}</h1>
          <div className="pdp-rating-row"><Rating value={product.rating} reviews={product.reviews} />{product.sold > 0 && <span>{product.sold.toLocaleString()} sold</span>}<button><FaShareAlt /> Share</button></div>
          <div className="pdp-price"><strong>Rs. {product.price.toLocaleString()}</strong>{product.old>product.price&&<del>Rs. {product.old.toLocaleString()}</del>}{discount > 0 && <span>Save Rs. {discount.toLocaleString()}</span>}</div>
          <div className="pdp-alert-actions"><button className={priceAlertActive ? 'active' : ''} onClick={/** Inline callback for this operation. */ async()=>{try{if(priceAlertActive){const a=alerts.alerts.find(/** Inline callback for this operation. */ x=>x.product?.slug===product.slug&&x.type==='price_drop'&&x.status==='active');if(a)await alerts.remove(a.id);setNotice('Price alert updated.')}else{await alerts.create(product,'price_drop');setNotice('Price-drop alert created.')}}catch(e){setNotice(e.message||'Price alert could not be updated.')}}}><FaBell /> {priceAlertActive ? 'Price alert on' : 'Notify price drop'}</button><button className={stockAlertActive ? 'active' : ''} onClick={/** Inline callback for this operation. */ async()=>{try{if(stockAlertActive){const a=alerts.alerts.find(/** Inline callback for this operation. */ x=>x.product?.slug===product.slug&&x.type==='back_in_stock'&&x.status==='active');if(a)await alerts.remove(a.id);setNotice('Stock alert updated.')}else{await alerts.create(product,'back_in_stock');setNotice('Back-in-stock alert created.')}}catch(e){setNotice(e.message||'Stock alert could not be updated.')}}}><FaBell /> {stockAlertActive ? 'Stock alert on' : 'Stock alert'}</button></div>
          <p className="pdp-summary">{product.shortDescription || 'Purchase through the marketplace checkout using the options currently authorized for this product.'}</p>

          {activeLaravelGame && <Card className="pdp-game-countdown"><div><Badge tone="game">Live Game Win</Badge><strong>Winner announcement</strong><small>Your entry remains visible in My Games until the verified result is published.</small></div><div>{liveAnnouncementAt?<Countdown target={liveAnnouncementAt} />:<small>Announcement time pending</small>}{liveGameCost!==null&&<small>{liveGameCost.toLocaleString()} coins{coinsPerRupee>0?` = Rs. ${(liveGameCost/coinsPerRupee).toFixed(0)} entry`:' entry'}</small>}</div></Card>}

          {product.colors.length>0&&<div className="pdp-option-group"><div className="pdp-option-head"><span>Color</span><strong>{color}</strong></div><div className="pdp-chips">{product.colors.map(/** Inline callback for this operation. */ v => <button className={color === v ? 'active' : ''} key={v} onClick={/** Inline callback for this operation. */ () => setColor(v)}>{v}</button>)}</div></div>}
          {product.variants.length>0&&<div className="pdp-option-group"><div className="pdp-option-head"><span>Variant / storage</span><strong>{storage}</strong></div><div className="pdp-chips">{product.variants.map(/** Inline callback for this operation. */ v => <button className={storage === v ? 'active' : ''} key={v} onClick={/** Inline callback for this operation. */ () => setStorage(v)}>{v}</button>)}</div></div>}

          <section className="pdp-purchase-panel">
            <div className="pdp-tabs" role="tablist">
              <button className={tab === 'full' ? 'active' : ''} onClick={/** Inline callback for this operation. */ () => setTab('full')}><FaCreditCard /> Full pay</button>
              {product.installment && <button className={tab === 'install' ? 'active' : ''} onClick={/** Inline callback for this operation. */ () => setTab('install')}><FaCreditCard /> Installment</button>}
              {product.game && <button className={tab === 'game' ? 'active game' : ''} onClick={/** Inline callback for this operation. */ () => setTab('game')}><FaGamepad /> Win it</button>}
              <button className={tab === 'gift' ? 'active gift' : ''} onClick={/** Inline callback for this operation. */ () => setTab('gift')}><FaGift /> Gift</button>
            </div>

            <div className="pdp-tab-content">
              {tab === 'full' && <div className="pdp-full-pay">
                <p>Delivery options and final charges are confirmed during checkout.</p>
                <div className="pdp-stock-row"><div className="qty-control"><button disabled={!product.inStock} onClick={/** Inline callback for this operation. */ () => setQty(/** Inline callback for this operation. */ q => Math.max(1, q - 1))}><FaMinus /></button><strong>{qty}</strong><button disabled={!product.inStock} onClick={/** Inline callback for this operation. */ () => setQty(/** Inline callback for this operation. */ q => Math.min(10, q + 1))}><FaPlus /></button></div><Status ok={product.inStock}>{product.inStock ? `In stock · ${product.stock.toLocaleString()} available` : 'Out of stock'}</Status></div>
                <div className="pdp-primary-actions"><Button disabled={!product.inStock} onClick={/** Inline callback for this operation. */ () => addCart(true)}><FaBolt /> Buy now</Button><Button disabled={!product.inStock} variant="secondary" onClick={/** Inline callback for this operation. */ () => addCart(false)}>Add to cart</Button></div>
              </div>}

              {tab === 'install' && product.installment && <div className="pdp-installment">
                <div className="pdp-option-head"><span>Installment checkout</span><strong>Provider-authorized</strong></div>
                <p>Installment availability, identity requirements, repayment term and final charges are confirmed by the enabled payment provider during secure checkout.</p>
                <Button disabled={!product.inStock} variant="success" onClick={/** Inline callback for this operation. */ () => addCart(true)}><FaCreditCard /> Continue to secure checkout</Button>
              </div>}

              {tab === 'game' && product.game && <div className="pdp-game-tab">
                <div className="pdp-game-icon"><FaGamepad /></div><h3>{activeLaravelGame&&liveGameCost!==null?`Win this product for ${liveGameCost.toLocaleString()} coins`:'Game Win eligibility'}</h3><p>Laravel records accepted rules, entry ledger and draw audit for every server-authorized campaign.</p>{liveAnnouncementAt?<Countdown target={liveAnnouncementAt} />:<p>No server-authorized campaign is open right now.</p>}{activeLaravelGame&&<p><small>Commitment: {activeLaravelGame.commitmentHash?.slice(0,16)||'pending'}… · {Number(activeLaravelGame.totalEntries||0).toLocaleString()} entries</small></p>}<div className="pdp-game-modes"><span>Audited Draw</span><span>Immutable Entry</span><span>Server Time</span><span>Coin Ledger</span></div>{laravelWallet.wallet?<p className="pdp-balance">Your balance: <b>{visibleCoinBalance.toLocaleString()} coins</b>{coinsPerRupee>0&&<> · Rs. {(visibleCoinBalance / coinsPerRupee).toFixed(2)}</>}</p>:<p className="pdp-balance">Sign in to view your VSN Coin balance.</p>}{!activeLaravelGame?<Button variant="secondary" disabled>Entries unavailable</Button>:<Button variant="game" onClick={joinGame}><FaGamepad /> Join game — {liveGameCost?.toLocaleString()} coins</Button>}</div>}

              {tab === 'gift' && <div className="pdp-gift-tab">
                <p>Send this product to another VSN user through the secure gift checkout.</p>
                <Field label="Recipient" value={recipient} onChange={/** Inline callback for this operation. */ e => setRecipient(e.target.value)} placeholder="Email or verified phone" />
                <label className="ui-field"><span>Gift message</span><textarea value={giftMessage} onChange={/** Inline callback for this operation. */ e => setGiftMessage(e.target.value)} placeholder="Write a personal message" /></label>
                <div className="pdp-gift-options"><label><input type="checkbox" checked={giftWrap} onChange={/** Inline callback for this operation. */ e => setGiftWrap(e.target.checked)} /><span><FaGift /> Gift wrap <small>{freeGiftWrapReward ? 'Free reward available' : 'Final charge confirmed at checkout'}</small></span></label><label><input type="checkbox" checked={anonymous} onChange={/** Inline callback for this operation. */ e => setAnonymous(e.target.checked)} /><span><FaShieldAlt /> Stay anonymous</span></label></div>
                <Field label="Schedule delivery (optional)" type="datetime-local" value={schedule} onChange={/** Inline callback for this operation. */ e => setSchedule(e.target.value)} />
                {giftSavedMethods.length > 0 && <Select label="Saved card for gift" value={giftSavedMethodId} onChange={/** Inline callback for this operation. */ e => setGiftSavedMethodId(e.target.value)}><option value="">Provider checkout (no saved token)</option>{giftSavedMethods.map(/** Inline callback for this operation. */ m => <option key={m.id} value={m.id}>{(m.brand || 'Card').toUpperCase()} •••• {m.last4}{m.default ? ' · Default' : ''}</option>)}</Select>}
                <div className="pdp-primary-actions"><Button variant="gift" onClick={/** Inline callback for this operation. */ () => sendGift(false)}><FaGift /> Continue gift checkout</Button><Button variant="coin" onClick={/** Inline callback for this operation. */ () => sendGift(true)}>Pay with VSN Coins</Button></div>
              </div>}
            </div>
          </section>

          {notice && <div className="pdp-notice" role="status">{notice}</div>}

          <div className="pdp-trust-grid"><div><FaTruck /><span><b>Tracked delivery</b><small>Courier status after dispatch</small></span></div><div><FaUndo /><span><b>Returns</b><small>Marketplace policy applies</small></span></div><div><FaShieldAlt /><span><b>Buyer protection</b><small>Secure checkout</small></span></div><div><FaBoxOpen /><span><b>Seller accountability</b><small>{product.vendor || 'Marketplace seller'}</small></span></div></div>
        </div>
      </section>

      <section className="pdp-lower">
        <div className="pdp-specs"><SectionHeader title="Product overview" sub="Key buying information in one place" /><Card><div className="pdp-spec-grid"><div><span>Seller</span><strong>{product.vendor || 'Marketplace seller'}</strong></div><div><span>Category</span><strong>{categoryLabel}</strong></div><div><span>Warranty</span><strong>{warrantyLabel}</strong></div><div><span>Returns</span><strong>Marketplace policy applies</strong></div><div><span>Delivery</span><strong>Calculated at checkout</strong></div><div><span>Payment</span><strong>Full{product.installment ? ' · Installment' : ''} · Gift{product.game ? ' · Game' : ''}</strong></div></div></Card></div>
        <div className="pdp-reviews">
          <SectionHeader title="Customer reviews" sub={`${liveReviewCount.toLocaleString()} verified ratings and reviews`} />
          <div className="pdp-review-layout">
            <Card className="pdp-review-summary"><strong>{liveReviewRating}</strong><Rating value={liveReviewRating} reviews={liveReviewCount} /><p>Verified purchase feedback</p>{reviewEligible && <div className="pdp-review-reward"><FaTag /><span><b>Review reward available</b><small>Submit your verified review to unlock the server-issued reward.</small></span></div>}</Card>
            <div className="pdp-review-list">
              {reviewEligible && <Card className="pdp-write-review">
                <div className="review-form-head"><div><Badge tone="success">Verified purchase</Badge><h3>Review this product</h3><p>Help other buyers and receive the reward configured for this eligible order item.</p></div><FaStar /></div>
                <form onSubmit={submitProductReview}>
                  <fieldset className="review-stars"><legend>Your rating</legend>{[1, 2, 3, 4, 5].map(/** Inline callback for this operation. */ n => <button type="button" key={n} className={reviewRating >= n ? 'active' : ''} onClick={/** Inline callback for this operation. */ () => setReviewRating(n)} aria-label={`${n} star rating`}><FaStar /></button>)}</fieldset>
                  <label className="ui-field"><span>Your review</span><textarea value={reviewText} onChange={/** Inline callback for this operation. */ e => setReviewText(e.target.value)} placeholder="What did you like? Mention quality, delivery, packaging or seller experience." minLength="10" required /></label>
                  <label className="review-upload"><input type="file" accept="image/*" multiple onChange={/** Inline callback for this operation. */ e => handleReviewImages(e.target.files)} /><FaCamera /><span><b>Add photos</b><small>Up to 4 images · JPG, PNG or WebP</small></span></label>
                  {reviewImages.length > 0 && <div className="review-photo-strip">{reviewImages.map(/** Inline callback for this operation. */ (img, i) => <div className="review-photo-preview" key={img.url}><SafeImage src={img.url} alt={img.name} /><button type="button" onClick={/** Inline callback for this operation. */ () => setReviewImages(/** Inline callback for this operation. */ v => v.filter(/** Inline callback for this operation. */ (_, x) => x !== i))}>×</button></div>)}</div>}
                  <div className="review-form-actions"><Button type="submit">Submit review</Button><small>Eligibility and rewards are enforced by the server.</small></div>
                </form>
                {reviewResult && <div className={`review-result ${reviewResult.ok ? 'is-success' : 'is-error'}`}>{reviewResult.msg}</div>}
              </Card>}
              {submittedProductReviews.map(/** Inline callback for this operation. */ r => <Card key={r.id}><div className="pdp-review-head"><span className="review-avatar">Y</span><div><b>You</b><Rating value={r.rating} /><small>{new Date(r.submittedAt || r.createdAt).toLocaleDateString()} · Verified purchase</small></div></div><p>{r.text}</p>{r.images?.length > 0 && <div className="review-photo-strip">{r.images.map(/** Inline callback for this operation. */ (img, i) => <SafeImage key={img.id || i} src={img.url || img} alt={`Your review upload ${i + 1}`} />)}</div>}{r.coupon?.code&&<div className="review-earned"><FaTag /> Reward: <b>{r.coupon.code}</b></div>}</Card>)}
              {visiblePublicReviews.map(/** Inline callback for this operation. */ (r, i) => { const name=r.user?.name||'Verified buyer'; return <Card key={r.id||`${name}-${i}`}><div className="pdp-review-head"><span className={`review-avatar avatar-${i + 1}`}>{name[0]}</span><div><b>{name}</b><Rating value={r.rating} /><small>{r.submittedAt?new Date(r.submittedAt).toLocaleDateString():'Verified purchase'} · Verified purchase</small></div></div><p>{r.text}</p>{r.images?.length>0&&<div className="review-photo-strip">{r.images.map(/** Inline callback for this operation. */ (img,x)=><SafeImage key={img.id||x} src={img.url||img} alt={`Review upload ${x+1}`}/>)}</div>}{r.sellerReply&&<div className="review-seller-reply"><b>{r.sellerReply.sellerName||'Seller'} replied</b><p>{r.sellerReply.text}</p></div>}<div className="review-engagement-actions"><button className={r.helpfulByMe?'active':''} onClick={/** Inline callback for this operation. */ ()=>helpfulReview(r)}><FaThumbsUp/> Helpful ({r.helpfulCount||0})</button><button onClick={/** Inline callback for this operation. */ ()=>reportReview(r)}><FaFlag/> Report</button></div></Card>; })}
            </div>
          </div>
        </div>
      </section>
    </main>
  </>;
}
