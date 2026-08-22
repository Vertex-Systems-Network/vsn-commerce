import {createContext,useContext,useMemo,useState} from 'react';
import {games as gameCatalog,products as productCatalog} from '../data/catalog';
export const COINS_PER_RUPEE=70;
const Ctx=createContext(null);
const read=/** Handles read for the VSN Ecommerce interface. */ (k,f)=>{try{const v=localStorage.getItem(k);return v?JSON.parse(v):f}catch{return f}};
const save=/** Handles save for the VSN Ecommerce interface. */ (k,v)=>localStorage.setItem(k,JSON.stringify(v));
const requireProduct=/** Handles require product for the VSN Ecommerce interface. */ id=>productCatalog.find(/** Inline callback for this operation. */ p=>p.id===Number(id));
const initialOrders=[];
const initialNotifications=[];
const initialMessages=[];
const levels=[{name:'Starter Gifter',min:0,next:7000,reward:'Free gift wrap'},{name:'Silver Gifter',min:7000,next:35000,reward:'500 bonus coins'},{name:'Gold Gifter',min:35000,next:140000,reward:'Priority gift delivery'},{name:'Platinum Gifter',min:140000,next:350000,reward:'Annual surprise box'},{name:'Legend Gifter',min:350000,next:Infinity,reward:'VIP gifting concierge'}];
/** Handles store provider for the VSN Ecommerce interface. */
export function StoreProvider({children}){
 const [coinBalance,setCoins]=useState(/** Inline callback for this operation. */ ()=>read('vsn-coins',0));
 const [lastCheckIn,setLastCheckIn]=useState(/** Inline callback for this operation. */ ()=>read('vsn-checkin',null));
 const [profile,setProfile]=useState(/** Inline callback for this operation. */ ()=>read('vsn-profile',{name:'',email:'',phone:'',phoneVerified:false,idType:'CNIC',idNumber:'',idVerified:false,idFile:'',addressProof:'',addressProofVerified:false,avatar:'',addresses:[],paymentMethods:[]}));
 const [gameEntries,setGameEntries]=useState(/** Inline callback for this operation. */ ()=>read('vsn-games',[]));
 const [txns,setTxns]=useState(/** Inline callback for this operation. */ ()=>read('vsn-txns',[]));
 const [orders]=useState(/** Inline callback for this operation. */ ()=>read('vsn-orders',initialOrders));
 const [notifications]=useState(/** Inline callback for this operation. */ ()=>read('vsn-notifications',initialNotifications));
 const [messages,setMessages]=useState(/** Inline callback for this operation. */ ()=>read('vsn-messages',initialMessages));
 const [giftSentCoins,setGiftSentCoins]=useState(/** Inline callback for this operation. */ ()=>read('vsn-gift-sent',0));
 const [giftOrders,setGiftOrders]=useState(/** Inline callback for this operation. */ ()=>read('vsn-gift-orders',[]));
 const [myReviews,setMyReviews]=useState(/** Inline callback for this operation. */ ()=>read('vsn-reviews',[]));
 const [reviewCoupons,setReviewCoupons]=useState(/** Inline callback for this operation. */ ()=>read('vsn-review-coupons',[]));
 const [returnRequests,setReturnRequests]=useState(/** Inline callback for this operation. */ ()=>read('vsn-returns',[]));
 const [productAlerts,setProductAlerts]=useState(/** Inline callback for this operation. */ ()=>read('vsn-product-alerts',[]));
 const [inventoryReservations,setInventoryReservations]=useState(/** Inline callback for this operation. */ ()=>read('vsn-inventory-reservations',[]));
 const [subOrders,setSubOrders]=useState(/** Inline callback for this operation. */ ()=>read('vsn-suborders',[]));
 const [notificationQueue]=useState(/** Inline callback for this operation. */ ()=>read('vsn-notification-queue',[]));
 const [featureFlags,setFeatureFlags]=useState(/** Inline callback for this operation. */ ()=>read('vsn-feature-flags',{gameWin:true,coins:true,affiliate:true,installments:true,gifts:true,international:false}));
 const shippingQuotes=[{id:'std',name:'Standard Delivery',eta:'2–4 business days',price:220},{id:'exp',name:'Express Delivery',eta:'Next business day',price:450},{id:'same',name:'Same-day Lahore',eta:'Today before 9 PM',price:650}];
 const riskSignals=[];
 const sellerScores=[];
 const finance={gmv:0,platformRevenue:0,sellerPayable:0,refundLiability:0,coinLiability:0,affiliateLiability:0,gamePrizeLiability:0,couponCost:0};
 const updateProfile=/** Handles update profile for the VSN Ecommerce interface. */ (patch)=>{const n={...profile,...patch};setProfile(n);save('vsn-profile',n)};
 const updateCoins=/** Handles update coins for the VSN Ecommerce interface. */ (next,label,type='credit')=>{setCoins(next);save('vsn-coins',next);const t=[{id:Date.now(),label,type,coins:Math.abs(next-coinBalance),date:new Date().toISOString()},...txns];setTxns(t);save('vsn-txns',t)};
 const checkIn=/** Handles check in for the VSN Ecommerce interface. */ ()=>{const day=new Date().toISOString().slice(0,10);if(lastCheckIn===day)return {ok:false,msg:'Already claimed today'};const next=coinBalance+70;updateCoins(next,'Daily check-in');setLastCheckIn(day);save('vsn-checkin',day);return {ok:true,msg:'+70 coins claimed'}};
 const buyCoins=/** Handles buy coins for the VSN Ecommerce interface. */ (coins)=>updateCoins(coinBalance+Number(coins),'Coins purchased');
 const sendCoins=/** Handles send coins for the VSN Ecommerce interface. */ (to,coins)=>{coins=Number(coins);if(!to||coins<=0||coins>coinBalance)return false;updateCoins(coinBalance-coins,`Sent to ${to}`,'debit');return true};
 const spendCoins=/** Handles spend coins for the VSN Ecommerce interface. */ (coins,label)=>{coins=Number(coins);if(coins>coinBalance)return false;updateCoins(coinBalance-coins,label,'debit');return true};

 const joinGame=/** Handles join game for the VSN Ecommerce interface. */ (product,entries=1)=>{const cost=(product.gameEntryCoins||70)*entries;if(!product.game)return {ok:false,msg:'This product is not Game Win eligible'};if(cost>coinBalance)return {ok:false,msg:`You need ${cost.toLocaleString()} coins`};updateCoins(coinBalance-cost,`${entries} Game Win entr${entries>1?'ies':'y'} · ${product.name}`,'debit');const existing=gameEntries.find(/** Inline callback for this operation. */ g=>g.productId===product.id);const next=existing?gameEntries.map(/** Inline callback for this operation. */ g=>g.productId===product.id?{...g,entries:(g.entries||0)+entries}:g):[{id:`GW-${product.id}`,productId:product.id,name:product.name,image:product.image,entryCoins:product.gameEntryCoins||70,entries,announcementAt:product.announcementAt,status:'live'},...gameEntries];setGameEntries(next);save('vsn-games',next);return {ok:true,msg:`Game entry confirmed · ${cost.toLocaleString()} coins used`}};
 const saveGiftOrder=/** Handles save gift order for the VSN Ecommerce interface. */ (gift)=>{const next=[{id:`GIFT-${Date.now()}`,createdAt:new Date().toISOString(),status:'draft',...gift},...giftOrders];setGiftOrders(next);save('vsn-gift-orders',next);return next[0]};

 const pendingReviews=useMemo(/** Inline callback for this operation. */ ()=>{
   const reviewed=new Set(myReviews.map(/** Inline callback for this operation. */ r=>r.key));
   const out=[];
   orders.filter(/** Inline callback for this operation. */ o=>o.status==='Delivered').forEach(/** Inline callback for this operation. */ o=>(o.products||[]).forEach(/** Inline callback for this operation. */ (item,index)=>{
     const key=`${o.id}:${item.productId}:${index}`;
     if(!reviewed.has(key)){
       let product=null; try{product=requireProduct(item.productId)}catch{}
       out.push({key,orderId:o.id,orderItemIndex:index,productId:item.productId,name:item.name,image:product?.image||''});
     }
   }));
   return out;
 },[orders,myReviews]);
 const submitReview=/** Handles submit review for the VSN Ecommerce interface. */ ({productId,rating,text,images=[]})=>{
   const pending=pendingReviews.find(/** Inline callback for this operation. */ x=>x.productId===Number(productId));
   if(!pending)return {ok:false,msg:'No eligible delivered purchase is waiting for review.'};
   if(!rating||rating<1||rating>5)return {ok:false,msg:'Choose a rating from 1 to 5.'};
   if(!text||text.trim().length<10)return {ok:false,msg:'Write at least 10 characters of useful feedback.'};
   const code=`VSNREV-${String(Date.now()).slice(-6)}`;
   const review={id:Date.now(),key:pending.key,productId:Number(productId),productName:pending.name,image:pending.image,rating:Number(rating),text:text.trim(),images,couponCode:code,createdAt:new Date().toISOString(),verifiedPurchase:true};
   const next=[review,...myReviews]; setMyReviews(next); save('vsn-reviews',next);
   const coupons=[{code,percent:10,used:false,productId:Number(productId),productName:pending.name,reviewId:review.id,createdAt:review.createdAt},...reviewCoupons]; setReviewCoupons(coupons); save('vsn-review-coupons',coupons);
   return {ok:true,msg:`Review submitted. Your 10% coupon is ${code}`,couponCode:code};
 };


 const createReturnRequest=/** Handles create return request for the VSN Ecommerce interface. */ ({orderId,reason,resolution='refund',details=''})=>{
   if(!orderId||!reason)return {ok:false,msg:'Choose an order and return reason.'};
   const existing=returnRequests.find(/** Inline callback for this operation. */ r=>r.orderId===orderId&&['submitted','reviewing','approved'].includes(r.status));
   if(existing)return {ok:false,msg:'A return/refund request is already open for this order.'};
   const request={id:`RET-${String(Date.now()).slice(-7)}`,orderId,reason,resolution,details,status:'submitted',createdAt:new Date().toISOString(),timeline:['Request submitted']};
   const next=[request,...returnRequests];setReturnRequests(next);save('vsn-returns',next);return {ok:true,msg:`Return request ${request.id} submitted.`,request};
 };
 const reserveInventory=/** Handles reserve inventory for the VSN Ecommerce interface. */ (productId,qty=1,minutes=15)=>{qty=Number(qty)||1;const now=Date.now();const active=inventoryReservations.filter(/** Inline callback for this operation. */ r=>new Date(r.expiresAt).getTime()>now);const existingQty=active.filter(/** Inline callback for this operation. */ r=>r.productId===Number(productId)).reduce(/** Inline callback for this operation. */ (a,r)=>a+r.qty,0);const available=8;if(existingQty+qty>available)return {ok:false,msg:'Not enough reservable stock.'};const item={id:`RES-${now}`,productId:Number(productId),qty,expiresAt:new Date(now+minutes*60000).toISOString(),status:'held'};const next=[item,...active];setInventoryReservations(next);save('vsn-inventory-reservations',next);return {ok:true,msg:`${qty} unit reserved for ${minutes} minutes.`}};
 const toggleFeature=/** Handles toggle feature for the VSN Ecommerce interface. */ (key)=>{const next={...featureFlags,[key]:!featureFlags[key]};setFeatureFlags(next);save('vsn-feature-flags',next)};
 const toggleProductAlert=/** Handles toggle product alert for the VSN Ecommerce interface. */ (product,type='price')=>{
   const key=`${product.id}:${type}`; const exists=productAlerts.some(/** Inline callback for this operation. */ a=>a.key===key);
   const next=exists?productAlerts.filter(/** Inline callback for this operation. */ a=>a.key!==key):[{key,productId:product.id,name:product.name,image:product.image,type,targetPrice:type==='price'?Math.round(product.price*.95):null,createdAt:new Date().toISOString()},...productAlerts];
   setProductAlerts(next);save('vsn-product-alerts',next);return {active:!exists,msg:!exists?`${type==='price'?'Price':'Stock'} alert created.`:'Alert removed.'};
 };

 const sendMessage=/** Handles send message for the VSN Ecommerce interface. */ (text)=>{const n=[...messages,{id:Date.now(),text,time:new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}),me:true}];setMessages(n);save('vsn-messages',n)};
 const recordGift=/** Handles record gift for the VSN Ecommerce interface. */ (coins)=>{const n=giftSentCoins+coins;setGiftSentCoins(n);save('vsn-gift-sent',n)};
 const giftLevel=useMemo(/** Inline callback for this operation. */ ()=>{const l=[...levels].reverse().find(/** Inline callback for this operation. */ x=>giftSentCoins>=x.min)||levels[0];const progress=l.next===Infinity?100:Math.min(100,Math.round(((giftSentCoins-l.min)/(l.next-l.min))*100));return {name:l.name,nextReward:l.reward,progress}},[giftSentCoins]);
 const value=useMemo(/** Inline callback for this operation. */ ()=>({coinBalance,lastCheckIn,profile,gameEntries,giftOrders,txns,orders,notifications,messages,giftSentCoins,giftLevel,myReviews,reviewCoupons,pendingReviews,returnRequests,productAlerts,inventoryReservations,subOrders,notificationQueue,featureFlags,shippingQuotes,riskSignals,sellerScores,finance,submitReview,createReturnRequest,toggleProductAlert,reserveInventory,toggleFeature,updateProfile,checkIn,buyCoins,sendCoins,spendCoins,joinGame,saveGiftOrder,sendMessage,recordGift,coinsToRs:/** Inline callback for this operation. */ c=>c/COINS_PER_RUPEE}),[coinBalance,lastCheckIn,profile,gameEntries,giftOrders,txns,orders,notifications,messages,giftSentCoins,giftLevel,myReviews,reviewCoupons,pendingReviews,returnRequests,productAlerts,inventoryReservations,subOrders,notificationQueue,featureFlags]);
 return <Ctx.Provider value={value}>{children}</Ctx.Provider>
}
export const useStore=/** Handles use store for the VSN Ecommerce interface. */ ()=>useContext(Ctx);
/** Handles countdown for the VSN Ecommerce interface. */
export function countdown(target){const d=Math.max(0,new Date(target)-Date.now());const days=Math.floor(d/86400000);const h=Math.floor((d%86400000)/3600000);const m=Math.floor((d%3600000)/60000);const s=Math.floor((d%60000)/1000);return `${days?`${days}d `:''}${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`}
