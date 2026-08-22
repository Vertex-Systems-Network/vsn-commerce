import {useEffect,useMemo,useState} from 'react';
import {Link} from 'react-router-dom';
import SEO from '../components/SEO';
import {Badge,Button,Card,Field} from '../components/Toolkit';
import {apiGet,apiPost} from '../platform/api';

const money=/** Formats server-provided minor currency units. */ (minor,currency='PKR')=>`${currency==='PKR'?'Rs. ':`${currency} `}${(Number(minor||0)/100).toLocaleString(undefined,{maximumFractionDigits:2})}`;

/** Renders the Laravel-authoritative affiliate network. */
export default function Affiliate(){
 const [data,setData]=useState(null);
 const [busy,setBusy]=useState(true);
 const [error,setError]=useState('');
 const [acceptTerms,setAcceptTerms]=useState(false);
 const [referralCode,setReferralCode]=useState('');
 const [copied,setCopied]=useState(false);

 async function load(){
  setBusy(true);setError('');
  try{setData(await apiGet('/affiliate'));}
  catch(e){setError(e.message);}
  finally{setBusy(false);}
 }
 useEffect(/** Loads the authenticated user's server-owned affiliate account. */ ()=>{load();},[]);
 const rows=useMemo(/** Uses only commission levels returned by Laravel. */ ()=>data?.levels||[],[data]);
 const metrics=data?.metrics||{totalNetwork:0,pendingCoins:0,creditedCoins:0,lifetimeCoins:0};

 async function enroll(){
  if(!acceptTerms){setError('Accept the Affiliate Program Rules to activate your account.');return;}
  setBusy(true);setError('');
  try{setData(await apiPost('/affiliate/enroll',{acceptTerms:true}));}
  catch(e){setError(e.message);}finally{setBusy(false);}
 }
 async function attach(){
  setBusy(true);setError('');
  try{setData(await apiPost('/affiliate/referrer',{referralCode}));setReferralCode('');}
  catch(e){setError(e.message);}finally{setBusy(false);}
 }
 async function copyLink(){
  const link=data?.account?.referralLink;if(!link)return;
  try{await navigator.clipboard.writeText(link);setCopied(true);setTimeout(/** Clears copy confirmation after a short delay. */ ()=>setCopied(false),1500);}catch{setError('Could not copy the referral link.');}
 }

 return <><SEO title="Affiliate Network | VSN Ecommerce"/><div className="affiliate-page">
  <div className="page-title"><span>AFFILIATE</span><h1>10-level referral network</h1><p>Rewards are created from paid eligible merchandise spend, mature after the return-risk hold, then credit to VSN Coins.</p></div>
  {error&&<Card className="policy-card"><p className="form-error">{error}</p></Card>}
  {!busy&&data&&!data.enrolled&&<Card className="affiliate-enroll-card"><div><Badge tone="primary">Enrollment required</Badge><h2>Activate your affiliate account</h2><p>Your referral code is created only after explicit acceptance of Affiliate Program Rules version <b>{data?.programTermsVersion}</b>.</p></div><label className="check-line"><input type="checkbox" checked={acceptTerms} onChange={/** Tracks explicit program-rules acceptance. */ e=>setAcceptTerms(e.target.checked)}/><span>I accept the Affiliate Program Rules.</span></label><Button disabled={busy||!acceptTerms} onClick={enroll}>Activate affiliate account</Button></Card>}
  {data?.enrolled&&data?.account&&<Card className="affiliate-link-card"><div><small>YOUR REFERRAL CODE</small><strong>{data.account.referralCode}</strong><span>Status: {data.account.status}</span></div><div className="affiliate-link-actions"><code>{data.account.referralLink}</code><Button variant="secondary" onClick={copyLink}>{copied?'Copied':'Copy invite link'}</Button></div></Card>}
  {data&&!data.referrerAttached&&<Card className="affiliate-referrer-card"><div><h2>Have a referral code?</h2><p>You can attach one referrer once. Self-referral and circular networks are rejected server-side.</p></div><div className="affiliate-referrer-form"><Field label="Referral code" value={referralCode} onChange={/** Normalizes referral codes before submission. */ e=>setReferralCode(e.target.value.toUpperCase())} placeholder="VSN…"/><Button onClick={attach} disabled={busy||!referralCode.trim()}>Attach referrer</Button></div></Card>}
  <div className="metric-grid"><Card><small>Total network</small><strong>{metrics.totalNetwork.toLocaleString()}</strong><span>descendants up to L10</span></Card><Card><small>Pending</small><strong>{metrics.pendingCoins.toLocaleString()}</strong><span>coins in hold window</span></Card><Card><small>Credited</small><strong>{metrics.creditedCoins.toLocaleString()}</strong><span>coins posted to wallet</span></Card><Card><small>Coin value</small><strong>{data?.coinsPerRupee||70} = Rs.1</strong><span>platform conversion</span></Card></div>
  <Card className="affiliate-table-card"><div className="card-title"><div><h2>Network performance</h2><p>Level 1 direct referral → Level 10 deepest eligible descendant. Shipping is excluded from commissionable spend.</p></div><Badge tone="success">Auditable</Badge></div><div className="data-table affiliate-table"><div className="table-row table-head"><span>Level</span><span>Rate</span><span>Members</span><span>Eligible spend</span><span>Reward coins</span></div>{rows.map(/** Renders one server-configured affiliate level. */ r=><div className="table-row" key={r.level}><span><b>L{r.level}</b></span><span>{Number(r.rate).toLocaleString()}%</span><span>{Number(r.members||0).toLocaleString()}</span><span>{money(r.eligibleSpendMinor)}</span><span className="coin-value">{Number(r.rewardCoins||0).toLocaleString()}</span></div>)}</div></Card>
  <Card className="policy-card"><h2>Settlement controls</h2><ul><li>Commission is accrued only after an order is payment-settled.</li><li>Rewards remain pending for {data?.holdDays??14} days before wallet credit.</li><li>Refund/chargeback integration uses compensating wallet reversals rather than editing ledger history.</li><li>Self-referral, circular ancestry and re-parenting are blocked server-side.</li></ul><p><Link to="/coins">Open VSN Coins wallet</Link> to view credited affiliate transactions.</p></Card>
 </div></>;
}
