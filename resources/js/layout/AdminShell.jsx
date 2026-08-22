import {useEffect,useState} from "react";
import { NavLink, Outlet, Link, useLocation, useNavigate } from "react-router-dom";
import { FaBars,FaBell, FaBoxOpen, FaChartLine, FaCheckCircle, FaCreditCard, FaClipboardCheck, FaCoins, FaCog, FaFileInvoiceDollar, FaIdCard, FaList, FaMoneyBillWave, FaPercentage, FaPhotoVideo, FaShippingFast, FaShieldAlt, FaSignOutAlt, FaStore, FaTags, FaTimes, FaUndo, FaUsers, FaUserShield, FaWrench } from "react-icons/fa";
import { useAuth } from "../platform/auth";
import SkipLink from "../components/SkipLink";

const items = [
  ["/admin", "Overview", FaChartLine, "admin.overview.view", true],
  ["/admin/users", "Users", FaUsers, "users.view"],
  ["/admin/access", "Access Control", FaUserShield, "users.view"],
  ["/admin/vendors", "Vendors", FaStore, "vendors.view"],
  ["/admin/catalog", "Catalog", FaBoxOpen, "catalog.view"],
  ["/admin/orders", "Orders", FaClipboardCheck, "orders.view"],
  ["/admin/shipping", "Shipping", FaShippingFast, "shipping.view"],
  ["/admin/payments", "Payments", FaCreditCard, "payments.view"],
  ["/admin/returns", "Returns & Refunds", FaUndo, "returns.view"],
  ["/admin/finance", "Finance", FaMoneyBillWave, "finance.view"],
  ["/admin/payouts", "Payouts", FaFileInvoiceDollar, "finance.view"],
  ["/admin/promotions", "Promotions", FaPercentage, "promotions.view"],
  ["/admin/loyalty", "VSN Coins & Affiliate", FaCoins, "loyalty.view"],
  ["/admin/games", "Game Win", FaTags, "games.view"],
  ["/admin/tax", "Tax & VAT", FaFileInvoiceDollar, "tax.view"],
  ["/admin/reviews", "Reviews", FaList, "reviews.view"],
  ["/admin/media", "Media", FaPhotoVideo, "media.view"],
  ["/admin/compliance", "Compliance", FaIdCard, "compliance.view"],
  ["/admin/risk", "Risk", FaShieldAlt, "risk.view"],
  ["/admin/analytics", "Analytics", FaChartLine, "analytics.view"],
  ["/admin/seller-quality", "Seller Quality", FaTags, "vendors.view"],
  ["/admin/notifications", "Notifications", FaBell, "notifications.view"],
  ["/admin/settings", "Settings", FaCog, "settings.view"],
  ["/admin/operations", "Operations", FaWrench, "operations.view"],
  ["/admin/production-readiness", "Readiness", FaCheckCircle, "operations.view"],
  ["/admin/acceptance", "Acceptance", FaUserShield, "acceptance.view"],
];

/** Handles admin shell for the VSN Ecommerce interface. */
export default function AdminShell() {
  const { user, logout, hasPermission } = useAuth();
  const navigate = useNavigate();
  const location=useLocation();
  const [open,setOpen]=useState(false);
  useEffect(/** Inline callback for this operation. */ ()=>setOpen(false),[location.pathname]);
  const visible = items.filter(/** Inline callback for this operation. */ ([, , , permission]) => hasPermission(permission));
  const signOut = /** Handles sign out for the VSN Ecommerce interface. */ async () => { await logout(); navigate("/login", { replace: true }); };
  return <div className={`admin-app-shell ${open?'nav-open':''}`}>
    <SkipLink/>
    <button className="shell-overlay" aria-label="Close navigation" onClick={/** Inline callback for this operation. */ ()=>setOpen(false)}/>
    <aside className="admin-sidebar" aria-label="Admin navigation">
      <div className="mobile-drawer-head"><span>Navigation</span><button type="button" aria-label="Close navigation" onClick={/** Inline callback for this operation. */ ()=>setOpen(false)}><FaTimes/></button></div>
      <Link to="/admin" className="admin-brand"><span>VSN</span><b>Ecommerce</b><small>Administration</small></Link>
      <nav className="admin-nav">
        {visible.map(/** Inline callback for this operation. */ ([to,label,Icon,,end]) => <NavLink key={to} end={Boolean(end)} to={to}><Icon/><span>{label}</span></NavLink>)}
      </nav>
      <div className="admin-sidebar-footer">
        <Link to="/">← View storefront</Link>
        <button type="button" onClick={signOut}><FaSignOutAlt/> Sign out</button>
      </div>
    </aside>
    <section className="admin-workspace">
      <header className="admin-topbar">
        <div className="admin-topbar-title"><button className="shell-menu-button" type="button" aria-label="Open navigation" aria-expanded={open} onClick={/** Inline callback for this operation. */ ()=>setOpen(true)}><FaBars/></button><div><small>VSN Ecommerce</small><strong>Admin Panel</strong></div></div>
        <div className="admin-user-chip"><span>{user?.name?.slice(0,1)?.toUpperCase()}</span><div><b>{user?.name}</b><small>{String(user?.role || "").replaceAll("_"," ")}</small></div></div>
      </header>
      <main className="admin-content" id="main-content" tabIndex="-1"><Outlet/></main>
    </section>
  </div>;
}
