import { useEffect, useState } from "react";
import SEO from "../components/SEO";
import { Button, Card, Field, Status } from "../components/Toolkit";
import { apiBackend, apiGet, apiPost } from "../platform/api";
import { useStore } from "../platform/store";
import {useUX} from "../components/UXProvider";

/** Handles coins for the VSN Ecommerce interface. */
export default function Coins() {
  if (apiBackend !== "laravel") return <LegacyCoins />;
  return <LaravelCoins />;
}

/** Handles laravel coins for the VSN Ecommerce interface. */
function LaravelCoins() {
  const [wallet, setWallet] = useState(null);
  const [buy, setBuy] = useState(700);
  const [to, setTo] = useState("");
  const [amount, setAmount] = useState(70);
  const [purchase, setPurchase] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");

  const refresh = /** Handles refresh for the VSN Ecommerce interface. */ async () => {
    const next = await apiGet("/wallet");
    setWallet(next);
    return next;
  };

  useEffect(/** Inline callback for this operation. */ () => {
    let live = true;
    apiGet("/wallet")
      .then(/** Inline callback for this operation. */ (next) => live && setWallet(next))
      .catch(/** Inline callback for this operation. */ (err) => live && setError(err.message || "Wallet could not be loaded."));
    return /** Inline callback for this operation. */ () => { live = false; };
  }, []);

  const checkIn = /** Handles check in for the VSN Ecommerce interface. */ async () => {
    setBusy(true); setError(""); setMessage("");
    try {
      const result = await apiPost("/wallet/check-in", {});
      setMessage(`+${result.totalRewardCoins.toLocaleString()} coins claimed · streak day ${result.streakDay}`);
      await refresh();
    } catch (err) { setError(err.message); }
    finally { setBusy(false); }
  };

  const send = /** Handles send for the VSN Ecommerce interface. */ async () => {
    setBusy(true); setError(""); setMessage("");
    try {
      const tx = await apiPost("/wallet/transfers", {
        recipient: to.trim(), coins: Number(amount),
        idempotencyKey: `gift-${Date.now()}-${Math.random().toString(36).slice(2)}`,
      });
      setMessage(`${tx.coins.toLocaleString()} coins sent.`);
      setTo("");
      await refresh();
    } catch (err) { setError(err.message); }
    finally { setBusy(false); }
  };

  const startPurchase = /** Handles start purchase for the VSN Ecommerce interface. */ async () => {
    setBusy(true); setError(""); setMessage("");
    try {
      const next = await apiPost("/wallet/coin-purchases", {
        coins: Number(buy),
        idempotencyKey: `coin-purchase-${Date.now()}-${Math.random().toString(36).slice(2)}`,
      });
      setPurchase(next);
      setMessage("Payment intent created. Coins are credited only after a verified payment event.");
    } catch (err) { setError(err.message); }
    finally { setBusy(false); }
  };

  const simulatePayment = /** Handles simulate payment for the VSN Ecommerce interface. */ async () => {
    const paymentId = purchase?.payment?.id;
    if (!paymentId) return;
    setBusy(true); setError(""); setMessage("");
    try {
      await apiPost(`/payments/${paymentId}/sandbox/complete`, {});
      const current = await apiGet(`/wallet/coin-purchases/${purchase.id}`);
      setPurchase(current);
      await refresh();
      setMessage(current.status === "paid" ? `${current.coins.toLocaleString()} coins credited.` : `Purchase status: ${current.status}`);
    } catch (err) { setError(err.message); }
    finally { setBusy(false); }
  };

  const perRupee = wallet?.coinsPerRupee || 70;
  const txns = wallet?.transactions || [];

  return <>
    <SEO title="VSN Coins | Rewards Wallet" />
    <div className="simple-page">
      <div className="page-title"><span>REWARDS</span><h1>VSN Coins</h1><p>{perRupee} coins = Rs.1. Laravel wallet uses an immutable transaction ledger.</p></div>
      {error && <Status>{error}</Status>}
      {message && <Status ok>{message}</Status>}
      <div className="metric-grid">
        <Card><small>Balance</small><strong>{(wallet?.balanceCoins || 0).toLocaleString()}</strong><span>Available {(wallet?.availableCoins || 0).toLocaleString()} · Reserved {(wallet?.reservedCoins || 0).toLocaleString()}</span></Card>
        <Card><small>Daily free coins</small><strong>{wallet?.checkin?.baseRewardCoins || 70}</strong><span>7-day bonus: +{wallet?.checkin?.sevenDayBonusCoins || 350}</span><Button disabled={busy || wallet?.checkin?.claimedToday} onClick={checkIn}>{wallet?.checkin?.claimedToday ? "Claimed today" : "Check in"}</Button></Card>
        <Card><small>Expiring in 30 days</small><strong>{Number(wallet?.expiration?.expiring30Days||0).toLocaleString()}</strong><span>{wallet?.expiration?.nextExpiryAt?`Next expiry ${new Date(wallet.expiration.nextExpiryAt).toLocaleDateString()}`:'No upcoming promotional expiry'}</span></Card>
      </div>
      <div className="two-col">
        <Card className="buy-coins"><h2>Buy coins</h2><Field label="Coins" type="number" min={perRupee} step={perRupee} value={buy} onChange={/** Inline callback for this operation. */ e=>setBuy(e.target.value)}/><p>Payable: Rs. {(Number(buy || 0)/perRupee).toFixed(2)}</p><Button disabled={busy} onClick={startPurchase}>Create secure purchase</Button>
          {purchase && <div className="saved-list"><div><b>{purchase.coins.toLocaleString()} coins</b><span>{purchase.status} · Rs. {(purchase.amountMinor/100).toFixed(2)}</span>{purchase.payment?.sandboxCanSimulate && purchase.status !== "paid" ? <Button disabled={busy} onClick={simulatePayment}>Simulate signed sandbox payment</Button> : <strong>{purchase.status}</strong>}</div></div>}
        </Card>
        <Card className="send-coins"><h2>Send coins as a gift</h2><Field label="Recipient email / phone" value={to} onChange={/** Inline callback for this operation. */ e=>setTo(e.target.value)}/><Field label="Coins" type="number" min="1" value={amount} onChange={/** Inline callback for this operation. */ e=>setAmount(e.target.value)}/><Button disabled={busy || !to.trim()} onClick={send}>Send coins</Button></Card>
      </div>
      <Card className="transaction-history"><h2>Recent transactions</h2><div className="saved-list">{txns.length ? txns.map(/** Inline callback for this operation. */ t=><div key={t.id}><b>{String(t.type).replaceAll("_"," ")}</b><span>{t.occurredAt ? new Date(t.occurredAt).toLocaleString() : ""}</span><strong className={t.direction === "credit" ? "credit" : "debit"}>{t.direction === "credit" ? "+" : "-"}{Number(t.coins || 0).toLocaleString()}</strong></div>) : <p>No wallet transactions yet.</p>}</div></Card>
    </div>
  </>;
}

/** Handles legacy coins for the VSN Ecommerce interface. */
function LegacyCoins(){const {toast}=useUX();const s=useStore();const [buy,setBuy]=useState(700);const [to,setTo]=useState('');const [amount,setAmount]=useState(70);return <><SEO title="VSN Coins | Rewards Wallet"/><div className="simple-page"><div className="page-title"><span>REWARDS</span><h1>VSN Coins</h1><p>70 coins = Rs.1. Spend on products, game entries and gifts.</p></div><div className="metric-grid"><Card><small>Balance</small><strong>{s.coinBalance.toLocaleString()}</strong><span>Rs. {s.coinsToRs(s.coinBalance).toFixed(2)}</span></Card><Card><small>Daily free coins</small><strong>70</strong><Button onClick={/** Inline callback for this operation. */ ()=>toast(s.checkIn().msg,{tone:'success'})}>Check in</Button></Card></div><div className="two-col"><Card className="buy-coins"><h2>Buy coins</h2><Field label="Coins" type="number" value={buy} onChange={/** Inline callback for this operation. */ e=>setBuy(e.target.value)}/><p>Payable: Rs. {(Number(buy)/70).toFixed(2)}</p><Button onClick={/** Inline callback for this operation. */ ()=>s.buyCoins(Number(buy))}>Buy coins</Button></Card><Card className="send-coins"><h2>Send coins as a gift</h2><Field label="Recipient email / phone" value={to} onChange={/** Inline callback for this operation. */ e=>setTo(e.target.value)}/><Field label="Coins" type="number" value={amount} onChange={/** Inline callback for this operation. */ e=>setAmount(e.target.value)}/><Button onClick={/** Inline callback for this operation. */ ()=>{const ok=s.sendCoins(to,amount);toast(ok?'Coins sent':'Check recipient and balance',{tone:ok?'success':'warning'})}}>Send coins</Button></Card></div><Card className="transaction-history"><h2>Recent transactions</h2><div className="saved-list">{s.txns.length?s.txns.map(/** Inline callback for this operation. */ t=><div key={t.id}><b>{t.label}</b><span>{new Date(t.date).toLocaleString()}</span><strong className={t.type==='credit'?'credit':'debit'}>{t.type==='credit'?'+':'-'}{t.coins}</strong></div>):<p>No transactions yet.</p>}</div></Card></div></>}
