import {useEffect,useMemo,useState} from 'react';
import {Link} from 'react-router-dom';
import SEO from '../components/SEO';
import {Badge,Button,Card,Field} from '../components/Toolkit';
import {apiBackend,apiGet,apiPost} from '../platform/api';

const legacyRates=[10,9,8,7,6,5,4,3,2.5,2];
const money=/** Handles money for the VSN Ecommerce interface. */ (minor,currency='PKR')=>`${currency==='PKR'?'Rs. ':`${currency} `}${(Number(minor||0)/100).toLocaleString(undefined,{maximumFractionDigits:2})}`;

/** Handles affiliate for the VSN Ecommerce interface. */
export default function Affiliate(){
 const [data,setData]=useState(null);
 const [busy,setBusy]=useState(true);
 const [error,setError]=useState('');
 const [acceptTerms,setAcceptTerms]=useState(false);
 const [referralCode,setReferralCode]=useState('');
 const [copied,setCopied]=useState(false);
 const laravel=apiBackend==='laravel';

  /** Handles load for the VSN Ecommerce interface. */
async function load(){
  setBusy(true);setError('');
  try{
   if(laravel){setData(await apiGet('/affiliate'));}
   else{
    const levels=await apiGet('/affiliate/tree');
    setData({enrolled:true,account:null,referrerAttached:false,metrics:{totalNetwork:(levels||[]).reduce(/** Inline callback for this operation. */ (n,l)=>n+(l.members?.length||0),0),pendingCoins:0,creditedCoins:0,lifetimeCoins:0},levels:(levels||[]).map(/** Inline callback for this operation. */ (l,i)=>({level:l.level||i+1,rate:Number(l.rate??legacyRates[i]),members:l.members?.length||0,eligibleSpendMinor:0,rewardCoins:0})),coinsPerRupee:70,holdDays:0,programTermsVersion:'legacy'});
   }
  }catch(e){setError(e.message);}
  finally{setBusy(false);}
 }
 useEffect(/** Inline callback for this operation. */ ()=>{load();},[]);
 const rows=useMemo(/** Inline callback for this operation. */ ()=>data?.levels||legacyRates.map(/** Inline callback for this operation. */ (rate,i)=>({level:i+1,rate,members:0,eligibleSpendMinor:0,rewardCoins:0})),[data]);
 const metrics=data?.metrics||{totalNetwork:0,pendingCoins:0,creditedCoins:0,lifetimeCoins:0};

  /** Handles enroll for the VSN Ecommerce interface. */
async function enroll(){
  if(!acceptTerms){setError('Accept the Affiliate Program Rules to activate your account.');return;}
  setBusy(true);setError('');
  try{setData(await apiPost('/affiliate/enroll',{acceptTerms:true}));}
  catch(e){setError(e.message);}finally{setBusy(false);}
 }
  /** Handles attach for the VSN Ecommerce interface. */
async function attach(){
  setBusy(true);setError('');
  try{setData(await apiPost('/affiliate/referrer',{referralCode}));setReferralCode('');}
  catch(e){setError(e.message);}finally{setBusy(false);}
 }
  /** Handles copy link for the VSN Ecommerce interface. */
async function copyLink(){
  const link=data?.account?.referralLink;if(!link)return;
  try{await navigator.clipboard.writeText(link);setCopied(true);setTimeout(/** Inline callback for this operation. */ ()=>setCopied(false),1500);}catch{setError('Could not copy the referral link.');}
 }

 return <><SEO title="Affiliate Network | VSN Ecommerce"/><div className="affiliate-page">
  <div className="page-title"><span>AFFILIATE</span><h1>10-level referral network</h1><p>Rewards are created from paid eligible merchandise spend, mature after the return-risk hold, then credit to VSN Coins.</p></div>
  {error&&<Card className="policy-card"><p className="form-error">{error}</p></Card>}
  {!laravel&&<Card className="policy-card"><p>Legacy affiliate view is read-only during migration. Enrollment and wallet settlement are server-authoritative.</p></Card>}
  {laravel&&!busy&&data&&!data.enrolled&&<Card className="affiliate-enroll-card"><div><Badge tone="primary">Enrollment required</Badge><h2>Activate your affiliate account</h2><p>Your referral code is created only after explicit acceptance of Affiliate Program Rules version <b>{data?.programTermsVersion}</b>.</p></div><label className="check-line"><input type="checkbox" checked={acceptTerms} onChange={/** Inline callback for this operation. */ e=>setAcceptTerms(e.target.checked)}/><span>I accept the Affiliate Program Rules.</span></label><Button disabled={busy||!acceptTerms} onClick={enroll}>Activate affiliate account</Button></Card>}
  {data?.enrolled&&data?.account&&<Card className="affiliate-link-card"><div><small>YOUR REFERRAL CODE</small><strong>{data.account.referralCode}</strong><span>Status: {data.account.status}</span></div><div className="affiliate-link-actions"><code>{data.account.referralLink}</code><Button variant="secondary" onClick={copyLink}>{copied?'Copied':'Copy invite link'}</Button></div></Card>}
  {laravel&&data&&!data.referrerAttached&&<Card className="affiliate-referrer-card"><div><h2>Have a referral code?</h2><p>You can attach one referrer once. Self-referral and circular networks are rejected server-side.</p></div><div className="affiliate-referrer-form"><Field label="Referral code" value={referralCode} onChange={/** Inline callback for this operation. */ e=>setReferralCode(e.target.value.toUpperCase())} placeholder="VSN…"/><Button onClick={attach} disabled={busy||!referralCode.trim()}>Attach referrer</Button></div></Card>}
  <div className="metric-grid"><Card><small>Total network</small><strong>{metrics.totalNetwork.toLocaleString()}</strong><span>descendants up to L10</span></Card><Card><small>Pending</small><strong>{metrics.pendingCoins.toLocaleString()}</strong><span>coins in hold window</span></Card><Card><small>Credited</small><strong>{metrics.creditedCoins.toLocaleString()}</strong><span>coins posted to wallet</span></Card><Card><small>Coin value</small><strong>{data?.coinsPerRupee||70} = Rs.1</strong><span>platform conversion</span></Card></div>
  <Card className="affiliate-table-card"><div className="card-title"><div><h2>Network performance</h2><p>Level 1 direct referral → Level 10 deepest eligible descendant. Shipping is excluded from commissionable spend.</p></div><Badge tone="success">Auditable</Badge></div><div className="data-table affiliate-table"><div className="table-row table-head"><span>Level</span><span>Rate</span><span>Members</span><span>Eligible spend</span><span>Reward coins</span></div>{rows.map(/** Inline callback for this operation. */ r=><div className="table-row" key={r.level}><span><b>L{r.level}</b></span><span>{Number(r.rate).toLocaleString()}%</span><span>{Number(r.members||0).toLocaleString()}</span><span>{money(r.eligibleSpendMinor)}</span><span className="coin-value">{Number(r.rewardCoins||0).toLocaleString()}</span></div>)}</div></Card>
  <Card className="policy-card"><h2>Settlement controls</h2><ul><li>Commission is accrued only after an order is payment-settled.</li><li>Rewards remain pending for {data?.holdDays??14} days before wallet credit.</li><li>Refund/chargeback integration uses compensating wallet reversals rather than editing ledger history.</li><li>Self-referral, circular ancestry and re-parenting are blocked server-side.</li></ul>{laravel&&<p><Link to="/coins">Open VSN Coins wallet</Link> to view credited affiliate transactions.</p>}</Card>
 </div></>;
}
