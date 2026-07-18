import { useEffect, useState } from "react";
import { ShieldCheck, ShieldOff, Copy, Check } from "lucide-react";
import { api } from "../../lib/api";
import { useToast } from "../../lib/toast";
import { Badge, ErrorText } from "../../components/ui";
import AccountLayout, { Section } from "./AccountLayout";

type Status = { confirmed: boolean; emailEnabled: boolean; required: boolean; hasRecoveryCodes: boolean };

export default function AccountTwoFactor() {
  const toast = useToast();
  const [status, setStatus] = useState<Status | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [freshCodes, setFreshCodes] = useState<string[] | null>(null);
  const [copied, setCopied] = useState(false);

  const load = () =>
    api<Status>("/settings/two-factor")
      .then(setStatus)
      .catch((e) => setError((e as Error).message));
  useEffect(() => {
    load();
  }, []);

  const enable = async () => {
    setBusy(true);
    setError("");
    try {
      const r = await api<{ message: string; freshRecoveryCodes: string[] | null }>("/settings/two-factor/email", {
        method: "POST",
      });
      if (r.freshRecoveryCodes) setFreshCodes(r.freshRecoveryCodes);
      toast.success("Two-factor enabled", "You'll be asked for an email code at login.");
      await load();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const disable = async () => {
    setBusy(true);
    setError("");
    try {
      await api("/settings/two-factor/email", { method: "DELETE" });
      setFreshCodes(null);
      toast.success("Two-factor disabled");
      await load();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const regenerate = async () => {
    setBusy(true);
    setError("");
    try {
      const r = await api<{ message: string; freshRecoveryCodes: string[] }>("/settings/two-factor/recovery-codes", {
        method: "POST",
      });
      setFreshCodes(r.freshRecoveryCodes);
      toast.success("Recovery codes regenerated", "Your old codes no longer work.");
      await load();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const copyCodes = () => {
    if (!freshCodes) return;
    navigator.clipboard.writeText(freshCodes.join("\n")).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    });
  };

  const enabled = status?.emailEnabled ?? false;

  return (
    <AccountLayout>
      <Section
        title="Two-factor authentication"
        description="Add an extra layer of security by requiring an emailed verification code when you sign in."
      >
        <ErrorText error={error} />

        <div className="card p-4">
          <div className="flex items-start gap-3">
            <div
              className={
                enabled
                  ? "flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700"
                  : "flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500"
              }
            >
              {enabled ? <ShieldCheck size={20} /> : <ShieldOff size={20} />}
            </div>
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-2">
                <span className="text-sm font-bold text-slate-800">Email verification at login</span>
                {enabled ? <Badge color="green">On</Badge> : <Badge color="slate">Off</Badge>}
                {status?.required && <Badge color="amber">Required</Badge>}
              </div>
              <p className="mt-0.5 text-sm text-slate-500">
                {enabled
                  ? "A one-time code is emailed to you each time you sign in."
                  : "When enabled, a one-time code is emailed to you at each sign-in."}
              </p>
            </div>
            {!status ? null : enabled ? (
              <button
                className="btn-secondary shrink-0"
                onClick={disable}
                disabled={busy || status.required}
                title={status.required ? "Required by an administrator" : undefined}
              >
                Disable
              </button>
            ) : (
              <button className="btn-primary shrink-0" onClick={enable} disabled={busy}>
                Enable
              </button>
            )}
          </div>
        </div>

        {/* Freshly generated recovery codes — shown once. */}
        {freshCodes && (
          <div className="rounded-lg border border-brand-200 bg-brand-50 p-4">
            <div className="mb-2 flex items-center justify-between">
              <p className="text-sm font-bold text-brand-800">Recovery codes</p>
              <button className="btn-ghost !py-1 text-brand-700 hover:bg-brand-100" onClick={copyCodes}>
                {copied ? <Check size={14} /> : <Copy size={14} />} {copied ? "Copied" : "Copy"}
              </button>
            </div>
            <p className="mb-3 text-xs text-brand-700">
              Store these somewhere safe. Each code can be used once to sign in if you can't receive an email. They
              won't be shown again.
            </p>
            <div className="grid grid-cols-2 gap-x-4 gap-y-1 font-mono text-sm text-slate-700">
              {freshCodes.map((c) => (
                <span key={c}>{c}</span>
              ))}
            </div>
          </div>
        )}

        {/* Recovery-code management once 2FA is on. */}
        {enabled && (
          <div className="flex flex-wrap items-center gap-3">
            <button className="btn-secondary" onClick={regenerate} disabled={busy}>
              {status?.hasRecoveryCodes ? "Regenerate recovery codes" : "Generate recovery codes"}
            </button>
            {status?.hasRecoveryCodes && !freshCodes && (
              <span className="text-xs text-slate-400">Recovery codes are set. Regenerate to view a new set.</span>
            )}
          </div>
        )}
      </Section>
    </AccountLayout>
  );
}
