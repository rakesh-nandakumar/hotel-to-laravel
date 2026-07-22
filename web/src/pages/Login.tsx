import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useAuth, getPinUser, Me } from "../lib/auth";
import { useBranding } from "../lib/branding";
import { api, ApiFail } from "../lib/api";
import { landingPath } from "../lib/landing";
import { resetSocket } from "../lib/socket";
import { ErrorText } from "../components/ui";
import { Delete, KeyRound, Building2 } from "lucide-react";

type Screen = "password" | "pin" | "two-factor" | "otp";

/**
 * Login:
 * - Email + password always available.
 * - PIN quick-unlock is shown ONLY for the account that last signed in with
 *   credentials on this device (device-bound, enforced server-side). Signing in
 *   with different credentials rebinds the device and removes the previous
 *   user's PIN option.
 * - A password login may come back asking for a second factor — either the
 *   standard TOTP/recovery-code challenge, or this app's own email-OTP
 *   challenge — before a session is actually established.
 */
export default function Login() {
  const { login, pinLogin, completeTwoFactor, completeOtp } = useAuth();
  const { branding } = useBranding();
  const nav = useNavigate();
  const [pinUser, setPinUser] = useState(() => getPinUser());
  const [screen, setScreen] = useState<Screen>(pinUser ? "pin" : "password");
  const [pin, setPin] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const go = (me: Me | null) => {
    resetSocket();
    nav(me ? landingPath(me) : "/");
  };

  const submitPassword = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      const r = await login(email, password);
      if (r.status === "challenge") setScreen(r.challenge);
      else go(r.me);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const tryPin = async (fullPin: string) => {
    setBusy(true);
    setError("");
    try {
      go(await pinLogin(fullPin));
    } catch (err) {
      const msg = (err as Error).message;
      setError(msg);
      setPin("");
      if (
        err instanceof ApiFail &&
        err.status === 422 &&
        /no longer trusted/i.test(msg)
      ) {
        localStorage.removeItem("mv.device");
        localStorage.removeItem("mv.pinUser");
        setPinUser(null);
        setScreen("password");
      }
    } finally {
      setBusy(false);
    }
  };

  const press = (d: string) => {
    if (busy) return;
    const next = (pin + d).slice(0, 4);
    setPin(next);
  };

  return (
    <div className="flex min-h-screen flex-col lg:flex-row">
      {/* Branding panel — navy chrome ported from leolanka-inertia's auth split layout */}
      <aside
        aria-label="Branding"
        className="relative flex flex-col items-center justify-center gap-8 overflow-hidden bg-gradient-to-b from-sidebar to-sidebar-deep px-8 py-12 text-white lg:min-h-screen lg:w-[460px]"
      >
        {/* Subtle data-grid dot texture instead of generic blurred blobs */}
        <div
          aria-hidden="true"
          className="pointer-events-none absolute inset-0 opacity-[0.06]"
          style={{
            backgroundImage:
              "radial-gradient(circle, rgba(255,255,255,0.9) 1px, transparent 1px)",
            backgroundSize: "22px 22px",
          }}
        />
        <div className="relative z-10 flex flex-col items-center gap-5 text-center">
          <div className="flex h-20 w-20 items-center justify-center overflow-hidden rounded-2xl bg-white/10 shadow-2xl ring-1 ring-white/10">
            {branding.logo ? (
              <img
                src={branding.logo}
                alt={branding.name}
                className="h-full w-full"
              />
            ) : (
              <Building2 className="h-10 w-10 text-white" />
            )}
          </div>
          <div>
            <h1 className="text-3xl font-black tracking-tight">
              {branding.name}
            </h1>
            {branding.login_tagline && (
              <p className="mx-auto mt-2 max-w-[240px] text-sm font-medium tracking-widest text-brand-100/70">
                {branding.login_tagline}
              </p>
            )}
          </div>
        </div>
        <p className="absolute bottom-8 z-10 hidden text-xs text-brand-100/50 lg:block">
          A proud product of Vellix Global
        </p>
      </aside>

      {/* Form panel */}
      <main className="flex flex-1 flex-col items-center justify-center bg-slate-50 px-6 py-12">
        <div className="w-full max-w-sm">
          <div className="card modal-panel p-5">
            {pinUser && (screen === "pin" || screen === "password") && (
              <div className="mb-4 flex gap-1 rounded-xl bg-slate-200/70 p-1">
                {(["pin", "password"] as const).map((m) => (
                  <button
                    key={m}
                    className={`flex-1 rounded-lg px-3 py-1.5 text-sm font-semibold ${screen === m ? "bg-white shadow-sm" : "text-slate-500"}`}
                    onClick={() => {
                      setScreen(m);
                      setError("");
                    }}
                  >
                    {m === "pin" ? "Quick PIN" : "Email login"}
                  </button>
                ))}
              </div>
            )}
            <ErrorText error={error} />

            {screen === "password" && (
              <form onSubmit={submitPassword} className="mt-3 space-y-3">
                <input
                  className="input"
                  type="email"
                  placeholder="you@email.com"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  required
                  autoFocus
                />
                <input
                  className="input"
                  type="password"
                  placeholder="Password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  required
                />
                <button className="btn-primary w-full !py-3" disabled={busy}>
                  {busy ? "Signing in…" : "Sign in"}
                </button>
                {!pinUser && (
                  <p className="flex items-center gap-1.5 pt-1 text-[11px] text-slate-400">
                    <KeyRound size={12} /> After you sign in once, quick PIN
                    unlock becomes available on this device — for your account
                    only.
                  </p>
                )}
              </form>
            )}

            {screen === "pin" && pinUser && (
              <div className="mt-1">
                <div className="mb-1 text-center">
                  <div className="text-base font-extrabold">{pinUser.name}</div>
                  <div className="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                    {pinUser.roleName}
                  </div>
                </div>
                <div className="my-4 flex justify-center gap-3">
                  {[0, 1, 2, 3].map((i) => (
                    <div
                      key={i}
                      className={`h-3.5 w-3.5 rounded-full transition ${i < pin.length ? "bg-brand-600" : "bg-slate-200"}`}
                    />
                  ))}
                </div>
                <div className="grid grid-cols-3 gap-2">
                  {[
                    "1",
                    "2",
                    "3",
                    "4",
                    "5",
                    "6",
                    "7",
                    "8",
                    "9",
                    "",
                    "0",
                    "⌫",
                  ].map((k, i) =>
                    k === "" ? (
                      <div key={i} />
                    ) : k === "⌫" ? (
                      <button
                        key={i}
                        className="btn-secondary !py-3"
                        onClick={() => setPin(pin.slice(0, -1))}
                      >
                        <Delete size={18} />
                      </button>
                    ) : (
                      <button
                        key={i}
                        className="btn-secondary !py-3 text-lg font-bold"
                        onClick={() => press(k)}
                      >
                        {k}
                      </button>
                    ),
                  )}
                </div>
                <button
                  className="btn-primary mt-3 w-full !py-3"
                  disabled={pin.length !== 4 || busy}
                  onClick={() => void tryPin(pin)}
                >
                  {busy ? "Confirming…" : "Confirm PIN"}
                </button>
                <p className="mt-3 text-center text-[11px] text-slate-400">
                  Not {pinUser.name.split(" ")[0]}? Use{" "}
                  <button
                    className="font-bold text-brand-600 underline"
                    onClick={() => setScreen("password")}
                  >
                    email login
                  </button>{" "}
                  — signing in as someone else moves the PIN unlock to their
                  account.
                </p>
              </div>
            )}

            {screen === "two-factor" && (
              <TwoFactorForm
                busy={busy}
                onSubmit={async (payload) => {
                  setBusy(true);
                  setError("");
                  try {
                    go(await completeTwoFactor(payload));
                  } catch (err) {
                    setError((err as Error).message);
                  } finally {
                    setBusy(false);
                  }
                }}
                onBack={() => setScreen("password")}
              />
            )}

            {screen === "otp" && (
              <OtpForm
                busy={busy}
                onSubmit={async (payload) => {
                  setBusy(true);
                  setError("");
                  try {
                    go(await completeOtp(payload));
                  } catch (err) {
                    setError((err as Error).message);
                  } finally {
                    setBusy(false);
                  }
                }}
                onBack={() => setScreen("password")}
              />
            )}
          </div>
          <p className="mt-4 text-center text-xs text-slate-400">
            Guests:{" "}
            <a
              href="/pre-checkin"
              className="font-semibold text-brand-600 underline"
            >
              online pre-check-in
            </a>{" "}
            ·{" "}
            <a
              href="/venue-inquiry"
              className="font-semibold text-brand-600 underline"
            >
              venue inquiry
            </a>
          </p>
        </div>
      </main>
    </div>
  );
}

/** Fortify's standard TOTP challenge — a 6-digit authenticator code, or a one-time recovery code. */
function TwoFactorForm({
  busy,
  onSubmit,
  onBack,
}: {
  busy: boolean;
  onSubmit: (p: { code?: string; recovery_code?: string }) => void;
  onBack: () => void;
}) {
  const [useRecovery, setUseRecovery] = useState(false);
  const [value, setValue] = useState("");

  return (
    <form
      className="mt-1 space-y-3"
      onSubmit={(e) => {
        e.preventDefault();
        onSubmit(useRecovery ? { recovery_code: value } : { code: value });
      }}
    >
      <p className="text-sm text-slate-600">
        Enter the 6-digit code from your authenticator app.
      </p>
      <input
        className="input text-center text-lg tracking-[0.3em]"
        inputMode={useRecovery ? "text" : "numeric"}
        placeholder={useRecovery ? "Recovery code" : "••••••"}
        value={value}
        onChange={(e) => setValue(e.target.value)}
        autoFocus
        required
      />
      <button className="btn-primary w-full !py-3" disabled={busy || !value}>
        {busy ? "Verifying…" : "Verify"}
      </button>
      <div className="flex items-center justify-between text-xs">
        <button
          type="button"
          className="text-slate-400 underline"
          onClick={onBack}
        >
          ← Back
        </button>
        <button
          type="button"
          className="font-bold text-brand-600 underline"
          onClick={() => {
            setUseRecovery(!useRecovery);
            setValue("");
          }}
        >
          {useRecovery
            ? "Use authenticator code"
            : "Use a recovery code instead"}
        </button>
      </div>
    </form>
  );
}

/** This app's email-OTP challenge — masked email, resend cooldown, attempts remaining. */
function OtpForm({
  busy,
  onSubmit,
  onBack,
}: {
  busy: boolean;
  onSubmit: (p: { code?: string; recovery_code?: string }) => void;
  onBack: () => void;
}) {
  const [info, setInfo] = useState<{
    maskedEmail: string;
    resendIn: number;
    attemptsRemaining: number;
  } | null>(null);
  const [value, setValue] = useState("");
  const [resendMsg, setResendMsg] = useState("");
  const [cooldown, setCooldown] = useState(0);

  useEffect(() => {
    api<{ maskedEmail: string; resendIn: number; attemptsRemaining: number }>(
      "/otp-challenge",
    )
      .then((d) => {
        setInfo(d);
        setCooldown(d.resendIn);
      })
      .catch(() => {});
  }, []);

  useEffect(() => {
    if (cooldown <= 0) return;
    const t = setTimeout(() => setCooldown((c) => c - 1), 1000);
    return () => clearTimeout(t);
  }, [cooldown]);

  const resend = async () => {
    setResendMsg("");
    try {
      const r = await api<{ message: string }>("/otp-challenge/resend", {
        method: "POST",
      });
      setResendMsg(r.message);
      setCooldown(30);
    } catch (err) {
      setResendMsg((err as Error).message);
    }
  };

  return (
    <form
      className="mt-1 space-y-3"
      onSubmit={(e) => {
        e.preventDefault();
        onSubmit({ code: value });
      }}
    >
      <p className="text-sm text-slate-600">
        Enter the 6-digit code sent to{" "}
        {info ? <strong>{info.maskedEmail}</strong> : "your email"}.
        {info && info.attemptsRemaining <= 3 && (
          <span className="ml-1 text-amber-600">
            ({info.attemptsRemaining} attempts left)
          </span>
        )}
      </p>
      <input
        className="input text-center text-lg tracking-[0.3em]"
        inputMode="numeric"
        placeholder="••••••"
        value={value}
        onChange={(e) => setValue(e.target.value)}
        autoFocus
        required
      />
      <button className="btn-primary w-full !py-3" disabled={busy || !value}>
        {busy ? "Verifying…" : "Verify"}
      </button>
      {resendMsg && (
        <p className="text-center text-xs text-slate-500">{resendMsg}</p>
      )}
      <div className="flex items-center justify-between text-xs">
        <button
          type="button"
          className="text-slate-400 underline"
          onClick={onBack}
        >
          ← Back
        </button>
        <button
          type="button"
          className="font-bold text-brand-600 underline disabled:cursor-not-allowed disabled:text-slate-300 disabled:no-underline"
          disabled={cooldown > 0}
          onClick={resend}
        >
          {cooldown > 0 ? `Resend in ${cooldown}s` : "Resend code"}
        </button>
      </div>
    </form>
  );
}
