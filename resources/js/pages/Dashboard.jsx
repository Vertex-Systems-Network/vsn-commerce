import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import SEO from "../components/SEO";
import {
	Badge,
	Card,
	Status,
	Countdown,
	SectionHeader,
} from "../components/Toolkit";
import { apiGet } from "../platform/api";
import { moneyFromMinor } from "../platform/cart";
import { useLaravelWallet } from "../platform/wallet";
import { useLaravelGames } from "../platform/games";
import { useLaravelGifts } from "../platform/gifts";
import { useLaravelReviews } from "../platform/reviews";
import { getBuyAgain } from "../platform/personalization";
import { useAuth } from "../platform/auth";
import {useUX} from "../components/UXProvider";
import {
	FaBox,
	FaCoins,
	FaGamepad,
	FaIdCard,
	FaGift,
	FaArrowRight,
	FaWallet,
	FaUsers,
	FaBell,
	FaCreditCard,
	FaTruck,
	FaStar,
	FaTag,
	FaUndo,
	FaBookmark,
} from "react-icons/fa";

const terminalOrderStatuses = new Set(["delivered", "cancelled", "returned", "refunded", "partially_refunded"]);

/** Resolves a grouped dashboard delivery stage from the server order lifecycle. */
function lifecycleStage(order, codes) {
	const rows = (order?.lifecycle?.steps || []).filter((step) => codes.includes(step.code));
	return {
		done: rows.some((step) => step.complete),
		active: rows.some((step) => step.current),
	};
}

/** Handles dashboard for the VSN Ecommerce interface. */
export default function Dashboard() {
	const {toast}=useUX();
	const { user } = useAuth();
	const lw = useLaravelWallet();
	const lg = useLaravelGames();
	const lGifts = useLaravelGifts();
	const lReviews = useLaravelReviews();
	const [orders,setOrders]=useState([]);
	const [paymentMethods,setPaymentMethods]=useState([]);
	const [buyAgain,setBuyAgain]=useState([]);
	const [accountLoading,setAccountLoading]=useState(true);
	const [accountError,setAccountError]=useState('');
	const [paymentMethodsError,setPaymentMethodsError]=useState('');

	useEffect(/** Loads dashboard-only account summaries from existing Laravel endpoints. */ ()=>{
		let live=true;
		Promise.allSettled([apiGet('/orders'),apiGet('/payment-methods'),getBuyAgain(4)]).then((results)=>{
			if(!live)return;
			const [orderResult,paymentResult,buyAgainResult]=results;
			if(orderResult.status==='fulfilled'){
				setOrders(Array.isArray(orderResult.value)?orderResult.value:[]);
				setAccountError('');
			}else{
				setOrders([]);
				setAccountError(orderResult.reason?.message||'Orders could not be loaded.');
			}
			if(paymentResult.status==='fulfilled'){
				setPaymentMethods(paymentResult.value?.items||[]);
				setPaymentMethodsError('');
			}else{
				setPaymentMethods([]);
				setPaymentMethodsError(paymentResult.reason?.message||'Payment methods could not be loaded.');
			}
			setBuyAgain(buyAgainResult.status==='fulfilled'?(buyAgainResult.value?.items||[]):[]);
		}).finally(()=>live&&setAccountLoading(false));
		return()=>{live=false};
	},[]);

	const walletCoins=Number(lw.wallet?.balanceCoins||0);
	const walletRate=Number(lw.wallet?.coinsPerRupee||0);
	const walletValue=walletRate>0?walletCoins/walletRate:null;
	const dailyReward=lw.wallet?.checkin?.baseRewardCoins;
	const gameRows=lg.joinedGames||[];
	const g=gameRows[0]||null;
	const latest=orders[0]||null;
	const activeOrders=orders.filter((order)=>!terminalOrderStatuses.has(String(order.status||'').toLowerCase()));
	const verification=user?.verification||{};
	const identityReady=Boolean(verification.phoneVerified&&verification.governmentIdVerified);
	const shipment=latest?.shipments?.[0]||null;
	const eta=shipment?.estimatedDeliveryAt?new Date(shipment.estimatedDeliveryAt).toLocaleDateString():null;
	const confirmed=lifecycleStage(latest,['confirmed']);
	const packed=lifecycleStage(latest,['processing','packed']);
	const transit=lifecycleStage(latest,['shipped','out_for_delivery']);
	const delivered=lifecycleStage(latest,['delivered']);
	const claimDaily=/** Claims the daily reward through the server wallet ledger. */ async()=>{
		try{const result=await lw.checkIn();toast(`+${result.totalRewardCoins} coins claimed`,{tone:"success",title:"Daily reward claimed"})}
		catch(error){toast(error.message,{tone:"danger"})}
	};

	return (
		<>
			<SEO title="Buyer Dashboard | VSN Ecommerce" />
			<div className="dashboard-page buyer-dashboard">
				<div className="dashboard-head">
					<div>
						<span>MY VSN</span>
						<h1>Hello, {(user?.name||"Customer").split(" ")[0]}</h1>
						<p>Orders, games, rewards, gifts, referrals and account security in one place.</p>
					</div>
					<div className="dashboard-head-actions">
						<Link className="ui-btn ui-btn--secondary" to="/notifications"><FaBell /> Alerts</Link>
						<Link className="ui-btn ui-btn--primary" to="/search">Continue shopping</Link>
					</div>
				</div>
				{accountError&&<Status>{accountError}</Status>}
				{lw.error&&<Status>{lw.error}</Status>}
				{lg.error&&<Status>{lg.error}</Status>}
				{lReviews.error&&<Status>{lReviews.error}</Status>}
				{lGifts.error&&<Status>{lGifts.error}</Status>}
				<div className="metric-grid buyer-metrics">
					<Link to="/orders" className="metric-link"><Card><FaBox /><small>Active orders</small><strong>{accountLoading?'…':accountError?'—':activeOrders.length}</strong><span>Track deliveries</span></Card></Link>
					<Link to="/coins" className="metric-link"><Card><FaCoins /><small>Coin balance</small><strong>{lw.loading?'…':lw.error?'—':walletCoins.toLocaleString()}</strong><span>{lw.error?'Wallet unavailable':walletValue===null?'Server conversion unavailable':`Rs. ${walletValue.toFixed(2)} value`}</span></Card></Link>
					<Link to="/games" className="metric-link"><Card><FaGamepad /><small>Joined games</small><strong>{lg.loading?'…':lg.error?'—':gameRows.length}</strong><span>Announcement tracking</span></Card></Link>
					<Link to="/profile" className="metric-link"><Card><FaIdCard /><small>Verification</small><strong>{identityReady?"Verified":"Action needed"}</strong><Status ok={identityReady}>{identityReady?"Complete":"Review profile"}</Status></Card></Link>
				</div>
				<div className="dashboard-layout-main">
					<div className="dashboard-main-column">
						<Card className="dashboard-order-card">
							{latest?<>
								<div className="card-title"><div><span className="eyebrow">LATEST ORDER</span><h2>{latest.id}</h2><p>{latest.items?.length||0} items · Rs. {moneyFromMinor(latest.totals?.totalMinor).toLocaleString()}</p></div><Link to="/orders">All orders <FaArrowRight /></Link></div>
								<div className="delivery-progress">
									<div className={`delivery-step ${confirmed.done?'done':''} ${confirmed.active?'active':''}`}><i /><span><b>Confirmed</b><small>{confirmed.active?'Order confirmed':'Order accepted'}</small></span></div>
									<div className={`delivery-step ${packed.done?'done':''} ${packed.active?'active':''}`}><i /><span><b>{latest.status==='processing'?'Processing':'Packed'}</b><small>{latest.status==='processing'?'Seller is preparing the order':'Seller prepared parcel'}</small></span></div>
									<div className={`delivery-step ${transit.done?'done':''} ${transit.active?'active':''}`}><i /><span><b>In transit</b><small>{eta?`Expected ${eta}`:'Carrier estimate pending'}</small></span></div>
									<div className={`delivery-step ${delivered.done?'done':''} ${delivered.active?'active':''}`}><i /><span><b>Delivered</b><small>{latest.deliveredAt?new Date(latest.deliveredAt).toLocaleDateString():'Pending'}</small></span></div>
								</div>
								<Link className="text-link" to={`/tracking?order=${encodeURIComponent(latest.id)}`}><FaTruck /> Track package</Link>
							</>:<><div className="card-title"><div><span className="eyebrow">LATEST ORDER</span><h2>{accountLoading?'Loading orders…':'No orders yet'}</h2><p>{accountLoading?'Checking your purchase history.':'Your next server-confirmed order will appear here.'}</p></div><Link to="/orders">All orders <FaArrowRight /></Link></div></>}
						</Card>
						<Card className="dashboard-review-card">
							<div className="card-title"><div><span className="eyebrow">REVIEWS & REWARDS</span><h2>{lReviews.loading?'Loading…':`${lReviews.pending.length} products waiting`}</h2><p>Review delivered purchases to unlock server-issued reward coupons.</p></div><FaStar /></div>
							{lReviews.pending.slice(0,2).map((item)=><div className="dashboard-review-row" key={item.orderItemId}><div><b>{item.productName}</b><small>{item.orderId}</small></div><span><FaTag /> Review reward</span><Link to={`/product/${item.productSlug||item.productId}?review=1`}>Review</Link></div>)}
							<Link className="text-link" to="/reviews">Open Reviews <FaArrowRight /></Link>
						</Card>
						<Card className="dashboard-game-card">
							<div className="card-title"><div><span className="eyebrow">CURRENT GAME</span><h2>{lg.loading?'Loading…':g?.name||'No joined game'}</h2><p>{g?'Your joined Game Win entry':'Joined entries will appear here.'}</p></div>{g&&<Badge tone={g.status==='winner_selected'||g.status==='fulfilled'?'success':'game'}>{String(g.status||'open').replaceAll('_',' ')}</Badge>}</div>
							{g&&<div className="game-dashboard-row"><div><small>Your entries</small><strong>{g.entries??0}</strong></div><div><small>Entry value</small><strong>{g.entryCoins!=null?`${g.entryCoins} coins`:'—'}</strong></div><div><small>Winner announcement</small><Countdown target={g.announcementAt} /></div></div>}
							<Link className="text-link" to="/games">Open My Games <FaArrowRight /></Link>
						</Card>
						<Card className="buy-again-card">
							<SectionHeader title="Buy again" sub="Reorder products from delivered purchases" />
							<div className="buy-again-grid">{buyAgain.map((row)=><Link to={`/product/${row.product?.slug||row.product?.id}`} key={`${row.product?.id}-${row.variantId||0}`}><span><b>{row.product?.name||'Previous purchase'}</b><small>{row.lastPurchasedAt?`Last purchased ${new Date(row.lastPurchasedAt).toLocaleDateString()}`:'Previously purchased'}{!row.available?' · currently unavailable':''}</small></span><FaArrowRight /></Link>)}</div>
							{!accountLoading&&!buyAgain.length&&<Link className="text-link" to="/buy-again">Open buy again <FaArrowRight /></Link>}
						</Card>
						<Card>
							<SectionHeader title="Account shortcuts" sub="Frequently used buyer services" />
							<div className="account-shortcut-grid">
								<Link to="/wallet"><FaWallet /><span><b>Wallet</b><small>Coins & payment methods</small></span></Link>
								<Link to="/gifts"><FaGift /><span><b>Gifts</b><small>Send coins and track level</small></span></Link>
								<Link to="/affiliate"><FaUsers /><span><b>Affiliate</b><small>10-level rewards</small></span></Link>
								<Link to="/profile"><FaIdCard /><span><b>Verification</b><small>ID, phone & address proof</small></span></Link>
								<Link to="/messages"><FaBell /><span><b>Messages</b><small>Seller & support chat</small></span></Link>
								<Link to="/reviews"><FaStar /><span><b>Reviews</b><small>{lReviews.loading?'Loading pending reviews…':`${lReviews.pending.length} pending reviews`}</small></span></Link>
								<Link to="/returns"><FaUndo /><span><b>Returns</b><small>Refunds & disputes</small></span></Link>
								<Link to="/saved-alerts"><FaBookmark /><span><b>Saved & alerts</b><small>Price and stock watches</small></span></Link>
								<Link to="/settings"><FaCreditCard /><span><b>Settings</b><small>Security & preferences</small></span></Link>
							</div>
						</Card>
					</div>
					<aside className="dashboard-side-column">
						<Card className="daily-card"><div className="daily-card-header"><small>DAILY REWARD</small><FaCoins /></div><strong>{lw.loading?'Loading…':dailyReward!=null?`+${dailyReward} coins`:'Unavailable'}</strong><p>{lw.wallet?.checkin?.claimedToday?'Today’s reward is already claimed.':'Claim today’s reward through the wallet ledger.'}</p><button className="ui-btn ui-btn--coin" disabled={lw.loading||Boolean(lw.error)||Boolean(lw.wallet?.checkin?.claimedToday)} onClick={claimDaily}>{lw.wallet?.checkin?.claimedToday?'Claimed today':'Check in'}</button></Card>
						<Card><h3>Profile readiness</h3><div className="status-stack"><Status ok={Boolean(verification.phoneVerified)}>Phone verified</Status><Status ok={Boolean(verification.governmentIdVerified)}>Government ID</Status><Status ok={Boolean(verification.addressProofVerified)}>Address proof</Status><Status ok={paymentMethods.length>0}>{paymentMethodsError?'Payment methods unavailable':'Payment method'}</Status></div><Link className="text-link" to="/profile">Complete profile <FaArrowRight /></Link></Card>
						<Card className="gift-level-mini"><div className="gift-level-header"><small>GIFT SENDER LEVEL</small><strong>{lGifts.loading?'Loading…':lGifts.error?'Unavailable':lGifts.level?.name||'No level yet'}</strong></div><p>{lGifts.loading||lGifts.error?'—':`${lGifts.lifetimeGiftCoins.toLocaleString()} coins gifted`}</p><span>Next reward: {lGifts.loading?'Loading…':lGifts.error?'Unavailable':lGifts.level?.nextReward||'Top level reached'}</span><Link className="text-link" to="/gifts">Open gifts</Link></Card>
					</aside>
				</div>
			</div>
		</>
	);
}
