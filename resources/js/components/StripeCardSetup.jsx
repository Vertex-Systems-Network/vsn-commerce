import {useEffect,useRef,useState} from 'react';
import {apiPost} from '../platform/api';
import {Button,Status} from './Toolkit';

let stripeLoader;
/** Handles load stripe js for the VSN Ecommerce interface. */
function loadStripeJs(){
  if(globalThis.Stripe)return Promise.resolve(globalThis.Stripe);
  if(stripeLoader)return stripeLoader;
  stripeLoader=new Promise(/** Inline callback for this operation. */ (resolve,reject)=>{
    const existing=document.querySelector('script[data-vsn-stripe-js]');
    if(existing){existing.addEventListener('load',/** Inline callback for this operation. */ ()=>resolve(globalThis.Stripe),{once:true});existing.addEventListener('error',/** Inline callback for this operation. */ ()=>reject(new Error('Stripe.js could not be loaded.')),{once:true});return;}
    const script=document.createElement('script');script.src='https://js.stripe.com/v3/';script.async=true;script.dataset.vsnStripeJs='1';script.onload=/** Inline callback for this operation. */ ()=>resolve(globalThis.Stripe);script.onerror=/** Inline callback for this operation. */ ()=>reject(new Error('Stripe.js could not be loaded.'));document.head.appendChild(script);
  });
  return stripeLoader;
}

/** Handles stripe card setup for the VSN Ecommerce interface. */
export default function StripeCardSetup({stepUp,makeDefault=false,onSaved}){
  const host=useRef(null);const elementRef=useRef(null);const stripeRef=useRef(null);const elementsRef=useRef(null);const authRef=useRef(null);
  const [setup,setSetup]=useState(null),[busy,setBusy]=useState(false),[error,setError]=useState('');
  useEffect(/** Inline callback for this operation. */ ()=>/** Inline callback for this operation. */ ()=>{try{elementRef.current?.unmount()}catch{}},[]);
  const begin=/** Handles begin for the VSN Ecommerce interface. */ async()=>{
    setBusy(true);setError('');
    try{
      const auth=await stepUp();authRef.current=auth;
      const next=await apiPost('/payment-methods/setup',{provider:'stripe'},{headers:{'X-Step-Up-Token':auth.token}});
      if(!next?.clientSecret||!next?.publishableKey)throw new Error('Stripe setup response is incomplete.');
      const Stripe=await loadStripeJs();if(!Stripe)throw new Error('Stripe.js is unavailable.');
      const stripe=Stripe(next.publishableKey);const elements=stripe.elements({clientSecret:next.clientSecret});const payment=elements.create('payment');
      elementRef.current?.unmount?.();stripeRef.current=stripe;elementsRef.current=elements;elementRef.current=payment;setSetup(next);
      setTimeout(/** Inline callback for this operation. */ ()=>payment.mount(host.current),0);
    }catch(e){setError(e.message||'Card setup could not start.');}
    finally{setBusy(false);}
  };
  const save=/** Handles save for the VSN Ecommerce interface. */ async()=>{
    setBusy(true);setError('');
    try{
      const stripe=stripeRef.current,elements=elementsRef.current,auth=authRef.current;if(!stripe||!elements||!auth)throw new Error('Start Stripe setup again.');
      const result=await stripe.confirmSetup({elements,confirmParams:{return_url:window.location.href},redirect:'if_required'});
      if(result.error)throw new Error(result.error.message||'Stripe could not verify this card.');
      const intent=result.setupIntent;if(!intent||intent.status!=='succeeded')throw new Error(`Stripe card setup is ${intent?.status||'not complete'}.`);
      const token=typeof intent.payment_method==='string'?intent.payment_method:intent.payment_method?.id;if(!token)throw new Error('Stripe did not return a payment-method token.');
      await apiPost('/payment-methods',{provider:'stripe',providerToken:token,makeDefault},{headers:{'X-Step-Up-Token':auth.token}});
      elementRef.current?.unmount?.();elementRef.current=null;elementsRef.current=null;stripeRef.current=null;authRef.current=null;setSetup(null);onSaved?.();
    }catch(e){setError(e.message||'Card could not be saved.');}
    finally{setBusy(false);}
  };
  return <div className="stripe-payment-box"><h3>Add a Stripe card</h3><p>Card details are entered directly into Stripe's hosted Payment Element. VSN receives only the provider token and masked card metadata.</p>{error&&<Status>{error}</Status>}{!setup?<Button disabled={busy} onClick={begin}>{busy?'Starting…':'Confirm password & add card'}</Button>:<><div ref={host} className="stripe-payment-element"/><div className="button-row"><Button disabled={busy} onClick={save}>{busy?'Saving…':'Verify & save card'}</Button><Button variant="secondary" disabled={busy} onClick={/** Inline callback for this operation. */ ()=>{elementRef.current?.unmount?.();setSetup(null)}}>Cancel</Button></div></>}</div>;
}
