import { useCallback, useEffect, useRef, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { apiDelete, apiGet, apiPost, apiPut, apiUrl } from "../platform/api";
import { moneyFromMinor, useCart } from "../platform/cart";
import { useLaravelGifts } from "../platform/gifts";
import { useLaravelWallet } from "../platform/wallet";
import { useAuth } from "../platform/auth";
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
	FaStore,
	FaTruck,
	FaUsers,
	FaWallet,
	FaUndo,
	FaShieldAlt,
	FaTag,
	FaStar,
} from "react-icons/fa";

/** Renders a Systems page without client-side business-authority fallbacks. */
function Page({ title, sub, children }) {
	return <>
		<SEO title={`${title} | VSN Ecommerce`} description={sub} />
		<div className="simple-page">
			<div className="page-title"><h1>{title}</h1><p>{sub}</p></div>
			{children}
		</div>
	</>;
}

/** Renders a terminal API failure for a Systems route. */
function FailurePage({ title, sub, error }) {
	return <Page title={title} sub={sub}><Card><Status>{error || "Server data is unavailable."}</Status></Card></Page>;
}

/** Renders one shipment timeline step. */
function Step({ done, active, title, text }) {
	return <div className={`track-step ${done ? "done" : ""} ${active ? "active" : ""}`}><i /><span><b>{title}</b><small>{text}</small></span></div>;
}

/** Shows orders using the Laravel order contract only. */
export function Orders() {
	const [orders, setOrders] = useState([]);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState("");
	useEffect(() => {
		let live = true;
		apiGet("/orders")
			.then((data) => { if (live) { setOrders(Array.isArray(data) ? data : []); setError(""); } })
			.catch((err) => live && setError(err.message || "Orders could not be loaded."))
			.finally(() => live && setLoading(false));
		return () => { live = false; };
	}, []);
	if (!loading && error) return <FailurePage title="My orders" sub="Server-authoritative marketplace orders." error={error} />;
	return <Page title="My orders" sub="Laravel orders created from reserved inventory and seller sub-orders.">
		<div className="order-list">
			{loading ? <Card><p>Loading orders…</p></Card> : orders.length ? orders.map((order) => <Card key={order.id} className="order-card">
				<div><Badge tone={order.status === "delivered" ? "success" : "primary"}>{order.status}</Badge><h3>{order.id}</h3><p>{order.items?.length || 0} items · Rs. {moneyFromMinor(order.totals?.totalMinor).toLocaleString()}</p></div>
				<div><small>{order.sellerOrders?.length || 0} seller order{order.sellerOrders?.length === 1 ? "" : "s"}</small><strong>{order.paymentMethod === "cod" ? "Cash on delivery" : order.paymentStatus}</strong><small>{order.placedAt ? new Date(order.placedAt).toLocaleString() : ""}</small><Link to="/invoices">Tax invoices</Link>{order.shipments?.length > 0 && <Link to={`/tracking?order=${encodeURIComponent(order.id)}`}>Track {order.shipments.length} shipment{order.shipments.length === 1 ? "" : "s"}</Link>}{order.sellerOrders?.map((sellerOrder) => <Link key={sellerOrder.id} to={`/messages?vendorOrder=${encodeURIComponent(sellerOrder.id)}`}>Message {sellerOrder.seller}</Link>)}</div>
			</Card>) : <Card><p>No orders yet.</p><Link to="/">Continue shopping</Link></Card>}
		</div>
	</Page>;
}

/** Runs checkout entirely from server-issued cart, wallet, address, quote and payment state. */
export function Checkout() {
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
	const [paymentKey, setPaymentKey] = useState(() => `payment-${Date.now()}-${Math.random().toString(36).slice(2)}`);
	const [couponCode, setCouponCode] = useState("");
	const [attemptKey, setAttemptKey] = useState(() => `checkout-${Date.now()}-${Math.random().toString(36).slice(2)}`);
	const [session, setSession] = useState(null);
	const [order, setOrder] = useState(null);
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState("");
	const [initialError, setInitialError] = useState("");
	const [loading, setLoading] = useState(true);
	const resumeRef = useRef(false);

	useEffect(() => {
		let live = true;
		apiGet("/checkout/current").then((current) => {
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
		}).catch(() => {});
		return () => { live = false; };
	}, []);

	useEffect(() => {
		let live = true;
		apiGet("/wallet").then((next) => live && setWallet(next)).catch((err) => live && setInitialError(err.message || "Wallet data could not be loaded."));
		return () => { live = false; };
	}, []);

	useEffect(() => {
		let live = true;
		apiGet("/addresses")
			.then((rows) => {
				if (!live) return;
				const list = Array.isArray(rows) ? rows : [];
				setAddresses(list);
				const preferred = list.find((row) => row.is_default) || list[0];
				if (preferred && !resumeRef.current) setAddressId(String(preferred.id));
			})
			.catch((err) => live && setInitialError(err.message || "Sign in to continue to checkout."))
			.finally(() => live && setLoading(false));
		return () => { live = false; };
	}, []);

	useEffect(() => {
		if (!addressId) {
			setOptions({ shippingQuotes: [], paymentMethods: [], savedPaymentMethods: [] });
			return;
		}
		let live = true;
		apiGet(`/checkout/options?addressId=${encodeURIComponent(addressId)}`)
			.then((next) => {
				if (!live) return;
				const normalized = next || { shippingQuotes: [], paymentMethods: [], savedPaymentMethods: [] };
				setOptions(normalized);
				const preferredSaved = normalized.savedPaymentMethods?.find((method) => method.default) || normalized.savedPaymentMethods?.[0];
				setSavedPaymentMethodId(preferredSaved?.id || "");
				const firstShipping = normalized.shippingQuotes?.[0]?.code || "";
				setShipping((current) => normalized.shippingQuotes?.some((quote) => quote.code === current) ? current : firstShipping);
				const enabledPayment = normalized.paymentMethods?.find((method) => method.enabled)?.code || "cod";
				setPayment((current) => normalized.paymentMethods?.some((method) => method.code === current && method.enabled) ? current : enabledPayment);
				if (resumeRef.current) { resumeRef.current = false; return; }
				setSession(null);
				setPaymentIntent(null);
				setPaymentKey(`payment-${Date.now()}-${Math.random().toString(36).slice(2)}`);
			})
			.catch((err) => live && setError(err.message || "Checkout options could not be loaded."));
		return () => { live = false; };
	}, [addressId]);

	const selectedQuote = options.shippingQuotes?.find((quote) => quote.code === shipping);
	const estimatedSubtotal = cart?.summary?.subtotalMinor || 0;
	const estimatedShipping = selectedQuote?.amountMinor || 0;
	const estimatedTotal = estimatedSubtotal + estimatedShipping;
	const serverCoinRate = Number(wallet?.coinsPerRupee);
	const hasServerCoinRate = Number.isFinite(serverCoinRate) && serverCoinRate > 0;
	const availableRedeemableCoins = hasServerCoinRate ? Math.floor((wallet?.availableCoins || 0) / serverCoinRate) * serverCoinRate : 0;
	const maxRedeemableCoins = hasServerCoinRate ? Math.min(availableRedeemableCoins, Math.floor(estimatedTotal / 100) * serverCoinRate) : 0;

	const reserveCheckout = async () => {
		if (!addressId || !shipping || !payment || (payment === "coins" && !hasServerCoinRate)) return;
		setBusy(true); setError("");
		try {
			const next = await apiPost("/checkout/sessions", {
				addressId: Number(addressId), shippingMethod: shipping, paymentMethod: payment,
				savedPaymentMethodId: payment === "card" ? (savedPaymentMethodId || null) : null,
				couponCode: couponCode.trim() || null, coinRedemptionCoins: Number(coinRedemption) || 0,
				idempotencyKey: attemptKey,
			});
			setSession(next);
			apiGet("/wallet").then(setWallet).catch(() => {});
			setPaymentIntent(null);
			setPaymentKey(`payment-${Date.now()}-${Math.random().toString(36).slice(2)}`);
			setStep(4);
		} catch (err) { setError(err.message || "Checkout could not reserve inventory."); }
		finally { setBusy(false); }
	};

	const placeOrder = async () => {
		if (!session?.id) return;
		setBusy(true); setError("");
		try {
			if (payment !== "cod" && payment !== "coins") {
				setPaymentIntent(await apiPost(`/checkout/sessions/${session.id}/payments`, { idempotencyKey: paymentKey }));
				return;
			}
			setOrder(await apiPost(`/checkout/sessions/${session.id}/order`, {}));
			await refreshCart().catch(() => {});
			await apiGet("/wallet").then(setWallet).catch(() => {});
		} catch (err) { setError(err.message || "Order could not be placed."); }
		finally { setBusy(false); }
	};

	const refreshPayment = async () => {
		if (!paymentIntent?.id) return;
		setBusy(true); setError("");
		try {
			const current = await apiPost(`/payments/${paymentIntent.id}/refresh-provider`, {});
			setPaymentIntent(current);
			if (current.orderId) {
				setOrder(await apiGet(`/orders/${current.orderId}`));
				await refreshCart().catch(() => {});
				await apiGet("/wallet").then(setWallet).catch(() => {});
			}
		} catch (err) { setError(err.message || "Payment status could not be refreshed."); }
		finally { setBusy(false); }
	};

	const simulateSandboxPayment = async () => {
		if (!paymentIntent?.id) return;
		setBusy(true); setError("");
		try {
			const current = await apiPost(`/payments/${paymentIntent.id}/sandbox/complete`, {});
			setPaymentIntent(current);
			if (current.orderId) {
				setOrder(await apiGet(`/orders/${current.orderId}`));
				await refreshCart().catch(() => {});
				await apiGet("/wallet").then(setWallet).catch(() => {});
			}
		} catch (err) { setError(err.message || "Sandbox payment could not be completed."); }
		finally { setBusy(false); }
	};

	const cancelReservation = async () => {
		if (!session?.id) return;
		setBusy(true); setError("");
		try {
			await apiDelete(`/checkout/sessions/${session.id}`);
			await apiGet("/wallet").then(setWallet).catch(() => {});
			setSession(null); setPaymentIntent(null);
			setPaymentKey(`payment-${Date.now()}-${Math.random().toString(36).slice(2)}`);
			setAttemptKey(`checkout-${Date.now()}-${Math.random().toString(36).slice(2)}`);
			setStep(1);
		} catch (err) { setError(err.message || "Reservation could not be released."); }
		finally { setBusy(false); }
	};

	if (initialError && !session) return <FailurePage title="Checkout" sub="Server-authoritative marketplace checkout." error={initialError} />;
	if (order) return <Page title="Order confirmed" sub="Your Laravel marketplace order was created and split by seller."><Card className="confirmation-card"><FaCheckCircle /><h2>Thank you for your order</h2><p>Order <b>{order.id}</b> is confirmed. Inventory reservations were converted into stock movements atomically.</p><div className="simple-list">{(order.sellerOrders || []).map((sellerOrder) => <div key={sellerOrder.id}><FaStore /><span className="order-sub"><b>{sellerOrder.id}</b><small>{sellerOrder.seller}</small></span><Badge tone="primary">{sellerOrder.status}</Badge></div>)}</div>{order.totals?.coinRedemptionCoins > 0 && <p><span>VSN Coins used</span><b>{order.totals.coinRedemptionCoins.toLocaleString()}</b></p>}<p className="summary-total"><span>Cash / provider amount</span><b>Rs. {moneyFromMinor(order.totals?.totalMinor).toLocaleString()}</b></p><Link className="ui-btn ui-btn--primary" to="/account/orders">View orders</Link></Card></Page>;
	if (!loading && !cart?.items?.length) return <Page title="Checkout" sub="Your cart has no purchasable items."><Card><p>Your cart is empty.</p><Link className="ui-btn ui-btn--primary" to="/">Continue shopping</Link></Card></Page>;

	return <Page title="Checkout" sub="Laravel checkout with server pricing, signed payment webhooks, seller-aware shipping and inventory reservation.">
		<div className="checkout-layout"><div>
			<div className="checkout-steps"><span className={step >= 1 ? "active" : ""}>1 Address</span><span className={step >= 2 ? "active" : ""}>2 Delivery</span><span className={step >= 3 ? "active" : ""}>3 Payment</span><span className={step >= 4 ? "active" : ""}>4 Review</span></div>
			{error && <Status>{error}</Status>}
			{step === 1 && <Card><h2>Delivery address</h2>{loading ? <p>Loading addresses…</p> : addresses.length ? addresses.map((address) => <label className="choice-row" key={address.id} htmlFor={`checkout-address-${address.id}`}><input id={`checkout-address-${address.id}`} type="radio" name="address" checked={String(address.id) === addressId} onChange={() => { setAddressId(String(address.id)); setAttemptKey(`checkout-${Date.now()}-${Math.random().toString(36).slice(2)}`); }} /><span><b>{address.label || address.recipient_name}</b><small>{[address.line1, address.line2, address.city, address.state].filter(Boolean).join(", ")}</small></span></label>) : <p>No address found. <Link to="/profile">Add an address</Link>.</p>}<Button disabled={!addressId} onClick={() => setStep(2)}>Continue</Button></Card>}
			{step === 2 && <Card><h2>Delivery option</h2>{(options.shippingQuotes || []).map((quote) => <label className="choice-row" key={quote.code}><input type="radio" name="ship" checked={shipping === quote.code} onChange={() => { setShipping(quote.code); setSession(null); setAttemptKey(`checkout-${Date.now()}-${Math.random().toString(36).slice(2)}`); }} /><span><b>{quote.name}</b><small>{quote.eta} · {quote.vendorCount} seller shipment{quote.vendorCount === 1 ? "" : "s"}</small></span><strong>Rs. {moneyFromMinor(quote.amountMinor).toLocaleString()}</strong></label>)}<Button variant="secondary" onClick={() => setStep(1)}>Back</Button>{" "}<Button disabled={!shipping} onClick={() => setStep(3)}>Continue</Button></Card>}
			{step === 3 && <Card><h2>Payment & adjustments</h2>{(options.paymentMethods || []).map((method) => { const enabled = method.enabled && (method.code !== "coins" || hasServerCoinRate); return <label className={`choice-row ${enabled ? "" : "is-disabled"}`} key={method.code} htmlFor={`checkout-payment-${method.code}`}><input id={`checkout-payment-${method.code}`} type="radio" name="pay" disabled={!enabled} checked={payment === method.code} onChange={() => { if (!enabled) return; setPayment(method.code); if (method.code === "coins") setCoinRedemption(maxRedeemableCoins); setSession(null); setPaymentIntent(null); setPaymentKey(`payment-${Date.now()}-${Math.random().toString(36).slice(2)}`); setAttemptKey(`checkout-${Date.now()}-${Math.random().toString(36).slice(2)}`); }} /><span><b>{method.name}</b><small>{method.code === "coins" && !hasServerCoinRate ? "Coin conversion is unavailable from the server." : method.description}</small></span></label>; })}
				{payment === "card" && <div><h3>Saved payment method</h3>{(options.savedPaymentMethods || []).length ? options.savedPaymentMethods.map((method) => <label className="choice-row" key={method.id} htmlFor={`checkout-saved-card-${method.id}`}><input id={`checkout-saved-card-${method.id}`} type="radio" name="saved-card" checked={savedPaymentMethodId === method.id} onChange={() => { setSavedPaymentMethodId(method.id); setSession(null); setPaymentIntent(null); setAttemptKey(`checkout-${Date.now()}-${Math.random().toString(36).slice(2)}`); }} /><span><b>{(method.brand || "Card").toUpperCase()} •••• {method.last4}</b><small>{method.default ? "Default · " : ""}{method.provider} · expires {String(method.expiry?.month || "--").padStart(2,"0")}/{method.expiry?.year || "----"}</small></span></label>) : <p>No saved card yet. <Link to="/profile">Manage payment methods</Link>.</p>}</div>}
				<Field label="Coupon code" value={couponCode} onChange={(event) => { setCouponCode(event.target.value); setSession(null); setAttemptKey(`checkout-${Date.now()}-${Math.random().toString(36).slice(2)}`); }} placeholder="Optional" help="Promotions are revalidated when stock is reserved." />
				{hasServerCoinRate ? <><Field label="VSN Coins to use" type="number" min="0" step={serverCoinRate} value={coinRedemption} onChange={(event) => { const value = Math.max(0, Math.min(maxRedeemableCoins, Number(event.target.value) || 0)); setCoinRedemption(value); setSession(null); setAttemptKey(`checkout-${Date.now()}-${Math.random().toString(36).slice(2)}`); }} help={`${(wallet?.availableCoins || 0).toLocaleString()} available · use increments of ${serverCoinRate} · max ${maxRedeemableCoins.toLocaleString()} for this checkout`} />{maxRedeemableCoins > 0 && <Button variant="secondary" onClick={() => setCoinRedemption(maxRedeemableCoins)}>Use maximum coins</Button>}</> : <Status>VSN Coin conversion is unavailable until the server provides a valid rate.</Status>}
				<Button variant="secondary" onClick={() => setStep(2)}>Back</Button>{" "}<Button disabled={busy || !payment || (payment === "coins" && !hasServerCoinRate)} onClick={reserveCheckout}>{busy ? "Reserving…" : "Reserve stock & review"}</Button>
			</Card>}
			{step === 4 && session && <Card><h2>Review marketplace order</h2><Status ok>Stock reserved until {new Date(session.expiresAt).toLocaleTimeString()}.</Status><div className="simple-list">{(session.items || []).map((item) => <div key={item.id}><FaStore /><span><b>{item.productName}</b><small>{item.vendor || "Marketplace"} · {item.variantName} · Qty {item.quantity}</small></span><strong>Rs. {moneyFromMinor(item.lineTotalMinor).toLocaleString()}</strong></div>)}</div>{session.savedPaymentMethod && <p><b>Saved card:</b> {(session.savedPaymentMethod.brand || "Card").toUpperCase()} •••• {session.savedPaymentMethod.last4}</p>}{paymentIntent && <div className="simple-list"><div><FaCreditCard /><span><b>Payment {paymentIntent.status}</b><small>{paymentIntent.provider} · {paymentIntent.id}</small></span><strong>Rs. {moneyFromMinor(paymentIntent.amountMinor).toLocaleString()}</strong></div></div>}<Button variant="secondary" disabled={busy} onClick={cancelReservation}>Change checkout</Button>{" "}{(payment === "cod" || payment === "coins") && <Button disabled={busy || !session.canPlaceOrder} onClick={placeOrder}>{busy ? "Placing…" : "Place order"}</Button>}{payment !== "cod" && payment !== "coins" && !paymentIntent && <Button disabled={busy || !session.canPlaceOrder} onClick={placeOrder}>{busy ? "Starting…" : "Start secure payment"}</Button>}{paymentIntent && !paymentIntent.orderId && <Button variant="secondary" disabled={busy} onClick={refreshPayment}>{busy ? "Checking…" : "Refresh payment"}</Button>}{paymentIntent?.sandboxCanSimulate && !paymentIntent.orderId && <>{" "}<Button disabled={busy} onClick={simulateSandboxPayment}>{busy ? "Processing…" : "Simulate signed sandbox payment"}</Button></>}{paymentIntent?.provider === "stripe" && paymentIntent?.clientAction?.type === "stripe_payment_intent" && !paymentIntent.orderId && <StripePaymentElement clientAction={paymentIntent.clientAction} savedMethod={Boolean(paymentIntent.savedPaymentMethod)} onSubmitted={refreshPayment} />}</Card>}
		</div><Card className="order-summary"><h3>Order summary</h3><p><span>Items</span><b>Rs. {moneyFromMinor(session?.totals?.subtotalMinor ?? estimatedSubtotal).toLocaleString()}</b></p><p><span>Delivery</span><b>Rs. {moneyFromMinor(session?.totals?.shippingMinor ?? estimatedShipping).toLocaleString()}</b></p>{session?.totals?.discountMinor > 0 && <p><span>Discount</span><b>- Rs. {moneyFromMinor(session.totals.discountMinor).toLocaleString()}</b></p>}{session?.totals?.taxMinor > 0 && <p><span>Tax</span><b>Rs. {moneyFromMinor(session.totals.taxMinor).toLocaleString()}</b></p>}{session?.totals?.coinRedemptionCoins > 0 && <p><span>VSN Coins</span><b>- {session.totals.coinRedemptionCoins.toLocaleString()} coins</b></p>}<hr /><p className="summary-total"><span>Total</span><b>Rs. {moneyFromMinor(session?.totals?.totalMinor ?? estimatedTotal).toLocaleString()}</b></p><small>Reserved totals are immutable server snapshots.</small></Card></div>
	</Page>;
}

/** Shows courier tracking from the shipment API only. */
export function Tracking() {
	const [params] = useSearchParams();
	const orderId = params.get("order");
	const [shipments, setShipments] = useState([]);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState("");
	useEffect(() => {
		let live = true; setLoading(true);
		apiGet("/shipments").then((rows) => { if (!live) return; setShipments((Array.isArray(rows) ? rows : []).filter((row) => !orderId || row.orderId === orderId)); setError(""); }).catch((err) => live && setError(err.message || "Shipment tracking could not be loaded.")).finally(() => live && setLoading(false));
		return () => { live = false; };
	}, [orderId]);
	if (!loading && error) return <FailurePage title="Track order" sub="Server-authoritative shipment tracking." error={error} />;
	return <Page title="Track order" sub="Live courier events, seller-wise shipments and delivery SLA status from Laravel.">{loading ? <Card><p>Loading shipments…</p></Card> : shipments.length ? shipments.map((shipment) => <Card className="tracking-card" key={shipment.id}><div className="tracking-head"><div><Badge tone={shipment.status === "delivered" ? "success" : shipment.deliverySlaBreached ? "deal" : "primary"}>{shipment.status.replaceAll("_", " ")}</Badge><h2>{shipment.trackingNumber || shipment.id}</h2><p>{shipment.seller} · {shipment.provider} · {shipment.serviceCode}</p>{shipment.estimatedDeliveryAt && <small>Estimated delivery: {new Date(shipment.estimatedDeliveryAt).toLocaleString()}</small>}</div><FaTruck /></div>{(shipment.dispatchSlaBreached || shipment.deliverySlaBreached) && <Status>{shipment.deliverySlaBreached ? "Delivery SLA is overdue." : "Seller dispatch SLA was missed."}</Status>}<div className="timeline">{(shipment.events || []).length ? shipment.events.map((event, index, rows) => <Step key={event.id} done={index < rows.length - 1 || event.status === "delivered"} active={index === rows.length - 1 && event.status !== "delivered"} title={event.status.replaceAll("_", " ")} text={[event.message, event.location, event.occurredAt ? new Date(event.occurredAt).toLocaleString() : ""].filter(Boolean).join(" · ")} />) : <Step active title={shipment.status.replaceAll("_", " ")} text="Waiting for the next courier scan." />}</div></Card>) : <Card><p>{orderId ? "No shipment has been created for this order yet." : "No active or historical shipments yet."}</p><Link to="/orders">View orders</Link></Card>}</Page>;
}

/** Shows wallet balances and payment methods from Laravel only. */
export function Wallet() {
	const [wallet,setWallet]=useState(null),[methods,setMethods]=useState([]),[error,setError]=useState(""),[loading,setLoading]=useState(true);
	useEffect(()=>{let live=true;Promise.all([apiGet("/wallet"),apiGet("/payment-methods")]).then(([w,p])=>{if(!live)return;setWallet(w);setMethods(p.items||[]);setError("");}).catch((e)=>live&&setError(e.message||"Wallet data could not be loaded.")).finally(()=>live&&setLoading(false));return()=>{live=false;};},[]);
	if(!loading&&error)return <FailurePage title="Wallet" sub="Server-authoritative wallet and payment methods." error={error}/>;
	return <Page title="Wallet" sub="Balances, provider-tokenized payment methods and immutable coin activity.">{loading?<Card><p>Loading wallet…</p></Card>:<><div className="metric-grid"><Card><FaWallet/><small>Coin balance</small><strong>{Number(wallet?.balanceCoins||0).toLocaleString()}</strong><span>Rs. {Number(wallet?.valueRupees||0).toFixed(2)}</span></Card><Card><FaCreditCard/><small>Saved methods</small><strong>{methods.length}</strong><span>Provider tokens only</span></Card><Card><FaMoneyBillWave/><small>Reserved coins</small><strong>{Number(wallet?.reservedCoins||0).toLocaleString()}</strong><span>{Number(wallet?.availableCoins||0).toLocaleString()} available</span></Card></div><Card className="system-section"><SectionHeader title="Saved payment methods"/><div className="simple-list">{methods.length?methods.map((method)=><div key={method.id}><FaCreditCard/><span><b>{(method.brand||"Card").toUpperCase()} •••• {method.last4}</b><small>{method.provider} · {method.default?"Default":"Saved"}</small></span><Link to="/profile">Manage</Link></div>):<p>No saved payment methods. <Link to="/profile">Manage payment methods</Link>.</p>}</div></Card><Card className="system-section"><SectionHeader title="Recent transactions"/><div className="simple-list">{(wallet?.transactions||[]).slice(0,8).map((transaction)=><div key={transaction.id}><span><b>{transaction.type||transaction.referenceType||"Wallet transaction"}</b><small>{transaction.occurredAt?new Date(transaction.occurredAt).toLocaleString():""}</small></span><strong>{transaction.direction==="credit"?"+":"-"}{Number(transaction.coins||0).toLocaleString()} coins</strong></div>)}</div></Card></>}</Page>;
}

/** Shows notification history from the notification API only. */
export function Notifications() {
	const [items,setItems]=useState([]),[meta,setMeta]=useState({unreadCount:0}),[loading,setLoading]=useState(true),[error,setError]=useState("");
	const load=async()=>{try{const data=await apiGet("/notifications?perPage=100");setItems(data.items||[]);setMeta(data.meta||{});setError("");}catch(e){setError(e.message||"Notifications unavailable.");}finally{setLoading(false);}};
	useEffect(()=>{let live=true;const refresh=()=>live&&load();refresh();const id=setInterval(refresh,15000);return()=>{live=false;clearInterval(id);};},[]);
	const markRead=async(item)=>{if(item.read)return;try{await apiPost(`/notifications/${item.id}/read`,{});await load();}catch(e){setError(e.message);}};
	const readAll=async()=>{try{await apiPost("/notifications/read-all",{});await load();}catch(e){setError(e.message);}};
	if(!loading&&error&&!items.length)return <FailurePage title="Notifications" sub="Server-authoritative notification history." error={error}/>;
	return <Page title="Notifications" sub="Orders, shipping, games, gifts, reviews, returns and account alerts from Laravel.">{error&&<Status>{error}</Status>}<Card><div className="section-title"><div><h3>{meta.unreadCount||0} unread</h3><p>Server-authoritative notification history.</p></div><Button disabled={!meta.unreadCount} onClick={readAll}>Mark all read</Button></div><div className="simple-list">{loading?<p>Loading notifications…</p>:items.length?items.map((item)=><div key={item.id}><FaBell/><span><b>{item.title}</b><small>{item.body}</small><small>{item.createdAt?new Date(item.createdAt).toLocaleString():""} · {item.category}</small>{item.actionUrl&&<Link to={item.actionUrl}>View details</Link>}</span><Badge tone={item.read?"neutral":"primary"}>{item.read?"Read":"New"}</Badge>{!item.read&&<Button variant="secondary" onClick={()=>markRead(item)}>Mark read</Button>}</div>):<p>No notifications yet.</p>}</div></Card></Page>;
}

/** Shows buyer/seller/support conversations from the messages API only. */
export function Messages() {
	const [params,setParams]=useSearchParams();
	const vendorOrder=params.get("vendorOrder");
	const [threads,setThreads]=useState([]),[selected,setSelected]=useState(params.get("conversation")||""),[conversation,setConversation]=useState(null),[messages,setMessages]=useState([]),[text,setText]=useState(""),[files,setFiles]=useState([]),[error,setError]=useState(""),[busy,setBusy]=useState(false),[loaded,setLoaded]=useState(false);
	const loadThreads=useCallback(async()=>{try{const data=await apiGet("/messages/conversations");setThreads(data.items||[]);setSelected((current)=>current||data.items?.[0]?.id||"");setLoaded(true);}catch(e){setError(e.message||"Messages unavailable.");setLoaded(true);}},[]);
	const loadConversation=useCallback(async(id)=>{if(!id)return;try{const data=await apiGet(`/messages/conversations/${id}?perPage=100`);setConversation(data.conversation||null);setMessages(data.messages||[]);setError("");}catch(e){setError(e.message||"Conversation unavailable.");}},[]);
	useEffect(()=>{loadThreads();const id=setInterval(loadThreads,12000);return()=>clearInterval(id);},[loadThreads]);
	useEffect(()=>{if(!selected)return;setParams({conversation:selected},{replace:true});loadConversation(selected);const id=setInterval(()=>loadConversation(selected),8000);return()=>clearInterval(id);},[selected,setParams,loadConversation]);
	useEffect(()=>{if(!vendorOrder)return;let live=true;apiPost("/messages/conversations",{kind:"order",vendorOrderId:vendorOrder}).then((row)=>{if(!live)return;setSelected(row.id);setParams({conversation:row.id},{replace:true});loadThreads();}).catch((e)=>live&&setError(e.message));return()=>{live=false;};},[vendorOrder,setParams,loadThreads]);
	const startSupport=async()=>{setBusy(true);try{const row=await apiPost("/messages/conversations",{kind:"support"});setSelected(row.id);await loadThreads();}catch(e){setError(e.message);}finally{setBusy(false);}};
	const send=async()=>{if(!selected||(!text.trim()&&!files.length))return;setBusy(true);setError("");try{const form=new FormData();if(text.trim())form.append("body",text.trim());form.append("clientId",globalThis.crypto?.randomUUID?.()||`${Date.now()}-${Math.random()}`);files.forEach((file)=>form.append("attachments[]",file));await apiPost(`/messages/conversations/${selected}/messages`,form);setText("");setFiles([]);await Promise.all([loadConversation(selected),loadThreads()]);}catch(e){setError(e.message||"Message could not be sent.");}finally{setBusy(false);}};
	if(loaded&&error&&!threads.length)return <FailurePage title="Messages" sub="Server-authoritative buyer, seller and support conversations." error={error}/>;
	return <Page title="Messages" sub="Private buyer–seller order chat and VSN Support.">{error&&<Status>{error}</Status>}<div className="messages-layout"><Card className="thread-list"><div className="section-title"><h3>Conversations</h3><Button disabled={busy} onClick={startSupport}><FaHeadset/> Support</Button></div><div className="thread-list-scroll">{threads.length?threads.map((thread)=><button key={thread.id} className={`thread ${selected===thread.id?"active":""}`} onClick={()=>setSelected(thread.id)}>{thread.kind==="support"?<FaHeadset/>:<FaStore/>}<span><b>{thread.subject}</b><small>{thread.lastMessage?.body||(thread.kind==="support"?"General support":thread.vendor?.name||"Seller chat")}</small></span>{thread.unreadCount>0&&<Badge tone="primary">{thread.unreadCount}</Badge>}</button>):<p>No conversations yet. Open an order and choose Message seller, or start Support.</p>}</div></Card><Card className="chat-panel">{conversation?<><div className="section-title"><div><h3>{conversation.subject}</h3><p>{conversation.kind==="order"?`${conversation.vendor?.name||"Seller"} · ${conversation.orderId||""}`:"VSN Support"}</p></div><Badge tone={conversation.status==="open"?"success":"neutral"}>{conversation.status}</Badge></div><div className="chat-log">{messages.map((message)=><div key={message.id} className={`bubble ${message.sender?.me?"me":""}`}><b>{message.sender?.me?"You":message.sender?.name}</b>{message.body&&<span>{message.body}</span>}{message.attachments?.map((attachment)=><a key={attachment.id} href={apiUrl(attachment.downloadUrl)} target="_blank" rel="noreferrer">{attachment.name}</a>)}<small>{message.createdAt?new Date(message.createdAt).toLocaleString():""}</small></div>)}</div><div className="chat-compose"><input value={text} onChange={(event)=>setText(event.target.value)} onKeyDown={(event)=>{if(event.key==="Enter"&&!event.shiftKey){event.preventDefault();send();}}} placeholder="Write a message"/><input type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf" onChange={(event)=>setFiles(Array.from(event.target.files||[]).slice(0,4))}/><Button disabled={busy||(!text.trim()&&!files.length)} onClick={send}>{busy?"Sending…":"Send"}</Button></div></>:<div className="empty-state"><FaComments/><h3>Select a conversation</h3><p>Seller conversations are scoped to an individual seller sub-order.</p></div>}</Card></div></Page>;
}

/** Manages server-backed notification preferences only. */
export function Settings() {
	const [prefs,setPrefs]=useState(null),[error,setError]=useState(""),[saving,setSaving]=useState(false);
	useEffect(()=>{apiGet("/notification-preferences").then((data)=>setPrefs(data.preferences||{})).catch((e)=>setError(e.message||"Preferences could not be loaded."));},[]);
	const toggle=(category,channel)=>setPrefs((current)=>({...current,[category]:{...(current?.[category]||{}),[channel]:!current?.[category]?.[channel]}}));
	const save=async()=>{setSaving(true);try{const data=await apiPut("/notification-preferences",{preferences:prefs});setPrefs(data.preferences);setError("");}catch(e){setError(e.message);}finally{setSaving(false);}};
	const labels={orders:"Order updates",shipping:"Shipping & delivery",gifts:"Gifts",games:"Game Win",reviews:"Reviews",returns:"Returns & refunds",rewards:"Rewards",account:"Account & security",messages:"Messages",reports:"Reports"};
	if(error&&prefs===null)return <FailurePage title="Settings" sub="Server-authoritative communication preferences." error={error}/>;
	return <Page title="Settings" sub="Security, preferences and communication controls."><div className="two-col"><Card><h2>Security</h2><p>Identity, phone and password controls remain under your profile.</p><Link className="text-link" to="/profile">Manage profile & verification</Link></Card><Card><div className="section-title"><div><h2>Notifications</h2><p>In-app and email preferences are live. SMS/push remain provider-disabled.</p></div><Button disabled={!prefs||saving} onClick={save}>{saving?"Saving…":"Save"}</Button></div>{prefs?Object.keys(labels).map((category)=><div key={category} className="switch-row"><span><b>{labels[category]}</b><small>Choose delivery channels</small></span><label><input type="checkbox" checked={!!prefs[category]?.in_app} onChange={()=>toggle(category,"in_app")}/> In-app</label><label><input type="checkbox" checked={!!prefs[category]?.email} onChange={()=>toggle(category,"email")}/> Email</label></div>):<p>Loading preferences…</p>}</Card></div></Page>;
}

/** Manages product and coin gifts through Laravel contracts only. */
export function Gifts() {
	const gifts=useLaravelGifts();
	const wallet=useLaravelWallet();
	const [to,setTo]=useState(""),[coins,setCoins]=useState(140),[msg,setMsg]=useState(""),[busy,setBusy]=useState("");
	const sendCoinGift=async()=>{setBusy("coin");setMsg("");try{await apiPost("/wallet/transfers",{recipient:to.trim(),coins:Number(coins),idempotencyKey:`coin-gift-${globalThis.crypto?.randomUUID?.()||Date.now()}`});await Promise.all([gifts.refresh(),wallet.refresh()]);setMsg("Coin gift sent successfully.");}catch(error){setMsg(error.message||"Coin gift could not be sent.");}finally{setBusy("");}};
	const continueGiftPayment=async(gift)=>{setBusy(gift.id);setMsg("");try{if(!gift.paymentIntent){await gifts.startCardPayment(gift.checkoutId);setMsg("Card payment intent created.");}else if(gift.paymentIntent.sandboxCanSimulate){await gifts.completeSandboxPayment(gift.paymentIntent.id);await wallet.refresh();setMsg("Sandbox gift payment completed.");}}catch(error){setMsg(error.message||"Gift payment could not be completed.");}finally{setBusy("");}};
	const cancelGift=async(gift)=>{setBusy(gift.id);setMsg("");try{await gifts.cancelGift(gift.id);await wallet.refresh();setMsg("Gift checkout cancelled and reserved funds/stock released.");}catch(error){setMsg(error.message||"Gift could not be cancelled.");}finally{setBusy("");}};
	if((gifts.error||wallet.error)&&!gifts.loading&&!wallet.wallet)return <FailurePage title="Gifts" sub="Server-authoritative gifting and wallet state." error={gifts.error||wallet.error}/>;
	return <Page title="Gifts" sub="Send coin or product gifts and unlock server-tracked Gift Sender rewards.">{(gifts.error||wallet.error)&&<Status>{gifts.error||wallet.error}</Status>}{msg&&<Status>{msg}</Status>}<div className="gift-layout"><Card><h2>Send coin gift</h2><Field label="Recipient email or verified phone" value={to} onChange={(event)=>setTo(event.target.value)}/><Field label="Coins" type="number" min="1" value={coins} onChange={(event)=>setCoins(event.target.value)}/><p><small>Available: {Number(wallet.wallet?.availableCoins||0).toLocaleString()} coins</small></p><Button disabled={busy==="coin"||!to.trim()||Number(coins)<1} onClick={sendCoinGift}><FaGift/> {busy==="coin"?"Sending…":"Send gift"}</Button></Card><Card className="gift-level"><small>GIFT SENDER LEVEL</small><strong>{gifts.level.name}</strong><p>{gifts.lifetimeGiftCoins.toLocaleString()} lifetime gift coins sent</p><div className="progress"><i style={{width:`${gifts.level.progress||0}%`}}/></div><span>{gifts.level.nextReward?`Next reward: ${gifts.level.nextReward}`:"Top Gift Sender level reached"}</span></Card></div><SectionHeader title="Gift rewards" sub="Rewards are awarded once when a lifetime gifting threshold is crossed."/><div className="order-list">{gifts.rewards.length?gifts.rewards.map((reward)=><Card key={reward.id} className="order-card"><div><Badge tone="success">{reward.level}</Badge><h3>{reward.label}</h3></div><div><strong>{reward.status}</strong><small>{reward.awardedAt?new Date(reward.awardedAt).toLocaleString():""}</small></div></Card>):<Card><p>No Gift Sender rewards unlocked yet.</p></Card>}</div><SectionHeader title="Product gifts sent" sub="Payment, schedule and fulfillment remain server-authoritative."/><div className="order-list">{gifts.loading?<Card><p>Loading gifts…</p></Card>:gifts.sent.length?gifts.sent.map((gift)=><Card key={gift.id} className="order-card"><div><Badge tone={gift.status==="fulfilled"?"success":"primary"}>{gift.status}</Badge><h3>{gift.product?.name||"Product gift"}</h3><p>To {gift.recipient?.name||"recipient"} · {Number(gift.giftValueCoins||0).toLocaleString()} gift-value coins</p></div><div><small>{gift.scheduledFor?`Scheduled ${new Date(gift.scheduledFor).toLocaleString()}`:"Deliver normally"}</small><strong>{gift.paymentStatus||gift.paymentIntent?.status||gift.paymentMethod||"awaiting payment"}</strong>{gift.canCancel&&<Button variant="secondary" disabled={busy===gift.id} onClick={()=>cancelGift(gift)}>Cancel</Button>}{gift.paymentMethod==="card"&&gift.status==="awaiting_payment"&&(!gift.paymentIntent||gift.paymentIntent.sandboxCanSimulate)&&<Button disabled={busy===gift.id} onClick={()=>continueGiftPayment(gift)}>{!gift.paymentIntent?"Start card payment":"Complete sandbox payment"}</Button>}</div></Card>):<Card><p>No product gifts sent yet. Open a product and choose Send as Gift.</p></Card>}</div><SectionHeader title="Gifts received" sub="Anonymous sender identity and scheduled messages are protected by the API."/><div className="order-list">{gifts.received.length?gifts.received.map((gift)=><Card key={gift.id} className="order-card"><div><Badge tone="primary">{gift.status}</Badge><h3>{gift.product?.name||"Product gift"}</h3><p>From {gift.sender?.name||"Anonymous"}</p></div><div>{gift.message&&<strong>“{gift.message}”</strong>}<small>{gift.scheduledFor?new Date(gift.scheduledFor).toLocaleString():""}</small></div></Card>):<Card><p>No product gifts received yet.</p></Card>}</div></Page>;
}

/** Shows the live admin overview without static or local-store metrics. */
export function AdminControl() {
	const {hasPermission}=useAuth();
	const [bi,setBi]=useState(null),[loading,setLoading]=useState(true),[error,setError]=useState("");
	useEffect(()=>{let live=true;apiGet("/admin/analytics").then((data)=>{if(!live)return;setBi(data?.analytics||null);setError("");}).catch((err)=>live&&setError(err.message||"Admin analytics could not be loaded.")).finally(()=>live&&setLoading(false));return()=>{live=false;};},[]);
	if(loading)return <Page title="Admin Control Center" sub="Monitor marketplace operations, money movement, games and compliance."><Card><p>Loading marketplace analytics…</p></Card></Page>;
	if(error||!bi)return <FailurePage title="Admin Control Center" sub="Server-authoritative marketplace analytics." error={error||"Admin analytics are unavailable."}/>;
	const commerce=bi.commerce||{},market=bi.marketplace||{},ops=bi.operations||{};
	return <Page title="Admin Control Center" sub="Monitor marketplace operations, money movement, games and compliance."><div className="metric-grid admin-metrics"><Card><FaUsers/><small>Users</small><strong>{market.totalUsers==null?"—":Number(market.totalUsers).toLocaleString()}</strong><span>{Number(market.newUsers||0).toLocaleString()} new in report window</span></Card><Card><FaStore/><small>Active vendors</small><strong>{market.activeVendors??"—"}</strong><span>{Number(ops.pendingKyc||0).toLocaleString()} KYC pending</span></Card><Card><FaMoneyBillWave/><small>GMV / paid order value</small><strong>Rs. {moneyFromMinor(commerce.gmvMinor||0).toLocaleString()}</strong><span>{Number(commerce.orders||0).toLocaleString()} paid orders</span></Card><Card><FaIdCard/><small>Risk / compliance</small><strong>{Number(ops.openRiskCases||0)+Number(ops.pendingKyc||0)}</strong><span>{Number(ops.openRiskCases||0)} risk · {Number(ops.pendingKyc||0)} KYC</span></Card></div><div className="admin-grid"><Card><h2>System health</h2><div className="system-status"><Status ok>Laravel catalog API</Status><Status ok>Finance ledger</Status><Status ok>Game scheduler</Status><Status ok>Affiliate engine</Status><Status ok>Notification queue</Status></div></Card><Card><h2>Operational queues</h2><div className="simple-list"><div><span><b>KYC review</b><small>Government IDs</small></span><strong>{ops.pendingKyc||0}</strong></div><div><span><b>Game draws</b><small>Due in next 24h</small></span><strong>{ops.gameDrawsDue24h||0}</strong></div><div><span><b>Return / dispute queue</b><small>Buyer protection review</small></span><strong>{ops.openReturns||0}</strong></div><div><span><b>Product alerts</b><small>Price & stock watchers</small></span><strong>{ops.activeProductAlerts||0}</strong></div></div></Card><Card className="admin-wide"><h2>Marketplace controls</h2><div className="admin-action-grid">{hasPermission("vendors.view")&&<Link to="/admin/vendors"><FaStore/> Vendors</Link>}{hasPermission("catalog.view")&&<Link to="/admin/catalog"><FaBox/> Catalog</Link>}{hasPermission("orders.view")&&<Link to="/admin/orders"><FaBox/> Orders</Link>}{hasPermission("shipping.view")&&<Link to="/admin/shipping"><FaTruck/> Shipping</Link>}{hasPermission("payments.view")&&<Link to="/admin/payments"><FaCreditCard/> Payments</Link>}{hasPermission("returns.view")&&<Link to="/admin/returns"><FaUndo/> Returns & refunds</Link>}{hasPermission("finance.view")&&<><Link to="/admin/finance"><FaMoneyBillWave/> Finance</Link><Link to="/admin/payouts"><FaWallet/> Payouts</Link></>}{hasPermission("promotions.view")&&<Link to="/admin/promotions"><FaGift/> Promotions</Link>}{hasPermission("compliance.view")&&<Link to="/admin/compliance"><FaIdCard/> KYC & compliance</Link>}{hasPermission("reviews.view")&&<Link to="/admin/reviews"><FaStar/> Reviews</Link>}{hasPermission("notifications.view")&&<Link to="/admin/notifications"><FaBell/> Notifications</Link>}{hasPermission("settings.view")&&<Link to="/admin/settings"><FaCog/> Settings</Link>}{hasPermission("operations.view")&&<><Link to="/admin/operations"><FaMoneyBillWave/> Operations</Link><Link to="/admin/production-readiness"><FaShieldAlt/> Launch gate</Link></>}{hasPermission("acceptance.view")&&<Link to="/admin/acceptance"><FaShieldAlt/> Production acceptance</Link>}{hasPermission("risk.view")&&<Link to="/admin/risk"><FaShieldAlt/> Risk & abuse</Link>}{hasPermission("analytics.view")&&<Link to="/admin/analytics"><FaMoneyBillWave/> Analytics & reports</Link>}</div></Card></div></Page>;
}

/** Manages buyer returns from server-authorized orders and return requests. */
export function ReturnsCenter() {
	const [orders,setOrders]=useState([]),[requests,setRequests]=useState([]),[orderId,setOrderId]=useState(""),[reason,setReason]=useState(""),[resolution,setResolution]=useState("refund_original"),[details,setDetails]=useState(""),[quantities,setQuantities]=useState({}),[tracking,setTracking]=useState({}),[msg,setMsg]=useState(""),[error,setError]=useState(""),[busy,setBusy]=useState(false),[loading,setLoading]=useState(true);
	const load=async()=>{const [orderRows,returnRows]=await Promise.all([apiGet("/orders"),apiGet("/returns")]);const eligible=(Array.isArray(orderRows)?orderRows:[]).filter((order)=>order.returnEligible);setOrders(eligible);setRequests(Array.isArray(returnRows)?returnRows:[]);setOrderId((current)=>current||eligible[0]?.id||"");setError("");};
	useEffect(()=>{load().catch((e)=>setError(e.message||"Returns could not be loaded.")).finally(()=>setLoading(false));},[]);
	const selected=orders.find((order)=>order.id===orderId);
	useEffect(()=>{if(!selected)return;const next={};(selected.items||[]).forEach((item)=>{next[item.id]=Math.max(0,Number(item.quantity||0)-Number(item.returnedQuantity||0));});setQuantities(next);},[orderId,selected]);
	const submit=async()=>{setBusy(true);setError("");setMsg("");try{const items=Object.entries(quantities).filter(([,quantity])=>Number(quantity)>0).map(([orderItemId,quantity])=>({orderItemId:Number(orderItemId),quantity:Number(quantity)}));const result=await apiPost("/returns",{orderId,reason,resolution,details,items});setMsg(`Return request ${result.id} submitted.`);setReason("");setDetails("");await load();}catch(e){setError(e.message);}finally{setBusy(false);}};
	const markShipped=async(request)=>{const ref=(tracking[request.id]||"").trim();if(!ref)return;try{await apiPost(`/returns/${request.id}/ship`,{trackingReference:ref});await load();}catch(e){setError(e.message);}};
	if(!loading&&error&&!orders.length&&!requests.length)return <FailurePage title="Returns, refunds & disputes" sub="Server-authoritative buyer protection." error={error}/>;
	return <Page title="Returns, refunds & disputes" sub="Laravel-managed item returns, refund settlement and marketplace disputes with an auditable financial trail.">{error&&<Status>{error}</Status>}<div className="postpurchase-grid"><Card><h2>Start a request</h2><Select label="Delivered order" value={orderId} onChange={(event)=>setOrderId(event.target.value)}><option value="">Choose an order</option>{orders.map((order)=><option key={order.id} value={order.id}>{order.id} · Rs. {moneyFromMinor(order.totals?.totalMinor).toLocaleString()}</option>)}</Select>{selected?.items?.length?<div className="simple-list">{selected.items.map((item)=>{const remaining=Math.max(0,Number(item.quantity||0)-Number(item.returnedQuantity||0));return <div key={item.id}><FaBox/><span><b>{item.productName}</b><small>{item.variantName} · {remaining} returnable</small></span><input style={{maxWidth:90}} type="number" min="0" max={remaining} value={quantities[item.id]??0} onChange={(event)=>setQuantities({...quantities,[item.id]:Math.min(remaining,Math.max(0,Number(event.target.value)))})}/></div>;})}</div>:null}<Select label="Reason" value={reason} onChange={(event)=>setReason(event.target.value)}><option value="">Choose a reason</option><option>Item damaged</option><option>Wrong item received</option><option>Not as described</option><option>Missing parts/accessories</option><option>Changed my mind</option><option>Delivery issue</option></Select><Select label="Preferred resolution" value={resolution} onChange={(event)=>setResolution(event.target.value)}><option value="refund_original">Refund to original payment</option><option value="coins">Refund as VSN Coins</option><option value="replacement">Replacement</option><option value="dispute">Marketplace dispute review</option></Select><label className="ui-field"><span>Details</span><textarea value={details} onChange={(event)=>setDetails(event.target.value)} placeholder="Describe the issue, packaging condition and what resolution you need."/></label><Button disabled={busy||!orderId||!reason} onClick={submit}><FaUndo/> {busy?"Submitting…":"Submit request"}</Button>{msg&&<p className="form-message">{msg}</p>}</Card><Card><SectionHeader title="Your requests" sub="Status, refund and next action"/><div className="simple-list">{requests.length?requests.map((request)=><div key={request.id}><FaUndo/><span><b>{request.id} · {request.orderId}</b><small>{request.reason} · {request.resolution.replaceAll("_"," ")} · Rs. {moneyFromMinor(request.approvedMinor||request.requestedMinor).toLocaleString()}</small>{request.status==="approved"&&<span style={{display:"flex",gap:8,marginTop:6}}><input placeholder="Return tracking reference" value={tracking[request.id]||""} onChange={(event)=>setTracking({...tracking,[request.id]:event.target.value})}/><Button onClick={()=>markShipped(request)}>Mark shipped</Button></span>}</span><Badge tone={["refunded","replaced"].includes(request.status)?"success":"primary"}>{request.status.replaceAll("_"," ")}</Badge></div>):<p>No return or dispute requests yet.</p>}</div></Card></div></Page>;
}

/** Shows product alerts from Laravel only. */
export function SavedAlerts() {
	const [alerts,setAlerts]=useState([]),[error,setError]=useState(""),[loading,setLoading]=useState(true);
	const load=()=>apiGet("/product-alerts").then((rows)=>{setAlerts(Array.isArray(rows)?rows:[]);setError("");}).catch((e)=>setError(e.message||"Product alerts could not be loaded.")).finally(()=>setLoading(false));
	useEffect(()=>{load();},[]);
	const remove=async(id)=>{try{await apiDelete(`/product-alerts/${id}`);await load();}catch(e){setError(e.message);}};
	if(!loading&&error&&!alerts.length)return <FailurePage title="Saved & alerts" sub="Server-authoritative product alerts." error={error}/>;
	const prices=alerts.filter((alert)=>alert.type==="price_drop"),stocks=alerts.filter((alert)=>alert.type==="back_in_stock");
	return <Page title="Saved & alerts" sub="Server-authoritative price-drop and back-in-stock notifications.">{error&&<Status>{error}</Status>}<div className="metric-grid"><Card><FaTag/><small>Active price alerts</small><strong>{prices.filter((alert)=>alert.status==="active").length}</strong><span>Price changes monitored</span></Card><Card><FaBell/><small>Stock alerts</small><strong>{stocks.filter((alert)=>alert.status==="active").length}</strong><span>Inventory availability monitored</span></Card><Card><FaShieldAlt/><small>Alert source</small><strong>Laravel</strong><span>Catalog + inventory authoritative state</span></Card></div><Card className="system-section"><SectionHeader title="Product alerts" sub="Triggered stock alerts stay visible until removed"/><div className="alert-product-grid">{loading?<p>Loading alerts…</p>:alerts.length?alerts.map((alert)=><div className="alert-product" key={alert.id}><SafeImage src={alert.product?.image} alt={alert.product?.name}/><span><b>{alert.product?.name}</b><small>{alert.type==="price_drop"?(alert.targetPriceMinor?`Notify at Rs. ${moneyFromMinor(alert.targetPriceMinor).toLocaleString()} or lower`:"Notify on next price drop"):(alert.status==="triggered"?"Back-in-stock alert triggered":"Notify when stock returns")}</small></span><Badge tone={alert.status==="triggered"?"success":alert.type==="price_drop"?"deal":"primary"}>{alert.status}</Badge><button onClick={()=>remove(alert.id)}>Remove</button></div>):<p>No alerts yet. Open a product and create one.</p>}</div></Card></Page>;
}

/** Runs finance and production operations from admin APIs only. */
export function OperationsCenter() {
	const [summary,setSummary]=useState(null),[systemOps,setSystemOps]=useState(null),[payouts,setPayouts]=useState([]),[batches,setBatches]=useState([]),[refs,setRefs]=useState({}),[error,setError]=useState(""),[msg,setMsg]=useState(""),[busy,setBusy]=useState(""),[incidentDrafts,setIncidentDrafts]=useState({});
	const load=async()=>{const [finance,payoutRows,batchRows,operations]=await Promise.all([apiGet("/admin/finance"),apiGet("/admin/finance/payouts"),apiGet("/admin/finance/payout-batches"),apiGet("/admin/system/operations")]);setSummary(finance);setPayouts(Array.isArray(payoutRows)?payoutRows:[]);setBatches(Array.isArray(batchRows)?batchRows:[]);setSystemOps(operations);setError("");};
	useEffect(()=>{load().catch((e)=>setError(e.message||"Marketplace operations could not be loaded."));},[]);
	const incidentText=(id)=>incidentDrafts[id]||"";
	const setIncidentText=(id,value)=>setIncidentDrafts((current)=>({...current,[id]:value}));
	const incidentAction=async(incident,type)=>{const message=incidentText(incident.id).trim();if(!message){setMsg("Add an operator note before changing incident state.");return;}setBusy(`incident:${incident.id}:${type}`);setError("");try{if(type==="note")await apiPost(`/admin/system/operations/incidents/${incident.id}/notes`,{message});else if(type==="resolve")await apiPost(`/admin/system/operations/incidents/${incident.id}/resolve`,{summary:message});else await apiPut(`/admin/system/operations/incidents/${incident.id}/status`,{status:type,message});setIncidentText(incident.id,"");await load();}catch(e){setError(e.message);}finally{setBusy("");}};
	const payoutAction=async(id,type,body={})=>{setBusy(`${id}:${type}`);setError("");try{await apiPost(`/admin/finance/payouts/${id}/${type}`,body);await load();}catch(e){setError(e.message);}finally{setBusy("");}};
	const reconcile=async()=>{setBusy("reconcile");setError("");try{const result=await apiPost("/admin/finance/reconcile",{});setMsg(`Reconciliation ${result.status}: ${result.issuesCount} issue(s).`);await load();}catch(e){setError(e.message);}finally{setBusy("");}};
	const createBatch=async()=>{const ids=payouts.filter((payout)=>payout.status==="approved"&&!payout.batchId).map((payout)=>payout.id);if(!ids.length){setMsg("No approved unbatched payouts are waiting.");return;}setBusy("batch");setError("");try{const batch=await apiPost("/admin/finance/payout-batches",{payoutIds:ids});setMsg(`Payout batch ${batch.id} created with ${batch.payoutCount} payout(s).`);await load();}catch(e){setError(e.message);}finally{setBusy("");}};
	const money=(value)=>`Rs. ${moneyFromMinor(Number(value||0)).toLocaleString()}`;
	if(!summary)return error?<FailurePage title="Finance & marketplace operations" sub="Server-authoritative finance and runtime operations." error={error}/>:<Page title="Finance & marketplace operations" sub="Immutable marketplace accounting, seller settlements and payout controls."><Card><p>Loading finance ledger…</p></Card></Page>;
	return <Page title="Finance & marketplace operations" sub="Immutable double-entry finance ledger, operational liabilities, seller settlements and payout reconciliation.">{error&&<Status>{error}</Status>}{msg&&<p className="form-message">{msg}</p>}{systemOps&&<Card className="system-section"><SectionHeader title="Production health" sub="Database, Redis, storage, scheduler, queue workers and failed-job pressure"/><div className="finance-grid">{Object.entries(systemOps.health?.checks||{}).map(([name,check])=><div key={name}><small>{name.replaceAll("_"," ")}</small><strong>{check.ok?"Healthy":"Needs attention"}</strong><span>{check.latencyMs!=null?`${check.latencyMs} ms`:check.ageSeconds!=null?`${check.ageSeconds}s since heartbeat`:""}</span></div>)}</div><div className="finance-grid" style={{marginTop:16}}><div><small>Failed jobs</small><strong>{systemOps.health?.failedJobs??0}</strong></div><div><small>Release</small><strong>{systemOps.health?.app?.version||"unknown"}</strong></div><div><small>Recent backups</small><strong>{systemOps.backups?.filter((backup)=>backup.status==="completed").length||0}</strong></div><div><small>Launch blockers</small><strong>{systemOps.launchGate?.blockersCount??"—"}</strong><span>{systemOps.launchGate?.ready?"Automated gates pass":"Launch gate needs attention"}</span></div></div></Card>}{systemOps&&<div className="ops-grid system-section"><Card><SectionHeader title="Release operations" sub="Audited deployment evidence and production configuration"/><div className="finance-grid"><div><small>Configuration blockers</small><strong>{systemOps.configuration?.blockersCount??"—"}</strong></div><div><small>Deployment records</small><strong>{systemOps.deployments?.length||0}</strong></div><div><small>Open SEV1/SEV2</small><strong>{(systemOps.incidents||[]).filter((incident)=>incident.status!=="resolved"&&["sev1","sev2"].includes(incident.severity)).length}</strong></div></div></Card><Card><SectionHeader title="Incident command" sub="Append-only operator timeline; unresolved SEV1/SEV2 blocks launch"/>{(systemOps.incidents||[]).filter((incident)=>incident.status!=="resolved").length?(systemOps.incidents||[]).filter((incident)=>incident.status!=="resolved").slice(0,5).map((incident)=><div className="incident-ops-card" key={incident.id}><div><Badge tone={["sev1","sev2"].includes(incident.severity)?"danger":"warning"}>{incident.severity}</Badge> <b>{incident.title}</b><small>{incident.status} · {incident.type}</small></div><Field label="Operator update" value={incidentText(incident.id)} onChange={(event)=>setIncidentText(incident.id,event.target.value)} placeholder="What changed, evidence, next action or resolution"/><div className="button-row"><Button disabled={!!busy} onClick={()=>incidentAction(incident,"note")}>Add note</Button><Button disabled={!!busy} onClick={()=>incidentAction(incident,"investigating")}>Investigating</Button><Button disabled={!!busy} onClick={()=>incidentAction(incident,"monitoring")}>Monitoring</Button><Button disabled={!!busy} onClick={()=>incidentAction(incident,"resolve")}>Resolve</Button></div></div>):<p>No active incidents.</p>}</Card></div>}<div className="metric-grid"><Card><FaMoneyBillWave/><small>Seller payable</small><strong>{money(summary.ledger?.sellerPayableMinor)}</strong><span>Net outstanding liability</span></Card><Card><FaStore/><small>Platform commission</small><strong>{money(summary.ledger?.platformCommissionRevenueMinor)}</strong><span>Net commission revenue</span></Card><Card><FaTag/><small>Coupon subsidy</small><strong>{money(summary.ledger?.reviewCouponSubsidyExpenseMinor)}</strong><span>Platform-funded review rewards</span></Card><Card><FaUndo/><small>Seller recovery</small><strong>{money(summary.ledger?.sellerRecoveryReceivableMinor)}</strong><span>Refunds after seller payout</span></Card></div><Card className="system-section"><SectionHeader title="Seller payout queue" sub="Finance approval and confirmed payout settlement"/><div className="simple-list">{payouts.length?payouts.map((payout)=><div key={payout.id}><FaMoneyBillWave/><span><b>{payout.vendor||"Vendor"} · {payout.id}</b><small>{payout.status} · requested by {payout.requestedBy||"seller"}</small></span><strong>{money(payout.amountMinor)}</strong>{payout.status==="requested"&&<><Button variant="secondary" disabled={!!busy} onClick={()=>payoutAction(payout.id,"review",{approve:false,note:"Rejected by finance"})}>Reject</Button><Button disabled={!!busy} onClick={()=>payoutAction(payout.id,"review",{approve:true})}>Approve</Button></>}{["approved","processing"].includes(payout.status)&&<><input placeholder="Bank/provider reference" value={refs[payout.id]||""} onChange={(event)=>setRefs({...refs,[payout.id]:event.target.value})}/><Button disabled={!!busy||!(refs[payout.id]||"").trim()} onClick={()=>payoutAction(payout.id,"paid",{providerReference:refs[payout.id].trim()})}>Mark paid</Button></>}</div>):<p>No seller payouts queued.</p>}</div><div style={{marginTop:16,display:"flex",gap:8,flexWrap:"wrap"}}><Button variant="secondary" disabled={!!busy||!payouts.some((payout)=>payout.status==="approved"&&!payout.batchId)} onClick={createBatch}>{busy==="batch"?"Creating batch…":"Batch approved payouts"}</Button></div></Card><Card className="system-section"><SectionHeader title="Payout batches" sub="Approved seller payouts grouped for bank/provider processing"/><div className="simple-list">{batches.length?batches.map((batch)=><div key={batch.id}><FaMoneyBillWave/><span><b>{batch.id}</b><small>{batch.status} · {batch.payoutCount} payouts · {batch.providerBatchReference||"provider batch reference pending"}</small></span><strong>{money(batch.totalMinor)}</strong></div>):<p>No payout batches yet.</p>}</div></Card><Card><h2>Ledger reconciliation</h2><p>Backfill missing order journals, reconcile settlement states and verify every journal remains debit/credit balanced.</p><Button disabled={!!busy} onClick={reconcile}>{busy==="reconcile"?"Reconciling…":"Run reconciliation"}</Button></Card></Page>;
}

/** Shows seller quality from courier-derived admin metrics only. */
export function SellerQuality() {
	const [rows,setRows]=useState([]),[error,setError]=useState("") ,[loading,setLoading]=useState(true);
	useEffect(()=>{apiGet("/admin/shipping/quality").then((data)=>{setRows(Array.isArray(data)?data:[]);setError("");}).catch((e)=>setError(e.message||"Seller quality could not be loaded.")).finally(()=>setLoading(false));},[]);
	if(!loading&&error)return <FailurePage title="Seller quality & SLA" sub="Server-authoritative seller SLA metrics." error={error}/>;
	return <Page title="Seller quality & SLA" sub="Live courier-derived seller dispatch and delivery performance."><div className="seller-quality-grid">{loading?<Card><p>Loading SLA metrics…</p></Card>:rows.map((vendor)=>{const score=Math.round((Number(vendor.onTimeDispatchPercent||0)+Number(vendor.onTimeDeliveryPercent||0))/2);return <Card key={vendor.vendorId}><div className="card-title"><div><h2>{vendor.vendor}</h2><p>{vendor.shipments} shipments in {vendor.days} days</p></div><Badge tone={score>=90?"success":"deal"}>{score}/100</Badge></div><div className="quality-list"><span>On-time dispatch <b>{vendor.onTimeDispatchPercent}%</b></span><span>On-time delivery <b>{vendor.onTimeDeliveryPercent}%</b></span><span>Active dispatch breaches <b>{vendor.lateDispatchActive}</b></span><span>Active delivery breaches <b>{vendor.lateDeliveryActive}</b></span><span>Failed / RTO <b>{vendor.failedDeliveries} / {vendor.rtoCount}</b></span><span>Commission <b>{(Number(vendor.commissionBps||0)/100).toFixed(2)}%</b></span><span>Payout hold <b>{vendor.payoutHoldDays} days</b></span></div></Card>;})}</div></Page>;
}
