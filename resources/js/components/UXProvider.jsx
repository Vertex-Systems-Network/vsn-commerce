import {createContext,useCallback,useContext,useEffect,useId,useMemo,useRef,useState} from 'react';
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
  const previousPath=useRef(location.pathname);

  useEffect(/** Moves route-change focus to the active workspace rather than repeated global chrome. */ ()=>{
    window.scrollTo({top:0,behavior:'auto'});
    if(previousPath.current===location.pathname)return;
    previousPath.current=location.pathname;
    const main=document.querySelector('#account-content')||document.querySelector('main');
    if(main){main.setAttribute('tabindex','-1');requestAnimationFrame(/** Focuses the new route without introducing an additional scroll jump. */ ()=>main.focus({preventScroll:true}));}
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
    <Icon className="ux-toast-icon" aria-hidden="true"/><div><b>{item.title||toneTitle(item.tone)}</b><p>{item.message}</p></div><button type="button" aria-label="Dismiss notification" onClick={onClose}><FaTimes aria-hidden="true"/></button>
  </div>
}
/** Handles tone title for the VSN Ecommerce interface. */
function toneTitle(tone){return tone==='success'?'Success':tone==='danger'?'Action failed':tone==='warning'?'Attention':'Update'}

/** Returns the enabled focusable controls inside a confirm dialog. */
function confirmFocusable(root){if(!root)return[];return [...root.querySelectorAll('a[href],button,input,select,textarea,[tabindex]:not([tabindex="-1"])')].filter(node=>!node.disabled&&node.getAttribute('aria-hidden')!=='true'&&node.getClientRects().length>0)}

/** Handles confirm dialog for the VSN Ecommerce interface. */
function ConfirmDialog({state,onCancel,onConfirm}){
  const confirmRef=useRef(null),dialogRef=useRef(null),cancelRef=useRef(onCancel),uid=useId();
  const titleId=`ux-confirm-title-${uid.replaceAll(':','')}`,messageId=`ux-confirm-message-${uid.replaceAll(':','')}`;
  useEffect(/** Keeps the latest cancel action available without resetting dialog focus. */ ()=>{cancelRef.current=onCancel},[onCancel]);
  useEffect(/** Contains keyboard focus, supports Escape and restores origin focus on close. */ ()=>{
    const previous=document.activeElement;
    confirmRef.current?.focus();
    document.body.classList.add('modal-open');
    const key=/** Handles keyboard dismissal and Tab cycling for the confirm dialog. */ e=>{
      if(e.key==='Escape'){e.preventDefault();cancelRef.current?.();return}
      if(e.key!=='Tab')return;
      const nodes=confirmFocusable(dialogRef.current);
      if(!nodes.length){e.preventDefault();confirmRef.current?.focus();return}
      const first=nodes[0],last=nodes[nodes.length-1];
      if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus()}
      else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus()}
    };
    document.addEventListener('keydown',key);
    return/** Restores background scrolling and focus to the control that opened the dialog. */ ()=>{document.removeEventListener('keydown',key);document.body.classList.remove('modal-open');previous?.focus?.()};
  },[]);
  return <div className="modal-backdrop ux-confirm-backdrop" role="presentation" onMouseDown={/** Inline callback for this operation. */ e=>e.target===e.currentTarget&&cancelRef.current?.()}>
    <div ref={dialogRef} className="ux-confirm" role="alertdialog" aria-modal="true" aria-labelledby={titleId} aria-describedby={messageId}>
      <span className={`ux-confirm-icon ux-confirm-icon--${state.tone}`}><FaExclamationTriangle aria-hidden="true"/></span>
      <div><h3 id={titleId}>{state.title}</h3><p id={messageId}>{state.message}</p></div>
      <div className="ux-confirm-actions"><button type="button" className="ui-btn ui-btn--secondary" onClick={onCancel}>{state.cancelLabel}</button><button ref={confirmRef} type="button" className={`ui-btn ${state.tone==='danger'?'ui-btn--danger':'ui-btn--primary'}`} onClick={onConfirm}>{state.confirmLabel}</button></div>
    </div>
  </div>
}
