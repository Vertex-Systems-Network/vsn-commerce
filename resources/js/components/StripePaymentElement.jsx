import { useEffect, useRef, useState } from "react";
import { Button, Status } from "./Toolkit";

let stripeLoader;
/** Handles load stripe js for the VSN Ecommerce interface. */
function loadStripeJs() {
  if (window.Stripe) return Promise.resolve(window.Stripe);
  if (!stripeLoader) stripeLoader = new Promise(/** Inline callback for this operation. */ (resolve, reject) => {
    const existing = document.querySelector('script[src="https://js.stripe.com/v3/"]');
    if (existing) { existing.addEventListener("load", /** Inline callback for this operation. */ () => resolve(window.Stripe), { once: true }); existing.addEventListener("error", reject, { once: true }); return; }
    const script = document.createElement("script"); script.src = "https://js.stripe.com/v3/"; script.async = true;
    script.onload = /** Inline callback for this operation. */ () => resolve(window.Stripe); script.onerror = /** Inline callback for this operation. */ () => reject(new Error("Stripe.js could not be loaded.")); document.head.appendChild(script);
  });
  return stripeLoader;
}

/** Handles stripe payment element for the VSN Ecommerce interface. */
export default function StripePaymentElement({ clientAction, savedMethod = false, onSubmitted }) {
  const mountRef = useRef(null); const elementsRef = useRef(null); const stripeRef = useRef(null); const [ready, setReady] = useState(false); const [busy, setBusy] = useState(false); const [error, setError] = useState("");
  useEffect(/** Inline callback for this operation. */ () => {
    let active = true; let paymentElement;
    (/** Inline callback for this operation. */ async () => {
      try {
        const Stripe = await loadStripeJs(); if (!active || !Stripe) return;
        const stripe = Stripe(clientAction.publishableKey); stripeRef.current = stripe;
        if (!savedMethod) {
          const elements = stripe.elements({ clientSecret: clientAction.clientSecret }); elementsRef.current = elements;
          paymentElement = elements.create("payment", { layout: "tabs" }); paymentElement.mount(mountRef.current);
        }
        if (active) setReady(true);
      } catch (e) { if (active) setError(e.message || "Stripe could not initialize."); }
    })();
    return /** Inline callback for this operation. */ () => { active = false; try { paymentElement?.destroy(); } catch {} };
  }, [clientAction?.clientSecret, clientAction?.publishableKey, savedMethod]);

  const confirm = /** Handles confirm for the VSN Ecommerce interface. */ async () => {
    if (!stripeRef.current || !clientAction?.clientSecret) return; setBusy(true); setError("");
    try {
      let result;
      if (savedMethod) result = await stripeRef.current.confirmCardPayment(clientAction.clientSecret);
      else result = await stripeRef.current.confirmPayment({ elements: elementsRef.current, confirmParams: { return_url: `${window.location.origin}/checkout` }, redirect: "if_required" });
      if (result?.error) throw new Error(result.error.message || "Payment could not be confirmed.");
      await onSubmitted?.(result?.paymentIntent || null);
    } catch (e) { setError(e.message || "Stripe payment confirmation failed."); }
    finally { setBusy(false); }
  };

  return <div className="stripe-payment-box">
    {error && <Status>{error}</Status>}
    {!savedMethod && <div ref={mountRef} className="stripe-payment-element" />}
    {savedMethod && <p><small>Stripe will use the selected tokenized card. Additional bank authentication may be shown if required.</small></p>}
    <Button disabled={!ready || busy} onClick={confirm}>{busy ? "Confirming…" : savedMethod ? "Authenticate & pay" : "Pay securely"}</Button>
    <p><small>Card details are entered into Stripe.js and are never sent through or stored by VSN Ecommerce.</small></p>
  </div>;
}
