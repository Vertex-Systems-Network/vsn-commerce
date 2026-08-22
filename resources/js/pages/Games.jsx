import React from 'react';
import SEO from '../components/SEO';
import {SafeImage,Countdown,Button,Badge,Card} from '../components/Toolkit';
import {useLaravelGames} from '../platform/games';
import {useLaravelWallet} from '../platform/wallet';

/** Returns the visual tone for a server-authoritative game state. */
function statusTone(status){return status==='open'?'game':status==='winner_selected'||status==='fulfilled'?'success':'neutral'}

/** Renders the Laravel-authoritative Game Win experience. */
export default function Games(){
  const lg=useLaravelGames();
  const wallet=useLaravelWallet();
  const rows=lg.games;
  const myEntries=lg.entries;
  const [notice,setNotice]=React.useState('');

  const enter=/** Creates a real server-side game entry and refreshes the wallet ledger. */ async(g)=>{
    try{
      const result=await lg.join(g.id,1);
      await wallet.refresh();
      setNotice(`Entry confirmed · ${result.coinsSpent.toLocaleString()} coins used.`);
    }catch(error){setNotice(error.message||'Could not join this game.')}
  };

  return <><SEO title="Win for Rs.1 Games | VSN Ecommerce"/><div className="simple-page">
    <div className="page-title"><span>GAME WIN</span><h1>Live product games</h1><p>Entry cost, close time, published draw commitment and winner audit are controlled by Laravel—not browser state.</p></div>
    {notice&&<Card><b>{notice}</b></Card>}
    {lg.error&&<Card><b>{lg.error}</b></Card>}
    <div className="game-grid">{rows.map(/** Renders one server-owned game. */ g=>{
      const product=g.product;
      const status=g.status;
      const entryCoins=g.entryCoins;
      const announcementAt=g.announcementAt;
      return <article className="game-card" key={g.id}><div className="game-image"><SafeImage src={product?.image} alt={product?.name||'Game product'}/><Badge tone={statusTone(status)}>{status.replaceAll('_',' ')}</Badge></div><div>
        <h2>{product?.name||'Game product'}</h2><p>Entry: {entryCoins} coins · Rs. {(entryCoins/70).toFixed(2)} equivalent</p>
        <small>Winner announcement</small><Countdown target={announcementAt}/>
        <p><small>Entries: {g.totalEntries.toLocaleString()}{g.entriesRemaining!==null?` · ${g.entriesRemaining.toLocaleString()} remaining`:''} · user cap {g.maxEntriesPerUser||'—'}</small></p>{Number(g.winnerBonusCoins||0)>0&&<p><small>Winner bonus: {Number(g.winnerBonusCoins).toLocaleString()} VSN Coins</small></p>}<p><small>Draw commitment: {g.commitmentHash.slice(0,12)}…</small></p>
        {status==='open'?<Button variant="game" onClick={/** Submits a server-authoritative game entry. */ ()=>enter(g)}>Enter with coins</Button>:<Button variant="secondary" disabled>Entries closed</Button>}
        {g.draw&&<Card><b>Winner selected: {g.draw.winner?.name}</b><small> Winning ticket #{g.draw.winningTicketNumber} / {g.draw.totalTickets}</small><small> Audit hash: {g.draw.selectionHash.slice(0,16)}…</small></Card>}
      </div></article>
    })}</div>
    <div className="system-section"><div className="page-title"><span>MY GAMES</span><h2>Your Game Win entries</h2></div>
      <div className="saved-list">{myEntries.length?myEntries.map(/** Renders one server-owned game entry. */ e=><div key={e.id}><b>{e.game?.product?.name}</b><span>{e.quantity} entr{e.quantity===1?'y':'ies'} · {e.coinsSpent.toLocaleString()} coins{e.refunded?' · refunded':''}</span><strong>{e.game?.isWinner?'WINNER':e.game?.status?.replaceAll('_',' ')}</strong></div>):<p>No Game Win entries yet.</p>}</div>
    </div>
  </div></>;
}
