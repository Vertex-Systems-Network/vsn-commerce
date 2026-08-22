import {useEffect,useState} from 'react';
import {NavLink, Outlet, useLocation} from 'react-router-dom';
import {useAuth} from '../platform/auth';
import {FaBars,FaHome, FaUser, FaMapMarkerAlt, FaBox, FaHeart, FaWallet, FaCreditCard,FaIdCard, FaShieldAlt, FaBell, FaEnvelope, FaUndo, FaChevronRight,FaTimes} from 'react-icons/fa';
import SkipLink from '../components/SkipLink';

const nav = [
  ['/account','Overview',FaHome,true],['/account/profile','Profile',FaUser],['/account/addresses','Addresses',FaMapMarkerAlt],['/account/orders','Orders',FaBox],['/account/wishlist','Wishlist',FaHeart],['/account/wallet','VSN Coins',FaWallet],['/account/payment-methods','Payment methods',FaCreditCard],['/account/verification','Verification',FaIdCard],['/account/security','Security',FaShieldAlt],['/account/notifications','Notifications',FaBell],['/account/messages','Messages',FaEnvelope],['/account/returns','Returns & refunds',FaUndo],
];

/** Handles account shell for the VSN Ecommerce interface. */
export default function AccountShell(){
  const {user}=useAuth();const location=useLocation();const [open,setOpen]=useState(false);
  useEffect(/** Inline callback for this operation. */ ()=>setOpen(false),[location.pathname]);
  return <div className={`account-center ${open?'nav-open':''}`}>
    <SkipLink/>
    <button className="account-overlay" aria-label="Close account navigation" onClick={/** Inline callback for this operation. */ ()=>setOpen(false)}/>
    <div className="account-mobile-bar"><button type="button" aria-label="Open account navigation" aria-expanded={open} onClick={/** Inline callback for this operation. */ ()=>setOpen(true)}><FaBars/> Account menu</button><span>{nav.find(/** Inline callback for this operation. */ ([to,, ,end])=>end?location.pathname===to:location.pathname.startsWith(to))?.[1]||'Account'}</span></div>
    <aside className="account-sidebar" aria-label="Account navigation">
      <div className="mobile-drawer-head"><span>My account</span><button type="button" aria-label="Close account navigation" onClick={/** Inline callback for this operation. */ ()=>setOpen(false)}><FaTimes/></button></div>
      <div className="account-identity"><span className="account-avatar">{(user?.name||'U').slice(0,1).toUpperCase()}</span><div><strong>{user?.name}</strong><small>{user?.email}</small></div></div>
      <nav>{nav.map(/** Inline callback for this operation. */ ([to,label,Icon,end])=><NavLink key={to} to={to} end={Boolean(end)} className={/** Inline callback for this operation. */ ({isActive})=>isActive?'active':''}><Icon/><span>{label}</span><FaChevronRight className="account-nav-arrow"/></NavLink>)}</nav>
    </aside>
    <section className="account-content" id="main-content" tabIndex="-1"><Outlet/></section>
  </div>;
}
