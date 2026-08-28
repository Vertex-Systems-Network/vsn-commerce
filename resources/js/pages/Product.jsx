import { useEffect, useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { FaBolt, FaCreditCard, FaGamepad, FaGift, FaMinus, FaPlus, FaShieldAlt, FaTruck, FaUndo, FaHeart, FaShareAlt, FaBoxOpen, FaCamera, FaStar, FaTag, FaBell, FaThumbsUp, FaFlag } from 'react-icons/fa';
import SEO from '../components/SEO';
import { SafeImage, Rating, Countdown, Button, Badge, Card, Field, Select, Status, SectionHeader } from '../components/Toolkit';
import { apiGet, apiPost } from '../platform/api';
import { recordProductView, removeWishlist, saveWishlist, wishlistStatus } from '../platform/personalization';
import { useLaravelWallet } from '../platform/wallet';
import { useLaravelGames } from '../platform/games';
import { useLaravelGifts } from '../platform/gifts';
import { useLaravelReviews } from '../platform/reviews';
import { normalizeLaravelProduct, useLaravelProductAlerts } from '../platform/catalog';

/** Handles product for the VSN Ecommerce interface. */
export default function Product({ onAdd }) {
  const { id } = useParams();
  const navigate = useNavigate();
  const location = useLocation();
  const laravelWallet = useLaravelWallet();
  const laravelGames = useLaravelGames();
  const laravelGifts = useLaravelGifts();
  const laravelReviews = useLaravelReviews();
  const loadProductReviews = laravelReviews.loadProductReviews;
  const alerts = useLaravelProductAlerts();

  const slugify = /** Handles slugify for the VSN Ecommerce interface. */ value => String(value || '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  const [remoteProduct, setRemoteProduct] = useState(null);
  const [remoteLoading, setRemoteLoading] = useState(true);
  const [remoteError, setRemoteError] = useState('');
  const [wishlist, setWishlist] = useState({ saved: false, itemId: null, busy: false });
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
  const [reviewRating, setReviewRating] = useState(0);
  const [reviewText, setReviewText] = useState('');
  const [reviewImages, setReviewImages] = useState([]);
  const [reviewResult, setReviewResult] = useState(null);

  const product = remoteProduct || {
    id: null,
    publicId: null,
    slug: id,
    name: 'Loading product',
    image: '',
    images: [],
    price: 0,
    old: 0,
    rating: 0,
    reviews: 0,
    colors: [],
    variants: [],
    vendor: '',
    category: '',
    stock: 0,
    game: false,
    installment: false,
  };
  const remoteProductId = remoteProduct?.id;
  const images = (remoteProduct?.images || []).filter(Boolean);
  const mainImage = images[activeImage] || product.image || '';
  const visibleCoinBalance = Number(laravelWallet.wallet?.balanceCoins || 0);
  const priceAlertActive = alerts.isActive(product, 'price_drop');
  const stockAlertActive = alerts.isActive(product, 'back_in_stock');
  const requestedOrderItemId = Number(new URLSearchParams(location.search).get('orderItem') || 0);
  const productRouteKey = product.slug || slugify(product.name);
  const eligibleReview = laravelReviews.pending.find(
    /** Inline callback for this operation. */ r =>
      (r.productSlug === productRouteKey || r.productId === product.publicId || r.productId === product.id)
      && (!requestedOrderItemId || r.orderItemId === requestedOrderItemId),
  );
  const reviewEligible = Boolean(eligibleReview);
  const submittedProductReviews = laravelReviews.reviews.filter(
    /** Inline callback for this operation. */ r =>
      r.product?.slug === productRouteKey
      || r.product?.slug === slugify(product.name)
      || r.product?.id === product.publicId
      || r.product?.id === product.id,
  );
  const reviewProductKey = productRouteKey;
  const publicProductReviews = laravelReviews.productReviews[reviewProductKey] || [];
  const visiblePublicReviews = publicProductReviews.filter(
    /** Inline callback for this operation. */ review =>
      !submittedProductReviews.some(/** Inline callback for this operation. */ own => own.id === review.id),
  );
  const liveReviewCount = Math.max(Number(product.reviews || 0), publicProductReviews.length);
  const liveReviewRating = publicProductReviews.length
    ? (publicProductReviews.reduce(/** Inline callback for this operation. */ (sum, review) => sum + Number(review.rating || 0), 0) / publicProductReviews.length).toFixed(1)
    : Number(product.rating || 0);
  const selectedRemoteVariant = remoteProduct?.rawVariants?.find(
    /** Inline callback for this operation. */ v => {
      const options = v.options || {};
      return (!options.color || options.color === color)
        && (!options.variant || options.variant === storage)
        && (options.color || options.variant || v.name === storage);
    },
  ) || remoteProduct?.rawVariants?.find(/** Inline callback for this operation. */ v => v.isDefault)
    || remoteProduct?.rawVariants?.[0]
    || null;
  const remoteSelectedOptions = selectedRemoteVariant?.options || {};
  const discount = Math.max(0, Number(product.old || 0) - Number(product.price || 0));
  const freeGiftWrapReward = laravelGifts.rewards.some(
    /** Inline callback for this operation. */ reward => reward.code === 'free_gift_wrap' && reward.status === 'available',
  );
  const activeLaravelGame = laravelGames.games.find(
    /** Inline callback for this operation. */ game =>
      (game.product?.slug && game.product.slug === reviewProductKey)
      || (game.product?.id && (game.product.id === product.publicId || game.product.id === product.id)),
  );
  const openLaravelGame = activeLaravelGame?.status === 'open' ? activeLaravelGame : null;
  const liveGameCost = Number(openLaravelGame?.entryCoins || 0);
  const liveAnnouncementAt = openLaravelGame?.announcementAt || null;
  const soldCount = Number(product.raw?.soldCount ?? product.raw?.sold ?? 0);

  useEffect(/** Inline callback for this operation. */ () => {
    let live = true;
    setRemoteLoading(true);
    setRemoteError('');
    setRemoteProduct(null);
    setActiveImage(0);
    apiGet(`/products/${encodeURIComponent(id)}`)
      .then(/** Inline callback for this operation. */ payload => {
        if (!live) return;
        const next = normalizeLaravelProduct(payload);
        setRemoteProduct(next);
        setColor(next.colors?.[0] || '');
        setStorage(next.storage?.[0] || next.variants?.[0] || '');
      })
      .catch(/** Inline callback for this operation. */ error => {
        if (live) setRemoteError(error.message || 'Product could not be loaded.');
      })
      .finally(/** Inline callback for this operation. */ () => {
        if (live) setRemoteLoading(false);
      });
    return /** Inline callback for this operation. */ () => { live = false; };
  }, [id]);

  useEffect(/** Inline callback for this operation. */ () => {
    if (!remoteProduct) return;
    recordProductView(remoteProduct, selectedRemoteVariant?.id || null).catch(/** Inline callback for this operation. */ () => {});
    wishlistStatus(remoteProduct)
      .then(/** Inline callback for this operation. */ row => setWishlist(/** Inline callback for this operation. */ current => ({
        ...current,
        saved: Boolean(row.saved),
        itemId: row.itemId || null,
      })))
      .catch(/** Inline callback for this operation. */ () => {});
  }, [remoteProduct?.id]);

  useEffect(/** Inline callback for this operation. */ () => {
    if (!remoteProductId) return;
    loadProductReviews(reviewProductKey).catch(/** Inline callback for this operation. */ () => {});
  }, [remoteProductId, reviewProductKey, loadProductReviews]);

  useEffect(/** Inline callback for this operation. */ () => {
    apiGet('/payment-methods')
      .then(/** Inline callback for this operation. */ data => {
        const rows = data?.items || [];
        setGiftSavedMethods(rows);
        const preferred = rows.find(/** Inline callback for this operation. */ item => item.default) || rows[0];
        if (preferred) setGiftSavedMethodId(preferred.id);
      })
      .catch(/** Inline callback for this operation. */ () => {});
  }, []);

  const toggleWishlist = /** Handles toggle wishlist for the VSN Ecommerce interface. */ async () => {
    setWishlist(/** Inline callback for this operation. */ current => ({ ...current, busy: true }));
    try {
      if (wishlist.saved && wishlist.itemId) {
        await removeWishlist(wishlist.itemId);
        setWishlist({ saved: false, itemId: null, busy: false });
        setNotice('Removed from wishlist.');
        return;
      }
      const row = await saveWishlist(product, selectedRemoteVariant?.id || null);
      setWishlist({ saved: true, itemId: row.id, busy: false });
      setNotice('Saved to wishlist.');
    } catch (error) {
      setWishlist(/** Inline callback for this operation. */ current => ({ ...current, busy: false }));
      setNotice(error.message || 'Wishlist could not be updated.');
    }
  };

  const addCart = /** Handles add cart for the VSN Ecommerce interface. */ async (goCheckout = false) => {
    try {
      await onAdd?.({
        ...product,
        selectedVariantId: selectedRemoteVariant?.id || null,
        selectedColor: remoteSelectedOptions.color || null,
        selectedVariant: remoteSelectedOptions.variant || (!Object.keys(remoteSelectedOptions).length ? null : storage),
      }, qty);
      setNotice(`${qty} item${qty > 1 ? 's' : ''} added to cart.`);
      if (goCheckout) navigate('/checkout');
    } catch (error) {
      setNotice(error.message || 'Could not add this item to cart.');
    }
  };

  const joinGame = /** Handles join game for the VSN Ecommerce interface. */ async () => {
    if (!product.game || !openLaravelGame) {
      setNotice('No server-authorized Game Win campaign is currently accepting entries for this product.');
      return;
    }
    try {
      const entry = await laravelGames.join(openLaravelGame.id, 1);
      await laravelWallet.refresh();
      setNotice(`Game entry confirmed · ${entry.coinsSpent.toLocaleString()} coins used.`);
      navigate('/games');
    } catch (error) {
      setNotice(error.message || 'Could not join this Game Win campaign.');
    }
  };

  const handleReviewImages = /** Handles handle review images for the VSN Ecommerce interface. */ files => {
    const prepared = Array.from(files || []).slice(0, 4).map(
      /** Inline callback for this operation. */ file => ({ name: file.name, file, url: URL.createObjectURL(file) }),
    );
    setReviewImages(prepared);
  };

  const submitProductReview = /** Handles submit product review for the VSN Ecommerce interface. */ async event => {
    event.preventDefault();
    if (!eligibleReview) {
      setReviewResult({ ok: false, msg: 'No eligible delivered purchase is waiting for review.' });
      return;
    }
    try {
      const review = await laravelReviews.submit({
        orderItemId: eligibleReview.orderItemId,
        rating: reviewRating,
        text: reviewText,
        images: reviewImages.map(/** Inline callback for this operation. */ image => image.file).filter(Boolean),
      });
      await laravelReviews.loadProductReviews(reviewProductKey).catch(/** Inline callback for this operation. */ () => {});
      setReviewResult({ ok: true, msg: `Review submitted. Your 10% coupon is ${review.coupon?.code || 'now available'}.` });
      setReviewRating(0);
      setReviewText('');
      setReviewImages([]);
    } catch (error) {
      setReviewResult({ ok: false, msg: error.message || 'Could not submit review.' });
    }
  };

  const sendGift = /** Handles send gift for the VSN Ecommerce interface. */ async (withCoins = false) => {
    if (!recipient.trim()) {
      setNotice('Enter the recipient email or verified phone number.');
      return;
    }
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

  const toggleProductAlert = /** Handles product alert state for the VSN Ecommerce interface. */ async type => {
    const active = type === 'price_drop' ? priceAlertActive : stockAlertActive;
    try {
      if (active) {
        const alert = alerts.alerts.find(
          /** Inline callback for this operation. */ item =>
            item.product?.slug === product.slug
            && item.type === type
            && item.status === 'active',
        );
        if (alert) await alerts.remove(alert.id);
        setNotice(type === 'price_drop' ? 'Price alert updated.' : 'Stock alert updated.');
        return;
      }
      await alerts.create(product, type);
      setNotice(type === 'price_drop' ? 'Price-drop alert created.' : 'Back-in-stock alert created.');
    } catch (error) {
      setNotice(error.message || 'Product alert could not be updated.');
    }
  };

  const helpfulReview = /** Handles helpful review for the VSN Ecommerce interface. */ async review => {
    try {
      await apiPost(`/reviews/${review.id}/helpful`, {});
      await laravelReviews.loadProductReviews(reviewProductKey);
    } catch (error) {
      setNotice(error.message || 'Sign in to mark a review helpful.');
    }
  };

  const reportReview = /** Handles report review for the VSN Ecommerce interface. */ async review => {
    const details = globalThis.prompt?.('Why are you reporting this review?') || '';
    if (!details.trim()) return;
    try {
      await apiPost(`/reviews/${review.id}/report`, { reason: 'other', details });
      setNotice('Review report submitted for moderation.');
    } catch (error) {
      setNotice(error.message || 'Review could not be reported.');
    }
  };

  if (remoteLoading) {
    return <>
      <SEO title="Loading product | VSN Ecommerce" />
      <div className="simple-page"><Card><p>Loading product…</p></Card></div>
    </>;
  }

  if (remoteError && !remoteProduct) {
    return <>
      <SEO title="Product unavailable | VSN Ecommerce" />
      <div className="simple-page"><Status>{remoteError}</Status></div>
    </>;
  }

  return <>
    <SEO title={`${product.name} | VSN Ecommerce`} description={`Buy ${product.name} from verified seller ${product.vendor}. Full payment, installments${product.game ? ', Game Win' : ''} and gifting options available.`} />
    <main className="pdp-page">
      <nav className="pdp-breadcrumb" aria-label="Breadcrumb">
        <button onClick={/** Inline callback for this operation. */ () => navigate('/')}>Home</button>
        <span>/</span>
        <button onClick={/** Inline callback for this operation. */ () => navigate(`/search?q=${encodeURIComponent(product.category)}`)}>{product.category}</button>
        <span>/</span>
        <strong>{product.name}</strong>
      </nav>

      <section className="pdp-main">
        <div className="pdp-media">
          <div className="pdp-thumbs" aria-label="Product images">
            {images.map(/** Inline callback for this operation. */ (src, index) =>
              <button key={src} className={activeImage === index ? 'active' : ''} onClick={/** Inline callback for this operation. */ () => setActiveImage(index)} aria-label={`View image ${index + 1}`}>
                <SafeImage src={src} alt={`${product.name} view ${index + 1}`} />
              </button>)}
          </div>
          <div className="pdp-hero-image">
            <SafeImage src={mainImage} alt={product.name} />
            <button className={`pdp-wishlist ${wishlist.saved ? 'active' : ''}`} disabled={wishlist.busy} aria-label={wishlist.saved ? 'Remove from wishlist' : 'Add to wishlist'} onClick={toggleWishlist}><FaHeart /></button>
          </div>
        </div>

        <div className="pdp-info">
          <div className="pdp-badges">
            <Badge tone="deal">Best Seller</Badge>
            <Badge tone="success">Verified seller</Badge>
            {product.game && <Badge tone="game">Game Win live</Badge>}
          </div>
          <p className="pdp-brand">{product.vendor} <Status ok>Official / verified store</Status></p>
          <h1>{product.name}</h1>
          <div className="pdp-rating-row">
            <Rating value={product.rating} reviews={product.reviews} />
            <span>{soldCount.toLocaleString()} sold</span>
            <button><FaShareAlt /> Share</button>
          </div>
          <div className="pdp-price">
            <strong>Rs. {Number(product.price).toLocaleString()}</strong>
            {product.old > product.price && <del>Rs. {Number(product.old).toLocaleString()}</del>}
            {discount > 0 && <span>Save Rs. {discount.toLocaleString()}</span>}
          </div>
          <div className="pdp-alert-actions">
            <button className={priceAlertActive ? 'active' : ''} onClick={/** Inline callback for this operation. */ () => toggleProductAlert('price_drop')}><FaBell /> {priceAlertActive ? 'Price alert on' : 'Notify price drop'}</button>
            <button className={stockAlertActive ? 'active' : ''} onClick={/** Inline callback for this operation. */ () => toggleProductAlert('back_in_stock')}><FaBell /> {stockAlertActive ? 'Stock alert on' : 'Stock alert'}</button>
          </div>
          <p className="pdp-summary">Authentic product with buyer protection, tracked delivery and seller accountability. Purchase through full payment, installment plan, gifting or eligible Game Win entry.</p>

          {product.game && <Card className="pdp-game-countdown">
            <div>
              <Badge tone="game">Live Game Win</Badge>
              <strong>Winner announcement</strong>
              <small>Your entry remains visible in My Games until the verified result is published.</small>
            </div>
            <div>
              {liveAnnouncementAt ? <Countdown target={liveAnnouncementAt} /> : <small>No server-authorized campaign is open right now.</small>}
              {liveGameCost > 0 && <small>{liveGameCost.toLocaleString()} coins per entry</small>}
            </div>
          </Card>}

          <div className="pdp-option-group">
            <div className="pdp-option-head"><span>Color</span><strong>{color}</strong></div>
            <div className="pdp-chips">{(product.colors || []).map(/** Inline callback for this operation. */ value => <button className={color === value ? 'active' : ''} key={value} onClick={/** Inline callback for this operation. */ () => setColor(value)}>{value}</button>)}</div>
          </div>
          <div className="pdp-option-group">
            <div className="pdp-option-head"><span>Variant / storage</span><strong>{storage}</strong></div>
            <div className="pdp-chips">{(product.variants || []).map(/** Inline callback for this operation. */ value => <button className={storage === value ? 'active' : ''} key={value} onClick={/** Inline callback for this operation. */ () => setStorage(value)}>{value}</button>)}</div>
          </div>

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
                <div className="pdp-stock-row">
                  <div className="qty-control">
                    <button onClick={/** Inline callback for this operation. */ () => setQty(/** Inline callback for this operation. */ current => Math.max(1, current - 1))}><FaMinus /></button>
                    <strong>{qty}</strong>
                    <button onClick={/** Inline callback for this operation. */ () => setQty(/** Inline callback for this operation. */ current => Math.min(10, current + 1))}><FaPlus /></button>
                  </div>
                  <Status ok={product.stock > 0}>{product.stock > 0 ? `In stock · ${Number(product.stock).toLocaleString()} available` : 'Currently unavailable'}</Status>
                </div>
                <div className="pdp-primary-actions">
                  <Button onClick={/** Inline callback for this operation. */ () => addCart(true)} disabled={product.stock <= 0}><FaBolt /> Buy now</Button>
                  <Button variant="secondary" onClick={/** Inline callback for this operation. */ () => addCart(false)} disabled={product.stock <= 0}>Add to cart</Button>
                </div>
              </div>}

              {tab === 'install' && product.installment && <div className="pdp-installment">
                <div className="pdp-option-head"><span>Installment checkout</span><strong>Provider-authorized</strong></div>
                <p>Installment availability, identity requirements, repayment term and final charges are confirmed by the enabled payment provider during secure checkout.</p>
                <Button variant="success" onClick={/** Inline callback for this operation. */ () => addCart(true)}><FaCreditCard /> Continue to secure checkout</Button>
              </div>}

              {tab === 'game' && product.game && <div className="pdp-game-tab">
                <div className="pdp-game-icon"><FaGamepad /></div>
                <h3>{openLaravelGame ? `Win this product for ${liveGameCost} coins` : 'Game Win currently unavailable'}</h3>
                <p>One verified winner receives the product. Your click confirms the published Game Win rules version; Laravel records the consent, entry ledger and immutable draw audit.</p>
                {liveAnnouncementAt ? <Countdown target={liveAnnouncementAt} /> : <p>No server-authorized campaign is open right now.</p>}
                {openLaravelGame && <p><small>Commitment: {openLaravelGame.commitmentHash.slice(0, 16)}… · {openLaravelGame.totalEntries.toLocaleString()} entries</small></p>}
                <div className="pdp-game-modes"><span>Audited Draw</span><span>Immutable Entry</span><span>Server Time</span><span>Coin Ledger</span></div>
                <p className="pdp-balance">Your balance: <b>{visibleCoinBalance.toLocaleString()} coins</b></p>
                {!openLaravelGame
                  ? <Button variant="secondary" disabled>Entries unavailable</Button>
                  : <Button variant="game" onClick={joinGame}><FaGamepad /> Join game — {liveGameCost} coins</Button>}
              </div>}

              {tab === 'gift' && <div className="pdp-gift-tab">
                <p>Send this product to another VSN user and grow your Gift Sender level.</p>
                <Field label="Recipient" value={recipient} onChange={/** Inline callback for this operation. */ event => setRecipient(event.target.value)} placeholder="Email or verified phone" />
                <label className="ui-field"><span>Gift message</span><textarea value={giftMessage} onChange={/** Inline callback for this operation. */ event => setGiftMessage(event.target.value)} placeholder="Write a personal message" /></label>
                <div className="pdp-gift-options">
                  <label><input type="checkbox" checked={giftWrap} onChange={/** Inline callback for this operation. */ event => setGiftWrap(event.target.checked)} /><span><FaGift /> Gift wrap <small>{freeGiftWrapReward ? 'Free reward available' : 'Calculated at checkout'}</small></span></label>
                  <label><input type="checkbox" checked={anonymous} onChange={/** Inline callback for this operation. */ event => setAnonymous(event.target.checked)} /><span><FaShieldAlt /> Stay anonymous</span></label>
                </div>
                <Field label="Schedule delivery (optional)" type="datetime-local" value={schedule} onChange={/** Inline callback for this operation. */ event => setSchedule(event.target.value)} />
                {giftSavedMethods.length > 0 && <Select label="Saved card for gift" value={giftSavedMethodId} onChange={/** Inline callback for this operation. */ event => setGiftSavedMethodId(event.target.value)}>
                  <option value="">Provider checkout (no saved token)</option>
                  {giftSavedMethods.map(/** Inline callback for this operation. */ method => <option key={method.id} value={method.id}>{(method.brand || 'Card').toUpperCase()} •••• {method.last4}{method.default ? ' · Default' : ''}</option>)}
                </Select>}
                <div className="pdp-primary-actions">
                  <Button variant="gift" onClick={/** Inline callback for this operation. */ () => sendGift(false)}><FaGift /> Continue gift checkout</Button>
                  <Button variant="coin" onClick={/** Inline callback for this operation. */ () => sendGift(true)}>Pay with VSN Coins</Button>
                </div>
              </div>}
            </div>
          </section>

          {notice && <div className="pdp-notice" role="status">{notice}</div>}

          <div className="pdp-trust-grid">
            <div><FaTruck /><span><b>Tracked delivery</b><small>Live courier status</small></span></div>
            <div><FaUndo /><span><b>Easy returns</b><small>30-day policy</small></span></div>
            <div><FaShieldAlt /><span><b>Buyer protection</b><small>Secure payments</small></span></div>
            <div><FaBoxOpen /><span><b>Authentic product</b><small>Verified seller</small></span></div>
          </div>
        </div>
      </section>

      <section className="pdp-lower">
        <div className="pdp-specs">
          <SectionHeader title="Product overview" sub="Key buying information in one place" />
          <Card><div className="pdp-spec-grid">
            <div><span>Seller</span><strong>{product.vendor}</strong></div>
            <div><span>Category</span><strong>{product.category}</strong></div>
            <div><span>Warranty</span><strong>1 year seller / brand warranty</strong></div>
            <div><span>Returns</span><strong>30 days</strong></div>
            <div><span>Delivery</span><strong>Tracked nationwide</strong></div>
            <div><span>Payment</span><strong>Full{product.installment ? ' · EMI' : ''} · Gift{product.game ? ' · Game' : ''}</strong></div>
          </div></Card>
        </div>

        <div className="pdp-reviews">
          <SectionHeader title="Customer reviews" sub={`${Number(liveReviewCount).toLocaleString()} verified ratings and reviews`} />
          <div className="pdp-review-layout">
            <Card className="pdp-review-summary">
              <strong>{liveReviewRating}</strong>
              <Rating value={Number(liveReviewRating)} reviews={liveReviewCount} />
              <p>Verified purchase feedback</p>
              {reviewEligible && <div className="pdp-review-reward"><FaTag /><span><b>VSNREV-•••••••••• · 10% coupon waiting</b><small>Submit your verified review to unlock the real code.</small></span></div>}
            </Card>

            <div className="pdp-review-list">
              {reviewEligible && <Card className="pdp-write-review">
                <div className="review-form-head">
                  <div><Badge tone="success">Verified purchase</Badge><h3>Review this product</h3><p>Help other buyers and unlock a one-time 10% coupon for your next order.</p></div>
                  <FaStar />
                </div>
                <form onSubmit={submitProductReview}>
                  <fieldset className="review-stars">
                    <legend>Your rating</legend>
                    {[1, 2, 3, 4, 5].map(/** Inline callback for this operation. */ value => <button type="button" key={value} className={reviewRating >= value ? 'active' : ''} onClick={/** Inline callback for this operation. */ () => setReviewRating(value)} aria-label={`${value} star rating`}><FaStar /></button>)}
                  </fieldset>
                  <label className="ui-field"><span>Your review</span><textarea value={reviewText} onChange={/** Inline callback for this operation. */ event => setReviewText(event.target.value)} placeholder="What did you like? Mention quality, delivery, packaging or seller experience." minLength="10" required /></label>
                  <label className="review-upload"><input type="file" accept="image/*" multiple onChange={/** Inline callback for this operation. */ event => handleReviewImages(event.target.files)} /><FaCamera /><span><b>Add photos</b><small>Up to 4 images · JPG, PNG or WebP</small></span></label>
                  {reviewImages.length > 0 && <div className="review-photo-strip">{reviewImages.map(/** Inline callback for this operation. */ (image, index) => <div className="review-photo-preview" key={image.url}><SafeImage src={image.url} alt={image.name} /><button type="button" onClick={/** Inline callback for this operation. */ () => setReviewImages(/** Inline callback for this operation. */ current => current.filter(/** Inline callback for this operation. */ (_, itemIndex) => itemIndex !== index))}>×</button></div>)}</div>}
                  <div className="review-form-actions"><Button type="submit">Submit review & unlock 10% coupon</Button><small>Reward applies once per eligible delivered order item.</small></div>
                </form>
                {reviewResult && <div className={`review-result ${reviewResult.ok ? 'is-success' : 'is-error'}`}>{reviewResult.msg}</div>}
              </Card>}

              {submittedProductReviews.map(/** Inline callback for this operation. */ review => <Card key={review.id}>
                <div className="pdp-review-head"><span className="review-avatar">Y</span><div><b>You</b><Rating value={review.rating} /><small>{new Date(review.submittedAt || review.createdAt).toLocaleDateString()} · Verified purchase</small></div></div>
                <p>{review.text}</p>
                {review.images?.length > 0 && <div className="review-photo-strip">{review.images.map(/** Inline callback for this operation. */ (image, index) => <SafeImage key={image.id || index} src={image.url || image} alt={`Your review upload ${index + 1}`} />)}</div>}
                <div className="review-earned"><FaTag /> Coupon earned: <b>{review.coupon?.code || review.couponCode}</b></div>
              </Card>)}

              {visiblePublicReviews.map(/** Inline callback for this operation. */ (review, index) => {
                const name = review.user?.name || review.name || 'Verified buyer';
                return <Card key={review.id || `${name}-${index}`}>
                  <div className="pdp-review-head">
                    <span className={`review-avatar avatar-${index + 1}`}>{name[0]}</span>
                    <div><b>{name}</b><Rating value={review.rating} /><small>{review.submittedAt ? new Date(review.submittedAt).toLocaleDateString() : review.date} · Verified purchase</small></div>
                  </div>
                  <p>{review.text}</p>
                  {review.images?.length > 0 && <div className="review-photo-strip">{review.images.map(/** Inline callback for this operation. */ (image, imageIndex) => <SafeImage key={image.id || imageIndex} src={image.url || image} alt={`Review upload ${imageIndex + 1}`} />)}</div>}
                  {review.sellerReply && <div className="review-seller-reply"><b>{review.sellerReply.sellerName || 'Seller'} replied</b><p>{review.sellerReply.text}</p></div>}
                  <div className="review-engagement-actions">
                    <button className={review.helpfulByMe ? 'active' : ''} onClick={/** Inline callback for this operation. */ () => helpfulReview(review)}><FaThumbsUp /> Helpful ({review.helpfulCount || 0})</button>
                    <button onClick={/** Inline callback for this operation. */ () => reportReview(review)}><FaFlag /> Report</button>
                  </div>
                </Card>;
              })}
            </div>
          </div>
        </div>
      </section>
    </main>
  </>;
}
