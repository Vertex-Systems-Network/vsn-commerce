import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { FaBolt, FaCheckCircle, FaCreditCard, FaGamepad, FaGift, FaMinus, FaPlus, FaShieldAlt, FaTruck, FaUndo, FaHeart, FaShareAlt, FaClock, FaBoxOpen, FaCamera, FaStar, FaTag, FaBell, FaThumbsUp, FaFlag } from 'react-icons/fa';
import SEO from '../components/SEO';
import { products } from '../data/catalog';
import { SafeImage, Rating, Countdown, Button, Badge, Card, Field, Select, Status, SectionHeader } from '../components/Toolkit';
import { COINS_PER_RUPEE, useStore } from '../platform/store';
import { apiBackend, apiGet, apiPost } from '../platform/api';
import {recordProductView,removeWishlist,saveWishlist,wishlistStatus} from '../platform/personalization';
import { useLaravelWallet } from '../platform/wallet';
import { useLaravelGames } from '../platform/games';
import { useLaravelGifts } from '../platform/gifts';
import { useLaravelReviews } from '../platform/reviews';
import { normalizeLaravelProduct, useLaravelProductAlerts } from '../platform/catalog';

const demoImages = {
  1: ['https://images.unsplash.com/photo-1695048132590-b687e2a7e2aa?w=1000&h=1000&fit=crop&auto=format', 'https://images.unsplash.com/photo-1592750475338-74b7b21085ab?w=1000&h=1000&fit=crop&auto=format', 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?w=1000&h=1000&fit=crop&auto=format'],
  2: ['https://images.unsplash.com/photo-1517336714731-489689fd1ca8?w=1000&h=1000&fit=crop&auto=format', 'https://images.unsplash.com/photo-1541807084-5c52b6b3adef?w=1000&h=1000&fit=crop&auto=format'],
};

const reviews = [
  { name: 'Ahmed K.', rating: 5, date: '12 Jul 2026', text: 'Product arrived sealed and exactly as described. Delivery tracking was accurate and seller communication was excellent.' },
  { name: 'Fatima R.', rating: 5, date: '8 Jul 2026', text: 'Installment application was straightforward in the demo flow. Product quality and packaging were both excellent.' },
  { name: 'Usman A.', rating: 4, date: '5 Jul 2026', text: 'Very good overall experience. I would like more delivery-slot choices, but the product itself is excellent.' },
];

/** Handles product for the VSN Ecommerce interface. */
export default function Product({ onAdd }) {
  const { id } = useParams();
  const navigate = useNavigate();
  const location = useLocation();
  const store = useStore();
  const laravelWallet = useLaravelWallet();
  const laravelGames = useLaravelGames();
  const laravelGifts = useLaravelGifts();
  const laravelReviews = useLaravelReviews();
  const visibleCoinBalance = apiBackend === 'laravel' ? (laravelWallet.wallet?.balanceCoins || 0) : store.coinBalance;
  const slugify = /** Handles slugify for the VSN Ecommerce interface. */ value => String(value || '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  const staticProduct = products.find(/** Inline callback for this operation. */ x => x.id === Number(id) || slugify(x.name) === id) || null;
  const fallbackProduct = staticProduct || products[0];
  const [remoteProduct,setRemoteProduct]=useState(null);
  const [remoteLoading,setRemoteLoading]=useState(apiBackend==='laravel');
  const [remoteError,setRemoteError]=useState('');
  const [wishlist,setWishlist]=useState({saved:false,itemId:null,busy:false});
  const product = apiBackend === 'laravel' ? (remoteProduct || {...fallbackProduct,id:null,publicId:null,slug:id,name:'Loading product',image:'',images:[],price:0,old:0,rating:0,reviews:0,colors:[],variants:[]}) : fallbackProduct;
  const alerts = useLaravelProductAlerts();
  const images = useMemo(/** Uses only server media in Laravel mode and demo media only in legacy mode. */ () => apiBackend==='laravel' ? (remoteProduct?.images||[]) : (demoImages[fallbackProduct.id] || [product.image]), [remoteProduct, fallbackProduct, product.image]);
  const [activeImage, setActiveImage] = useState(0);
  const [color, setColor] = useState((product.colors || ['Natural Titanium', 'Black', 'White', 'Blue'])[0]);
  const [storage, setStorage] = useState((product.variants || ['256GB', '512GB', '1TB'])[0]);
  const [qty, setQty] = useState(1);
  const [tab, setTab] = useState('full');
  const [emi, setEmi] = useState(12);
  const [recipient, setRecipient] = useState('');
  const [giftMessage, setGiftMessage] = useState('');
  const [giftWrap, setGiftWrap] = useState(true);
  const [anonymous, setAnonymous] = useState(false);
  const [schedule, setSchedule] = useState('');
  const [giftSavedMethods, setGiftSavedMethods] = useState([]);
  const [giftSavedMethodId, setGiftSavedMethodId] = useState('');
  const [notice, setNotice] = useState('');
  const [installmentApplied, setInstallmentApplied] = useState(false);
  const priceAlertActive = apiBackend==='laravel' ? alerts.isActive(product,'price_drop') : store.productAlerts?.some(/** Inline callback for this operation. */ a => a.productId === product.id && a.type === 'price');
  const stockAlertActive = apiBackend==='laravel' ? alerts.isActive(product,'back_in_stock') : store.productAlerts?.some(/** Inline callback for this operation. */ a => a.productId === product.id && a.type === 'stock');
  const [reviewRating, setReviewRating] = useState(0);
  const [reviewText, setReviewText] = useState('');
  const [reviewImages, setReviewImages] = useState([]);
  const [reviewResult, setReviewResult] = useState(null);
  const requestedOrderItemId = Number(new URLSearchParams(location.search).get('orderItem') || 0);
  const productRouteKey = product.slug || slugify(product.name);
  const eligibleReview = apiBackend === 'laravel'
    ? laravelReviews.pending.find(/** Inline callback for this operation. */ r => (r.productSlug === productRouteKey || r.productId === product.publicId || r.productId === product.id) && (!requestedOrderItemId || r.orderItemId === requestedOrderItemId))
    : store.pendingReviews.find(/** Inline callback for this operation. */ r => r.productId === product.id);
  const reviewEligible = Boolean(eligibleReview);
  const submittedProductReviews = apiBackend === 'laravel'
    ? laravelReviews.reviews.filter(/** Inline callback for this operation. */ r => r.product?.slug === slugify(product.name) || r.product?.id === product.id)
    : store.myReviews.filter(/** Inline callback for this operation. */ r => r.productId === product.id);
  const reviewProductKey = productRouteKey;
  const publicProductReviews = apiBackend === 'laravel' ? (laravelReviews.productReviews[reviewProductKey] || []) : reviews;
  const visiblePublicReviews = apiBackend === 'laravel'
    ? publicProductReviews.filter(/** Inline callback for this operation. */ review => !submittedProductReviews.some(/** Inline callback for this operation. */ own => own.id === review.id))
    : publicProductReviews;
  const liveReviewCount = apiBackend === 'laravel' ? publicProductReviews.length : product.reviews;
  const liveReviewRating = apiBackend === 'laravel' && publicProductReviews.length
    ? (publicProductReviews.reduce(/** Inline callback for this operation. */ (sum, review) => sum + Number(review.rating || 0), 0) / publicProductReviews.length).toFixed(1)
    : product.rating;
  const selectedRemoteVariant = remoteProduct?.rawVariants?.find(/** Inline callback for this operation. */ v => { const o=v.options||{}; return (!o.color || o.color===color) && (!o.variant || o.variant===storage) && (o.color || o.variant || v.name===storage); }) || remoteProduct?.rawVariants?.find(/** Inline callback for this operation. */ v=>v.isDefault) || remoteProduct?.rawVariants?.[0] || null;
  const remoteSelectedOptions = selectedRemoteVariant?.options || {};

  const discount = Math.max(0, product.old - product.price);
  const gameCost = product.gameEntryCoins || 70;
  const emiDown = Math.round(product.price * 0.2);
  const emiFinanced = product.price - emiDown;
  const emiAmount = Math.ceil(emiFinanced / emi);
  const freeGiftWrapReward = apiBackend === 'laravel' && laravelGifts.rewards.some(/** Inline callback for this operation. */ r => r.code === 'free_gift_wrap' && r.status === 'available');
  const giftCoins = Math.ceil((product.price + (giftWrap ? 299 : 0)) * COINS_PER_RUPEE);

  useEffect(/** Inline callback for this operation. */ () => {
    if (apiBackend !== 'laravel') return;
    let live=true; setRemoteLoading(true); setRemoteError(''); apiGet(`/products/${encodeURIComponent(id)}`).then(/** Inline callback for this operation. */ p=>{ if(!live)return; const next=normalizeLaravelProduct(p); setRemoteProduct(next); setColor(next.colors?.[0]||''); setStorage(next.storage?.[0]||''); }).catch(/** Inline callback for this operation. */ e=>{if(live)setRemoteError(e.message||'Product could not be loaded.')}).finally(/** Inline callback for this operation. */ ()=>{if(live)setRemoteLoading(false)}); return/** Inline callback for this operation. */ ()=>{live=false};
  }, [id]);
  useEffect(/** Inline callback for this operation. */ () => {
    if (apiBackend !== 'laravel' || !remoteProduct) return;
    recordProductView(remoteProduct, selectedRemoteVariant?.id || null).catch(/** Inline callback for this operation. */ ()=>{});
    wishlistStatus(remoteProduct).then(/** Inline callback for this operation. */ x=>setWishlist(/** Inline callback for this operation. */ w=>({...w,saved:Boolean(x.saved),itemId:x.itemId||null}))).catch(/** Inline callback for this operation. */ ()=>{});
  }, [remoteProduct?.id]);
  const toggleWishlist = /** Handles toggle wishlist for the VSN Ecommerce interface. */ async () => {
    if (apiBackend !== 'laravel') { setNotice('Wishlist persistence is available in Laravel mode.'); return; }
    setWishlist(/** Inline callback for this operation. */ w=>({...w,busy:true}));
    try { if (wishlist.saved && wishlist.itemId) { await removeWishlist(wishlist.itemId); setWishlist({saved:false,itemId:null,busy:false}); setNotice('Removed from wishlist.'); } else { const row=await saveWishlist(product,selectedRemoteVariant?.id||null); setWishlist({saved:true,itemId:row.id,busy:false}); setNotice('Saved to wishlist.'); } } catch(e){setWishlist(/** Inline callback for this operation. */ w=>({...w,busy:false}));setNotice(e.message||'Wishlist could not be updated.');}
  };
  useEffect(/** Inline callback for this operation. */ () => {
    if (apiBackend === 'laravel') laravelReviews.loadProductReviews(reviewProductKey).catch(/** Inline callback for this operation. */ () => {});
  }, [reviewProductKey, laravelReviews.loadProductReviews]);
  useEffect(/** Inline callback for this operation. */ () => {
    if (apiBackend !== 'laravel') return;
    apiGet('/payment-methods').then(/** Inline callback for this operation. */ data => {
      const rows = data?.items || [];
      setGiftSavedMethods(rows);
      const preferred = rows.find(/** Inline callback for this operation. */ x => x.default) || rows[0];
      if (preferred) setGiftSavedMethodId(preferred.id);
    }).catch(/** Inline callback for this operation. */ () => {});
  }, []);


  const addCart = /** Handles add cart for the VSN Ecommerce interface. */ async (goCheckout = false) => {
    try {
      await onAdd?.({ ...product, selectedVariantId:selectedRemoteVariant?.id||null, selectedColor: remoteProduct?(remoteSelectedOptions.color||null):color, selectedVariant: remoteProduct?(remoteSelectedOptions.variant||(!Object.keys(remoteSelectedOptions).length?null:storage)):storage }, qty);
      setNotice(`${qty} item${qty > 1 ? 's' : ''} added to cart.`);
      if (goCheckout) navigate('/checkout');
    } catch (error) {
      setNotice(error.message || 'Could not add this item to cart.');
    }
  };

  const activeLaravelGame = apiBackend === 'laravel'
    ? laravelGames.games.find(/** Inline callback for this operation. */ g => g.product?.slug === reviewProductKey && g.status === 'open')
    : null;
  const liveGameCost = activeLaravelGame?.entryCoins || gameCost;
  const liveAnnouncementAt = activeLaravelGame?.announcementAt || (apiBackend === 'laravel' ? null : product.announcementAt);

  const joinGame = /** Handles join game for the VSN Ecommerce interface. */ async () => {
    if (!product.game) { setNotice('This product is not currently Game Win eligible.'); return; }
    if (apiBackend === 'laravel') {
      if (!activeLaravelGame) { setNotice('No server-authorized Game Win campaign is currently accepting entries for this product.'); return; }
      try {
        const entry = await laravelGames.join(activeLaravelGame.id, 1);
        await laravelWallet.refresh();
        setNotice(`Game entry confirmed · ${entry.coinsSpent.toLocaleString()} coins used.`);
        navigate('/games');
      } catch (error) {
        setNotice(error.message || 'Could not join this Game Win campaign.');
      }
      return;
    }
    const result = store.joinGame?.(product, 1);
    if (result?.ok) { setNotice(result.msg); navigate('/games'); return; }
    const ok = store.spendCoins(gameCost, `Game entry · ${product.name}`);
    setNotice(ok ? 'Game entry confirmed. Open My Games to track the announcement.' : 'Not enough coins for this entry.');
    if (ok) navigate('/games');
  };



  const handleReviewImages = /** Handles handle review images for the VSN Ecommerce interface. */ async (files) => {
    const list = Array.from(files || []).slice(0, 4);
    if (apiBackend === 'laravel') {
      const prepared = list.map(/** Inline callback for this operation. */ file => ({ name: file.name, file, url: URL.createObjectURL(file) }));
      setReviewImages(prepared);
      return;
    }
    const encoded = await Promise.all(list.map(/** Inline callback for this operation. */ file => new Promise(/** Inline callback for this operation. */ resolve => { const reader = new FileReader(); reader.onload = /** Inline callback for this operation. */ () => resolve({ name: file.name, url: String(reader.result || '') }); reader.onerror = /** Inline callback for this operation. */ () => resolve({ name: file.name, url: '' }); reader.readAsDataURL(file) })));
    setReviewImages(encoded.filter(/** Inline callback for this operation. */ x => x.url));
  };

  const submitProductReview = /** Handles submit product review for the VSN Ecommerce interface. */ async (e) => {
    e.preventDefault();
    if (apiBackend === 'laravel') {
      if (!eligibleReview) { setReviewResult({ok:false,msg:'No eligible delivered purchase is waiting for review.'}); return; }
      try {
        const review = await laravelReviews.submit({ orderItemId: eligibleReview.orderItemId, rating: reviewRating, text: reviewText, images: reviewImages.map(/** Inline callback for this operation. */ x => x.file).filter(Boolean) });
        await laravelReviews.loadProductReviews(reviewProductKey).catch(/** Inline callback for this operation. */ () => {});
        setReviewResult({ok:true,msg:`Review submitted. Your 10% coupon is ${review.coupon?.code || 'now available'}.`});
        setReviewRating(0); setReviewText(''); setReviewImages([]);
      } catch (error) {
        setReviewResult({ok:false,msg:error.message || 'Could not submit review.'});
      }
      return;
    }
    const result = store.submitReview({ productId: product.id, rating: reviewRating, text: reviewText, images: reviewImages.map(/** Inline callback for this operation. */ x => x.url) });
    setReviewResult(result);
    if (result.ok) { setReviewRating(0); setReviewText(''); setReviewImages([]) }
  };

  const sendGift = /** Handles send gift for the VSN Ecommerce interface. */ async (withCoins = false) => {
    if (!recipient.trim()) { setNotice('Enter the recipient email or verified phone number.'); return; }
    if (apiBackend === 'laravel') {
      try {
        setNotice('Preparing secure gift checkout…');
        const result = await laravelGifts.createProductGift({
          recipient: recipient.trim(),
          productSlug: reviewProductKey,
          variantId: selectedRemoteVariant?.id || null,
          selectedOptions: remoteProduct ? remoteSelectedOptions : { color, variant: storage },
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
      return;
    }
    if (withCoins) {
      const ok = store.spendCoins(giftCoins, `Gift purchase · ${product.name}`);
      if (!ok) { setNotice(`You need ${giftCoins.toLocaleString()} coins for this gift.`); return; }
      store.recordGift?.(giftCoins);
    } else {
      store.recordGift?.(Math.ceil(product.price * COINS_PER_RUPEE));
    }
    store.saveGiftOrder?.({ productId: product.id, productName: product.name, recipient, message: giftMessage, giftWrap, anonymous, schedule, payment: withCoins ? 'coins' : 'cash' });
    setNotice(`Gift prepared for ${recipient}. ${withCoins ? 'Coins deducted.' : 'Continue to checkout for payment.'}`);
    if (!withCoins) navigate('/checkout');
  };

  const helpfulReview = /** Handles helpful review for the VSN Ecommerce interface. */ async (review) => { try { await apiPost(`/reviews/${review.id}/helpful`,{}); await laravelReviews.loadProductReviews(reviewProductKey); } catch(e) { setNotice(e.message||'Sign in to mark a review helpful.'); } };
  const reportReview = /** Handles report review for the VSN Ecommerce interface. */ async (review) => { const details=globalThis.prompt?.('Why are you reporting this review?')||''; if(!details.trim())return; try { await apiPost(`/reviews/${review.id}/report`,{reason:'other',details}); setNotice('Review report submitted for moderation.'); } catch(e) { setNotice(e.message||'Review could not be reported.'); } };

  if (apiBackend==='laravel' && remoteLoading) return <><SEO title="Loading product | VSN Ecommerce"/><div className="simple-page"><Card><p>Loading product…</p></Card></div></>;
  if (apiBackend==='laravel' && remoteError && !remoteProduct) return <><SEO title="Product unavailable | VSN Ecommerce"/><div className="simple-page"><Status>{remoteError}</Status></div></>;

  return <>
    <SEO title={`${product.name} | VSN Ecommerce`} description={`Buy ${product.name} from verified seller ${product.vendor}. Full payment, installments${product.game ? ', Game Win' : ''} and gifting options available.`} />
    <main className="pdp-page">
      <nav className="pdp-breadcrumb" aria-label="Breadcrumb"><button onClick={/** Inline callback for this operation. */ () => navigate('/')}>Home</button><span>/</span><button onClick={/** Inline callback for this operation. */ () => navigate(`/search?q=${encodeURIComponent(product.category)}`)}>{product.category}</button><span>/</span><strong>{product.name}</strong></nav>

      <section className="pdp-main">
        <div className="pdp-media">
          <div className="pdp-thumbs" aria-label="Product images">
            {images.map(/** Inline callback for this operation. */ (src, i) => <button key={src} className={activeImage === i ? 'active' : ''} onClick={/** Inline callback for this operation. */ () => setActiveImage(i)} aria-label={`View image ${i + 1}`}><SafeImage src={src} alt={`${product.name} view ${i + 1}`} /></button>)}
          </div>
          <div className="pdp-hero-image"><SafeImage src={images[activeImage]} alt={product.name} /><button className={`pdp-wishlist ${wishlist.saved?"active":""}`} disabled={wishlist.busy} aria-label={wishlist.saved?"Remove from wishlist":"Add to wishlist"} onClick={toggleWishlist}><FaHeart /></button></div>
        </div>

        <div className="pdp-info">
          <div className="pdp-badges"><Badge tone="deal">Best Seller</Badge><Badge tone="success">Verified seller</Badge>{product.game && <Badge tone="game">Game Win live</Badge>}</div>
          <p className="pdp-brand">{product.vendor} <Status ok>Official / verified store</Status></p>
          <h1>{product.name}</h1>
          <div className="pdp-rating-row"><Rating value={product.rating} reviews={product.reviews} /><span>{product.sold || 4820} sold</span><button><FaShareAlt /> Share</button></div>
          <div className="pdp-price"><strong>Rs. {product.price.toLocaleString()}</strong>{product.old>product.price&&<del>Rs. {product.old.toLocaleString()}</del>}{discount > 0 && <span>Save Rs. {discount.toLocaleString()}</span>}</div><div className="pdp-alert-actions"><button className={priceAlertActive ? 'active' : ''} onClick={/** Inline callback for this operation. */ async()=>{try{if(apiBackend==='laravel'){if(priceAlertActive){const a=alerts.alerts.find(/** Inline callback for this operation. */ x=>x.product?.slug===product.slug&&x.type==='price_drop'&&x.status==='active');if(a)await alerts.remove(a.id);setNotice('Price alert updated.')}else{await alerts.create(product,'price_drop');setNotice('Price-drop alert created.')}}else setNotice(store.toggleProductAlert(product,'price').msg)}catch(e){setNotice(e.message)}}}><FaBell /> {priceAlertActive ? 'Price alert on' : 'Notify price drop'}</button><button className={stockAlertActive ? 'active' : ''} onClick={/** Inline callback for this operation. */ async()=>{try{if(apiBackend==='laravel'){if(stockAlertActive){const a=alerts.alerts.find(/** Inline callback for this operation. */ x=>x.product?.slug===product.slug&&x.type==='back_in_stock'&&x.status==='active');if(a)await alerts.remove(a.id);setNotice('Stock alert updated.')}else{await alerts.create(product,'back_in_stock');setNotice('Back-in-stock alert created.')}}else setNotice(store.toggleProductAlert(product,'stock').msg)}catch(e){setNotice(e.message)}}}><FaBell /> {stockAlertActive ? 'Stock alert on' : 'Stock alert'}</button></div>
          <p className="pdp-summary">Authentic product with buyer protection, tracked delivery and seller accountability. Purchase through full payment, installment plan, gifting or eligible Game Win entry.</p>

          {product.game && <Card className="pdp-game-countdown"><div><Badge tone="game">Live Game Win</Badge><strong>Winner announcement</strong><small>Your entry remains visible in My Games until the verified result is published.</small></div><div><Countdown target={product.announcementAt} /><small>{gameCost} coins = Rs. {(gameCost / COINS_PER_RUPEE).toFixed(0)} entry</small></div></Card>}

          <div className="pdp-option-group"><div className="pdp-option-head"><span>Color</span><strong>{color}</strong></div><div className="pdp-chips">{(remoteProduct ? product.colors : (product.colors || ['Natural Titanium', 'Black', 'White', 'Blue'])).map(/** Inline callback for this operation. */ v => <button className={color === v ? 'active' : ''} key={v} onClick={/** Inline callback for this operation. */ () => setColor(v)}>{v}</button>)}</div></div>
          <div className="pdp-option-group"><div className="pdp-option-head"><span>Variant / storage</span><strong>{storage}</strong></div><div className="pdp-chips">{(remoteProduct ? product.variants : (product.variants || ['256GB', '512GB', '1TB'])).map(/** Inline callback for this operation. */ v => <button className={storage === v ? 'active' : ''} key={v} onClick={/** Inline callback for this operation. */ () => setStorage(v)}>{v}</button>)}</div></div>

          <section className="pdp-purchase-panel">
            <div className="pdp-tabs" role="tablist">
              <button className={tab === 'full' ? 'active' : ''} onClick={/** Inline callback for this operation. */ () => setTab('full')}><FaCreditCard /> Full pay</button>
              {product.installment && <button className={tab === 'install' ? 'active' : ''} onClick={/** Inline callback for this operation. */ () => setTab('install')}><FaCreditCard /> Installment</button>}
              {product.game && <button className={tab === 'game' ? 'active game' : ''} onClick={/** Inline callback for this operation. */ () => setTab('game')}><FaGamepad /> Win it</button>}
              <button className={tab === 'gift' ? 'active gift' : ''} onClick={/** Inline callback for this operation. */ () => setTab('gift')}><FaGift /> Gift</button>
            </div>

            <div className="pdp-tab-content">
              {tab === 'full' && <div className="pdp-full-pay">
                <p>Pay the full amount now. Estimated delivery: <b>2–4 business days</b>.</p>
                <div className="pdp-stock-row"><div className="qty-control"><button onClick={/** Inline callback for this operation. */ () => setQty(/** Inline callback for this operation. */ q => Math.max(1, q - 1))}><FaMinus /></button><strong>{qty}</strong><button onClick={/** Inline callback for this operation. */ () => setQty(/** Inline callback for this operation. */ q => Math.min(10, q + 1))}><FaPlus /></button></div><Status ok>In stock · {product.stock || 248} available</Status></div>
                <div className="pdp-primary-actions"><Button onClick={/** Inline callback for this operation. */ () => addCart(true)}><FaBolt /> Buy now</Button><Button variant="secondary" onClick={/** Inline callback for this operation. */ () => addCart(false)}>Add to cart</Button></div>
              </div>}

              {tab === 'install' && product.installment && <div className="pdp-installment">
                <div className="pdp-option-head"><span>Select plan</span><strong>{emi} months</strong></div>
                <div className="pdp-chips">{[3, 6, 12, 24].map(/** Inline callback for this operation. */ m => <button key={m} className={emi === m ? 'active success' : ''} onClick={/** Inline callback for this operation. */ () => setEmi(m)}>{m}M</button>)}</div>
                <div className="pdp-payment-breakdown"><div><span>Monthly payment</span><strong>Rs. {emiAmount.toLocaleString()}/mo</strong></div><div><span>Down payment (20%)</span><strong>Rs. {emiDown.toLocaleString()}</strong></div><div><span>Demo interest</span><strong>0% bank offer</strong></div><div><span>Identity requirement</span><strong>{store.profile.idVerified ? 'Verified' : 'Govt ID required'}</strong></div></div>
                {!store.profile.idVerified && <p className="pdp-inline-warning">Complete government ID verification in Profile before production financing approval.</p>}
                <Button variant="success" onClick={/** Inline callback for this operation. */ () => { setInstallmentApplied(true); setNotice('Installment application created in demo state. Production flow will submit to the financing API.') }}><FaCreditCard /> {installmentApplied ? 'Application created' : 'Apply for installment plan'}</Button>
              </div>}

              {tab === 'game' && product.game && <div className="pdp-game-tab">
                <div className="pdp-game-icon"><FaGamepad /></div><h3>Win this product for {liveGameCost} coins</h3><p>One verified winner receives the product. Your click confirms the published Game Win rules version; Laravel records the consent, entry ledger and immutable draw audit.</p>{liveAnnouncementAt?<Countdown target={liveAnnouncementAt} />:<p>No server-authorized campaign is open right now.</p>}{apiBackend==='laravel'&&activeLaravelGame&&<p><small>Commitment: {activeLaravelGame.commitmentHash.slice(0,16)}… · {activeLaravelGame.totalEntries.toLocaleString()} entries</small></p>}<div className="pdp-game-modes"><span>Audited Draw</span><span>Immutable Entry</span><span>Server Time</span><span>Coin Ledger</span></div><p className="pdp-balance">Your balance: <b>{visibleCoinBalance.toLocaleString()} coins</b> · Rs. {(visibleCoinBalance / COINS_PER_RUPEE).toFixed(2)}</p>{apiBackend==='laravel'&&!activeLaravelGame?<Button variant="secondary" disabled>Entries unavailable</Button>:<Button variant="game" onClick={joinGame}><FaGamepad /> Join game — {liveGameCost} coins</Button>}</div>}

              {tab === 'gift' && <div className="pdp-gift-tab">
                <p>Send this product to another VSN user and grow your Gift Sender level.</p>
                <Field label="Recipient" value={recipient} onChange={/** Inline callback for this operation. */ e => setRecipient(e.target.value)} placeholder="Email or verified phone" />
                <label className="ui-field"><span>Gift message</span><textarea value={giftMessage} onChange={/** Inline callback for this operation. */ e => setGiftMessage(e.target.value)} placeholder="Write a personal message" /></label>
                <div className="pdp-gift-options"><label><input type="checkbox" checked={giftWrap} onChange={/** Inline callback for this operation. */ e => setGiftWrap(e.target.checked)} /><span><FaGift /> Gift wrap <small>{freeGiftWrapReward ? 'Free reward available' : '+Rs.299'}</small></span></label><label><input type="checkbox" checked={anonymous} onChange={/** Inline callback for this operation. */ e => setAnonymous(e.target.checked)} /><span><FaShieldAlt /> Stay anonymous</span></label></div>
                <Field label="Schedule delivery (optional)" type="datetime-local" value={schedule} onChange={/** Inline callback for this operation. */ e => setSchedule(e.target.value)} />
                {apiBackend === 'laravel' && giftSavedMethods.length > 0 && <Select label="Saved card for gift" value={giftSavedMethodId} onChange={/** Inline callback for this operation. */ e => setGiftSavedMethodId(e.target.value)}><option value="">Provider checkout (no saved token)</option>{giftSavedMethods.map(/** Inline callback for this operation. */ m => <option key={m.id} value={m.id}>{(m.brand || 'Card').toUpperCase()} •••• {m.last4}{m.default ? ' · Default' : ''}</option>)}</Select>}
                <div className="pdp-primary-actions"><Button variant="gift" onClick={/** Inline callback for this operation. */ () => sendGift(false)}><FaGift /> Continue gift checkout</Button><Button variant="coin" onClick={/** Inline callback for this operation. */ () => sendGift(true)}>Pay with VSN Coins</Button></div>
              </div>}
            </div>
          </section>

          {notice && <div className="pdp-notice" role="status">{notice}</div>}

          <div className="pdp-trust-grid"><div><FaTruck /><span><b>Tracked delivery</b><small>Live courier status</small></span></div><div><FaUndo /><span><b>Easy returns</b><small>30-day policy</small></span></div><div><FaShieldAlt /><span><b>Buyer protection</b><small>Secure payments</small></span></div><div><FaBoxOpen /><span><b>Authentic product</b><small>Verified seller</small></span></div></div>
        </div>
      </section>

      <section className="pdp-lower">
        <div className="pdp-specs"><SectionHeader title="Product overview" sub="Key buying information in one place" /><Card><div className="pdp-spec-grid"><div><span>Seller</span><strong>{product.vendor}</strong></div><div><span>Category</span><strong>{product.category}</strong></div><div><span>Warranty</span><strong>1 year seller / brand warranty</strong></div><div><span>Returns</span><strong>30 days</strong></div><div><span>Delivery</span><strong>Tracked nationwide</strong></div><div><span>Payment</span><strong>Full{product.installment ? ' · EMI' : ''} · Gift{product.game ? ' · Game' : ''}</strong></div></div></Card></div>
        <div className="pdp-reviews">
          <SectionHeader title="Customer reviews" sub={`${Number(liveReviewCount).toLocaleString()} verified ratings and reviews`} />
          <div className="pdp-review-layout">
            <Card className="pdp-review-summary"><strong>{liveReviewRating}</strong><Rating value={Number(liveReviewRating)} reviews={liveReviewCount} /><p>Verified purchase feedback</p>{reviewEligible && <div className="pdp-review-reward"><FaTag /><span><b>VSNREV-•••••••••• · 10% coupon waiting</b><small>Submit your verified review to unlock the real code.</small></span></div>}</Card>
            <div className="pdp-review-list">
              {reviewEligible && <Card className="pdp-write-review">
                <div className="review-form-head"><div><Badge tone="success">Verified purchase</Badge><h3>Review this product</h3><p>Help other buyers and unlock a one-time 10% coupon for your next order.</p></div><FaStar /></div>
                <form onSubmit={submitProductReview}>
                  <fieldset className="review-stars"><legend>Your rating</legend>{[1, 2, 3, 4, 5].map(/** Inline callback for this operation. */ n => <button type="button" key={n} className={reviewRating >= n ? 'active' : ''} onClick={/** Inline callback for this operation. */ () => setReviewRating(n)} aria-label={`${n} star rating`}><FaStar /></button>)}</fieldset>
                  <label className="ui-field"><span>Your review</span><textarea value={reviewText} onChange={/** Inline callback for this operation. */ e => setReviewText(e.target.value)} placeholder="What did you like? Mention quality, delivery, packaging or seller experience." minLength="10" required /></label>
                  <label className="review-upload"><input type="file" accept="image/*" multiple onChange={/** Inline callback for this operation. */ e => handleReviewImages(e.target.files)} /><FaCamera /><span><b>Add photos</b><small>Up to 4 images · JPG, PNG or WebP</small></span></label>
                  {reviewImages.length > 0 && <div className="review-photo-strip">{reviewImages.map(/** Inline callback for this operation. */ (img, i) => <div className="review-photo-preview" key={img.url}><SafeImage src={img.url} alt={img.name} /><button type="button" onClick={/** Inline callback for this operation. */ () => setReviewImages(/** Inline callback for this operation. */ v => v.filter(/** Inline callback for this operation. */ (_, x) => x !== i))}>×</button></div>)}</div>}
                  <div className="review-form-actions"><Button type="submit">Submit review & unlock 10% coupon</Button><small>Reward applies once per eligible delivered order item.</small></div>
                </form>
                {reviewResult && <div className={`review-result ${reviewResult.ok ? 'is-success' : 'is-error'}`}>{reviewResult.msg}</div>}
              </Card>}
              {submittedProductReviews.map(/** Inline callback for this operation. */ r => <Card key={r.id}><div className="pdp-review-head"><span className="review-avatar">Y</span><div><b>You</b><Rating value={r.rating} /><small>{new Date(r.submittedAt || r.createdAt).toLocaleDateString()} · Verified purchase</small></div></div><p>{r.text}</p>{r.images?.length > 0 && <div className="review-photo-strip">{r.images.map(/** Inline callback for this operation. */ (img, i) => <SafeImage key={img.id || i} src={img.url || img} alt={`Your review upload ${i + 1}`} />)}</div>}<div className="review-earned"><FaTag /> Coupon earned: <b>{r.coupon?.code || r.couponCode}</b></div></Card>)}
              {visiblePublicReviews.map(/** Inline callback for this operation. */ (r, i) => { const name=r.user?.name||r.name||'Verified buyer'; return <Card key={r.id||`${name}-${i}`}><div className="pdp-review-head"><span className={`review-avatar avatar-${i + 1}`}>{name[0]}</span><div><b>{name}</b><Rating value={r.rating} /><small>{r.submittedAt?new Date(r.submittedAt).toLocaleDateString():r.date} · Verified purchase</small></div></div><p>{r.text}</p>{r.images?.length>0&&<div className="review-photo-strip">{r.images.map(/** Inline callback for this operation. */ (img,x)=><SafeImage key={img.id||x} src={img.url||img} alt={`Review upload ${x+1}`}/>)}</div>}{r.sellerReply&&<div className="review-seller-reply"><b>{r.sellerReply.sellerName||'Seller'} replied</b><p>{r.sellerReply.text}</p></div>}{apiBackend==='laravel'&&<div className="review-engagement-actions"><button className={r.helpfulByMe?'active':''} onClick={/** Inline callback for this operation. */ ()=>helpfulReview(r)}><FaThumbsUp/> Helpful ({r.helpfulCount||0})</button><button onClick={/** Inline callback for this operation. */ ()=>reportReview(r)}><FaFlag/> Report</button></div>}</Card>; })}
            </div>
          </div>
        </div>
      </section>
    </main>
  </>;
}
