import { lazy, Suspense } from "react";
import { Routes, Route, Navigate } from "react-router-dom";
import Shell from "./layout/Shell";
import AdminShell from "./layout/AdminShell";
import VendorShell from "./layout/VendorShell";
import AccountShell from "./layout/AccountShell";

const lazyNamed = /** Handles lazy named for the VSN Ecommerce interface. */ (factory, name) => lazy(/** Inline callback for this operation. */ () => factory().then(/** Inline callback for this operation. */ (module) => ({default: module[name]})));

const Home = lazy(/** Inline callback for this operation. */ () => import("./pages/Home"));
const Search = lazy(/** Inline callback for this operation. */ () => import("./pages/Search"));
const Product = lazy(/** Inline callback for this operation. */ () => import("./pages/Product"));
const Games = lazy(/** Inline callback for this operation. */ () => import("./pages/Games"));
const Affiliate = lazy(/** Inline callback for this operation. */ () => import("./pages/Affiliate"));
const Help = lazy(/** Inline callback for this operation. */ () => import("./pages/Help"));
const Coins = lazy(/** Inline callback for this operation. */ () => import("./pages/Coins"));
const Reviews = lazy(/** Inline callback for this operation. */ () => import("./pages/Reviews"));
const AdminReviews = lazy(/** Inline callback for this operation. */ () => import("./pages/AdminReviews"));
const AdminMedia = lazy(/** Loads the administrator media library route. */ () => import("./pages/AdminMedia"));
const VendorMedia = lazy(/** Loads the seller media library route. */ () => import("./pages/VendorMedia"));
const VendorsList = lazyNamed(/** Loads the public seller directory route. */ () => import("./pages/Vendors"), "VendorsList");
const VendorShop = lazyNamed(/** Loads the public seller storefront route. */ () => import("./pages/Vendors"), "VendorShop");
const SellerReviews = lazy(/** Inline callback for this operation. */ () => import("./pages/SellerReviews"));
const AdminCompliance = lazy(/** Inline callback for this operation. */ () => import("./pages/AdminCompliance"));
const AdminRisk = lazy(/** Inline callback for this operation. */ () => import("./pages/Risk"));
const AdminAnalytics = lazy(/** Inline callback for this operation. */ () => import("./pages/AdminAnalytics"));
const AdminUsers = lazy(/** Inline callback for this operation. */ () => import("./pages/AdminUsers"));
const AdminAccess = lazy(/** Inline callback for this operation. */ () => import("./pages/AdminAccess"));
const AdminVendors = lazy(/** Inline callback for this operation. */ () => import("./pages/AdminVendors"));
const AccessDenied = lazy(/** Inline callback for this operation. */ () => import("./pages/AccessDenied"));
const Acceptance = lazy(/** Inline callback for this operation. */ () => import("./pages/Acceptance"));

const AdminLoyalty = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/AdminEngagement"), "AdminLoyalty");
const AdminGames = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/AdminEngagement"), "AdminGames");
const AdminOrders = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/AdminOperations"), "AdminOrders");
const AdminOrderDetail = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/AdminOperations"), "AdminOrderDetail");
const AdminShipping = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/AdminOperations"), "AdminShipping");
const AdminPayments = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/AdminOperations"), "AdminPayments");
const AdminReturns = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/AdminOperations"), "AdminReturns");
const AdminReturnDetail = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/AdminOperations"), "AdminReturnDetail");
const AdminFinanceCenter = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/AdminOperations"), "AdminFinanceCenter");
const AdminPayouts = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/AdminOperations"), "AdminPayouts");
const AdminNotifications = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/AdminOperations"), "AdminNotifications");
const AdminSettings = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/AdminOperations"), "AdminSettings");
const SellerCatalog = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/CatalogManagement"), "SellerCatalog");
const SellerProductEditor = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/CatalogManagement"), "SellerProductEditor");
const AdminCatalog = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/CatalogManagement"), "AdminCatalog");
const AdminProductEditor = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/CatalogManagement"), "AdminProductEditor");
const WishlistPage = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Personalization"), "WishlistPage");
const RecentlyViewedPage = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Personalization"), "RecentlyViewedPage");
const BuyAgainPage = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Personalization"), "BuyAgainPage");
const DealsPage = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Promotions"), "DealsPage");
const SellerPromotions = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Promotions"), "SellerPromotions");
const AdminPromotions = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Promotions"), "AdminPromotions");
const MyInvoices = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Tax"), "MyInvoices");
const VendorTaxProfile = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Tax"), "VendorTaxProfile");
const VendorInvoices = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Tax"), "VendorInvoices");
const AdminTax = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Tax"), "AdminTax");
const ProductionReadiness = lazy(/** Inline callback for this operation. */ () => import("./pages/Production"));
const LegalCenter = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Production"), "LegalCenter");
const Cart = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Generic"), "Cart");
const NotFound = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Generic"), "NotFound");
const Orders = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Systems"), "Orders");
const Checkout = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Systems"), "Checkout");
const Tracking = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Systems"), "Tracking");
const Wallet = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Systems"), "Wallet");
const Notifications = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Systems"), "Notifications");
const Messages = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Systems"), "Messages");
const Settings = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Systems"), "Settings");
const Gifts = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Systems"), "Gifts");
const AdminControl = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Systems"), "AdminControl");
const ReturnsCenter = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Systems"), "ReturnsCenter");
const SavedAlerts = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Systems"), "SavedAlerts");
const OperationsCenter = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Systems"), "OperationsCenter");
const SellerQuality = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Systems"), "SellerQuality");
const Auth = lazy(/** Inline callback for this operation. */ () => import("./pages/Auth"));
const ForgotPassword = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Auth"), "ForgotPassword");
const ResetPassword = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Auth"), "ResetPassword");
const AuthCallback = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Auth"), "AuthCallback");
const AccountOverview = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Account"), "AccountOverview");
const AccountProfile = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Account"), "AccountProfile");
const AccountAddresses = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Account"), "AccountAddresses");
const AccountOrders = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Account"), "AccountOrders");
const AccountOrderDetail = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Account"), "AccountOrderDetail");
const AccountWallet = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Account"), "AccountWallet");
const AccountPaymentMethods = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Account"), "AccountPaymentMethods");
const AccountVerification = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Account"), "AccountVerification");
const AccountSecurity = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Account"), "AccountSecurity");
const AccountNotifications = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Account"), "AccountNotifications");
const AccountMessages = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Account"), "AccountMessages");
const AccountReturns = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/Account"), "AccountReturns");
const SellerOverview = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/SellerCenter"), "SellerOverview");
const SellerInventory = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/SellerCenter"), "SellerInventory");
const SellerOrders = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/SellerCenter"), "SellerOrders");
const SellerOrderDetail = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/SellerCenter"), "SellerOrderDetail");
const SellerShipping = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/SellerCenter"), "SellerShipping");
const SellerReturns = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/SellerCenter"), "SellerReturns");
const SellerFinance = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/SellerCenter"), "SellerFinance");
const SellerPayouts = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/SellerCenter"), "SellerPayouts");
const SellerAnalytics = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/SellerCenter"), "SellerAnalytics");
const SellerVerification = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/SellerCenter"), "SellerVerification");
const SellerSettings = lazyNamed(/** Inline callback for this operation. */ () => import("./pages/SellerCenter"), "SellerSettings");
import { useCart } from "./platform/cart";
import { ADMIN_AREA_ROLES, FULL_ADMIN_ROLES, SELLER_ROLES } from "./platform/auth";
import { GuestOnly, RequireAuth, RequireRole, RequirePermission } from "./components/RouteGuards";

const auth = /** Handles auth for the VSN Ecommerce interface. */ (node) => <RequireAuth>{node}</RequireAuth>;
const role = /** Handles role for the VSN Ecommerce interface. */ (roles, node) => <RequireRole roles={roles}>{node}</RequireRole>;
const permit = /** Handles permit for the VSN Ecommerce interface. */ (permission, node) => <RequirePermission permission={permission}>{node}</RequirePermission>;

/** Handles app for the VSN Ecommerce interface. */
export default function App() {
  const { cart, addItem, removeItem, updateItem, loading: cartLoading, busyItemId, error: cartError } = useCart();
  const add = /** Handles add for the VSN Ecommerce interface. */ async (product, quantity = 1) => addItem(product, quantity);

  return <Suspense fallback={<div className="route-state"><div className="route-state-card"><span className="ui-spinner" aria-hidden="true"/><p>Loading…</p></div></div>}><Routes>
    <Route path="/auth" element={<GuestOnly><Auth/></GuestOnly>}/>
    <Route path="/login" element={<GuestOnly><Auth mode="login"/></GuestOnly>}/>
    <Route path="/register" element={<GuestOnly><Auth mode="register"/></GuestOnly>}/>
    <Route path="/auth/callback" element={<AuthCallback/>}/>
    <Route path="/forgot-password" element={<GuestOnly><ForgotPassword/></GuestOnly>}/>
    <Route path="/reset-password" element={<GuestOnly><ResetPassword/></GuestOnly>}/>
    <Route path="/access-denied" element={auth(<AccessDenied/>)}/>

    <Route path="/admin/*" element={role(ADMIN_AREA_ROLES, <AdminShell/>)}>
      <Route index element={<AdminControl/>}/>
      <Route path="users" element={permit("users.view", <AdminUsers/>)}/>
      <Route path="access" element={permit("users.view", <AdminAccess/>)}/>
      <Route path="vendors" element={permit("vendors.view", <AdminVendors/>)}/>
      <Route path="catalog" element={permit("catalog.view", <AdminCatalog/>)}/>
      <Route path="catalog/new" element={permit("catalog.manage", <AdminProductEditor/>)}/>
      <Route path="catalog/:id/edit" element={permit("catalog.manage", <AdminProductEditor/>)}/>
      <Route path="promotions" element={permit("promotions.view", <AdminPromotions/>)}/>
      <Route path="loyalty" element={permit("loyalty.view", <AdminLoyalty/>)}/>
      <Route path="games" element={permit("games.view", <AdminGames/>)}/>
      <Route path="tax" element={permit("tax.view", <AdminTax/>)}/>
      <Route path="reviews" element={permit("reviews.view", <AdminReviews/>)}/>
      <Route path="media" element={permit("media.view", <AdminMedia/>)}/>
      <Route path="compliance" element={permit("compliance.view", <AdminCompliance/>)}/>
      <Route path="risk" element={permit("risk.view", <AdminRisk/>)}/>
      <Route path="analytics" element={permit("analytics.view", <AdminAnalytics/>)}/>
      <Route path="orders" element={permit("orders.view", <AdminOrders/>)}/>
      <Route path="orders/:id" element={permit("orders.view", <AdminOrderDetail/>)}/>
      <Route path="shipping" element={permit("shipping.view", <AdminShipping/>)}/>
      <Route path="payments" element={permit("payments.view", <AdminPayments/>)}/>
      <Route path="returns" element={permit("returns.view", <AdminReturns/>)}/>
      <Route path="returns/:id" element={permit("returns.view", <AdminReturnDetail/>)}/>
      <Route path="finance" element={permit("finance.view", <AdminFinanceCenter/>)}/>
      <Route path="payouts" element={permit("finance.view", <AdminPayouts/>)}/>
      <Route path="notifications" element={permit("notifications.view", <AdminNotifications/>)}/>
      <Route path="settings" element={permit("settings.view", <AdminSettings/>)}/>
      <Route path="operations" element={permit("operations.view", <OperationsCenter/>)}/>
      <Route path="seller-quality" element={permit("vendors.view", <SellerQuality/>)}/>
      <Route path="production-readiness" element={permit("operations.view", <ProductionReadiness/>)}/>
      <Route path="acceptance" element={permit("acceptance.view", <Acceptance/>)}/>
    </Route>

    <Route path="/vendor/*" element={role(SELLER_ROLES, <VendorShell/>)}>
      <Route index element={<SellerOverview/>}/>
      <Route path="products" element={<SellerCatalog/>}/>
      <Route path="products/new" element={<SellerProductEditor/>}/>
      <Route path="products/:id/edit" element={<SellerProductEditor/>}/>
      <Route path="inventory" element={<SellerInventory/>}/>
      <Route path="orders" element={<SellerOrders/>}/>
      <Route path="orders/:id" element={<SellerOrderDetail/>}/>
      <Route path="shipping" element={<SellerShipping/>}/>
      <Route path="returns" element={<SellerReturns/>}/>
      <Route path="promotions" element={<SellerPromotions/>}/>
      <Route path="reviews" element={<SellerReviews/>}/>
      <Route path="finance" element={<SellerFinance/>}/>
      <Route path="payouts" element={<SellerPayouts/>}/>
      <Route path="analytics" element={<SellerAnalytics/>}/>
      <Route path="verification" element={<SellerVerification/>}/>
      <Route path="tax" element={<VendorTaxProfile/>}/>
      <Route path="tax-invoices" element={<VendorInvoices/>}/>
      <Route path="media" element={<VendorMedia/>}/>
      <Route path="settings" element={<SellerSettings/>}/>
    </Route>

    <Route path="*" element={<Shell cartCount={cart.summary?.quantity || 0}>
      <Routes>
        <Route path="/" element={<Home onAdd={add}/>}/>
        <Route path="/search" element={<Search onAdd={add}/>}/>
        <Route path="/deals" element={<DealsPage onAdd={add}/>}/>
        <Route path="/vendors" element={<VendorsList/>}/>
        <Route path="/shop/:slug" element={<VendorShop onAdd={add}/>}/>
        <Route path="/product/:id" element={<Product onAdd={add}/>}/>
        <Route path="/games" element={<Games/>}/>
        <Route path="/help" element={<Help/>}/>
        <Route path="/legal" element={<LegalCenter/>}/>
        <Route path="/cart" element={<Cart cart={cart} onRemove={removeItem} onUpdate={updateItem} loading={cartLoading} busyItemId={busyItemId} cartError={cartError}/>}/>

        <Route path="/account" element={auth(<AccountShell/>)}>
          <Route index element={<AccountOverview/>}/>
          <Route path="profile" element={<AccountProfile/>}/>
          <Route path="addresses" element={<AccountAddresses/>}/>
          <Route path="orders" element={<AccountOrders/>}/>
          <Route path="orders/:id" element={<AccountOrderDetail/>}/>
          <Route path="wishlist" element={<WishlistPage onAdd={add}/>}/>
          <Route path="wallet" element={<AccountWallet/>}/>
          <Route path="payment-methods" element={<AccountPaymentMethods/>}/>
          <Route path="verification" element={<AccountVerification/>}/>
          <Route path="security" element={<AccountSecurity/>}/>
          <Route path="notifications" element={<AccountNotifications/>}/>
          <Route path="messages" element={<AccountMessages/>}/>
          <Route path="returns" element={<AccountReturns/>}/>
        </Route>

        <Route path="/dashboard" element={auth(<Navigate to="/account" replace/>)}/>
        <Route path="/orders" element={auth(<Navigate to="/account/orders" replace/>)}/>
        <Route path="/profile" element={auth(<Navigate to="/account/profile" replace/>)}/>
        <Route path="/coins" element={auth(<Coins/>)}/>
        <Route path="/affiliate" element={auth(<Affiliate/>)}/>
        <Route path="/reviews" element={auth(<Reviews/>)}/>
        <Route path="/gifts" element={auth(<Gifts/>)}/>
        <Route path="/wallet" element={auth(<Navigate to="/account/wallet" replace/>)}/>
        <Route path="/invoices" element={auth(<MyInvoices/>)}/>
        <Route path="/tracking" element={auth(<Tracking/>)}/>
        <Route path="/notifications" element={auth(<Navigate to="/account/notifications" replace/>)}/>
        <Route path="/messages" element={auth(<Navigate to="/account/messages" replace/>)}/>
        <Route path="/settings" element={auth(<Navigate to="/account/security" replace/>)}/>
        <Route path="/returns" element={auth(<Navigate to="/account/returns" replace/>)}/>
        <Route path="/saved-alerts" element={auth(<SavedAlerts/>)}/>
        <Route path="/wishlist" element={auth(<Navigate to="/account/wishlist" replace/>)}/>
        <Route path="/recently-viewed" element={auth(<RecentlyViewedPage onAdd={add}/>)}/>
        <Route path="/buy-again" element={auth(<BuyAgainPage onAdd={add}/>)}/>
        <Route path="/checkout" element={auth(<Checkout/>)}/>
        <Route path="*" element={<NotFound/>}/>
      </Routes>
    </Shell>}/>
  </Routes></Suspense>;
}
