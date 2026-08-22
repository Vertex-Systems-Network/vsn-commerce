import { useEffect, useMemo, useState } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import {
  FaApple,
  FaFacebookF,
  FaGoogle,
  FaLinkedinIn,
  FaLock,
  FaShieldAlt,
} from "react-icons/fa";
import SEO from "../components/SEO";
import { Button, Card, Field, Status } from "../components/Toolkit";
import { apiGet, apiPost } from "../platform/api";
import { useCart } from "../platform/cart";
import { defaultHomeFor, useAuth } from "../platform/auth";


const PROVIDERS = [
  { key: "google", label: "Google", icon: FaGoogle },
  { key: "apple", label: "Apple", icon: FaApple },
  { key: "facebook", label: "Facebook", icon: FaFacebookF },
  { key: "linkedin", label: "LinkedIn", icon: FaLinkedinIn },
];


/** Handles auth for the VSN Ecommerce interface. */
export default function Auth({ mode = "login" }) {
  const nav = useNavigate();
  const { mergeGuestCart } = useCart();
  const { acceptAuth } = useAuth();
  const loc = useLocation();
  const register = mode === "register";
  const [form, setForm] = useState({
    name: "",
    email: "",
    password: "",
    confirm: "",
    referralCode: new URLSearchParams(loc.search).get("ref") || "",
    accept: false,
    remember: false,
  });
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [otpMode, setOtpMode] = useState(false);
  const [otp, setOtp] = useState("");
  const [otpSent, setOtpSent] = useState(false);
  const [demoAccounts, setDemoAccounts] = useState([]);
  const next = useMemo(
    /** Inline callback for this operation. */ () => new URLSearchParams(loc.search).get("next"),
    [loc.search]
  );
  useEffect(/** Inline callback for this operation. */ () => {
    if (register) return;
    apiGet("/demo/accounts").then(/** Inline callback for this operation. */ (rows) => setDemoAccounts(Array.isArray(rows) ? rows : [])).catch(/** Inline callback for this operation. */ () => setDemoAccounts([]));
  }, [register]);
  const set = /** Handles set for the VSN Ecommerce interface. */ (k) => /** Inline callback for this operation. */ (e) =>
    setForm(/** Inline callback for this operation. */ (f) => ({
      ...f,
      [k]: e.target.type === "checkbox" ? e.target.checked : e.target.value,
    }));

    /** Handles submit for the VSN Ecommerce interface. */
async function submit(e) {
    e.preventDefault();
    setError("");
    setMessage("");
    if (register && form.password !== form.confirm) {
      setError("Passwords do not match.");
      return;
    }
    if (register && !form.accept) {
      setError("Accept the Terms and Privacy Policy to continue.");
      return;
    }
    setBusy(true);
    try {
      const signedInUser = await apiPost(register ? "/auth/register" : "/auth/login", {
        name: form.name,
        email: form.email,
        password: form.password,
        password_confirmation: register ? form.confirm : undefined,
        referral_code: register && form.referralCode ? form.referralCode : undefined,
        remember: !register ? Boolean(form.remember) : undefined,
      });
      acceptAuth(signedInUser);
      await mergeGuestCart().catch(/** Inline callback for this operation. */ () => {});
      nav(next || defaultHomeFor(signedInUser), { replace: true });
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

    /** Handles start social for the VSN Ecommerce interface. */
async function startSocial(provider) {
    setError("");
    setBusy(true);
    try {
      const data = await apiGet(
        `/auth/oauth/${provider}/start?redirect=${encodeURIComponent(next || "/dashboard")}`
      );
      const url = data?.authorizationUrl;
      if (!url) throw new Error("Authorization URL missing.");
      window.location.assign(url);
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

    /** Handles send otp for the VSN Ecommerce interface. */
async function sendOtp() {
    if (!form.email) {
      setError("Enter your email first.");
      return;
    }
    setBusy(true);
    setError("");
    try {
      await apiPost("/auth/otp/send", { email: form.email });
      setOtpSent(true);
      setMessage("A one-time code was sent to your email.");
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }
    /** Handles verify otp for the VSN Ecommerce interface. */
async function verifyOtp() {
    setBusy(true);
    setError("");
    try {
      const signedInUser = await apiPost("/auth/otp/verify", { email: form.email, code: otp });
      acceptAuth(signedInUser);
      await mergeGuestCart().catch(/** Inline callback for this operation. */ () => {});
      nav(next || defaultHomeFor(signedInUser), { replace: true });
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <SEO
        title={`${register ? "Create account" : "Sign in"} | VSN Ecommerce`}
        description={
          register
            ? "Create your VSN Ecommerce buyer account."
            : "Securely sign in to VSN Ecommerce."
        }
      />
      <main className="auth-shell">
        <section className="auth-benefits">
          <Link to="/" className="auth-brand">
            VSN <span>ECOMMERCE</span>
          </Link>
          <div>
            <span className="auth-eyebrow">
              ONE ACCOUNT. THE WHOLE MARKETPLACE.
            </span>
            <h1>
              {register
                ? "Shop, play, earn and grow from one secure account."
                : "Welcome back to your marketplace."}
            </h1>
            <p>
              Orders, Game Win entries, VSN Coins, affiliate earnings, gifts,
              reviews and verification all stay linked to one identity.
            </p>
          </div>
          <div className="auth-trust-list">
            <span>
              <FaShieldAlt /> Secure account & device sessions
            </span>
            <span>
              <FaLock /> Provider secrets never reach the browser
            </span>
            <span>✓ One secure VSN Ecommerce identity</span>
          </div>
        </section>

        <section className="auth-panel-wrap">
          <Card className="auth-panel">
            <div className="auth-switch">
              <Link className={!register ? "active" : ""} to="/login">
                Sign in
              </Link>
              <Link className={register ? "active" : ""} to="/register">
                Create account
              </Link>
            </div>
            <header>
              <h2>
                {register ? "Create your account" : "Sign in to VSN Ecommerce"}
              </h2>
              <p>
                {register
                  ? "Use email or continue with a trusted identity provider."
                  : "Access orders, rewards and your marketplace dashboard."}
              </p>
            </header>

            <div className="social-auth-grid">
              {PROVIDERS.map(/** Inline callback for this operation. */ (p) => {
                const Icon = p.icon;
                return (
                  <button
                    type="button"
                    className={`social-auth social-auth--${p.key}`}
                    onClick={/** Inline callback for this operation. */ () => startSocial(p.key)}
                    disabled={busy}
                    key={p.key}
                  >
                    <Icon />
                    <span>Continue with {p.label}</span>
                  </button>
                );
              })}
            </div>
            <div className="auth-divider">
              <span>or continue with email</span>
            </div>

            {!otpMode ? (
              <form className="auth-form" onSubmit={submit}>
                {register && (
                  <Field
                    label="Full name"
                    value={form.name}
                    onChange={set("name")}
                    autoComplete="name"
                    required
                  />
                )}
                <Field
                  label="Email address"
                  type="email"
                  value={form.email}
                  onChange={set("email")}
                  autoComplete="email"
                  required
                />
                <Field
                  label="Password"
                  type="password"
                  value={form.password}
                  onChange={set("password")}
                  autoComplete={register ? "new-password" : "current-password"}
                  required
                />
                {register && (
                  <Field
                    label="Confirm password"
                    type="password"
                    value={form.confirm}
                    onChange={set("confirm")}
                    autoComplete="new-password"
                    required
                  />
                )}
                {register && (
                  <Field
                    label="Referral code (optional)"
                    value={form.referralCode}
                    onChange={set("referralCode")}
                    autoComplete="off"
                    placeholder="e.g. VSNABC123"
                    help="A referrer can only be attached once."
                  />
                )}
                {!register && (
                  <div className="auth-row">
                    <label className="check-line">
                      <input type="checkbox" checked={form.remember} onChange={set("remember")} /> <span>Keep me signed in</span>
                    </label>
                    <Link to="/forgot-password">Forgot password?</Link>
                  </div>
                )}
                {register && (
                  <label className="check-line auth-consent">
                    <input
                      type="checkbox"
                      checked={form.accept}
                      onChange={set("accept")}
                    />
                    <span>
                      I agree to the{" "}
                      <Link to="/legal">
                        Terms, Privacy Policy and marketplace rules
                      </Link>
                      .
                    </span>
                  </label>
                )}
                {error && <Status>{error}</Status>}
                {message && <Status ok>{message}</Status>}
                <Button type="submit" disabled={busy}>
                  {busy
                    ? "Please wait…"
                    : register
                      ? "Create account"
                      : "Sign in"}
                </Button>
                {!register && (
                  <button
                    className="auth-link-button"
                    type="button"
                    onClick={/** Inline callback for this operation. */ () => setOtpMode(true)}
                  >
                    Sign in with one-time email code
                  </button>
                )}
              </form>
            ) : (
              <div className="auth-form">
                <Field
                  label="Email address"
                  type="email"
                  value={form.email}
                  onChange={set("email")}
                  autoComplete="email"
                />
                {otpSent && (
                  <Field
                    label="6-digit code"
                    inputMode="numeric"
                    value={otp}
                    onChange={/** Inline callback for this operation. */ (e) =>
                      setOtp(e.target.value.replace(/\D/g, "").slice(0, 6))
                    }
                  />
                )}
                {error && <Status>{error}</Status>}
                {message && <Status ok>{message}</Status>}
                {!otpSent ? (
                  <Button onClick={sendOtp} disabled={busy}>
                    Send one-time code
                  </Button>
                ) : (
                  <Button onClick={verifyOtp} disabled={busy}>
                    Verify & sign in
                  </Button>
                )}
                <button
                  className="auth-link-button"
                  type="button"
                  onClick={/** Inline callback for this operation. */ () => {
                    setOtpMode(false);
                    setOtpSent(false);
                    setMessage("");
                  }}
                >
                  Use password instead
                </button>
              </div>
            )}

            {!register && demoAccounts.length > 0 && <div className="demo-login-panel">
              <div><b>Local demo accounts</b><small>Development only · password: <code>ChangeMe12345</code> · click a role to fill the login form.</small></div>
              <div className="demo-login-grid">{demoAccounts.map(/** Inline callback for this operation. */ (account) => <button key={account.email} type="button" onClick={/** Inline callback for this operation. */ () => setForm(/** Inline callback for this operation. */ (f) => ({...f,email:account.email,password:account.password}))}><b>{account.role}</b><span>{account.email}</span></button>)}</div>
            </div>}

            <footer className="auth-foot">
              {register ? (
                <>
                  Already have an account? <Link to="/login">Sign in</Link>
                </>
              ) : (
                <>
                  New to VSN? <Link to="/register">Create account</Link>
                </>
              )}
            </footer>
          </Card>
        </section>
      </main>
    </>
  );
}

/** Handles forgot password for the VSN Ecommerce interface. */
export function ForgotPassword() {
  const [email, setEmail] = useState("");
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
    /** Handles submit for the VSN Ecommerce interface. */
async function submit(e) {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      await apiPost("/auth/password/forgot", { email });
      setMessage(
        "If an account exists, password reset instructions have been sent."
      );
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <>
      <SEO title="Reset password | VSN Ecommerce" />
      <main className="auth-simple">
        <Card className="auth-reset">
          <Link to="/login">← Back to sign in</Link>
          <h1>Reset your password</h1>
          <p>Enter the email attached to your account.</p>
          <form className="auth-form" onSubmit={submit}>
            <Field
              label="Email address"
              type="email"
              value={email}
              onChange={/** Inline callback for this operation. */ (e) => setEmail(e.target.value)}
              required
            />
            {error && <Status>{error}</Status>}
            {message && <Status ok>{message}</Status>}
            <Button type="submit" disabled={busy}>
              Send reset link
            </Button>
          </form>
        </Card>
      </main>
    </>
  );
}

/** Handles reset password for the VSN Ecommerce interface. */
export function ResetPassword() {
  const q = new URLSearchParams(useLocation().search);
  const [email, setEmail] = useState(q.get("email") || "");
  const [token] = useState(q.get("token") || "");
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

    /** Handles submit for the VSN Ecommerce interface. */
async function submit(e) {
    e.preventDefault();
    setError("");
    setMessage("");

    if (!token) {
      setError("Reset token is missing. Request a new password reset link.");
      return;
    }

    if (password !== confirm) {
      setError("Passwords do not match.");
      return;
    }

    setBusy(true);
    try {
      await apiPost("/auth/password/reset", {
        email,
        token,
        password,
        password_confirmation: confirm,
      });
      setMessage("Password updated. You can now sign in.");
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <SEO title="Choose a new password | VSN Ecommerce" />
      <main className="auth-simple">
        <Card className="auth-reset">
          <Link to="/login">← Back to sign in</Link>
          <h1>Choose a new password</h1>
          <p>Set a new password for your VSN Ecommerce account.</p>
          <form className="auth-form" onSubmit={submit}>
            <Field
              label="Email address"
              type="email"
              value={email}
              onChange={/** Inline callback for this operation. */ (e) => setEmail(e.target.value)}
              required
            />
            <Field
              label="New password"
              type="password"
              value={password}
              onChange={/** Inline callback for this operation. */ (e) => setPassword(e.target.value)}
              autoComplete="new-password"
              required
            />
            <Field
              label="Confirm password"
              type="password"
              value={confirm}
              onChange={/** Inline callback for this operation. */ (e) => setConfirm(e.target.value)}
              autoComplete="new-password"
              required
            />
            {error && <Status>{error}</Status>}
            {message && <Status ok>{message}</Status>}
            <Button type="submit" disabled={busy}>
              {busy ? "Updating…" : "Update password"}
            </Button>
          </form>
        </Card>
      </main>
    </>
  );
}


/** Handles auth callback for the VSN Ecommerce interface. */
export function AuthCallback() {
  const q = new URLSearchParams(useLocation().search);
  const ok = q.get("auth") === "success";
  const provider = q.get("provider") || "social provider";
  const requestedNext = q.get("next");
  const { mergeGuestCart } = useCart();
  const { refresh, user } = useAuth();
  const [cartMerged, setCartMerged] = useState(!ok);
  const [sessionReady, setSessionReady] = useState(!ok);
  useEffect(/** Inline callback for this operation. */ () => {
    if (!ok) return;
    Promise.all([refresh(), mergeGuestCart().catch(/** Inline callback for this operation. */ () => {})]).finally(/** Inline callback for this operation. */ () => { setSessionReady(true); setCartMerged(true); });
  }, [ok, mergeGuestCart, refresh]);
  const next = requestedNext || defaultHomeFor(user);
  return (
    <>
      <SEO title="Authentication | VSN Ecommerce" />
      <main className="auth-simple">
        <Card className="auth-reset">
          <Status ok={ok}>
            {ok
              ? `Signed in with ${provider}.`
              : q.get("message") || "Authentication could not be completed."}
          </Status>
          {ok ? (
            <Link className="ui-btn ui-btn--primary" to={next} aria-disabled={!cartMerged || !sessionReady} onClick={/** Inline callback for this operation. */ e => { if (!cartMerged || !sessionReady) e.preventDefault(); }}>
              {cartMerged && sessionReady ? "Continue" : "Syncing account…"}
            </Link>
          ) : (
            <Link className="ui-btn ui-btn--primary" to="/login">
              Try again
            </Link>
          )}
        </Card>
      </main>
    </>
  );
}
