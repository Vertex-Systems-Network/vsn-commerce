import { useEffect, useState } from "react";
import { Link, NavLink, useLocation, useNavigate } from "react-router-dom";
import {FaBars,FaSearch,FaHeart,FaShoppingCart,FaUser,FaGamepad,FaHeadset,FaBell,FaEnvelope,FaBox,FaTimes} from "react-icons/fa";
import { apiGet } from "../platform/api";
import { defaultHomeFor, useAuth } from "../platform/auth";
import SkipLink from "../components/SkipLink";
/** Handles shell for the VSN Ecommerce interface. */
export default function Shell({ children, cartCount = 0 }) {
 const nav = useNavigate();const location=useLocation();
 const { user, loading: authLoading, logout } = useAuth();
 const [activity,setActivity]=useState({notificationsUnread:0,messagesUnread:0});const [menuOpen,setMenuOpen]=useState(false);const [search,setSearch]=useState('');const [navCategories,setNavCategories]=useState([]);
 useEffect(/** Inline callback for this operation. */ ()=>setMenuOpen(false),[location.pathname]);
 useEffect(/** Loads the live public category navigation from Laravel. */ ()=>{let live=true;apiGet("/categories").then(/** Stores only active server categories. */ rows=>{if(live)setNavCategories((rows||[]).slice(0,8))}).catch(/** Keeps navigation usable when category loading fails. */ ()=>{if(live)setNavCategories([])});return/** Ignores late category responses after shell unmount. */ ()=>{live=false}},[]);
 useEffect(/** Loads authenticated activity counters from the server. */ ()=>{if(!user){setActivity({notificationsUnread:0,messagesUnread:0});return undefined;}let live=true;const load=/** Handles load for the VSN Ecommerce interface. */ ()=>apiGet("/activity").then(/** Inline callback for this operation. */ data=>live&&setActivity(data||{})).catch(/** Inline callback for this operation. */ ()=>{});load();const id=setInterval(load,15000);return/** Inline callback for this operation. */ ()=>{live=false;clearInterval(id)}},[user?.id]);
 const submitSearch=/** Handles submit search for the VSN Ecommerce interface. */ ()=>{const value=search.trim();nav(value?`/search?q=${encodeURIComponent(value)}`:'/search')};
 return <>
  <SkipLink/>
  <header className="site-header">
   <div className="top-strip"><span>Free nationwide delivery over Rs. 2,999</span><nav><Link to="/tracking">Track order</Link><Link to="/vendors">All stores</Link><Link to="/help">Customer care</Link><Link to="/vendor">Sell on VSN</Link></nav></div>
   <div className="header-main">
    <Link className="brand" to="/"><span>V</span><b>VSN<small>ECOMMERCE</small></b></Link>
    <button className="mobile-header-menu" type="button" aria-label="Open menu" aria-expanded={menuOpen} onClick={/** Inline callback for this operation. */ ()=>setMenuOpen(true)}><FaBars/></button>
    <button className="category-trigger" onClick={/** Inline callback for this operation. */ ()=>nav("/search")}><FaBars/> All</button>
    <form className="search-box" role="search" onSubmit={/** Inline callback for this operation. */ e=>{e.preventDefault();submitSearch()}}><FaSearch className="search-icon"/><input type="search" value={search} onChange={/** Inline callback for this operation. */ e=>setSearch(e.target.value)} aria-label="Search catalog" placeholder="Search products, brands, categories and stores"/><button className="search-btn" type="submit">Search</button></form>
    <nav className="account-nav" aria-label="Account shortcuts">
     {user&&<NavLink to="/account/wishlist" title="Wishlist" className="activity-link"><FaHeart/><span>Wishlist</span></NavLink>}
     {user&&<NavLink to="/account/notifications" title="Notifications" className="activity-link"><FaBell/><span>Alerts</span>{activity.notificationsUnread>0&&<em>{Math.min(activity.notificationsUnread,99)}</em>}</NavLink>}
     {user&&<NavLink to="/account/messages" title="Messages" className="activity-link"><FaEnvelope/><span>Messages</span>{activity.messagesUnread>0&&<em>{Math.min(activity.messagesUnread,99)}</em>}</NavLink>}
     {!authLoading&&!user&&<div className="header-auth-actions"><NavLink to="/login" className="header-login">Sign in</NavLink><NavLink to="/register" className="header-register">Create account</NavLink></div>}
     {user&&<NavLink to={defaultHomeFor(user)} className="account-link"><FaUser/><span>{user.name?.split(" ")[0]||"Account"}</span></NavLink>}
     {user&&<button type="button" className="header-logout" onClick={/** Inline callback for this operation. */ async()=>{await logout();nav("/",{replace:true})}}>Sign out</button>}
     <NavLink to="/cart" className="cart-link"><FaShoppingCart/><span>Cart</span>{cartCount>0&&<em>{cartCount}</em>}</NavLink>
    </nav>
   </div>
   <div className="category-bar"><Link className="all-deals" to="/deals">Today's deals</Link>{navCategories.map(/** Renders one live category shortcut. */ c=><Link key={c.id||c.slug} to={`/search?category=${encodeURIComponent(c.slug||c.name)}`}>{c.name}</Link>)}<Link className="game-link" to="/games"><FaGamepad/>Win for Rs.1</Link>{user&&<Link to="/account/orders"><FaBox/>Orders</Link>}</div>
  </header>
  <button className={`storefront-overlay ${menuOpen?'show':''}`} aria-label="Close menu" onClick={/** Inline callback for this operation. */ ()=>setMenuOpen(false)}/>
  <aside className={`storefront-drawer ${menuOpen?'open':''}`} aria-label="Mobile navigation"><div className="mobile-drawer-head"><b>VSN Ecommerce</b><button type="button" aria-label="Close menu" onClick={/** Inline callback for this operation. */ ()=>setMenuOpen(false)}><FaTimes/></button></div><nav><Link to="/search">Browse all products</Link><Link to="/vendors">All stores</Link><Link to="/deals">Today's deals</Link><Link to="/games">Win for Rs.1</Link><Link to="/help">Help center</Link>{user?<><Link to={defaultHomeFor(user)}>My dashboard</Link><Link to="/account/orders">Orders</Link><Link to="/account/wishlist">Wishlist</Link><Link to="/account/notifications">Notifications</Link></>:<><Link to="/login">Sign in</Link><Link to="/register">Create account</Link></>}</nav></aside>
  <main className="page-shell" id="main-content" tabIndex="-1">{children}</main>
  <footer className="site-footer"><div><b>VSN Ecommerce</b><p>Verified marketplace, rewards and transparent shopping.</p></div><div><Link to="/account/orders">Orders</Link><Link to="/account/wallet">Wallet</Link><Link to="/affiliate">Affiliate</Link><Link to="/gifts">Gifts</Link><Link to="/help"><FaHeadset/> Help Center</Link><Link to="/legal">Legal</Link></div></footer>
 </>;
}
