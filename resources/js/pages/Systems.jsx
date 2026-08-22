import { useEffect, useMemo, useRef, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { apiBackend, apiDelete, apiGet, apiPost, apiPut, apiUrl } from "../platform/api";
import { moneyFromMinor, useCart } from "../platform/cart";
import { useLaravelGifts } from "../platform/gifts";
import { useLaravelWallet } from "../platform/wallet";
import SEO from "../components/SEO";
import StripePaymentElement from "../components/StripePaymentElement";
import {
	Badge,
	Button,
	Card,
	Field,
	SafeImage,
	SectionHeader,
	Status,
	Select,
} from "../components/Toolkit";
import { useStore } from "../platform/store";
import { useAuth } from "../platform/auth";
import { products } from "../data/catalog";
import {
	FaBell,
	FaBox,
	FaCheckCircle,
	FaComments,
	FaCog,
	FaCreditCard,
	FaGift,
	FaHeadset,
	FaIdCard,
	FaMoneyBillWave,
	FaPlus,
	FaStore,
	FaTruck,
	FaUsers,
	FaWallet,
	FaUndo,
	FaShieldAlt,
	FaTag,
	FaStar,
} from "react-icons/fa";

/** Handles orders for the VSN Ecommerce interface. */
export function Orders() {
	if (apiBackend !== "laravel") return <LegacyOrders />;
	return <LaravelOrders />;
}

/** Handles laravel orders for the VSN Ecommerce interface. */
function LaravelOrders() {
	const [orders, setOrders] = useState([]);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState("");

	useEffect(/** Inline callback for this operation. */ () => {
		let live = true;
		apiGet("/orders")
			.then(/** Inline callback for this operation. */ (data) => live && setOrders(Array.isArray(data) ? data : []))
			.catch(/** Inline callback for this operation. */ (err) => live && setError(err.message || "Orders could not be loaded."))
			.finally(/** Inline callback for this operation. */ () => live && setLoading(false));
		return /** Inline callback for this operation. */ () => { live = false; };
	}, []);

	return (
		<Page title="My orders" sub="Laravel orders created from reserved inventory and seller sub-orders.">
			{error && <Status>{error}</Status>}
			<div className="order-list">
				{loading ? <Card><p>Loading orders…</p></Card> : orders.length ? orders.map(/** Inline callback for this operation. */ (order) => (
					<Card key={order.id} className="order-card">
						<div>
							<Badge tone={order.status === "delivered" ? "success" : "primary"}>{order.status}</Badge>
							<h3>{order.id}</h3>
							<p>{order.items?.length || 0} items · Rs. {moneyFromMinor(order.totals?.totalMinor).toLocaleString()}</p>
						</div>
						<div>
							<small>{order.sellerOrders?.length || 0} seller order{order.sellerOrders?.length === 1 ? "" : "s"}</small>
							<strong>{order.paymentMethod === "cod" ? "Cash on delivery" : order.paymentStatus}</strong>
							<small>{order.placedAt ? new Date(order.placedAt).toLocaleString() : ""}</small>
							<Link to="/invoices">Tax invoices</Link>
                            {order.shipments?.length > 0 && <Link to={`/tracking?order=${encodeURIComponent(order.id)}`}>Track {order.shipments.length} shipment{order.shipments.length === 1 ? "" : "s"}</Link>}
							{order.sellerOrders?.map(/** Inline callback for this operation. */ (sellerOrder) => <Link key={sellerOrder.id} to={`/messages?vendorOrder=${encodeURIComponent(sellerOrder.id)}`}>Message {sellerOrder.seller}</Link>)}
						</div>
					</Card>
				)) : <Card><p>No Laravel orders yet.</p><Link to="/">Continue shopping</Link></Card>}
			</div>
		</Page>
	);
}

/** Handles legacy orders for the VSN Ecommerce interface. */
function LegacyOrders() {
	const s = useStore();
	return (
		<Page title="My orders" sub="Track purchases, delivery and returns.">
			<div className="order-list">
				{s.orders.map(/** Inline callback for this operation. */ (o) => (
					<Card key={o.id} className="order-card">
						<div>
							<Badge tone={o.status === "Delivered" ? "success" : "primary"}>
								{o.status}
							</Badge>
							<h3>{o.id}</h3>
							<p>
								{o.items} items · Rs. {o.total.toLocaleString()}
							</p>
						</div>
						<div>
							<small>Expected</small>
							<strong>{o.eta}</strong>
							<Link to="/tracking">Track order</Link>
						</div>
					</Card>
				))}
			</div>
		</Page>
	);
}
/** Handles checkout for the VSN Ecommerce interface. */
export function Checkout() {
	if (apiBackend !== "laravel") return <LegacyCheckout />;
	return <LaravelCheckout />;
}

/** Handles laravel checkout for the VSN Ecommerce interface. */
function LaravelCheckout() {
	const { cart, refresh: refreshCart } = useCart();
	const [step, setStep] = useState(1);
	const [addresses, setAddresses] = useState([]);
	const [addressId, setAddressId] = useState("");
	const [options, setOptions] = useState({ shippingQuotes: [], paymentMethods: [], savedPaymentMethods: [] });
	const [savedPaymentMethodId, setSavedPaymentMethodId] = useState("");
	const [wallet, setWallet] = useState(null);
	const [coinRedemption, setCoinRedemption] = useState(0);
	const [shipping, setShipping] = useState("");
	const [payment, setPayment] = useState("cod");
	const [paymentIntent, setPaymentIntent] = useState(null);
	const [paymentKey, setPaymentKey] = useState(/** Inline callback for this operation. */ () => `payment-${Date.now()}-${Math.random().toString(36).slice(2)}`);
	const [couponCode, setCouponCode] = useState("");
	const [attemptKey, setAttemptKey] = useState(/** Inline callback for this operation. */ () => `checkout-${Date.now()}-${Math.random().toString(36).slice(2)}`);
	const [session, setSession] = useState(null);
	const [order, setOrder] = useState(null);
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState("");
	const [loading, setLoading] = useState(true);
	const resumeRef = useRef(false);

	useEffect(/** Inline callback for this operation. */ () => {
		let live = true;
		apiGet("/checkout/current").then(/** Inline callback for this operation. */ (current) => {
			if (!live || !current?.id) return;
			resumeRef.current = true;
			setSession(current);
			setAddressId(String(current.addressId || ""));
			setShipping(current.shippingMethod || "");
			setPayment(current.paymentMethod || "cod");
			setCouponCode(current.couponCode || "");
			setCoinRedemption(current.totals?.coinRedemptionCoins || 0);
			setSavedPaymentMethodId(current.savedPaymentMethod?.id || "");
			setPaymentIntent(current.activePaymentIntent || null);
			setStep(4);
		}).catch(/** Inline callback for this operation. */ () => {});
		return /** Inline callback for this operation. */ () => { live = false; };
	}, []);

	useEffect(/** Inline callback for this operation. */ () => {
		let live = true;
		apiGet("/wallet").then(/** Inline callback for this operation. */ (next) => live && setWallet(next)).catch(/** Inline callback for this operation. */ () => {});
		return /** Inline callback for this operation. */ () => { live = false; };
	}, []);

	useEffect(/** Inline callback for this operation. */ () => {
		let live = true;
		apiGet("/addresses")
			.then(/** Inline callback for this operation. */ (rows) => {
				if (!live) return;
				const list = Array.isArray(rows) ? rows : [];
				setAddresses(list);
				const preferred = list.find(/** Inline callback for this operation. */ (row) => row.is_default) || list[0];
				if (preferred && !resumeRef.current) setAddressId(String(preferred.id));
				setError("");
			})
			.catch(/** Inline callback for this operation. */ (err) => live && setError(err.message || "Sign in to continue to checkout."))
			.finally(/** Inline callback for this operation. */ () => live && setLoading(false));
		return /** Inline callback for this operation. */ () => { live = false; };
	}, []);

	useEffect(/** Inline callback for this operation. */ () => {
		if (!addressId) {
			setOptions({ shippingQuotes: [], paymentMethods: [], savedPaymentMethods: [] });
			return;
		}
		let live = true;
		apiGet(`/checkout/options?addressId=${encodeURIComponent(addressId)}`)
			.then(/** Inline callback for this operation. */ (next) => {
				if (!live) return;
				setOptions(next || { shippingQuotes: [], paymentMethods: [], savedPaymentMethods: [] });
				const preferredSaved = next?.savedPaymentMethods?.find(/** Inline callback for this operation. */ (m) => m.default) || next?.savedPaymentMethods?.[0];
				setSavedPaymentMethodId(preferredSaved?.id || "");
				const firstShipping = next?.shippingQuotes?.[0]?.code || "";
				setShipping(/** Inline callback for this operation. */ (current) => next?.shippingQuotes?.some(/** Inline callback for this operation. */ (q) => q.code === current) ? current : firstShipping);
				const enabledPayment = next?.paymentMethods?.find(/** Inline callback for this operation. */ (method) => method.enabled)?.code || "cod";
				setPayment(/** Inline callback for this operation. */ (current) => next?.paymentMethods?.some(/** Inline callback for this operation. */ (method) => method.code === current && method.enabled) ? current : enabledPayment);
				if (resumeRef.current) { resumeRef.current = false; return; }
				setSession(null);
				setPaymentIntent(null);
				setPaymentKey(`payment-${Date.now()}-${Math.random().toString(36).slice(2)}`);
			})
			.catch(/** Inline callback for this operation. */ (err) => live && setError(err.message))
		return /** Inline callback for this operation. */ () => { live = false; };
	}, [addressId]);

	const selectedQuote = options.shippingQuotes?.find(/** Inline callback for this operation. */ (quote) => quote.code === shipping);
	const estimatedSubtotal = cart?.summary?.subtotalMinor || 0;
	const estimatedShipping = selectedQuote?.amountMinor || 0;
	const estimatedTotal = estimatedSubtotal + estimatedShipping;
	const coinsPerRupee = wallet?.coinsPerRupee || 70;
	const availableRedeemableCoins = Math.floor((wallet?.availableCoins || 0) / coinsPerRupee) * coinsPerRupee;
	const maxRedeemableCoins = Math.min(availableRedeemableCoins, Math.floor(estimatedTotal / 100) * coinsPerRupee);

	const reserveCheckout = /** Handles reserve checkout for the VSN Ecommerce interface. */ async () => {
		if (!addressId || !shipping || !payment) return;
		setBusy(true);
		setError("");
		try {
			const next = await apiPost("/checkout/sessions", {
				addressId: Number(addressId),
				shippingMethod: shipping,
				paymentMethod: payment,
				savedPaymentMethodId: payment === "card" ? (savedPaymentMethodId || null) : null,
				couponCode: couponCode.trim() || null,
				coinRedemptionCoins: Number(coinRedemption) || 0,
				idempotencyKey: attemptKey,
			});
			setSession(next);
			apiGet("/wallet").then(setWallet).catch(/** Inline callback for this operation. */ () => {});
			setPaymentIntent(null);
			setPaymentKey(`payment-${Date.now()}-${Math.random().toString(36).slice(2)}`);
			setStep(4);
		} catch (err) {
			setError(err.message || "Checkout could not reserve inventory.");
		} finally {
			setBusy(false);
		}
	};

	const placeOrder = /** Handles place order for the VSN Ecommerce interface. */ async () => {
		if (!session?.id) return;
		setBusy(true);
		setError("");
		try {
			if (payment !== "cod" && payment !== "coins") {
				const intent = await apiPost(`/checkout/sessions/${session.id}/payments`, { idempotencyKey: paymentKey });
				setPaymentIntent(intent);
				return;
			}
			const placed = await apiPost(`/checkout/sessions/${session.id}/order`, {});
			setOrder(placed);
			await refreshCart().catch(/** Inline callback for this operation. */ () => {});
			await apiGet("/wallet").then(setWallet).catch(/** Inline callback for this operation. */ () => {});
		} catch (err) {
			setError(err.message || "Order could not be placed.");
		} finally {
			setBusy(false);
		}
	};

	const refreshPayment = /** Handles refresh payment for the VSN Ecommerce interface. */ async () => {
		if (!paymentIntent?.id) return;
		setBusy(true);
		setError("");
		try {
			const current = await apiPost(`/payments/${paymentIntent.id}/refresh-provider`, {});
			setPaymentIntent(current);
			if (current.orderId) {
				const placed = await apiGet(`/orders/${current.orderId}`);
				setOrder(placed);
				await refreshCart().catch(/** Inline callback for this operation. */ () => {});
				await apiGet("/wallet").then(setWallet).catch(/** Inline callback for this operation. */ () => {});
			}
		} catch (err) {
			setError(err.message || "Payment status could not be refreshed.");
		} finally {
			setBusy(false);
		}
	};

	const simulateSandboxPayment = /** Handles simulate sandbox payment for the VSN Ecommerce interface. */ async () => {
		if (!paymentIntent?.id) return;
		setBusy(true);
		setError("");
		try {
			const current = await apiPost(`/payments/${paymentIntent.id}/sandbox/complete`, {});
			setPaymentIntent(current);
			if (current.orderId) {
				const placed = await apiGet(`/orders/${current.orderId}`);
				setOrder(placed);
				await refreshCart().catch(/** Inline callback for this operation. */ () => {});
				await apiGet("/wallet").then(setWallet).catch(/** Inline callback for this operation. */ () => {});
			}
		} catch (err) {
			setError(err.message || "Sandbox payment could not be completed.");
		} finally {
			setBusy(false);
		}
	};

	const cancelReservation = /** Handles cancel reservation for the VSN Ecommerce interface. */ async () => {
		if (!session?.id) return;
		setBusy(true);
		try {
			await apiDelete(`/checkout/sessions/${session.id}`);
			await apiGet("/wallet").then(setWallet).catch(/** Inline callback for this operation. */ () => {});
			setSession(null);
			setPaymentIntent(null);
			setPaymentKey(`payment-${Date.now()}-${Math.random().toString(36).slice(2)}`);
			setAttemptKey(`checkout-${Date.now()}-${Math.random().toString(36).slice(2)}`);
			setStep(1);
		} catch (err) {
			setError(err.message || "Reservation could not be released.");
		} finally {
			setBusy(false);
		}
	};

	if (order) {
		return (
			<Page title="Order confirmed" sub="Your Laravel marketplace order was created and split by seller.">
				<Card className="confirmation-card">
					<FaCheckCircle />
					<h2>Thank you for your order</h2>
					<p>Order <b>{order.id}</b> is confirmed. Inventory reservations were converted into stock movements atomically.</p>
					<div className="simple-list">
						{(order.sellerOrders || []).map(/** Inline callback for this operation. */ (sellerOrder) => (
							<div key={sellerOrder.id}>
								<FaStore />
								<span className="order-sub"><b>{sellerOrder.id}</b><small>{sellerOrder.seller}</small></span>
								<Badge tone="primary">{sellerOrder.status}</Badge>
							</div>
						))}
					</div>
					{order.totals?.coinRedemptionCoins > 0 && <p><span>VSN Coins used</span><b>{order.totals.coinRedemptionCoins.toLocaleString()}</b></p>}<p className="summary-total"><span>Cash / provider amount</span><b>Rs. {moneyFromMinor(order.totals?.totalMinor).toLocaleString()}</b></p>
					<Link className="ui-btn ui-btn--primary" to="/account/orders">View orders</Link>
				</Card>
			</Page>
		);
	}

	if (!loading && !cart?.items?.length) {
		return <Page title="Checkout" sub="Your cart has no purchasable items."><Card><p>Your cart is empty.</p><Link className="ui-btn ui-btn--primary" to="/">Continue shopping</Link></Card></Page>;
	}

	return (
		<Page title="Checkout" sub="Laravel checkout with server pricing, signed payment webhooks, seller-aware shipping and inventory reservation.">
			<div className="checkout-layout">
				<div>
					<div className="checkout-steps">
						<span className={step >= 1 ? "active" : ""}>1 Address</span>
						<span className={step >= 2 ? "active" : ""}>2 Delivery</span>
						<span className={step >= 3 ? "active" : ""}>3 Payment</span>
						<span className={step >= 4 ? "active" : ""}>4 Review</span>
					</div>
					{error && <Status>{error}</Status>}

					{step === 1 && <Card>
						<h2>Delivery address</h2>
						{loading ? <p>Loading addresses…</p> : addresses.length ? addresses.map(/** Inline callback for this operation. */ (address) => (
							<label className="choice-row" key={address.id}>
								<input type="radio" name="address" checked={String(address.id) === addressId} onChange={/** Inline callback for this operation. */ () => { setAddressId(String(address.id)); setAttemptKey(`checkout-${Date.now()}-${Math.random().toString(36).slice(2)}`); }} />
								<span><b>{address.label || address.recipient_name}</b><small>{[address.line1, address.line2, address.city, address.state].filter(Boolean).join(", ")}</small></span>
							</label>
						)) : <p>No Laravel address found. <Link to="/profile">Add an address in your profile</Link> or <Link to="/login">sign in</Link>.</p>}
						<Button disabled={!addressId} onClick={/** Inline callback for this operation. */ () => setStep(2)}>Continue</Button>
					</Card>}

					{step === 2 && <Card>
						<h2>Delivery option</h2>
						{(options.shippingQuotes || []).map(/** Inline callback for this operation. */ (quote) => (
							<label className="choice-row" key={quote.code}>
								<input type="radio" name="ship" checked={shipping === quote.code} onChange={/** Inline callback for this operation. */ () => { setShipping(quote.code); setSession(null); setAttemptKey(`checkout-${Date.now()}-${Math.random().toString(36).slice(2)}`); }} />
								<span><b>{quote.name}</b><small>{quote.eta} · {quote.vendorCount} seller shipment{quote.vendorCount === 1 ? "" : "s"}</small></span>
								<strong>Rs. {moneyFromMinor(quote.amountMinor).toLocaleString()}</strong>
							</label>
						))}
						<Button variant="secondary" onClick={/** Inline callback for this operation. */ () => setStep(1)}>Back</Button>{" "}
						<Button disabled={!shipping} onClick={/** Inline callback for this operation. */ () => setStep(3)}>Continue</Button>
					</Card>}

					{step === 3 && <Card>
						<h2>Payment & adjustments</h2>
						{(options.paymentMethods || []).map(/** Inline callback for this operation. */ (method) => (
							<label className={`choice-row ${method.enabled ? "" : "is-disabled"}`} key={method.code}>
								<input type="radio" name="pay" disabled={!method.enabled} checked={payment === method.code} onChange={/** Inline callback for this operation. */ () => { if (method.enabled) { setPayment(method.code); if (method.code === "coins") setCoinRedemption(maxRedeemableCoins); setSession(null); setPaymentIntent(null); setPaymentKey(`payment-${Date.now()}-${Math.random().toString(36).slice(2)}`); setAttemptKey(`checkout-${Date.now()}-${Math.random().toString(36).slice(2)}`); } }} />
								<span><b>{method.name}</b><small>{method.description}</small></span>
							</label>
						))}

						{payment === "card" && <div>
							<h3>Saved payment method</h3>
							{(options.savedPaymentMethods || []).length ? (options.savedPaymentMethods || []).map(/** Inline callback for this operation. */ (method) => <label className="choice-row" key={method.id}>
								<input type="radio" name="saved-card" checked={savedPaymentMethodId === method.id} onChange={/** Inline callback for this operation. */ () => { setSavedPaymentMethodId(method.id); setSession(null); setPaymentIntent(null); setAttemptKey(`checkout-${Date.now()}-${Math.random().toString(36).slice(2)}`); }} />
								<span><b>{(method.brand || "Card").toUpperCase()} •••• {method.last4}</b><small>{method.default ? "Default · " : ""}{method.provider} · expires {String(method.expiry?.month || "--").padStart(2,"0")}/{method.expiry?.year || "----"}</small></span>
							</label>) : <p>No saved card yet. You can still start a provider payment, or <Link to="/profile">add a tokenized method in Profile</Link>.</p>}
						</div>}
						<Field label="Coupon code" value={couponCode} onChange={/** Inline callback for this operation. */ (event) => { setCouponCode(event.target.value); setSession(null); setAttemptKey(`checkout-${Date.now()}-${Math.random().toString(36).slice(2)}`); }} placeholder="Optional" help="Enter a verified-review coupon or marketplace promotion code. Automatic deals are applied server-side too; stacking and usage limits are revalidated when stock is reserved." />
						<Field label="VSN Coins to use" type="number" min="0" step={coinsPerRupee} value={coinRedemption} onChange={/** Inline callback for this operation. */ (event) => { const value = Math.max(0, Math.min(maxRedeemableCoins, Number(event.target.value) || 0)); setCoinRedemption(value); setSession(null); setAttemptKey(`checkout-${Date.now()}-${Math.random().toString(36).slice(2)}`); }} help={`${(wallet?.availableCoins || 0).toLocaleString()} available · use increments of ${coinsPerRupee} · max ${maxRedeemableCoins.toLocaleString()} for this checkout`} />
						{maxRedeemableCoins > 0 && <Button variant="secondary" onClick={/** Inline callback for this operation. */ () => setCoinRedemption(maxRedeemableCoins)}>Use maximum coins</Button>}
						<Button variant="secondary" onClick={/** Inline callback for this operation. */ () => setStep(2)}>Back</Button>{" "}
						<Button disabled={busy || !payment} onClick={reserveCheckout}>{busy ? "Reserving…" : "Reserve stock & review"}</Button>
					</Card>}

					{step === 4 && session && <Card>
						<h2>Review marketplace order</h2>
						<Status ok>Stock reserved until {new Date(session.expiresAt).toLocaleTimeString()}.</Status>
						<div className="simple-list">
							{(session.items || []).map(/** Inline callback for this operation. */ (item) => (
								<div key={item.id}><FaStore /><span><b>{item.productName}</b><small>{item.vendor || "Marketplace"} · {item.variantName} · Qty {item.quantity}</small></span><strong>Rs. {moneyFromMinor(item.lineTotalMinor).toLocaleString()}</strong></div>
							))}
						</div>
						{session.savedPaymentMethod && <p><b>Saved card:</b> {(session.savedPaymentMethod.brand || "Card").toUpperCase()} •••• {session.savedPaymentMethod.last4}</p>}
						{paymentIntent && <div className="simple-list">
							<div><FaCreditCard /><span><b>Payment {paymentIntent.status}</b><small>{paymentIntent.provider} · {paymentIntent.id}</small></span><strong>Rs. {moneyFromMinor(paymentIntent.amountMinor).toLocaleString()}</strong></div>
						</div>}
						<Button variant="secondary" disabled={busy} onClick={cancelReservation}>Change checkout</Button>{" "}
						{(payment === "cod" || payment === "coins") && <Button disabled={busy || !session.canPlaceOrder} onClick={placeOrder}>{busy ? "Placing…" : "Place order"}</Button>}
						{payment !== "cod" && payment !== "coins" && !paymentIntent && <Button disabled={busy || !session.canPlaceOrder} onClick={placeOrder}>{busy ? "Starting…" : "Start secure payment"}</Button>}
						{paymentIntent && !paymentIntent.orderId && <Button variant="secondary" disabled={busy} onClick={refreshPayment}>{busy ? "Checking…" : "Refresh payment"}</Button>}
						{paymentIntent?.sandboxCanSimulate && !paymentIntent.orderId && <>{" "}<Button disabled={busy} onClick={simulateSandboxPayment}>{busy ? "Processing…" : "Simulate signed sandbox payment"}</Button></>}
						{paymentIntent?.provider === "stripe" && paymentIntent?.clientAction?.type === "stripe_payment_intent" && !paymentIntent.orderId && <StripePaymentElement clientAction={paymentIntent.clientAction} savedMethod={Boolean(paymentIntent.savedPaymentMethod)} onSubmitted={/** Inline callback for this operation. */ async () => { await refreshPayment(); }} />}
						{paymentIntent?.status === "failed" && <>{" "}<Button variant="secondary" disabled={busy} onClick={/** Inline callback for this operation. */ async()=>{setBusy(true);setError("");try{if(paymentIntent.canRetryInitialization){setPaymentIntent(await apiPost(`/payments/${paymentIntent.id}/retry-initialization`,{}));}else{setPaymentIntent(null);setPaymentKey(`payment-${Date.now()}-${Math.random().toString(36).slice(2)}`);}}catch(e){setError(e.message)}finally{setBusy(false)}}}>Retry payment safely</Button></>}
					</Card>}
				</div>

				<Card className="order-summary">
					<h3>Order summary</h3>
					<p><span>Items</span><b>Rs. {moneyFromMinor(session?.totals?.subtotalMinor ?? estimatedSubtotal).toLocaleString()}</b></p>
					<p><span>Delivery</span><b>Rs. {moneyFromMinor(session?.totals?.shippingMinor ?? estimatedShipping).toLocaleString()}</b></p>
					{session?.totals?.discountMinor > 0 && <><p><span>Discount</span><b>- Rs. {moneyFromMinor(session.totals.discountMinor).toLocaleString()}</b></p>{(session.promotions||[]).map(/** Inline callback for this operation. */ (promo,i)=><p key={`${promo.id||promo.code||promo.name}-${i}`}><span><small>{promo.name||promo.code||"Promotion"}</small></span><small>- Rs. {moneyFromMinor(promo.discountMinor||0).toLocaleString()}</small></p>)}</>}
					{session?.totals?.taxMinor > 0 && <p><span>Tax {session?.tax?.jurisdiction ? `(${session.tax.jurisdiction})` : ''}</span><b>{session.totals.taxAddedMinor > 0 ? `+ Rs. ${moneyFromMinor(session.totals.taxAddedMinor).toLocaleString()}` : `Rs. ${moneyFromMinor(session.totals.taxMinor).toLocaleString()} included`}</b></p>}
                    {session?.totals?.coinRedemptionCoins > 0 && <p><span>VSN Coins</span><b>- {session.totals.coinRedemptionCoins.toLocaleString()} coins (Rs. {moneyFromMinor(session.totals.coinRedemptionMinor).toLocaleString()})</b></p>}
					<hr />
					<p className="summary-total"><span>Total</span><b>Rs. {moneyFromMinor(session?.totals?.totalMinor ?? estimatedTotal).toLocaleString()}</b></p>
					<small>Prices and totals shown after reservation are immutable checkout snapshots. Order placement never trusts a frontend total.</small>
				</Card>
			</div>
		</Page>
	);
}

/** Handles legacy checkout for the VSN Ecommerce interface. */
function LegacyCheckout() {
	const s = useStore();
	const [step, setStep] = useState(1);
	const [placed, setPlaced] = useState(false);
	const [shipping, setShipping] = useState("std");
	const [reservation, setReservation] = useState(null);
	const [msg, setMsg] = useState("");
	const reserve = /** Handles reserve for the VSN Ecommerce interface. */ () => {
		const r = s.reserveInventory(1, 1, 15);
		setMsg(r.msg);
		if (r.ok) {
			setReservation(r);
			setStep(4);
		}
	};
	if (placed)
		return (
			<Page
				title="Order confirmed"
				sub="Your marketplace order was created and will be split by seller automatically."
			>
				<Card className="confirmation-card">
					<FaCheckCircle />
					<h2>Thank you for your order</h2>
					<p>
						Inventory was reserved, the payment request was accepted, and seller
						sub-orders are ready for fulfilment.
					</p>
					<div className="simple-list">
						{s.subOrders.map(/** Inline callback for this operation. */ (o) => (
							<div key={o.id}>
								<FaStore />
								<span className="order-sub">
									<b>{o.id}</b>
									<small>{o.seller}</small>
								</span>
								<Badge tone="primary">{o.status}</Badge>
							</div>
						))}
					</div>
					<Link className="ui-btn ui-btn--primary" to="/orders">
						View orders
					</Link>
				</Card>
			</Page>
		);
	return (
		<Page
			title="Checkout"
			sub="Secure multi-vendor checkout with stock reservation, delivery quotes and payment review."
		>
			<div className="checkout-layout">
				<div>
					<div className="checkout-steps">
						<span className={step >= 1 ? "active" : ""}>1 Address</span>
						<span className={step >= 2 ? "active" : ""}>2 Delivery</span>
						<span className={step >= 3 ? "active" : ""}>3 Payment</span>
						<span className={step >= 4 ? "active" : ""}>4 Review</span>
					</div>
					{step === 1 && (
						<Card>
							<h2>Delivery address</h2>
							{s.profile.addresses.length ? (
								s.profile.addresses.map(/** Inline callback for this operation. */ (a, i) => (
									<label className="choice-row" key={a.id}>
										<input
											type="radio"
											name="address"
											defaultChecked={i === 0}
										/>
										<span>
											<b>{a.label || "Address"}</b>
											<small>{a.line}</small>
										</span>
									</label>
								))
							) : (
								<p>
									No saved address.{" "}
									<Link to="/profile">Add one in profile</Link>.
								</p>
							)}
							<Button onClick={/** Inline callback for this operation. */ () => setStep(2)}>Continue</Button>
						</Card>
					)}
					{step === 2 && (
						<Card>
							<h2>Delivery option</h2>
							{s.shippingQuotes.map(/** Inline callback for this operation. */ (q, i) => (
								<label className="choice-row" key={q.id}>
									<input
										type="radio"
										name="ship"
										checked={shipping === q.id}
										onChange={/** Inline callback for this operation. */ () => setShipping(q.id)}
									/>
									<span>
										<b>{q.name}</b>
										<small>{q.eta}</small>
									</span>
									<strong>Rs. {q.price}</strong>
								</label>
							))}
							<Button onClick={/** Inline callback for this operation. */ () => setStep(3)}>Continue</Button>
						</Card>
					)}
					{step === 3 && (
						<Card>
							<h2>Payment</h2>
							<label className="choice-row">
								<input type="radio" name="pay" defaultChecked />
								<span>
									<b>Saved payment method</b>
									<small>
										Tokenized provider reference; raw card data is never stored
										by VSN.
									</small>
								</span>
							</label>
							<label className="choice-row">
								<input type="radio" name="pay" />
								<span>
									<b>VSN Coins / split payment</b>
									<small>
										{s.coinBalance.toLocaleString()} coins available · server
										validates final payable.
									</small>
								</span>
							</label>
							<label className="choice-row">
								<input type="radio" name="pay" />
								<span>
									<b>Cash on delivery</b>
									<small>
										Available when seller, region and risk rules allow.
									</small>
								</span>
							</label>
							<Button onClick={reserve}>Reserve stock & review</Button>
							{msg && <p className="form-message">{msg}</p>}
						</Card>
					)}
					{step === 4 && (
						<Card>
							<h2>Review marketplace order</h2>
							{reservation && (
								<Status ok>
									Stock reserved until{" "}
									{new Date(reservation.expiresAt).toLocaleTimeString()}
								</Status>
							)}
							<div className="simple-list">
								{s.subOrders.map(/** Inline callback for this operation. */ (o) => (
									<div key={o.id}>
										<FaStore />
										<span>
											<b>{o.seller}</b>
											<small>Separate seller fulfilment</small>
										</span>
										<strong>Rs. {o.total.toLocaleString()}</strong>
									</div>
								))}
							</div>
							<Button onClick={/** Inline callback for this operation. */ () => setPlaced(true)}>Place order</Button>
						</Card>
					)}
				</div>
				<Card className="order-summary">
					<h3>Order summary</h3>
					<p>
						<span>Items</span>
						<b>Rs. 289,999</b>
					</p>
					<p>
						<span>Delivery</span>
						<b>
							Rs. {s.shippingQuotes.find(/** Inline callback for this operation. */ (q) => q.id === shipping)?.price || 0}
						</b>
					</p>
					<hr />
					<p className="summary-total">
						<span>Total</span>
						<b>
							Rs.{" "}
							{(
								289999 +
								(s.shippingQuotes.find(/** Inline callback for this operation. */ (q) => q.id === shipping)?.price || 0)
							).toLocaleString()}
						</b>
					</p>
					<small>
						Final total, tax, coupon, coin redemption and vendor split are
						recalculated server-side.
					</small>
				</Card>
			</div>
		</Page>
	);
}
/** Handles tracking for the VSN Ecommerce interface. */
export function Tracking() {
	if (apiBackend !== "laravel") return <LegacyTracking />;
	return <LaravelTracking />;
}

/** Handles laravel tracking for the VSN Ecommerce interface. */
function LaravelTracking() {
	const [params] = useSearchParams();
	const orderId = params.get("order");
	const [shipments, setShipments] = useState([]);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState("");
	const load = /** Handles load for the VSN Ecommerce interface. */ async () => {
		setLoading(true);
		try {
			const rows = await apiGet("/shipments");
			setShipments((Array.isArray(rows) ? rows : []).filter(/** Inline callback for this operation. */ (row) => !orderId || row.orderId === orderId));
			setError("");
		} catch (err) { setError(err.message || "Shipment tracking could not be loaded."); }
		finally { setLoading(false); }
	};
	useEffect(/** Inline callback for this operation. */ () => { load(); }, [orderId]);
	return (
		<Page title="Track order" sub="Live courier events, seller-wise shipments and delivery SLA status from Laravel.">
			{error && <Status>{error}</Status>}
			{loading ? <Card><p>Loading shipments…</p></Card> : shipments.length ? shipments.map(/** Inline callback for this operation. */ (shipment) => (
				<Card className="tracking-card" key={shipment.id}>
					<div className="tracking-head">
						<div>
							<Badge tone={shipment.status === "delivered" ? "success" : shipment.deliverySlaBreached ? "deal" : "primary"}>{shipment.status.replaceAll("_", " ")}</Badge>
							<h2>{shipment.trackingNumber || shipment.id}</h2>
							<p>{shipment.seller} · {shipment.provider} · {shipment.serviceCode}</p>
							{shipment.estimatedDeliveryAt && <small>Estimated delivery: {new Date(shipment.estimatedDeliveryAt).toLocaleString()}</small>}
						</div>
						<FaTruck />
					</div>
					{(shipment.dispatchSlaBreached || shipment.deliverySlaBreached) && <Status>{shipment.deliverySlaBreached ? "Delivery SLA is overdue." : "Seller dispatch SLA was missed."}</Status>}
					<div className="timeline">
						{(shipment.events || []).length ? shipment.events.map(/** Inline callback for this operation. */ (event, index, rows) => (
							<Step key={event.id} done={index < rows.length - 1 || event.status === "delivered"} active={index === rows.length - 1 && event.status !== "delivered"} title={event.status.replaceAll("_", " ")} text={[event.message, event.location, event.occurredAt ? new Date(event.occurredAt).toLocaleString() : ""].filter(Boolean).join(" · ")} />
						)) : <Step active title={shipment.status.replaceAll("_", " ")} text="Waiting for the next courier scan." />}
					</div>
				</Card>
			)) : <Card><p>{orderId ? "No shipment has been created for this order yet." : "No active or historical shipments yet."}</p><Link to="/orders">View orders</Link></Card>}
		</Page>
	);
}

/** Handles legacy tracking for the VSN Ecommerce interface. */
function LegacyTracking() {
	return (
		<Page title="Track order" sub="Live delivery timeline and carrier status.">
			<Card className="tracking-card"><div className="tracking-head"><div><Badge tone="primary">In transit</Badge><h2>VSN-2026-88142</h2><p>Legacy demo tracking</p></div><FaTruck /></div><div className="timeline"><Step done title="Order confirmed" text="Seller accepted your order" /><Step done title="Packed" text="Package handed to courier" /><Step active title="In transit" text="Distribution hub" /><Step title="Delivered" text="Pending" /></div></Card>
		</Page>
	);
}
/** Handles wallet for the VSN Ecommerce interface. */
export function Wallet() {
	if (apiBackend !== "laravel") return <LegacyWallet />;
	return <LaravelWallet />;
}
/** Handles laravel wallet for the VSN Ecommerce interface. */
function LaravelWallet(){
	const [wallet,setWallet]=useState(null),[methods,setMethods]=useState([]),[error,setError]=useState("");
	useEffect(/** Inline callback for this operation. */ ()=>{Promise.all([apiGet("/wallet"),apiGet("/payment-methods")]).then(/** Inline callback for this operation. */ ([w,p])=>{setWallet(w);setMethods(p.items||[])}).catch(/** Inline callback for this operation. */ e=>setError(e.message));},[]);
	return <Page title="Wallet" sub="Balances, provider-tokenized payment methods and immutable coin activity.">{error&&<Status>{error}</Status>}<div className="metric-grid"><Card><FaWallet/><small>Coin balance</small><strong>{(wallet?.balanceCoins||0).toLocaleString()}</strong><span>Rs. {(wallet?.valueRupees||0).toFixed(2)}</span></Card><Card><FaCreditCard/><small>Saved methods</small><strong>{methods.length}</strong><span>Provider tokens only</span></Card><Card><FaMoneyBillWave/><small>Reserved coins</small><strong>{(wallet?.reservedCoins||0).toLocaleString()}</strong><span>{(wallet?.availableCoins||0).toLocaleString()} available</span></Card></div><Card className="system-section"><SectionHeader title="Saved payment methods"/><div className="simple-list">{methods.length?methods.map(/** Inline callback for this operation. */ m=><div key={m.id}><FaCreditCard/><span><b>{(m.brand||"Card").toUpperCase()} •••• {m.last4}</b><small>{m.provider} · {m.default?"Default":"Saved"}</small></span><Link to="/profile">Manage</Link></div>):<p>No saved payment methods. <Link to="/profile">Manage payment methods</Link>.</p>}</div></Card><Card className="system-section"><SectionHeader title="Recent transactions"/><div className="simple-list">{(wallet?.transactions||[]).slice(0,8).map(/** Inline callback for this operation. */ t=><div key={t.id}><span><b>{t.type||t.referenceType||"Wallet transaction"}</b><small>{t.occurredAt?new Date(t.occurredAt).toLocaleString():""}</small></span><strong>{t.direction === "credit" ? "+" : "-"}{Number(t.coins || 0).toLocaleString()} coins</strong></div>)}</div></Card></Page>;
}
/** Handles legacy wallet for the VSN Ecommerce interface. */
function LegacyWallet(){const s=useStore();return <Page title="Wallet" sub="Legacy local wallet."><div className="metric-grid"><Card><FaWallet/><small>Coin balance</small><strong>{s.coinBalance.toLocaleString()}</strong></Card><Card><FaCreditCard/><small>Saved methods</small><strong>{s.profile.paymentMethods.length}</strong></Card></div></Page>}
/** Handles notifications for the VSN Ecommerce interface. */
export function Notifications() {
	if (apiBackend !== "laravel") return <LegacyNotifications />;
	return <LaravelNotifications />;
}
/** Handles laravel notifications for the VSN Ecommerce interface. */
function LaravelNotifications() {
	const [items, setItems] = useState([]);
	const [meta, setMeta] = useState({ unreadCount: 0 });
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState("");
	const load = /** Handles load for the VSN Ecommerce interface. */ async () => { try { const data = await apiGet("/notifications?perPage=100"); setItems(data.items || []); setMeta(data.meta || {}); setError(""); } catch (e) { setError(e.message || "Notifications unavailable."); } finally { setLoading(false); } };
	useEffect(/** Inline callback for this operation. */ () => { let live = true; const refresh = /** Handles refresh for the VSN Ecommerce interface. */ () => live && load(); refresh(); const id = setInterval(refresh, 15000); return /** Inline callback for this operation. */ () => { live = false; clearInterval(id); }; }, []);
	const markRead = /** Handles mark read for the VSN Ecommerce interface. */ async (item) => { if (item.read) return; try { await apiPost(`/notifications/${item.id}/read`, {}); await load(); } catch (e) { setError(e.message); } };
	const readAll = /** Handles read all for the VSN Ecommerce interface. */ async () => { try { await apiPost("/notifications/read-all", {}); await load(); } catch (e) { setError(e.message); } };
	return <Page title="Notifications" sub="Orders, shipping, games, gifts, reviews, returns and account alerts from Laravel.">
		{error && <Status>{error}</Status>}
		<Card>
			<div className="section-title"><div><h3>{meta.unreadCount || 0} unread</h3><p>Server-authoritative notification history.</p></div><Button disabled={!meta.unreadCount} onClick={readAll}>Mark all read</Button></div>
			<div className="simple-list">
				{loading ? <p>Loading notifications…</p> : items.length ? items.map(/** Inline callback for this operation. */ (n) => <div key={n.id} onClick={/** Inline callback for this operation. */ () => markRead(n)} style={{ cursor: n.read ? "default" : "pointer" }}>
					<FaBell /><span><b>{n.title}</b><small>{n.body}</small><small>{n.createdAt ? new Date(n.createdAt).toLocaleString() : ""} · {n.category}</small>{n.actionUrl && <Link to={n.actionUrl}>View details</Link>}</span><Badge tone={n.read ? "neutral" : "primary"}>{n.read ? "Read" : "New"}</Badge>
				</div>) : <p>No notifications yet.</p>}
			</div>
		</Card>
	</Page>;
}
/** Handles legacy notifications for the VSN Ecommerce interface. */
function LegacyNotifications() {
	const s = useStore();
	return <Page title="Notifications" sub="Orders, games, rewards and account alerts."><Card><div className="simple-list">{s.notifications.map(/** Inline callback for this operation. */ (n) => <div key={n.id}><FaBell /><span><b>{n.title}</b><small>{n.body}</small></span><Badge tone={n.read ? "neutral" : "primary"}>{n.read ? "Read" : "New"}</Badge></div>)}</div></Card></Page>;
}

/** Handles messages for the VSN Ecommerce interface. */
export function Messages() {
	if (apiBackend !== "laravel") return <LegacyMessages />;
	return <LaravelMessages />;
}
/** Handles laravel messages for the VSN Ecommerce interface. */
function LaravelMessages() {
	const [params, setParams] = useSearchParams();
	const [threads, setThreads] = useState([]);
	const [selected, setSelected] = useState(params.get("conversation") || "");
	const [conversation, setConversation] = useState(null);
	const [messages, setMessages] = useState([]);
	const [text, setText] = useState("");
	const [files, setFiles] = useState([]);
	const [error, setError] = useState("");
	const [busy, setBusy] = useState(false);
	const loadThreads = /** Handles load threads for the VSN Ecommerce interface. */ async () => { try { const data = await apiGet("/messages/conversations"); setThreads(data.items || []); if (!selected && data.items?.length) setSelected(data.items[0].id); } catch (e) { setError(e.message || "Messages unavailable."); } };
	const loadConversation = /** Handles load conversation for the VSN Ecommerce interface. */ async (id = selected) => { if (!id) return; try { const data = await apiGet(`/messages/conversations/${id}?perPage=100`); setConversation(data.conversation || null); setMessages(data.messages || []); setError(""); } catch (e) { setError(e.message || "Conversation unavailable."); } };
	useEffect(/** Inline callback for this operation. */ () => { loadThreads(); const id = setInterval(loadThreads, 12000); return /** Inline callback for this operation. */ () => clearInterval(id); }, []);
	useEffect(/** Inline callback for this operation. */ () => { if (!selected) return; setParams({ conversation: selected }, { replace: true }); loadConversation(selected); const id = setInterval(/** Inline callback for this operation. */ () => loadConversation(selected), 8000); return /** Inline callback for this operation. */ () => clearInterval(id); }, [selected]);
	useEffect(/** Inline callback for this operation. */ () => { const vendorOrder = params.get("vendorOrder"); if (!vendorOrder) return; let live = true; apiPost("/messages/conversations", { kind: "order", vendorOrderId: vendorOrder }).then(/** Inline callback for this operation. */ (row) => { if (!live) return; setSelected(row.id); setParams({ conversation: row.id }, { replace: true }); loadThreads(); }).catch(/** Inline callback for this operation. */ (e) => live && setError(e.message)); return /** Inline callback for this operation. */ () => { live = false; }; }, []);
	const startSupport = /** Handles start support for the VSN Ecommerce interface. */ async () => { setBusy(true); try { const row = await apiPost("/messages/conversations", { kind: "support" }); setSelected(row.id); await loadThreads(); } catch (e) { setError(e.message); } finally { setBusy(false); } };
	const send = /** Handles send for the VSN Ecommerce interface. */ async () => { if (!selected || (!text.trim() && !files.length)) return; setBusy(true); setError(""); try { const form = new FormData(); if (text.trim()) form.append("body", text.trim()); form.append("clientId", globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}`); files.forEach(/** Inline callback for this operation. */ (f) => form.append("attachments[]", f)); await apiPost(`/messages/conversations/${selected}/messages`, form); setText(""); setFiles([]); await Promise.all([loadConversation(selected), loadThreads()]); } catch (e) { setError(e.message || "Message could not be sent."); } finally { setBusy(false); } };
	return <Page title="Messages" sub="Private buyer–seller order chat and VSN Support.">
		{error && <Status>{error}</Status>}
		<div className="messages-layout">
			<Card className="thread-list"><div className="section-title"><h3>Conversations</h3><Button disabled={busy} onClick={startSupport}><FaHeadset /> Support</Button></div><div className="thread-list-scroll">{threads.length ? threads.map(/** Inline callback for this operation. */ (t) => <button key={t.id} className={`thread ${selected === t.id ? "active" : ""}`} onClick={/** Inline callback for this operation. */ () => setSelected(t.id)}>{t.kind === "support" ? <FaHeadset /> : <FaStore />}<span><b>{t.subject}</b><small>{t.lastMessage?.body || (t.kind === "support" ? "General support" : t.vendor?.name || "Seller chat")}</small></span>{t.unreadCount > 0 && <Badge tone="primary">{t.unreadCount}</Badge>}</button>) : <p>No conversations yet. Open an order and choose Message seller, or start Support.</p>}</div></Card>
			<Card className="chat-panel">{conversation ? <><div className="section-title"><div><h3>{conversation.subject}</h3><p>{conversation.kind === "order" ? `${conversation.vendor?.name || "Seller"} · ${conversation.orderId || ""}` : "VSN Support"}</p></div><Badge tone={conversation.status === "open" ? "success" : "neutral"}>{conversation.status}</Badge></div><div className="chat-log">{messages.map(/** Inline callback for this operation. */ (m) => <div key={m.id} className={`bubble ${m.sender?.me ? "me" : ""}`}><b>{m.sender?.me ? "You" : m.sender?.name}</b>{m.body && <span>{m.body}</span>}{m.attachments?.map(/** Inline callback for this operation. */ (a) => <a key={a.id} href={apiUrl(a.downloadUrl)} target="_blank" rel="noreferrer">{a.name}</a>)}<small>{m.createdAt ? new Date(m.createdAt).toLocaleString() : ""}</small></div>)}</div><div className="chat-compose"><input value={text} onChange={/** Inline callback for this operation. */ (e) => setText(e.target.value)} onKeyDown={/** Inline callback for this operation. */ (e) => { if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); send(); } }} placeholder="Write a message" /><input type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf" onChange={/** Inline callback for this operation. */ (e) => setFiles(Array.from(e.target.files || []).slice(0, 4))} /><Button disabled={busy || (!text.trim() && !files.length)} onClick={send}>{busy ? "Sending…" : "Send"}</Button></div>{files.length > 0 && <small>{files.map(/** Inline callback for this operation. */ (f) => f.name).join(", ")}</small>}</> : <div className="empty-state"><FaComments /><h3>Select a conversation</h3><p>Seller conversations are scoped to an individual seller sub-order.</p></div>}</Card>
		</div>
	</Page>;
}
/** Handles legacy messages for the VSN Ecommerce interface. */
function LegacyMessages() {
	const s = useStore(); const [text, setText] = useState(""); const send = /** Handles send for the VSN Ecommerce interface. */ () => { if (text.trim()) { s.sendMessage(text.trim()); setText(""); } };
	return <Page title="Messages" sub="Chat with sellers and VSN support."><div className="messages-layout"><Card className="thread-list"><h3>Conversations</h3><div className="thread-list-scroll"><button className="thread active"><FaStore /><span><b>TechZone PK</b><small>Legacy seller chat</small></span></button></div></Card><Card className="chat-panel"><div className="chat-log">{s.messages.map(/** Inline callback for this operation. */ (m) => <div key={m.id} className={`bubble ${m.me ? "me" : ""}`}>{m.text}<small>{m.time}</small></div>)}</div><div className="chat-compose"><input value={text} onChange={/** Inline callback for this operation. */ (e) => setText(e.target.value)} placeholder="Write a message" /><Button onClick={send}>Send</Button></div></Card></div></Page>;
}

/** Handles settings for the VSN Ecommerce interface. */
export function Settings() {
	if (apiBackend !== "laravel") return <LegacySettings />;
	return <LaravelSettings />;
}
/** Handles laravel settings for the VSN Ecommerce interface. */
function LaravelSettings() {
	const [prefs, setPrefs] = useState(null); const [error, setError] = useState(""); const [saving, setSaving] = useState(false);
	useEffect(/** Inline callback for this operation. */ () => { apiGet("/notification-preferences").then(/** Inline callback for this operation. */ (d) => setPrefs(d.preferences || {})).catch(/** Inline callback for this operation. */ (e) => setError(e.message)); }, []);
	const toggle = /** Handles toggle for the VSN Ecommerce interface. */ (category, channel) => setPrefs(/** Inline callback for this operation. */ (current) => ({ ...current, [category]: { ...(current?.[category] || {}), [channel]: !current?.[category]?.[channel] } }));
	const save = /** Handles save for the VSN Ecommerce interface. */ async () => { setSaving(true); try { const d = await apiPut("/notification-preferences", { preferences: prefs }); setPrefs(d.preferences); setError(""); } catch (e) { setError(e.message); } finally { setSaving(false); } };
	const labels = { orders: "Order updates", shipping: "Shipping & delivery", gifts: "Gifts", games: "Game Win", reviews: "Reviews", returns: "Returns & refunds", rewards: "Rewards", account: "Account & security", messages: "Messages", reports: "Reports" };
	return <Page title="Settings" sub="Security, preferences and communication controls.">{error && <Status>{error}</Status>}<div className="two-col"><Card><h2>Security</h2><p>Identity, phone and password controls remain under your profile.</p><Link className="text-link" to="/profile">Manage profile & verification</Link></Card><Card><div className="section-title"><div><h2>Notifications</h2><p>In-app and email preferences are live. SMS/push remain provider-disabled.</p></div><Button disabled={!prefs || saving} onClick={save}>{saving ? "Saving…" : "Save"}</Button></div>{prefs ? Object.keys(labels).map(/** Inline callback for this operation. */ (category) => <div key={category} className="switch-row"><span><b>{labels[category]}</b><small>Choose delivery channels</small></span><label><input type="checkbox" checked={!!prefs[category]?.in_app} onChange={/** Inline callback for this operation. */ () => toggle(category, "in_app")} /> In-app</label><label><input type="checkbox" checked={!!prefs[category]?.email} onChange={/** Inline callback for this operation. */ () => toggle(category, "email")} /> Email</label></div>) : <p>Loading preferences…</p>}</Card></div></Page>;
}
/** Handles legacy settings for the VSN Ecommerce interface. */
function LegacySettings() {
	const s = useStore(); return <Page title="Settings" sub="Security, preferences and communication controls."><div className="two-col"><Card><h2>Security</h2><div className="settings-status"><Status ok={s.profile.phoneVerified}>Phone verification</Status><Status ok={s.profile.idVerified}>Government ID</Status></div><Link className="text-link" to="/profile">Manage verification</Link></Card><Card><h2>Notifications</h2><label className="switch-row"><span><b>Order updates</b><small>Email and in-app</small></span><input type="checkbox" defaultChecked /></label><label className="switch-row"><span><b>Game announcements</b><small>Winner countdown reminders</small></span><input type="checkbox" defaultChecked /></label></Card></div></Page>;
}
/** Handles gifts for the VSN Ecommerce interface. */
export function Gifts() {
	if (apiBackend !== "laravel") return <LegacyGifts />;
	return <LaravelGifts />;
}

/** Handles laravel gifts for the VSN Ecommerce interface. */
function LaravelGifts() {
	const gifts = useLaravelGifts();
	const wallet = useLaravelWallet();
	const [to, setTo] = useState("");
	const [coins, setCoins] = useState(140);
	const [msg, setMsg] = useState("");
	const [busy, setBusy] = useState("");

	const sendCoinGift = /** Handles send coin gift for the VSN Ecommerce interface. */ async () => {
		setBusy("coin"); setMsg("");
		try {
			await apiPost("/wallet/transfers", {
				recipient: to.trim(),
				coins: Number(coins),
				idempotencyKey: `coin-gift-${globalThis.crypto?.randomUUID?.() || Date.now()}`,
			});
			await Promise.all([gifts.refresh(), wallet.refresh()]);
			setMsg("Coin gift sent successfully.");
		} catch (error) { setMsg(error.message || "Coin gift could not be sent."); }
		finally { setBusy(""); }
	};

	const continueGiftPayment = /** Handles continue gift payment for the VSN Ecommerce interface. */ async (gift) => {
		setBusy(gift.id); setMsg("");
		try {
			if (!gift.paymentIntent) {
				await gifts.startCardPayment(gift.checkoutId);
				setMsg("Card payment intent created.");
			} else if (gift.paymentIntent.sandboxCanSimulate) {
				await gifts.completeSandboxPayment(gift.paymentIntent.id);
				await wallet.refresh();
				setMsg("Sandbox gift payment completed.");
			}
		} catch (error) { setMsg(error.message || "Gift payment could not be completed."); }
		finally { setBusy(""); }
	};

	const cancelGift = /** Handles cancel gift for the VSN Ecommerce interface. */ async (gift) => {
		setBusy(gift.id); setMsg("");
		try { await gifts.cancelGift(gift.id); await wallet.refresh(); setMsg("Gift checkout cancelled and reserved funds/stock released."); }
		catch (error) { setMsg(error.message || "Gift could not be cancelled."); }
		finally { setBusy(""); }
	};

	return (
		<Page title="Gifts" sub="Send coin or product gifts and unlock server-tracked Gift Sender rewards.">
			{(gifts.error || wallet.error) && <Status>{gifts.error || wallet.error}</Status>}
			{msg && <Status>{msg}</Status>}
			<div className="gift-layout">
				<Card>
					<h2>Send coin gift</h2>
					<Field label="Recipient email or verified phone" value={to} onChange={/** Inline callback for this operation. */ (e) => setTo(e.target.value)} />
					<Field label="Coins" type="number" min="1" value={coins} onChange={/** Inline callback for this operation. */ (e) => setCoins(e.target.value)} />
					<p><small>Available: {(wallet.wallet?.availableCoins || 0).toLocaleString()} coins</small></p>
					<Button disabled={busy === "coin" || !to.trim() || Number(coins) < 1} onClick={sendCoinGift}><FaGift /> {busy === "coin" ? "Sending…" : "Send gift"}</Button>
				</Card>
				<Card className="gift-level">
					<small>GIFT SENDER LEVEL</small>
					<strong>{gifts.level.name}</strong>
					<p>{gifts.lifetimeGiftCoins.toLocaleString()} lifetime gift coins sent</p>
					<div className="progress"><i style={{ width: `${gifts.level.progress || 0}%` }} /></div>
					<span>{gifts.level.nextReward ? `Next reward: ${gifts.level.nextReward}` : "Top Gift Sender level reached"}</span>
				</Card>
			</div>

			<SectionHeader title="Gift rewards" sub="Rewards are awarded once when a lifetime gifting threshold is crossed." />
			<div className="order-list">
				{gifts.rewards.length ? gifts.rewards.map(/** Inline callback for this operation. */ (reward) => <Card key={reward.id} className="order-card"><div><Badge tone="success">{reward.level}</Badge><h3>{reward.label}</h3></div><div><strong>{reward.status}</strong><small>{reward.awardedAt ? new Date(reward.awardedAt).toLocaleString() : ""}</small></div></Card>) : <Card><p>No Gift Sender rewards unlocked yet.</p></Card>}
			</div>

			<SectionHeader title="Product gifts sent" sub="Payment, schedule and fulfillment remain server-authoritative." />
			<div className="order-list">
				{gifts.loading ? <Card><p>Loading gifts…</p></Card> : gifts.sent.length ? gifts.sent.map(/** Inline callback for this operation. */ (gift) => (
					<Card key={gift.id} className="order-card">
						<div><Badge tone={gift.status === "fulfilled" ? "success" : "primary"}>{gift.status}</Badge><h3>{gift.product?.name || "Product gift"}</h3><p>To {gift.recipient?.name || "recipient"} · {Number(gift.giftValueCoins || 0).toLocaleString()} gift-value coins</p></div>
						<div><small>{gift.scheduledFor ? `Scheduled ${new Date(gift.scheduledFor).toLocaleString()}` : "Deliver normally"}</small><strong>{gift.paymentStatus || gift.paymentIntent?.status || gift.paymentMethod || "awaiting payment"}</strong>
							{gift.canCancel && <Button variant="secondary" disabled={busy === gift.id} onClick={/** Inline callback for this operation. */ () => cancelGift(gift)}>Cancel</Button>}
							{gift.paymentMethod === "card" && gift.status === "awaiting_payment" && (!gift.paymentIntent || gift.paymentIntent.sandboxCanSimulate) && <Button disabled={busy === gift.id} onClick={/** Inline callback for this operation. */ () => continueGiftPayment(gift)}>{!gift.paymentIntent ? "Start card payment" : "Complete sandbox payment"}</Button>}
						</div>
					</Card>
				)) : <Card><p>No product gifts sent yet. Open a product and choose Send as Gift.</p></Card>}
			</div>

			<SectionHeader title="Gifts received" sub="Anonymous sender identity and scheduled messages are protected by the API." />
			<div className="order-list">
				{gifts.received.length ? gifts.received.map(/** Inline callback for this operation. */ (gift) => <Card key={gift.id} className="order-card"><div><Badge tone="primary">{gift.status}</Badge><h3>{gift.product?.name || "Product gift"}</h3><p>From {gift.sender?.name || "Anonymous"}</p></div><div>{gift.message && <strong>“{gift.message}”</strong>}<small>{gift.scheduledFor ? new Date(gift.scheduledFor).toLocaleString() : ""}</small></div></Card>) : <Card><p>No product gifts received yet.</p></Card>}
			</div>
		</Page>
	);
}

/** Handles legacy gifts for the VSN Ecommerce interface. */
function LegacyGifts() {
	const s = useStore();
	const [to, setTo] = useState("");
	const [coins, setCoins] = useState(140);
	const [msg, setMsg] = useState("");
	const send = /** Handles send for the VSN Ecommerce interface. */ () => {
		if (s.sendCoins(to, coins)) { s.recordGift(Number(coins)); setMsg("Gift sent successfully"); }
		else setMsg("Check recipient and balance");
	};
	return (
		<Page title="Gifts" sub="Send coins, products and unlock Gift Sender levels.">
			<div className="gift-layout"><Card><h2>Send coin gift</h2><Field label="Recipient email or username" value={to} onChange={/** Inline callback for this operation. */ (e) => setTo(e.target.value)} /><Field label="Coins" type="number" value={coins} onChange={/** Inline callback for this operation. */ (e) => setCoins(e.target.value)} /><Button onClick={send}><FaGift /> Send gift</Button>{msg && <p>{msg}</p>}</Card><Card className="gift-level"><small>GIFT SENDER LEVEL</small><strong>{s.giftLevel.name}</strong><p>{s.giftSentCoins.toLocaleString()} lifetime gift coins sent</p><div className="progress"><i className={`progress-w-${s.giftLevel.progress}`} /></div><span>Next reward: {s.giftLevel.nextReward}</span></Card></div>
		</Page>
	);
}
/** Handles vendor dashboard for the VSN Ecommerce interface. */
export function VendorDashboard() {
	if (apiBackend !== "laravel") return <LegacyVendorDashboard />;
	return <LaravelVendorDashboard />;
}

/** Handles laravel vendor dashboard for the VSN Ecommerce interface. */
function LaravelVendorDashboard() {
	const [finance, setFinance] = useState(null);
	const [payouts, setPayouts] = useState([]);
	const [shipping, setShipping] = useState(null);
	const [analytics, setAnalytics] = useState(null);
	const [tab, setTab] = useState("overview");
	const [amount, setAmount] = useState("");
	const [error, setError] = useState("");
	const [msg, setMsg] = useState("");
	const [busy, setBusy] = useState(false);
	const load = /** Handles load for the VSN Ecommerce interface. */ async () => {
		const [f,p,sh,an] = await Promise.all([apiGet("/vendor/finance"),apiGet("/vendor/payouts"),apiGet("/vendor/shipping"),apiGet("/vendor/analytics")]);
		setFinance(f); setPayouts(Array.isArray(p)?p:[]); setShipping(sh); setAnalytics(an);
	};
	useEffect(/** Inline callback for this operation. */ ()=>{ load().catch(/** Inline callback for this operation. */ (e)=>setError(e.message)); },[]);
	const requestPayout = /** Handles request payout for the VSN Ecommerce interface. */ async () => { setBusy(true);setError("");setMsg("");try { const minor=amount.trim()?Math.round(Number(amount)*100):undefined; await apiPost("/vendor/payouts",{amountMinor:minor,idempotencyKey:`vendor-payout:${crypto.randomUUID()}`});setAmount("");setMsg("Payout request created and available settlements reserved.");await load(); } catch(e){setError(e.message);} finally{setBusy(false);} };
	const pack = /** Handles pack for the VSN Ecommerce interface. */ async (id) => { setBusy(true);setError("");try{await apiPost(`/vendor/orders/${id}/pack`,{});await load();}catch(e){setError(e.message);}finally{setBusy(false);} };
	const createShipment = /** Handles create shipment for the VSN Ecommerce interface. */ async (id) => { setBusy(true);setError("");try{await apiPost(`/vendor/orders/${id}/shipments`,{serviceCode:"standard",idempotencyKey:`shipment:${id}`});await load();}catch(e){setError(e.message);}finally{setBusy(false);} };
	const ready = /** Handles ready for the VSN Ecommerce interface. */ async (id) => { setBusy(true);setError("");try{await apiPost(`/vendor/shipments/${id}/ready`,{});await load();}catch(e){setError(e.message);}finally{setBusy(false);} };
	const simulate = /** Handles simulate for the VSN Ecommerce interface. */ async (id,status) => { setBusy(true);setError("");try{await apiPost(`/vendor/shipments/${id}/sandbox-event`,{status,message:`Sandbox ${status.replaceAll("_"," ")}`});await load();}catch(e){setError(e.message);}finally{setBusy(false);} };
	const m=/** Handles m for the VSN Ecommerce interface. */ (v)=>`Rs. ${moneyFromMinor(Number(v||0)).toLocaleString()}`;
	return <Page title="Vendor Center" sub="Seller fulfilment, shipping SLA, settlements and payouts from Laravel.">
		{error&&<Status>{error}</Status>}{msg&&<p className="form-message">{msg}</p>}
		<div className="dashboard-tabs">{["overview","catalog","promotions","tax","analytics","shipping","settlements","payouts"].map(/** Inline callback for this operation. */ x=><button className={tab===x?"active":""} onClick={/** Inline callback for this operation. */ ()=>setTab(x)} key={x}>{x}</button>)}</div>
		{!finance||!shipping?<Card><p>Loading vendor operations…</p></Card>:<>
			<div className="metric-grid"><Card><small>Available payout</small><strong>{m(finance.availableMinor)}</strong><span>Eligible to request</span></Card><Card><small>On-time dispatch</small><strong>{shipping.metrics?.onTimeDispatchPercent ?? 100}%</strong><span>{shipping.metrics?.lateDispatchActive || 0} active breach(es)</span></Card><Card><small>On-time delivery</small><strong>{shipping.metrics?.onTimeDeliveryPercent ?? 100}%</strong><span>{shipping.metrics?.lateDeliveryActive || 0} active breach(es)</span></Card><Card><small>Shipments</small><strong>{shipping.metrics?.shipments || 0}</strong><span>{shipping.metrics?.rtoCount || 0} RTO</span></Card></div>
			{tab==="catalog"&&<Card><SectionHeader title="Seller catalog" action="Open catalog" to="/vendor/products"/><p>Products, variants, publishing workflow and inventory are managed by Laravel.</p></Card>}
			{tab==="promotions"&&<Card><SectionHeader title="Promotions & deals" action="Manage promotions" to="/vendor/promotions"/><p>Create seller-funded automatic deals, flash campaigns and coupon codes with schedule, scope and usage limits.</p></Card>}{tab==="tax"&&<Card><SectionHeader title="Seller tax profile" action="Open tax profile" to="/vendor/tax"/><p>Submit registration details and control whether approved catalog prices include configured VAT/GST/tax.</p></Card>}
			{tab==="analytics"&&analytics&&<><div className="metric-grid"><Card><small>30-day views</small><strong>{analytics.summary?.views||0}</strong><span>{analytics.summary?.uniqueVisitors||0} unique visitors</span></Card><Card><small>Conversion</small><strong>{analytics.summary?.conversionPercent||0}%</strong><span>{analytics.summary?.orders||0} paid orders</span></Card><Card><small>Units</small><strong>{analytics.summary?.units||0}</strong><span>{analytics.summary?.wishlistSaves||0} current wishlist saves</span></Card><Card><small>Revenue</small><strong>{m(analytics.summary?.revenueMinor)}</strong><span>{analytics.summary?.buyers||0} buyers</span></Card></div><Card><SectionHeader title="Top catalog products" sub="Views, units, revenue and view-to-order conversion"/><div className="simple-list">{(analytics.products||[]).map(/** Inline callback for this operation. */ x=><div key={x.id}><span><b>{x.name}</b><small>{x.views} views · {x.units} units · {x.conversionPercent}% conversion</small></span><strong>{m(x.revenueMinor)}</strong></div>)}</div></Card></>}
			{tab==="overview"&&<div className="dashboard-grid"><Card><h2>Settlement policy</h2><div className="store-health"><Status ok>Seller delivery is courier-event driven</Status><Status ok>Each seller order matures independently</Status><Status ok>Review reward discounts are platform-funded</Status></div></Card><Card><h2>Lifetime finance</h2><p>Seller payable {m(finance.lifetimeSellerPayableMinor)} · reversed {m(finance.lifetimeReversedMinor)}</p></Card></div>}
			{tab==="shipping"&&<><Card><h2>Orders to fulfil</h2><div className="simple-list">{(shipping.orders||[]).map(/** Inline callback for this operation. */ o=><div key={o.id}><FaBox/><span><b>{o.id}</b><small>{o.status.replaceAll("_"," ")} · {o.items} item(s) · payment {o.paymentStatus}</small></span><span><Link to={`/messages?vendorOrder=${encodeURIComponent(o.id)}`}>Message buyer</Link> {!o.packedAt&&<Button disabled={busy} variant="secondary" onClick={/** Inline callback for this operation. */ ()=>pack(o.id)}>Pack</Button>} {!o.shipmentId&&<Button disabled={busy} onClick={/** Inline callback for this operation. */ ()=>createShipment(o.id)}>Create label</Button>}</span></div>)}</div></Card><Card><h2>Shipments</h2><div className="simple-list">{(shipping.shipments||[]).length?(shipping.shipments||[]).map(/** Inline callback for this operation. */ s=><div key={s.id}><FaTruck/><span><b>{s.trackingNumber||s.id}</b><small>{s.status.replaceAll("_"," ")} · {s.serviceCode}{s.dispatchSlaBreached?" · dispatch SLA breached":""}{s.deliverySlaBreached?" · delivery SLA breached":""}</small></span><span>{s.status==="label_created"&&<Button disabled={busy} variant="secondary" onClick={/** Inline callback for this operation. */ ()=>ready(s.id)}>Ready</Button>} {s.sandboxCanSimulate&&!["delivered","returned_to_sender","cancelled"].includes(s.status)&&<Select value="" onChange={/** Inline callback for this operation. */ (e)=>e.target.value&&simulate(s.id,e.target.value)}><option value="">Simulate event</option><option value="picked_up">Picked up</option><option value="in_transit">In transit</option><option value="out_for_delivery">Out for delivery</option><option value="delivered">Delivered</option><option value="delivery_failed">Delivery failed</option><option value="return_to_origin">RTO</option></Select>}</span></div>):<p>No shipments yet.</p>}</div></Card></>}
			{tab==="settlements"&&<Card><h2>Recent settlements</h2><div className="simple-list">{(finance.settlements||[]).map(/** Inline callback for this operation. */ x=><div key={x.id}><FaBox/><span><b>{x.vendorOrderId||x.id}</b><small>{x.status.replaceAll("_"," ")} · eligible {x.eligibleAt?new Date(x.eligibleAt).toLocaleDateString():"after this seller shipment is delivered"}</small></span><strong>{m(x.availableMinor||x.sellerPayableMinor)}</strong></div>)}</div></Card>}
			{tab==="payouts"&&<><Card><h2>Request payout</h2><Field label="Amount in Rs. (leave blank for all available)" type="number" value={amount} onChange={/** Inline callback for this operation. */ (e)=>setAmount(e.target.value)}/><Button disabled={busy||Number(finance.availableMinor||0)<=0} onClick={requestPayout}>{busy?"Requesting…":"Request payout"}</Button></Card><Card><h2>Payout history</h2><div className="simple-list">{payouts.length?payouts.map(/** Inline callback for this operation. */ x=><div key={x.id}><FaMoneyBillWave/><span><b>{x.id}</b><small>{x.status} · {x.providerReference||"provider reference pending"}</small></span><strong>{m(x.amountMinor)}</strong></div>):<p>No payouts yet.</p>}</div></Card></>}
		</>}
	</Page>;
}

/** Handles legacy vendor dashboard for the VSN Ecommerce interface. */
function LegacyVendorDashboard() {
	const [tab, setTab] = useState("overview");
	const metrics = [
		["Revenue", "Rs. 12.8M"],
		["Orders", "1,842"],
		["Products", "428"],
		["Rating", "4.89"],
	];
	return (
		<Page
			title="Vendor Center"
			sub="Manage products, orders, analytics and payouts."
		>
			<div className="dashboard-tabs">
				{["overview", "products", "orders", "analytics", "payouts"].map(/** Inline callback for this operation. */ (t) => (
					<button
						className={tab === t ? "active" : ""}
						onClick={/** Inline callback for this operation. */ () => setTab(t)}
						key={t}
					>
						{t}
					</button>
				))}
			</div>
			{tab === "overview" && (
				<>
					<div className="metric-grid">
						{metrics.map(/** Inline callback for this operation. */ (m) => (
							<Card key={m[0]}>
								<small>{m[0]}</small>
								<strong>{m[1]}</strong>
								<span>Last 30 days</span>
							</Card>
						))}
					</div>
					<div className="dashboard-grid">
						<Card className="sales-chart">
							<div className="chart-header">
								<div>
									<h3>Sales performance</h3>
									<p>Last 7 days</p>
								</div>
							</div>

							<div className="bar-chart">
								{[
									{ label: "Jul 1", value: 58 },
									{ label: "Jul 2", value: 72 },
									{ label: "Jul 3", value: 46 },
									{ label: "Jul 4", value: 82 },
									{ label: "Jul 5", value: 64 },
									{ label: "Jul 6", value: 91 },
									{ label: "Jul 7", value: 76 },
								].map(/** Inline callback for this operation. */ (item) => (
									<div className="bar-item" key={item.label}>
										<div className="bar-value">{item.value}</div>

										<div className="bar-track">
											<i
												style={{ "--bar-height": `${item.value}%` }}
											/>
										</div>

										<small>{item.label}</small>
									</div>
								))}
							</div>
						</Card>
						<Card>
							<h2>Store health</h2>
							<div className="store-health">
								<Status ok>Verified seller</Status>
								<Status ok>98.6% on-time dispatch</Status>
								<Status ok>1.4% return rate</Status>
							</div>
						</Card>
					</div>
				</>
			)}
			{tab === "products" && (
				<Card>
					<SectionHeader
						title="Products"
						action="Add product"
						to="/vendor/products/new"
					/>
					<div className="simple-list">
						{products.slice(0, 5).map(/** Inline callback for this operation. */ (p) => (
							<div key={p.id}>
								<SafeImage src={p.image} alt={p.name} />
								<span>
									<b>{p.name}</b>
									<small>Stock: {42 + p.id * 7}</small>
								</span>
								<strong>Rs. {p.price.toLocaleString()}</strong>
							</div>
						))}
					</div>
				</Card>
			)}
			{tab === "orders" && (
				<Card>
					<h2>Recent orders</h2>
					<div className="simple-list">
						{["VSN-8821", "VSN-8820", "VSN-8819"].map(/** Inline callback for this operation. */ (id, i) => (
							<div key={id}>
								<FaBox />
								<span>
									<b>{id}</b>
									<small>{2 + i} items</small>
								</span>
								<Badge tone="primary">Process</Badge>
							</div>
						))}
					</div>
				</Card>
			)}
			{tab === "analytics" && (
				<Card>
					<h2>Analytics</h2>
					<p>
						Conversion rate 4.8% · Repeat buyer rate 31% · Average order value
						Rs. 19,420.
					</p>
				</Card>
			)}
			{tab === "payouts" && (
				<Card>
					<h2>Payouts</h2>
					<p>Next payout: Rs. 842,500 on Aug 7, 2026.</p>
					<Button>Request statement</Button>
				</Card>
			)}
		</Page>
	);
}
/** Handles admin control for the VSN Ecommerce interface. */
export function AdminControl() {
	const s = useStore();
	const { hasPermission } = useAuth();
	const [bi, setBi] = useState(null);
	useEffect(/** Inline callback for this operation. */ () => { if (apiBackend === "laravel") apiGet("/admin/analytics").then(/** Inline callback for this operation. */ (d) => setBi(d?.analytics || null)).catch(/** Inline callback for this operation. */ () => {}); }, []);
	const commerce = bi?.commerce || {}, market = bi?.marketplace || {}, ops = bi?.operations || {};
	return (
		<Page title="Admin Control Center" sub="Monitor marketplace operations, money movement, games and compliance.">
			<div className="metric-grid admin-metrics">
				<Card><FaUsers /><small>Users</small><strong>{apiBackend === "laravel" ? (market.totalUsers == null ? "—" : Number(market.totalUsers).toLocaleString()) : "128,420"}</strong><span>{apiBackend === "laravel" ? `${market.newUsers || 0} new in report window` : "+4.2% month"}</span></Card>
				<Card><FaStore /><small>Active vendors</small><strong>{apiBackend === "laravel" ? (market.activeVendors ?? "—") : "4,812"}</strong><span>{apiBackend === "laravel" ? `${ops.pendingKyc || 0} KYC pending` : "96 KYC pending"}</span></Card>
				<Card><FaMoneyBillWave /><small>GMV / paid order value</small><strong>{apiBackend === "laravel" ? `Rs. ${moneyFromMinor(commerce.gmvMinor || 0).toLocaleString()}` : "Rs. 48.2M"}</strong><span>{apiBackend === "laravel" ? `${commerce.orders || 0} paid orders` : "2,944 orders"}</span></Card>
				<Card><FaIdCard /><small>Risk / compliance</small><strong>{apiBackend === "laravel" ? (ops.openRiskCases || 0) + (ops.pendingKyc || 0) : 17}</strong><span>{apiBackend === "laravel" ? `${ops.openRiskCases || 0} risk · ${ops.pendingKyc || 0} KYC` : "Needs review"}</span></Card>
			</div>
			<div className="admin-grid">
				<Card><h2>System health</h2><div className="system-status"><Status ok>Laravel catalog API</Status><Status ok>Finance ledger</Status><Status ok>Game scheduler</Status><Status ok>Affiliate engine</Status><Status ok>Notification queue</Status></div></Card>
				<Card><h2>Operational queues</h2><div className="simple-list"><div><span><b>KYC review</b><small>Government IDs</small></span><strong>{apiBackend === "laravel" ? ops.pendingKyc || 0 : 96}</strong></div><div><span><b>Game draws</b><small>Due in next 24h</small></span><strong>{apiBackend === "laravel" ? ops.gameDrawsDue24h || 0 : s.gameEntries.length}</strong></div><div><span><b>Return / dispute queue</b><small>Buyer protection review</small></span><strong>{apiBackend === "laravel" ? ops.openReturns || 0 : s.returnRequests.length}</strong></div><div><span><b>Product alerts</b><small>Price & stock watchers</small></span><strong>{apiBackend === "laravel" ? ops.activeProductAlerts || 0 : s.productAlerts.length}</strong></div></div></Card>
				<Card className="admin-wide"><h2>Marketplace controls</h2><div className="admin-action-grid">
{hasPermission("vendors.view")&&<Link to="/admin/vendors"><FaStore /> Vendors</Link>}
{hasPermission("catalog.view")&&<Link to="/admin/catalog"><FaBox /> Catalog</Link>}
{hasPermission("orders.view")&&<Link to="/admin/orders"><FaBox /> Orders</Link>}
{hasPermission("shipping.view")&&<Link to="/admin/shipping"><FaTruck /> Shipping</Link>}
{hasPermission("payments.view")&&<Link to="/admin/payments"><FaCreditCard /> Payments</Link>}
{hasPermission("returns.view")&&<Link to="/admin/returns"><FaUndo /> Returns & refunds</Link>}
{hasPermission("finance.view")&&<><Link to="/admin/finance"><FaMoneyBillWave /> Finance</Link><Link to="/admin/payouts"><FaWallet /> Payouts</Link></>}
{hasPermission("promotions.view")&&<Link to="/admin/promotions"><FaGift /> Promotions</Link>}
{hasPermission("tax.view")&&<Link to="/admin/tax"><FaMoneyBillWave /> Tax & invoices</Link>}
{hasPermission("compliance.view")&&<Link to="/admin/compliance"><FaIdCard /> KYC & compliance</Link>}
{hasPermission("reviews.view")&&<Link to="/admin/reviews"><FaStar /> Reviews</Link>}
{hasPermission("notifications.view")&&<Link to="/admin/notifications"><FaBell /> Notifications</Link>}
{hasPermission("settings.view")&&<Link to="/admin/settings"><FaCog /> Settings</Link>}
{hasPermission("operations.view")&&<><Link to="/admin/operations"><FaMoneyBillWave /> Operations</Link><Link to="/admin/production-readiness"><FaShieldAlt /> Launch gate</Link></>}
{hasPermission("acceptance.view")&&<Link to="/admin/acceptance"><FaShieldAlt /> Production acceptance</Link>}
{hasPermission("risk.view")&&<Link to="/admin/risk"><FaShieldAlt /> Risk & abuse</Link>}
{hasPermission("analytics.view")&&<Link to="/admin/analytics"><FaMoneyBillWave /> Analytics & reports</Link>}
</div></Card>
			</div>
		</Page>
	);
}

/** Handles seller product form for the VSN Ecommerce interface. */
export function SellerProductForm() {
	return (
		<Page
			title="Add product"
			sub="Products are created in the backend and synced to storefront."
		>
			<Card className="seller-add-card">
				<div className="form-grid">
					<Field label="Product title" />
					<Field label="SKU" />
					<Field label="Regular price" type="number" />
					<Field label="Sale price" type="number" />
					<Field label="Stock quantity" type="number" />
					<Field label="Category" />

				</div>
				<div className="form-row">
					<Field label="Short description" />
					<label className="ui-field checkbox-field">
						<span>Game Win enabled</span>
						<input type="checkbox" />
					</label>
				</div>

				<Button>Save draft</Button>
			</Card>
		</Page>
	);
}
/** Handles page for the VSN Ecommerce interface. */
function Page({ title, sub, children }) {
	return (
		<>
			<SEO title={`${title} | VSN Ecommerce`} description={sub} />
			<div className="simple-page">
				<div className="page-title">
					<h1>{title}</h1>
					<p>{sub}</p>
				</div>
				{children}
			</div>
		</>
	);
}
/** Handles step for the VSN Ecommerce interface. */
function Step({ done, active, title, text }) {
	return (
		<div
			className={`track-step ${done ? "done" : ""} ${active ? "active" : ""}`}
		>
			<i />
			<span>
				<b>{title}</b>
				<small>{text}</small>
			</span>
		</div>
	);
}

/** Handles returns center for the VSN Ecommerce interface. */
export function ReturnsCenter() {
	if (apiBackend !== "laravel") return <LegacyReturnsCenter />;
	return <LaravelReturnsCenter />;
}

/** Handles laravel returns center for the VSN Ecommerce interface. */
function LaravelReturnsCenter() {
	const [orders, setOrders] = useState([]);
	const [requests, setRequests] = useState([]);
	const [orderId, setOrderId] = useState("");
	const [reason, setReason] = useState("");
	const [resolution, setResolution] = useState("refund_original");
	const [details, setDetails] = useState("");
	const [quantities, setQuantities] = useState({});
	const [tracking, setTracking] = useState({});
	const [msg, setMsg] = useState("");
	const [error, setError] = useState("");
	const [busy, setBusy] = useState(false);

	const load = /** Handles load for the VSN Ecommerce interface. */ async () => {
		const [orderRows, returnRows] = await Promise.all([apiGet("/orders"), apiGet("/returns")]);
		const eligible = (Array.isArray(orderRows) ? orderRows : []).filter(/** Inline callback for this operation. */ (o) => o.returnEligible);
		setOrders(eligible);
		setRequests(Array.isArray(returnRows) ? returnRows : []);
		setOrderId(/** Inline callback for this operation. */ (current) => current || eligible[0]?.id || "");
	};

	useEffect(/** Inline callback for this operation. */ () => { load().catch(/** Inline callback for this operation. */ (e) => setError(e.message)); }, []);
	const selected = orders.find(/** Inline callback for this operation. */ (o) => o.id === orderId);
	useEffect(/** Inline callback for this operation. */ () => {
		if (!selected) return;
		const next = {};
		(selected.items || []).forEach(/** Inline callback for this operation. */ (item) => {
			const remaining = Math.max(0, Number(item.quantity || 0) - Number(item.returnedQuantity || 0));
			next[item.id] = remaining;
		});
		setQuantities(next);
	}, [orderId]);

	const submit = /** Handles submit for the VSN Ecommerce interface. */ async () => {
		setBusy(true); setError(""); setMsg("");
		try {
			const items = Object.entries(quantities).filter(/** Inline callback for this operation. */ ([, q]) => Number(q) > 0).map(/** Inline callback for this operation. */ ([orderItemId, quantity]) => ({ orderItemId: Number(orderItemId), quantity: Number(quantity) }));
			const result = await apiPost("/returns", { orderId, reason, resolution, details, items });
			setMsg(`Return request ${result.id} submitted.`); setReason(""); setDetails(""); await load();
		} catch (e) { setError(e.message); } finally { setBusy(false); }
	};

	const markShipped = /** Handles mark shipped for the VSN Ecommerce interface. */ async (request) => {
		const ref = (tracking[request.id] || "").trim(); if (!ref) return;
		try { await apiPost(`/returns/${request.id}/ship`, { trackingReference: ref }); await load(); }
		catch (e) { setError(e.message); }
	};

	return <Page title="Returns, refunds & disputes" sub="Laravel-managed item returns, refund settlement and marketplace disputes with an auditable financial trail.">
		<div className="postpurchase-grid">
			<Card>
				<h2>Start a request</h2>
				<Select label="Delivered order" value={orderId} onChange={/** Inline callback for this operation. */ (e) => setOrderId(e.target.value)}>
					<option value="">Choose an order</option>
					{orders.map(/** Inline callback for this operation. */ (o) => <option key={o.id} value={o.id}>{o.id} · Rs. {moneyFromMinor(o.totals?.totalMinor).toLocaleString()}</option>)}
				</Select>
				{selected?.items?.length ? <div className="simple-list">
					{selected.items.map(/** Inline callback for this operation. */ (item) => {
						const remaining = Math.max(0, Number(item.quantity || 0) - Number(item.returnedQuantity || 0));
						return <div key={item.id}><FaBox/><span><b>{item.productName}</b><small>{item.variantName} · {remaining} returnable</small></span><input style={{maxWidth:90}} type="number" min="0" max={remaining} value={quantities[item.id] ?? 0} onChange={/** Inline callback for this operation. */ (e)=>setQuantities({...quantities,[item.id]:Math.min(remaining,Math.max(0,Number(e.target.value)))})}/></div>;
					})}
				</div> : null}
				<Select label="Reason" value={reason} onChange={/** Inline callback for this operation. */ (e) => setReason(e.target.value)}>
					<option value="">Choose a reason</option><option>Item damaged</option><option>Wrong item received</option><option>Not as described</option><option>Missing parts/accessories</option><option>Changed my mind</option><option>Delivery issue</option>
				</Select>
				<Select label="Preferred resolution" value={resolution} onChange={/** Inline callback for this operation. */ (e) => setResolution(e.target.value)}>
					<option value="refund_original">Refund to original payment</option><option value="coins">Refund as VSN Coins</option><option value="replacement">Replacement</option><option value="dispute">Marketplace dispute review</option>
				</Select>
				<label className="ui-field"><span>Details</span><textarea value={details} onChange={/** Inline callback for this operation. */ (e)=>setDetails(e.target.value)} placeholder="Describe the issue, packaging condition and what resolution you need."/></label>
				<Button disabled={busy || !orderId || !reason} onClick={submit}><FaUndo/> {busy ? "Submitting…" : "Submit request"}</Button>
				{msg && <p className="form-message">{msg}</p>}{error && <Status>{error}</Status>}
				<div className="policy-note"><FaShieldAlt/><span><b>Buyer Protection</b><small>Refunds, coin credits, affiliate reversals, seller liabilities and restocking are reconciled by Laravel.</small></span></div>
			</Card>
			<Card>
				<SectionHeader title="Your requests" sub="Status, refund and next action"/>
				<div className="simple-list">
					{requests.length ? requests.map(/** Inline callback for this operation. */ (r)=><div key={r.id}><FaUndo/><span><b>{r.id} · {r.orderId}</b><small>{r.reason} · {r.resolution.replaceAll("_"," ")} · Rs. {moneyFromMinor(r.approvedMinor || r.requestedMinor).toLocaleString()}{r.refund ? ` · refund ${r.refund.status.replaceAll("_"," ")}` : ""}</small>{r.status === "approved" && <span style={{display:"flex",gap:8,marginTop:6}}><input placeholder="Return tracking reference" value={tracking[r.id]||""} onChange={/** Inline callback for this operation. */ (e)=>setTracking({...tracking,[r.id]:e.target.value})}/><Button onClick={/** Inline callback for this operation. */ ()=>markShipped(r)}>Mark shipped</Button></span>}</span><Badge tone={["refunded","replaced"].includes(r.status)?"success":"primary"}>{r.status.replaceAll("_"," ")}</Badge></div>) : <p>No return or dispute requests yet.</p>}
				</div>
			</Card>
		</div>
	</Page>;
}

/** Handles legacy returns center for the VSN Ecommerce interface. */
function LegacyReturnsCenter() {
	const s = useStore();
	const delivered = s.orders.filter(/** Inline callback for this operation. */ (o) => o.status === "Delivered");
	const [orderId, setOrderId] = useState(delivered[0]?.id || "");
	const [reason, setReason] = useState("");
	const [resolution, setResolution] = useState("refund");
	const [details, setDetails] = useState("");
	const [msg, setMsg] = useState("");
	const submit = /** Handles submit for the VSN Ecommerce interface. */ () => { const r = s.createReturnRequest({ orderId, reason, resolution, details }); setMsg(r.msg); if (r.ok) { setReason(""); setDetails(""); } };
	return <Page title="Returns, refunds & disputes" sub="Start a return, request a refund, or escalate a marketplace dispute with a clear audit trail."><div className="postpurchase-grid"><Card><h2>Start a request</h2><Select label="Delivered order" value={orderId} onChange={/** Inline callback for this operation. */ (e)=>setOrderId(e.target.value)}>{delivered.map(/** Inline callback for this operation. */ (o)=><option key={o.id} value={o.id}>{o.id} · Rs. {o.total.toLocaleString()}</option>)}</Select><Select label="Reason" value={reason} onChange={/** Inline callback for this operation. */ (e)=>setReason(e.target.value)}><option value="">Choose a reason</option><option>Item damaged</option><option>Wrong item received</option><option>Not as described</option><option>Missing parts/accessories</option><option>Changed my mind</option><option>Delivery issue</option></Select><Select label="Preferred resolution" value={resolution} onChange={/** Inline callback for this operation. */ (e)=>setResolution(e.target.value)}><option value="refund">Refund to original payment</option><option value="coins">Refund as VSN Coins</option><option value="replacement">Replacement</option><option value="dispute">Marketplace dispute review</option></Select><label className="ui-field"><span>Details</span><textarea value={details} onChange={/** Inline callback for this operation. */ (e)=>setDetails(e.target.value)} placeholder="Describe the issue, packaging condition and what resolution you need."/></label><Button onClick={submit}><FaUndo/> Submit request</Button>{msg&&<p className="form-message">{msg}</p>}</Card><Card><SectionHeader title="Your requests" sub="Status and next action"/><div className="simple-list">{s.returnRequests.length?s.returnRequests.map(/** Inline callback for this operation. */ (r)=><div key={r.id}><FaUndo/><span><b>{r.id} · {r.orderId}</b><small>{r.reason} · {r.resolution}</small></span><Badge tone={r.status==="approved"?"success":"primary"}>{r.status}</Badge></div>):<p>No return or dispute requests yet.</p>}</div></Card></div></Page>;
}

/** Handles saved alerts for the VSN Ecommerce interface. */
export function SavedAlerts() {
	if (apiBackend !== "laravel") return <LegacySavedAlerts />;
	return <LaravelSavedAlerts />;
}
/** Handles laravel saved alerts for the VSN Ecommerce interface. */
function LaravelSavedAlerts(){const [alerts,setAlerts]=useState([]),[error,setError]=useState(''),[loading,setLoading]=useState(true);const load=/** Handles load for the VSN Ecommerce interface. */ ()=>apiGet('/product-alerts').then(/** Inline callback for this operation. */ x=>setAlerts(Array.isArray(x)?x:[])).catch(/** Inline callback for this operation. */ e=>setError(e.message)).finally(/** Inline callback for this operation. */ ()=>setLoading(false));useEffect(/** Inline callback for this operation. */ ()=>{load()},[]);const remove=/** Handles remove for the VSN Ecommerce interface. */ async id=>{try{await apiDelete(`/product-alerts/${id}`);await load()}catch(e){setError(e.message)}};const prices=alerts.filter(/** Inline callback for this operation. */ a=>a.type==='price_drop'),stocks=alerts.filter(/** Inline callback for this operation. */ a=>a.type==='back_in_stock');return <Page title="Saved & alerts" sub="Server-authoritative price-drop and back-in-stock notifications.">{error&&<Status>{error}</Status>}<div className="metric-grid"><Card><FaTag/><small>Active price alerts</small><strong>{prices.filter(/** Inline callback for this operation. */ a=>a.status==='active').length}</strong><span>Price changes monitored</span></Card><Card><FaBell/><small>Stock alerts</small><strong>{stocks.filter(/** Inline callback for this operation. */ a=>a.status==='active').length}</strong><span>Inventory availability monitored</span></Card><Card><FaShieldAlt/><small>Alert source</small><strong>Laravel</strong><span>Catalog + inventory authoritative state</span></Card></div><Card className="system-section"><SectionHeader title="Product alerts" sub="Triggered stock alerts stay visible until removed"/><div className="alert-product-grid">{loading?<p>Loading alerts…</p>:alerts.length?alerts.map(/** Inline callback for this operation. */ a=><div className="alert-product" key={a.id}><SafeImage src={a.product?.image} alt={a.product?.name}/><span><b>{a.product?.name}</b><small>{a.type==='price_drop'?(a.targetPriceMinor?`Notify at Rs. ${moneyFromMinor(a.targetPriceMinor).toLocaleString()} or lower`:'Notify on next price drop'):(a.status==='triggered'?'Back-in-stock alert triggered':'Notify when stock returns')}</small></span><Badge tone={a.status==='triggered'?'success':a.type==='price_drop'?'deal':'primary'}>{a.status}</Badge><button onClick={/** Inline callback for this operation. */ ()=>remove(a.id)}>Remove</button></div>):<p>No alerts yet. Open a product and create one.</p>}</div></Card></Page>}
/** Handles legacy saved alerts for the VSN Ecommerce interface. */
function LegacySavedAlerts(){const s=useStore();return <Page title="Saved & alerts" sub="Track products you care about and get notified about meaningful price or stock changes."><div className="metric-grid"><Card><FaTag/><small>Active price alerts</small><strong>{s.productAlerts.filter(/** Inline callback for this operation. */ a=>a.type==='price').length}</strong></Card><Card><FaBell/><small>Stock alerts</small><strong>{s.productAlerts.filter(/** Inline callback for this operation. */ a=>a.type==='stock').length}</strong></Card></div></Page>}

/** Handles operations center for the VSN Ecommerce interface. */
export function OperationsCenter() {
	if (apiBackend !== "laravel") return <LegacyOperationsCenter />;
	return <LaravelOperationsCenter />;
}

/** Handles laravel operations center for the VSN Ecommerce interface. */
function LaravelOperationsCenter() {
	const [summary,setSummary]=useState(null);
	const [systemOps,setSystemOps]=useState(null);
	const [payouts,setPayouts]=useState([]);
	const [batches,setBatches]=useState([]);
	const [refs,setRefs]=useState({});
	const [error,setError]=useState("");
	const [msg,setMsg]=useState("");
	const [busy,setBusy]=useState("");
	const [incidentDrafts,setIncidentDrafts]=useState({});
	const load=/** Handles load for the VSN Ecommerce interface. */ async()=>{const [a,b,c,d]=await Promise.all([apiGet("/admin/finance"),apiGet("/admin/finance/payouts"),apiGet("/admin/finance/payout-batches"),apiGet("/admin/system/operations")]);setSummary(a);setPayouts(Array.isArray(b)?b:[]);setBatches(Array.isArray(c)?c:[]);setSystemOps(d);};
	const incidentText=/** Handles incident text for the VSN Ecommerce interface. */ id=>incidentDrafts[id]||"";
	const setIncidentText=/** Handles set incident text for the VSN Ecommerce interface. */ (id,value)=>setIncidentDrafts(/** Inline callback for this operation. */ v=>({...v,[id]:value}));
	const incidentAction=/** Handles incident action for the VSN Ecommerce interface. */ async(incident,type)=>{const message=incidentText(incident.id).trim();if(!message){setMsg("Add an operator note before changing incident state.");return;}setBusy(`incident:${incident.id}:${type}`);setError("");try{if(type==="note")await apiPost(`/admin/system/operations/incidents/${incident.id}/notes`,{message});else if(type==="resolve")await apiPost(`/admin/system/operations/incidents/${incident.id}/resolve`,{summary:message});else await apiPut(`/admin/system/operations/incidents/${incident.id}/status`,{status:type,message});setIncidentText(incident.id,"");await load();}catch(e){setError(e.message);}finally{setBusy("");}};
	useEffect(/** Inline callback for this operation. */ ()=>{load().catch(/** Inline callback for this operation. */ e=>setError(e.message));},[]);
	const act=/** Handles act for the VSN Ecommerce interface. */ async(id,type,body={})=>{setBusy(`${id}:${type}`);setError("");try{await apiPost(`/admin/finance/payouts/${id}/${type}`,body);await load();}catch(e){setError(e.message);}finally{setBusy("");}};
	const reconcile=/** Handles reconcile for the VSN Ecommerce interface. */ async()=>{setBusy("reconcile");setError("");try{const r=await apiPost("/admin/finance/reconcile",{});setMsg(`Reconciliation ${r.status}: ${r.issuesCount} issue(s).`);await load();}catch(e){setError(e.message);}finally{setBusy("");}};
	const createBatch=/** Handles create batch for the VSN Ecommerce interface. */ async()=>{const ids=payouts.filter(/** Inline callback for this operation. */ p=>p.status==="approved"&&!p.batchId).map(/** Inline callback for this operation. */ p=>p.id);if(!ids.length){setMsg("No approved unbatched payouts are waiting.");return;}setBusy("batch");setError("");try{const b=await apiPost("/admin/finance/payout-batches",{payoutIds:ids});setMsg(`Payout batch ${b.id} created with ${b.payoutCount} payout(s).`);await load();}catch(e){setError(e.message);}finally{setBusy("");}};
	const m=/** Handles m for the VSN Ecommerce interface. */ (v)=>`Rs. ${moneyFromMinor(Number(v||0)).toLocaleString()}`;
	if(!summary)return <Page title="Finance & marketplace operations" sub="Immutable marketplace accounting, seller settlements and payout controls.">{error?<Status>{error}</Status>:<Card><p>Loading finance ledger…</p></Card>}</Page>;
	return <Page title="Finance & marketplace operations" sub="Immutable double-entry finance ledger, operational liabilities, seller settlements and payout reconciliation.">
		{error&&<Status>{error}</Status>}{msg&&<p className="form-message">{msg}</p>}
		{systemOps&&<Card className="system-section"><SectionHeader title="Production health" sub="Database, Redis, storage, scheduler, queue workers and failed-job pressure"/><div className="finance-grid">{Object.entries(systemOps.health?.checks||{}).map(/** Inline callback for this operation. */ ([name,check])=><div key={name}><small>{name.replaceAll("_"," ")}</small><strong>{check.ok?"Healthy":"Needs attention"}</strong><span>{check.latencyMs!=null?`${check.latencyMs} ms`:check.ageSeconds!=null?`${check.ageSeconds}s since heartbeat`:""}</span></div>)}</div><div className="finance-grid" style={{marginTop:16}}><div><small>Failed jobs</small><strong>{systemOps.health?.failedJobs??0}</strong></div><div><small>Release</small><strong>{systemOps.health?.app?.version||"unknown"}</strong></div><div><small>Recent backups</small><strong>{systemOps.backups?.filter(/** Inline callback for this operation. */ b=>b.status==="completed").length||0}</strong></div><div><small>Launch blockers</small><strong>{systemOps.launchGate?.blockersCount??"—"}</strong><span>{systemOps.launchGate?.ready?"Automated gates pass":"Launch gate needs attention"}</span></div></div></Card>}
		{systemOps&&<div className="ops-grid system-section"><Card><SectionHeader title="Release operations" sub="Audited deployment evidence and production configuration"/><div className="finance-grid"><div><small>Configuration blockers</small><strong>{systemOps.configuration?.blockersCount??"—"}</strong></div><div><small>Deployment records</small><strong>{systemOps.deployments?.length||0}</strong></div><div><small>Open SEV1/SEV2</small><strong>{(systemOps.incidents||[]).filter(/** Inline callback for this operation. */ i=>i.status!=="resolved"&&["sev1","sev2"].includes(i.severity)).length}</strong></div></div><div className="simple-list">{(systemOps.deployments||[]).slice(0,6).map(/** Inline callback for this operation. */ d=><div key={d.id}><FaShieldAlt/><span><b>{d.release} · {d.status}</b><small>{d.phase} · {d.previousRelease?`from ${d.previousRelease} · `:""}{d.backupId?`backup ${d.backupId}`:"backup evidence pending"}</small></span><Badge tone={d.status==="completed"?"success":d.status==="failed"?"danger":"primary"}>{d.status}</Badge></div>)}</div></Card><Card><SectionHeader title="Incident command" sub="Append-only operator timeline; unresolved SEV1/SEV2 blocks launch"/>{(systemOps.incidents||[]).filter(/** Inline callback for this operation. */ i=>i.status!=="resolved").length?(systemOps.incidents||[]).filter(/** Inline callback for this operation. */ i=>i.status!=="resolved").slice(0,5).map(/** Inline callback for this operation. */ i=><div className="incident-ops-card" key={i.id}><div><Badge tone={["sev1","sev2"].includes(i.severity)?"danger":"warning"}>{i.severity}</Badge> <b>{i.title}</b><small>{i.status} · {i.type} · {i.startedAt?new Date(i.startedAt).toLocaleString():""}</small></div><Field label="Operator update" value={incidentText(i.id)} onChange={/** Inline callback for this operation. */ e=>setIncidentText(i.id,e.target.value)} placeholder="What changed, evidence, next action or resolution"/><div className="button-row"><Button disabled={!!busy} onClick={/** Inline callback for this operation. */ ()=>incidentAction(i,"note")}>Add note</Button><Button disabled={!!busy} onClick={/** Inline callback for this operation. */ ()=>incidentAction(i,"investigating")}>Investigating</Button><Button disabled={!!busy} onClick={/** Inline callback for this operation. */ ()=>incidentAction(i,"monitoring")}>Monitoring</Button><Button disabled={!!busy} onClick={/** Inline callback for this operation. */ ()=>incidentAction(i,"resolve")}>Resolve</Button></div><div className="simple-list">{(i.events||[]).slice(0,3).map(/** Inline callback for this operation. */ e=><div key={e.id}><span><b>{e.type}</b><small>{e.message} · {e.occurredAt?new Date(e.occurredAt).toLocaleString():""}</small></span></div>)}</div></div>):<p>No active incidents.</p>}</Card></div>}
		<div className="metric-grid"><Card><FaMoneyBillWave/><small>Seller payable</small><strong>{m(summary.ledger?.sellerPayableMinor)}</strong><span>Net outstanding liability</span></Card><Card><FaStore/><small>Platform commission</small><strong>{m(summary.ledger?.platformCommissionRevenueMinor)}</strong><span>Net commission revenue</span></Card><Card><FaTag/><small>Coupon subsidy</small><strong>{m(summary.ledger?.reviewCouponSubsidyExpenseMinor)}</strong><span>Platform-funded review rewards</span></Card><Card><FaUndo/><small>Seller recovery</small><strong>{m(summary.ledger?.sellerRecoveryReceivableMinor)}</strong><span>Refunds after seller payout</span></Card></div>
		<div className="ops-grid"><Card><h2>Cash & receivables</h2><div className="finance-grid"><div><small>Payment clearing</small><strong>{m(summary.ledger?.paymentClearingMinor)}</strong></div><div><small>COD receivable</small><strong>{m(summary.ledger?.codReceivableMinor)}</strong></div></div></Card><Card><h2>Operational liabilities</h2><div className="finance-grid"><div><small>VSN Coins</small><strong>{m(summary.operationalLiabilities?.vsnCoinLiabilityMinor)}</strong></div><div><small>Affiliate pending</small><strong>{m(summary.operationalLiabilities?.affiliatePendingLiabilityMinor)}</strong></div><div><small>Game prizes</small><strong>{m(summary.operationalLiabilities?.gamePrizeLiabilityMinor)}</strong></div></div></Card></div>
		<Card className="system-section"><SectionHeader title="Seller payout queue" sub="Finance approval and confirmed payout settlement"/><div className="simple-list">{payouts.length?payouts.map(/** Inline callback for this operation. */ p=><div key={p.id}><FaMoneyBillWave/><span><b>{p.vendor||"Vendor"} · {p.id}</b><small>{p.status} · requested by {p.requestedBy||"seller"}</small></span><strong>{m(p.amountMinor)}</strong>{p.status==="requested"&&<><Button variant="secondary" disabled={!!busy} onClick={/** Inline callback for this operation. */ ()=>act(p.id,"review",{approve:false,note:"Rejected by finance"})}>Reject</Button><Button disabled={!!busy} onClick={/** Inline callback for this operation. */ ()=>act(p.id,"review",{approve:true})}>Approve</Button></>}{["approved","processing"].includes(p.status)&&<><input placeholder="Bank/provider reference" value={refs[p.id]||""} onChange={/** Inline callback for this operation. */ e=>setRefs({...refs,[p.id]:e.target.value})}/><Button disabled={!!busy||!(refs[p.id]||"").trim()} onClick={/** Inline callback for this operation. */ ()=>act(p.id,"paid",{providerReference:refs[p.id].trim()})}>Mark paid</Button><Button variant="secondary" disabled={!!busy} onClick={/** Inline callback for this operation. */ ()=>act(p.id,"cancel",{note:"Cancelled by finance"})}>Cancel</Button></>}</div>):<p>No seller payouts queued.</p>}</div><div style={{marginTop:16,display:"flex",gap:8,flexWrap:"wrap"}}><Button variant="secondary" disabled={!!busy||!payouts.some(/** Inline callback for this operation. */ p=>p.status==="approved"&&!p.batchId)} onClick={createBatch}>{busy==="batch"?"Creating batch…":"Batch approved payouts"}</Button></div></Card>
		<Card className="system-section"><SectionHeader title="Payout batches" sub="Approved seller payouts grouped for bank/provider processing"/><div className="simple-list">{batches.length?batches.map(/** Inline callback for this operation. */ b=><div key={b.id}><FaMoneyBillWave/><span><b>{b.id}</b><small>{b.status} · {b.payoutCount} payouts · {b.providerBatchReference||"provider batch reference pending"}</small></span><strong>{m(b.totalMinor)}</strong></div>):<p>No payout batches yet.</p>}</div></Card>
		<Card><h2>Ledger reconciliation</h2><p>Backfill missing order journals, reconcile settlement states and verify every journal remains debit/credit balanced.</p><Button disabled={!!busy} onClick={reconcile}>{busy==="reconcile"?"Reconciling…":"Run reconciliation"}</Button></Card>
	</Page>;
}

/** Handles legacy operations center for the VSN Ecommerce interface. */
function LegacyOperationsCenter() {
	const s = useStore();
	const [reserveMsg, setReserveMsg] = useState("");
	const reserve = /** Handles reserve for the VSN Ecommerce interface. */ () => {
		const r = s.reserveInventory(1, 1, 15);
		setReserveMsg(r.msg);
	};
	return (
		<Page
			title="Marketplace operations"
			sub="Shipping, inventory, payments, notifications and order-splitting operational flows."
		>
			<div className="metric-grid">
				<Card>
					<FaBox />
					<small>Reserved inventory</small>
					<strong>{s.inventoryReservations.length}</strong>
					<span>Checkout holds</span>
				</Card>
				<Card>
					<FaStore />
					<small>Seller sub-orders</small>
					<strong>{s.subOrders.length}</strong>
					<span>Multi-vendor split</span>
				</Card>
				<Card>
					<FaMoneyBillWave />
					<small>Seller payable</small>
					<strong>Rs. {s.finance.sellerPayable.toLocaleString()}</strong>
					<span>After commission</span>
				</Card>
				<Card>
					<FaBell />
					<small>Notification queue</small>
					<strong>{s.notificationQueue.length}</strong>
					<span>Email / SMS / push</span>
				</Card>
			</div>
			<div className="ops-grid">
				<Card>
					<h2>Inventory reservation</h2>
					<p>
						Reserve stock before payment so two buyers cannot oversell the same
						SKU.
					</p>
					<Button onClick={reserve}>Reserve iPhone for 15 min</Button>
					{reserveMsg && <p>{reserveMsg}</p>}
					<div className="simple-list">
						{s.inventoryReservations.map(/** Inline callback for this operation. */ (r) => (
							<div key={r.id}>
								<span>
									<b>SKU {r.productId}</b>
									<small>
										{r.qty} unit · expires{" "}
										{new Date(r.expiresAt).toLocaleTimeString()}
									</small>
								</span>
								<Badge tone="primary">held</Badge>
							</div>
						))}
					</div>
				</Card>
				<Card>
					<h2>Shipping quotes</h2>
					{s.shippingQuotes.map(/** Inline callback for this operation. */ (q) => (
						<div className="shipping-quote" key={q.id}>
							<span>
								<b>{q.name}</b>
								<small>{q.eta}</small>
							</span>
							<strong>Rs. {q.price}</strong>
						</div>
					))}
				</Card>
				<Card>
					<h2>Fraud & abuse controls</h2>
					<div className="risk-status">
						{s.riskSignals.map(/** Inline callback for this operation. */ (x) => (
							<Status key={x.label} ok={x.level === "low"}>
								{x.label} · {x.level}
							</Status>
						))}
					</div>
				</Card>
				<Card>
					<h2>Feature flags</h2>
					{Object.entries(s.featureFlags).map(/** Inline callback for this operation. */ ([k, v]) => (
						<label className="flag-row" key={k}>
							<span>{k}</span>
							<input
								type="checkbox"
								checked={v}
								onChange={/** Inline callback for this operation. */ () => s.toggleFeature(k)}
							/>
						</label>
					))}
				</Card>
			</div>
			<Card className="system-section">
				<SectionHeader
					title="Finance & liabilities"
					sub="Operational obligations that should reconcile against marketplace orders and ledgers"
				/>
				<div className="finance-grid">
					{Object.entries(s.finance).map(/** Inline callback for this operation. */ ([k, v]) => (
						<div key={k}>
							<small>{k.replace(/([A-Z])/g, " $1")}</small>
							<strong>Rs. {Number(v).toLocaleString()}</strong>
						</div>
					))}
				</div>
			</Card>
		</Page>
	);
}

/** Handles seller quality for the VSN Ecommerce interface. */
export function SellerQuality() {
	if (apiBackend !== "laravel") return <LegacySellerQuality />;
	return <LaravelSellerQuality />;
}

/** Handles laravel seller quality for the VSN Ecommerce interface. */
function LaravelSellerQuality() {
	const [rows,setRows]=useState([]); const [error,setError]=useState(""); const [loading,setLoading]=useState(true);
	useEffect(/** Inline callback for this operation. */ ()=>{apiGet("/admin/shipping/quality").then(/** Inline callback for this operation. */ x=>setRows(Array.isArray(x)?x:[])).catch(/** Inline callback for this operation. */ e=>setError(e.message)).finally(/** Inline callback for this operation. */ ()=>setLoading(false));},[]);
	return <Page title="Seller quality & SLA" sub="Live courier-derived seller dispatch and delivery performance.">
		{error&&<Status>{error}</Status>}
		<div className="seller-quality-grid">{loading?<Card><p>Loading SLA metrics…</p></Card>:rows.map(/** Inline callback for this operation. */ v=>{const score=Math.round((Number(v.onTimeDispatchPercent||0)+Number(v.onTimeDeliveryPercent||0))/2);return <Card key={v.vendorId}><div className="card-title"><div><h2>{v.vendor}</h2><p>{v.shipments} shipments in {v.days} days</p></div><Badge tone={score>=90?"success":"deal"}>{score}/100</Badge></div><div className="quality-list"><span>On-time dispatch <b>{v.onTimeDispatchPercent}%</b></span><span>On-time delivery <b>{v.onTimeDeliveryPercent}%</b></span><span>Active dispatch breaches <b>{v.lateDispatchActive}</b></span><span>Active delivery breaches <b>{v.lateDeliveryActive}</b></span><span>Failed / RTO <b>{v.failedDeliveries} / {v.rtoCount}</b></span><span>Commission <b>{(Number(v.commissionBps||0)/100).toFixed(2)}%</b></span><span>Payout hold <b>{v.payoutHoldDays} days</b></span></div></Card>})}</div>
	</Page>;
}

/** Handles legacy seller quality for the VSN Ecommerce interface. */
function LegacySellerQuality() {
	const s = useStore();
	return <Page title="Seller quality & SLA" sub="Service quality, marketplace commission and payout maturity controls."><div className="seller-quality-grid">{s.sellerScores.map(/** Inline callback for this operation. */ (v)=><Card key={v.name}><div className="card-title"><div><h2>{v.name}</h2><p>{v.category}</p></div><Badge tone={v.score>90?"success":"deal"}>{v.score}/100</Badge></div><div className="quality-list"><span>On-time dispatch <b>{v.dispatch}%</b></span><span>Return rate <b>{v.returns}%</b></span><span>Cancellation rate <b>{v.cancel}%</b></span><span>Commission <b>{v.commission}%</b></span><span>Payout hold <b>{v.hold} days</b></span></div></Card>)}</div></Page>;
}

