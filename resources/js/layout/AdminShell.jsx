import {useEffect,useMemo,useState} from "react";
import { NavLink, Outlet, Link, useLocation, useNavigate } from "react-router-dom";
import { FaBars,FaBell, FaBoxOpen, FaChartLine, FaCheckCircle, FaCreditCard, FaClipboardCheck, FaCoins, FaCog, FaFileInvoiceDollar, FaIdCard, FaList, FaMoneyBillWave, FaPercentage, FaPhotoVideo, FaShippingFast, FaShieldAlt, FaSignOutAlt, FaStore, FaTags, FaTimes, FaUndo, FaUsers, FaUserShield, FaWrench } from "react-icons/fa";
import { useAuth } from "../platform/auth";
import SkipLink from "../components/SkipLink";

const groups = [
  {label:"Overview",items:[
    ["/admin", "Overview", FaChartLine, "admin.overview.view", true],
    ["/admin/analytics", "Analytics", FaChartLine, "analytics.view"],
  ]},
  {label:"Commerce",items:[
    ["/admin/catalog", "Catalog", FaBoxOpen, "catalog.view"],
    ["/admin/orders", "Orders", FaClipboardCheck, "orders.view"],
    ["/admin/shipping", "Shipping", FaShippingFast, "shipping.view"],
    ["/admin/returns", "Returns & Refunds", FaUndo, "returns.view"],
    ["/admin/reviews", "Reviews", FaList, "reviews.view"],
  ]},
  {label:"Marketplace",items:[
    ["/admin/vendors", "Vendors", FaStore, "vendors.view"],
    ["/admin/seller-quality", "Seller Quality", FaTags, "vendors.view"],
    ["/admin/promotions", "Promotions", FaPercentage, "promotions.view"],
    ["/admin/games", "Game Win", FaTags, "games.view"],
    ["/admin/loyalty", "VSN Coins & Affiliate", FaCoins, "loyalty.view"],
    ["/admin/media", "Media", FaPhotoVideo, "media.view"],
  ]},
  {label:"Finance",items:[
    ["/admin/payments", "Payments", FaCreditCard, "payments.view"],
    ["/admin/finance", "Finance", FaMoneyBillWave, "finance.view"],
    ["/admin/payouts", "Payouts", FaFileInvoiceDollar, "finance.view"],
    ["/admin/tax", "Tax & VAT", FaFileInvoiceDollar, "tax.view"],
  ]},
  {label:"People & Access",items:[
    ["/admin/users", "Users", FaUsers, "users.view"],
    ["/admin/access", "Access Control", FaUserShield, "users.view"],
  ]},
  {label:"Trust & Operations",items:[
    ["/admin/compliance", "Compliance", FaIdCard, "compliance.view"],
    ["/admin/risk", "Risk", FaShieldAlt, "risk.view"],
    ["/admin/notifications", "Notifications", FaBell, "notifications.view"],
    ["/admin/operations", "Operations", FaWrench, "operations.view"],
    ["/admin/production-readiness", "Readiness", FaCheckCircle, "operations.view"],
    ["/admin/acceptance", "Acceptance", FaUserShield, "acceptance.view"],
  ]},
  {label:"Configuration",items:[
    ["/admin/settings", "Settings", FaCog, "settings.view"],
  ]},
];

/** Handles admin shell for the VSN Ecommerce interface. */
export default function AdminShell() {
  const { user, logout, hasPermission } = useAuth();
  const navigate = useNavigate();
  const location=useLocation();
  const [open,setOpen]=useState(false);
  useEffect(/** Inline callback for this operation. */ ()=>setOpen(false),[location.pathname]);
  const visibleGroups=useMemo(/** Preserves backend permission semantics while hiding empty navigation groups. */ ()=>groups.map(group=>({...group,items:group.items.filter(([, , , permission])=>hasPermission(permission))})).filter(group=>group.items.length),[hasPermission]);
  const currentLabel=useMemo(/** Resolves the longest matching navigation route for detail pages. */ ()=>{
    const candidates=groups.flatMap(group=>group.items).filter(([to,,, ,end])=>end?location.pathname===to:location.pathname===to||location.pathname.startsWith(`${to}/`));
    return candidates.sort((a,b)=>b[0].length-a[0].length)[0]?.[1]||"Admin Panel";
  },[location.pathname]);
  const signOut = /** Handles sign out for the VSN Ecommerce interface. */ async () => { await logout(); navigate("/login", { replace: true }); };
  return <div className={`admin-app-shell ${open?'nav-open':''}`}>
    <SkipLink/>
    <button className="shell-overlay" aria-label="Close navigation" onClick={/** Inline callback for this operation. */ ()=>setOpen(false)}/>
    <aside className="admin-sidebar" aria-label="Admin navigation">
      <div className="mobile-drawer-head"><span>Navigation</span><button type="button" aria-label="Close navigation" onClick={/** Inline callback for this operation. */ ()=>setOpen(false)}><FaTimes/></button></div>
      <Link to="/admin" className="admin-brand"><span>VSN</span><b>Ecommerce</b><small>Administration</small></Link>
      <nav className="admin-nav">
        {visibleGroups.map(group=><div className="admin-nav-group" key={group.label}><small>{group.label}</small>{group.items.map(([to,label,Icon,,end])=><NavLink key={to} end={Boolean(end)} to={to}><Icon/><span>{label}</span></NavLink>)}</div>)}
      </nav>
      <div className="admin-sidebar-footer">
        <Link to="/">← View storefront</Link>
        <button type="button" onClick={signOut}><FaSignOutAlt/> Sign out</button>
      </div>
    </aside>
    <section className="admin-workspace">
      <header className="admin-topbar">
        <div className="admin-topbar-title"><button className="shell-menu-button" type="button" aria-label="Open navigation" aria-expanded={open} onClick={/** Inline callback for this operation. */ ()=>setOpen(true)}><FaBars/></button><div><small>Administration</small><strong>{currentLabel}</strong></div></div>
        <div className="admin-user-chip"><span>{user?.name?.slice(0,1)?.toUpperCase()}</span><div><b>{user?.name}</b><small>{String(user?.role || "").replaceAll("_"," ")}</small></div></div>
      </header>
      <main className="admin-content" id="main-content" tabIndex="-1"><Outlet/></main>
    </section>
  </div>;
}
