import {useEffect,useState} from "react";
import {Link,NavLink,Outlet,useLocation,useNavigate} from "react-router-dom";
import {FaBars,FaBoxOpen,FaChartLine,FaClipboardList,FaCog,FaFileInvoiceDollar,FaIdCard,FaMoneyBillWave,FaPercentage,FaPhotoVideo,FaStar,FaSignOutAlt,FaStore,FaTimes,FaTruck,FaUndo,FaUser,FaWarehouse} from "react-icons/fa";
import {useAuth} from "../platform/auth";
import SkipLink from "../components/SkipLink";

const groups=[
  {label:"Business",items:[["/vendor","Overview",FaChartLine,true],["/vendor/orders","Orders",FaClipboardList],["/vendor/shipping","Shipping",FaTruck],["/vendor/returns","Returns",FaUndo]]},
  {label:"Catalog",items:[["/vendor/products","Products",FaBoxOpen],["/vendor/media","Media Library",FaPhotoVideo],["/vendor/inventory","Inventory",FaWarehouse],["/vendor/promotions","Promotions",FaPercentage],["/vendor/reviews","Reviews",FaStar]]},
  {label:"Money",items:[["/vendor/finance","Finance",FaMoneyBillWave],["/vendor/payouts","Payouts",FaMoneyBillWave],["/vendor/tax","Tax Profile",FaFileInvoiceDollar],["/vendor/tax-invoices","Tax Invoices",FaFileInvoiceDollar]]},
  {label:"Store",items:[["/vendor/analytics","Analytics",FaChartLine],["/vendor/verification","Verification",FaIdCard],["/vendor/settings","Settings",FaCog]]},
];

/** Handles vendor shell for the VSN Ecommerce interface. */
export default function VendorShell(){
  const {user,logout}=useAuth();const nav=useNavigate();const location=useLocation();const [open,setOpen]=useState(false);
  useEffect(/** Inline callback for this operation. */ ()=>setOpen(false),[location.pathname]);
  const signOut=/** Handles sign out for the VSN Ecommerce interface. */ async()=>{await logout();nav("/login",{replace:true})};
  return <div className={`vendor-app-shell ${open?'nav-open':''}`}><SkipLink/><button className="shell-overlay" aria-label="Close navigation" onClick={/** Inline callback for this operation. */ ()=>setOpen(false)}/><aside className="vendor-sidebar" aria-label="Seller navigation">
    <div className="mobile-drawer-head"><span>Seller navigation</span><button type="button" aria-label="Close navigation" onClick={/** Inline callback for this operation. */ ()=>setOpen(false)}><FaTimes/></button></div>
    <Link to="/vendor" className="admin-brand"><span>VSN</span><b>Ecommerce</b><small>Seller Center</small></Link>
    <nav className="admin-nav vendor-nav">{groups.map(/** Inline callback for this operation. */ g=><div className="vendor-nav-group" key={g.label}><small>{g.label}</small>{g.items.map(/** Inline callback for this operation. */ ([to,label,Icon,end])=><NavLink key={to} end={Boolean(end)} to={to}><Icon/><span>{label}</span></NavLink>)}</div>)}</nav>
    <div className="admin-sidebar-footer"><Link to="/account/profile"><FaUser/> My profile</Link><Link to="/"><FaStore/> Storefront</Link><button type="button" onClick={signOut}><FaSignOutAlt/> Sign out</button></div>
  </aside><section className="admin-workspace"><header className="admin-topbar"><div className="admin-topbar-title"><button className="shell-menu-button" type="button" aria-label="Open seller navigation" aria-expanded={open} onClick={/** Inline callback for this operation. */ ()=>setOpen(true)}><FaBars/></button><div><small>VSN Ecommerce</small><strong>Seller Center</strong></div></div><div className="admin-user-chip"><span>{user?.name?.slice(0,1)?.toUpperCase()||"S"}</span><div><b>{user?.name||"Seller"}</b><small>{user?.role?.replaceAll("_"," ")||"seller"}</small></div></div></header><main className="admin-content" id="main-content" tabIndex="-1"><Outlet/></main></section></div>
}
