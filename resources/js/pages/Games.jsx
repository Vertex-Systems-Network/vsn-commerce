import React from 'react';
import SEO from '../components/SEO';
import {games as legacyGames} from '../data/catalog';
import {SafeImage,Countdown,Button,Badge,Card} from '../components/Toolkit';
import {useStore} from '../platform/store';
import {apiBackend} from '../platform/api';
import {useLaravelGames} from '../platform/games';
import {useLaravelWallet} from '../platform/wallet';
import {useUX} from '../components/UXProvider';

/** Handles status tone for the VSN Ecommerce interface. */
function statusTone(status){return status==='open'?'game':status==='winner_selected'||status==='fulfilled'?'success':'neutral'}

/** Handles games for the VSN Ecommerce interface. */
export default function Games(){
  const {toast}=useUX();
  const store=useStore();
  const lg=useLaravelGames();
  const wallet=useLaravelWallet();
  const isLaravel=apiBackend==='laravel';
  const rows=isLaravel?lg.games:legacyGames;
  const myEntries=isLaravel?lg.entries:store.gameEntries;
  const [notice,setNotice]=React.useState('');

  const enter=/** Handles enter for the VSN Ecommerce interface. */ async(g)=>{
    if(!isLaravel){
      const product={id:g.productId,name:g.name,image:g.image,game:true,gameEntryCoins:g.entryCoins,announcementAt:g.announcementAt};
      const r=store.joinGame(product,1); toast(r.msg,{tone:r.ok===false?'danger':'success'}); return;
    }
    try{
      const result=await lg.join(g.id,1);
      await wallet.refresh();
      setNotice(`Entry confirmed · ${result.coinsSpent.toLocaleString()} coins used.`);
    }catch(error){setNotice(error.message||'Could not join this game.')}
  };

  return <><SEO title="Win for Rs.1 Games | VSN Ecommerce"/><div className="simple-page">
    <div className="page-title"><span>GAME WIN</span><h1>Live product games</h1><p>Entry cost, close time, published draw commitment and winner audit are controlled by Laravel—not browser state.</p></div>
    {notice&&<Card><b>{notice}</b></Card>}
    {isLaravel&&lg.error&&<Card><b>{lg.error}</b></Card>}
    <div className="game-grid">{rows.map(/** Inline callback for this operation. */ g=>{
      const product=isLaravel?g.product:g;
      const status=isLaravel?g.status:'open';
      const entryCoins=isLaravel?g.entryCoins:g.entryCoins;
      const announcementAt=isLaravel?g.announcementAt:g.announcementAt;
      return <article className="game-card" key={g.id}><div className="game-image"><SafeImage src={product?.image||g.image} alt={product?.name||g.name}/><Badge tone={statusTone(status)}>{status.replaceAll('_',' ')}</Badge></div><div>
        <h2>{product?.name||g.name}</h2><p>Entry: {entryCoins} coins · Rs. {(entryCoins/70).toFixed(2)} equivalent</p>
        <small>Winner announcement</small><Countdown target={announcementAt}/>
        {isLaravel&&<><p><small>Entries: {g.totalEntries.toLocaleString()}{g.entriesRemaining!==null?` · ${g.entriesRemaining.toLocaleString()} remaining`:''} · user cap {g.maxEntriesPerUser||'—'}</small></p>{Number(g.winnerBonusCoins||0)>0&&<p><small>Winner bonus: {Number(g.winnerBonusCoins).toLocaleString()} VSN Coins</small></p>}<p><small>Draw commitment: {g.commitmentHash.slice(0,12)}…</small></p></>}
        {status==='open'?<Button variant="game" onClick={/** Inline callback for this operation. */ ()=>enter(g)}>Enter with coins</Button>:<Button variant="secondary" disabled>Entries closed</Button>}
        {g.draw&&<Card><b>Winner selected: {g.draw.winner?.name}</b><small> Winning ticket #{g.draw.winningTicketNumber} / {g.draw.totalTickets}</small><small> Audit hash: {g.draw.selectionHash.slice(0,16)}…</small></Card>}
      </div></article>
    })}</div>
    <div className="system-section"><div className="page-title"><span>MY GAMES</span><h2>Your Game Win entries</h2></div>
      <div className="saved-list">{myEntries.length?myEntries.map(/** Inline callback for this operation. */ e=>{
        if(!isLaravel)return <div key={e.id}><b>{e.name}</b><span>{e.entries||1} entries</span><strong><Countdown target={e.announcementAt}/></strong></div>;
        return <div key={e.id}><b>{e.game?.product?.name}</b><span>{e.quantity} entr{e.quantity===1?'y':'ies'} · {e.coinsSpent.toLocaleString()} coins{e.refunded?' · refunded':''}</span><strong>{e.game?.isWinner?'WINNER':e.game?.status?.replaceAll('_',' ')}</strong></div>
      }):<p>No Game Win entries yet.</p>}</div>
    </div>
  </div></>;
}
