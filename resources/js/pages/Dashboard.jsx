import { Link } from "react-router-dom";
import SEO from "../components/SEO";
import {
	Badge,
	Card,
	Status,
	Countdown,
	SectionHeader,
} from "../components/Toolkit";
import { useStore } from "../platform/store";
import { apiBackend } from "../platform/api";
import { useLaravelWallet } from "../platform/wallet";
import { useLaravelGames } from "../platform/games";
import { useLaravelGifts } from "../platform/gifts";
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
/** Handles dashboard for the VSN Ecommerce interface. */
export default function Dashboard() {
	const {toast}=useUX();
	const s = useStore();
	const { user } = useAuth();
	const lw = useLaravelWallet();
	const lg = useLaravelGames();
	const lGifts = useLaravelGifts();
	const walletCoins = apiBackend === "laravel" ? (lw.wallet?.balanceCoins || 0) : s.coinBalance;
	const walletValue = apiBackend === "laravel" ? (lw.wallet?.valueRupees || 0) : s.coinsToRs(s.coinBalance);
	const claimDaily = /** Handles claim daily for the VSN Ecommerce interface. */ async () => { if (apiBackend !== "laravel") { toast(s.checkIn().msg,{tone:"success"}); return; } try { const result = await lw.checkIn(); toast(`+${result.totalRewardCoins} coins claimed`,{tone:"success",title:"Daily reward claimed"}); } catch (error) { toast(error.message,{tone:"danger"}); } };
	const gameRows = apiBackend === 'laravel' ? lg.joinedGames : s.gameEntries;
	const g = gameRows[0];
	const latest = s.orders[0];
	return (
		<>
			<SEO title="Buyer Dashboard | VSN Ecommerce" />
			<div className="dashboard-page buyer-dashboard">
				<div className="dashboard-head">
					<div>
						<span>MY VSN</span>
						<h1>Hello, {(user?.name || s.profile.name).split(" ")[0]}</h1>
						<p>
							Orders, games, rewards, gifts, referrals and account security in
							one place.
						</p>
					</div>
					<div className="dashboard-head-actions">
						<Link className="ui-btn ui-btn--secondary" to="/notifications">
							<FaBell /> Alerts
						</Link>
						<Link className="ui-btn ui-btn--primary" to="/search">
							Continue shopping
						</Link>
					</div>
				</div>
				<div className="metric-grid buyer-metrics">
					<Link to="/orders" className="metric-link">
						<Card>
							<FaBox />
							<small>Active orders</small>
							<strong>
								{s.orders.filter(/** Inline callback for this operation. */ (o) => o.status !== "Delivered").length}
							</strong>
							<span>Track deliveries</span>
						</Card>
					</Link>
					<Link to="/coins" className="metric-link">
						<Card>
							<FaCoins />
							<small>Coin balance</small>
							<strong>{walletCoins.toLocaleString()}</strong>
							<span>Rs. {Number(walletValue).toFixed(2)} value</span>
						</Card>
					</Link>
					<Link to="/games" className="metric-link">
						<Card>
							<FaGamepad />
							<small>Joined games</small>
							<strong>{gameRows.length}</strong>
							<span>Announcement tracking</span>
						</Card>
					</Link>
					<Link to="/profile" className="metric-link">
						<Card>
							<FaIdCard />
							<small>Verification</small>
							<strong>
								{s.profile.idVerified && s.profile.phoneVerified
									? "Verified"
									: "Action needed"}
							</strong>
							<Status ok={s.profile.idVerified && s.profile.phoneVerified}>
								{s.profile.idVerified && s.profile.phoneVerified
									? "Complete"
									: "Review profile"}
							</Status>
						</Card>
					</Link>
				</div>
				<div className="dashboard-layout-main">
					<div className="dashboard-main-column">
						<Card className="dashboard-order-card">
							<div className="card-title">
								<div>
									<span className="eyebrow">LATEST ORDER</span>
									<h2>{latest.id}</h2>
									<p>
										{latest.items} items · Rs. {latest.total.toLocaleString()}
									</p>
								</div>
								<Link to="/orders">
									All orders <FaArrowRight />
								</Link>
							</div>
							<div className="delivery-progress">
								<div className="delivery-step done">
									<i />
									<span>
										<b>Confirmed</b>
										<small>Order accepted</small>
									</span>
								</div>
								<div className="delivery-step done">
									<i />
									<span>
										<b>Packed</b>
										<small>Seller prepared parcel</small>
									</span>
								</div>
								<div className="delivery-step active">
									<i />
									<span>
										<b>In transit</b>
										<small>Expected {latest.eta}</small>
									</span>
								</div>
								<div className="delivery-step">
									<i />
									<span>
										<b>Delivered</b>
										<small>Pending</small>
									</span>
								</div>
							</div>
							<Link className="text-link" to="/tracking">
								<FaTruck /> Track package
							</Link>
						</Card>
						<Card className="dashboard-review-card">
							<div className="card-title">
								<div>
									<span className="eyebrow">REVIEWS & REWARDS</span>
									<h2>{s.pendingReviews.length} products waiting</h2>
									<p>
										Review delivered purchases to unlock one-time 10% coupons.
									</p>
								</div>
								<FaStar />
							</div>
							{s.pendingReviews.slice(0, 2).map(/** Inline callback for this operation. */ (item) => (
								<div className="dashboard-review-row" key={item.key}>
									<div>
										<b>{item.name}</b>
										<small>{item.orderId}</small>
									</div>
									<span>
										<FaTag /> 10% reward
									</span>
									<Link to={`/product/${item.productId}?review=1`}>Review</Link>
								</div>
							))}
							<Link className="text-link" to="/reviews">
								Open Reviews <FaArrowRight />
							</Link>
						</Card>
						<Card className="dashboard-game-card">
							<div className="card-title">
								<div>
									<span className="eyebrow">CURRENT GAME</span>
									<h2>{g?.name}</h2>
									<p>Your joined Game Win entry</p>
								</div>
								<Badge tone={g?.status==='winner_selected'||g?.status==='fulfilled'?'success':'game'}>{(g?.status||'live').replaceAll('_',' ')}</Badge>
							</div>
							<div className="game-dashboard-row">
								<div>
									<small>Your entries</small>
									<strong>{g?.entries || 1}</strong>
								</div>
								<div>
									<small>Entry value</small>
									<strong>{g?.entryCoins || 70} coins</strong>
								</div>
								<div>
									<small>Winner announcement</small>
									<Countdown target={g?.announcementAt} />
								</div>
							</div>
							<Link className="text-link" to="/games">
								Open My Games <FaArrowRight />
							</Link>
						</Card>
						<Card className="buy-again-card">
							<SectionHeader
								title="Buy again"
								sub="Reorder products from delivered purchases"
							/>
							<div className="buy-again-grid">
								{s.orders
									.filter(/** Inline callback for this operation. */ (o) => o.status === "Delivered")
									.flatMap(/** Inline callback for this operation. */ (o) => o.products || [])
									.slice(0, 4)
									.map(/** Inline callback for this operation. */ (item, i) => (
										<Link
											to={`/product/${item.productId}`}
											key={`${item.productId}-${i}`}
										>
											<span>
												<b>{item.name}</b>
												<small>Previously purchased · View options</small>
											</span>
											<FaArrowRight />
										</Link>
									))}
							</div>
						</Card>
						<Card>
							<SectionHeader
								title="Account shortcuts"
								sub="Frequently used buyer services"
							/>
							<div className="account-shortcut-grid">
								<Link to="/wallet">
									<FaWallet />
									<span>
										<b>Wallet</b>
										<small>Coins & payment methods</small>
									</span>
								</Link>
								<Link to="/gifts">
									<FaGift />
									<span>
										<b>Gifts</b>
										<small>Send coins and track level</small>
									</span>
								</Link>
								<Link to="/affiliate">
									<FaUsers />
									<span>
										<b>Affiliate</b>
										<small>10-level rewards</small>
									</span>
								</Link>
								<Link to="/profile">
									<FaIdCard />
									<span>
										<b>Verification</b>
										<small>ID, phone & address proof</small>
									</span>
								</Link>
								<Link to="/messages">
									<FaBell />
									<span>
										<b>Messages</b>
										<small>Seller & support chat</small>
									</span>
								</Link>
								<Link to="/reviews">
									<FaStar />
									<span>
										<b>Reviews</b>
										<small>
											{s.pendingReviews.length} pending · earn 10% coupons
										</small>
									</span>
								</Link>
								<Link to="/returns">
									<FaUndo />
									<span>
										<b>Returns</b>
										<small>Refunds & disputes</small>
									</span>
								</Link>
								<Link to="/saved-alerts">
									<FaBookmark />
									<span>
										<b>Saved & alerts</b>
										<small>Price and stock watches</small>
									</span>
								</Link>
								<Link to="/settings">
									<FaCreditCard />
									<span>
										<b>Settings</b>
										<small>Security & preferences</small>
									</span>
								</Link>
							</div>
						</Card>
					</div>
					<aside className="dashboard-side-column">
						<Card className="daily-card">
							<div className="daily-card-header">
								<small>DAILY REWARD</small>
								<FaCoins />
							</div>
							<strong>+70 coins</strong>
							<p>Claim today’s free coins manually.</p>
							<button
								className="ui-btn ui-btn--coin"
								onClick={claimDaily}
							>
								Check in
							</button>
						</Card>
						<Card>
							<h3>Profile readiness</h3>
							<div className="status-stack">
								<Status ok={s.profile.phoneVerified}>Phone verified</Status>
								<Status ok={s.profile.idVerified}>Government ID</Status>
								<Status ok={s.profile.addressProofVerified}>
									Address proof
								</Status>
								<Status ok={s.profile.paymentMethods.length > 0}>
									Payment method
								</Status>
							</div>
							<Link className="text-link" to="/profile">
								Complete profile <FaArrowRight />
							</Link>
						</Card>
						<Card className="gift-level-mini">
							<div className="gift-level-header">
								<small>GIFT SENDER LEVEL</small>
								<strong>{apiBackend === "laravel" ? lGifts.level.name : s.giftLevel.name}</strong>
							</div>
							<p>{(apiBackend === "laravel" ? lGifts.lifetimeGiftCoins : s.giftSentCoins).toLocaleString()} coins gifted</p>
							<span>Next reward: {apiBackend === "laravel" ? (lGifts.level.nextReward || "Top level reached") : s.giftLevel.nextReward}</span>
							<Link className="text-link" to="/gifts">
								Open gifts
							</Link>
						</Card>
					</aside>
				</div>
			</div>
		</>
	);
}
