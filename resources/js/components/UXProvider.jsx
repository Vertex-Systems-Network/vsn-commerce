import {createContext,useCallback,useContext,useEffect,useMemo,useRef,useState} from 'react';
import {useLocation} from 'react-router-dom';
import {FaCheckCircle,FaExclamationCircle,FaInfoCircle,FaTimes,FaExclamationTriangle} from 'react-icons/fa';

const UXContext=createContext(null);
let seq=0;

/** Handles uxprovider for the VSN Ecommerce interface. */
export function UXProvider({children}){
  const [toasts,setToasts]=useState([]);
  const [confirmState,setConfirmState]=useState(null);
  const resolver=useRef(null);
  const location=useLocation();

  useEffect(/** Inline callback for this operation. */ ()=>{
    window.scrollTo({top:0,behavior:'auto'});
    const main=document.querySelector('main');
    if(main){main.setAttribute('tabindex','-1');requestAnimationFrame(/** Inline callback for this operation. */ ()=>main.focus({preventScroll:true}));}
  },[location.pathname]);

  const toast=useCallback(/** Inline callback for this operation. */ (message,options={})=>{
    if(!message)return null;
    const id=++seq;
    const entry={id,message:String(message),tone:options.tone||'info',title:options.title||'',duration:options.duration??4200};
    setToasts(/** Inline callback for this operation. */ items=>[...items.slice(-3),entry]);
    if(entry.duration>0)window.setTimeout(/** Inline callback for this operation. */ ()=>setToasts(/** Inline callback for this operation. */ items=>items.filter(/** Inline callback for this operation. */ x=>x.id!==id)),entry.duration);
    return id;
  },[]);
  const dismiss=useCallback(/** Inline callback for this operation. */ id=>setToasts(/** Inline callback for this operation. */ items=>items.filter(/** Inline callback for this operation. */ x=>x.id!==id)),[]);
  const confirm=useCallback(/** Inline callback for this operation. */ (options={})=>new Promise(/** Inline callback for this operation. */ resolve=>{
    resolver.current=resolve;
    setConfirmState({
      title:options.title||'Confirm action',
      message:options.message||'Are you sure you want to continue?',
      confirmLabel:options.confirmLabel||'Confirm',
      cancelLabel:options.cancelLabel||'Cancel',
      tone:options.tone||'danger',
    });
  }),[]);
  const settle=useCallback(/** Inline callback for this operation. */ value=>{
    setConfirmState(null);
    const current=resolver.current;resolver.current=null;
    current?.(value);
  },[]);
  useEffect(/** Inline callback for this operation. */ ()=>/** Inline callback for this operation. */ ()=>{resolver.current?.(false)},[]);
  const value=useMemo(/** Inline callback for this operation. */ ()=>({toast,confirm,dismiss}),[toast,confirm,dismiss]);
  return <UXContext.Provider value={value}>
    {children}
    <div className="ux-toast-region" role="region" aria-label="Notifications" aria-live="polite">
      {toasts.map(/** Inline callback for this operation. */ item=><Toast key={item.id} item={item} onClose={/** Inline callback for this operation. */ ()=>dismiss(item.id)}/>) }
    </div>
    {confirmState&&<ConfirmDialog state={confirmState} onCancel={/** Inline callback for this operation. */ ()=>settle(false)} onConfirm={/** Inline callback for this operation. */ ()=>settle(true)}/>} 
  </UXContext.Provider>
}

/** Handles use ux for the VSN Ecommerce interface. */
export function useUX(){
  const value=useContext(UXContext);
  if(!value)throw new Error('useUX must be used inside UXProvider');
  return value;
}

/** Handles toast for the VSN Ecommerce interface. */
function Toast({item,onClose}){
  const Icon=item.tone==='success'?FaCheckCircle:item.tone==='danger'?FaExclamationCircle:item.tone==='warning'?FaExclamationTriangle:FaInfoCircle;
  return <div className={`ux-toast ux-toast--${item.tone}`} role={item.tone==='danger'?'alert':'status'}>
    <Icon className="ux-toast-icon"/><div><b>{item.title||toneTitle(item.tone)}</b><p>{item.message}</p></div><button type="button" aria-label="Dismiss notification" onClick={onClose}><FaTimes/></button>
  </div>
}
/** Handles tone title for the VSN Ecommerce interface. */
function toneTitle(tone){return tone==='success'?'Success':tone==='danger'?'Action failed':tone==='warning'?'Attention':'Update'}

/** Handles confirm dialog for the VSN Ecommerce interface. */
function ConfirmDialog({state,onCancel,onConfirm}){
  const confirmRef=useRef(null);
  useEffect(/** Inline callback for this operation. */ ()=>{confirmRef.current?.focus();const key=/** Handles key for the VSN Ecommerce interface. */ e=>{if(e.key==='Escape')onCancel()};document.addEventListener('keydown',key);return/** Inline callback for this operation. */ ()=>document.removeEventListener('keydown',key)},[onCancel]);
  return <div className="modal-backdrop ux-confirm-backdrop" role="presentation" onMouseDown={/** Inline callback for this operation. */ e=>e.target===e.currentTarget&&onCancel()}>
    <div className="ux-confirm" role="alertdialog" aria-modal="true" aria-labelledby="ux-confirm-title" aria-describedby="ux-confirm-message">
      <span className={`ux-confirm-icon ux-confirm-icon--${state.tone}`}><FaExclamationTriangle/></span>
      <div><h3 id="ux-confirm-title">{state.title}</h3><p id="ux-confirm-message">{state.message}</p></div>
      <div className="ux-confirm-actions"><button type="button" className="ui-btn ui-btn--secondary" onClick={onCancel}>{state.cancelLabel}</button><button ref={confirmRef} type="button" className={`ui-btn ${state.tone==='danger'?'ui-btn--danger':'ui-btn--primary'}`} onClick={onConfirm}>{state.confirmLabel}</button></div>
    </div>
  </div>
}
