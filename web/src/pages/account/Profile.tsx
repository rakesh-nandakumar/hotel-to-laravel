import { FormEventHandler, useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Monitor, Smartphone, MapPin } from "lucide-react";
import { api, ApiFail } from "../../lib/api";
import { useAuth } from "../../lib/auth";
import { useToast } from "../../lib/toast";
import { Field, ErrorText, Modal } from "../../components/ui";
import AccountLayout, { Section } from "./AccountLayout";

type Session = {
  id: string;
  agent: { platform: string; browser: string; is_mobile: boolean };
  ip_address: string | null;
  location: string | null;
  is_current_device: boolean;
  last_active: string;
};

/** First validation error for a field, if any. */
const firstError = (errors: Record<string, string[]> | undefined, field: string) => errors?.[field]?.[0] ?? "";

export default function AccountProfile() {
  const { me, refresh } = useAuth();
  const toast = useToast();
  const navigate = useNavigate();
  const user = me!.user;

  // ── Profile information ──────────────────────────────────────────────────
  const [name, setName] = useState(user.name);
  const [email, setEmail] = useState(user.email);
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState("");

  const dirty = name !== user.name || email !== user.email;

  const saveProfile: FormEventHandler = async (e) => {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    setFormError("");
    try {
      await api("/settings/profile", { method: "PATCH", body: { name, email } });
      await refresh();
      toast.success("Profile updated", "Your name and email have been saved.");
    } catch (err) {
      if (err instanceof ApiFail && err.errors) setErrors(err.errors);
      setFormError((err as Error).message);
    } finally {
      setSaving(false);
    }
  };

  // ── Browser sessions ─────────────────────────────────────────────────────
  const [sessions, setSessions] = useState<Session[]>([]);
  const loadSessions = () =>
    api<{ sessions: Session[] }>("/settings/profile")
      .then((d) => setSessions(d.sessions))
      .catch(() => {});
  useEffect(() => {
    loadSessions();
  }, []);

  const revokeSession = async (id: string) => {
    try {
      await api(`/settings/browser-sessions/${id}`, { method: "DELETE" });
      setSessions((s) => s.filter((x) => x.id !== id));
      toast.success("Session revoked");
    } catch (err) {
      toast.error("Could not revoke session", (err as Error).message);
    }
  };

  return (
    <AccountLayout>
      {/* Profile information */}
      <Section title="Profile information" description="Update your name and email address">
        <form onSubmit={saveProfile} className="space-y-4">
          <ErrorText error={formError && Object.keys(errors).length === 0 ? formError : ""} />
          <Field label="Name">
            <input className="input" value={name} onChange={(e) => setName(e.target.value)} required autoComplete="name" />
            {firstError(errors, "name") && <p className="mt-1 text-xs font-medium text-red-600">{firstError(errors, "name")}</p>}
          </Field>
          <Field label="Email address">
            <input
              className="input"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
              autoComplete="username"
            />
            {firstError(errors, "email") && <p className="mt-1 text-xs font-medium text-red-600">{firstError(errors, "email")}</p>}
          </Field>
          {!user.email_verified && (
            <p className="text-xs text-amber-600">Your email address is unverified.</p>
          )}
          <div className="flex items-center gap-3 pt-1">
            <button className="btn-primary" disabled={saving || !dirty}>
              {saving ? "Saving…" : "Save"}
            </button>
          </div>
        </form>
      </Section>

      {/* Browser sessions */}
      <Section title="Browser sessions" description="Manage and log out your active sessions on other browsers and devices.">
        <div className="space-y-2">
          {sessions.length === 0 && <p className="text-sm text-slate-400">No active sessions found.</p>}
          {sessions.map((s) => (
            <div key={s.id} className="flex items-center gap-3 rounded-lg border border-slate-200 p-3">
              <div className="shrink-0 text-slate-400">
                {s.agent.is_mobile ? <Smartphone className="h-6 w-6" /> : <Monitor className="h-6 w-6" />}
              </div>
              <div className="min-w-0 flex-1">
                <p className="text-sm font-medium text-slate-800">
                  {s.agent.platform} — {s.agent.browser}
                </p>
                <p className="flex flex-wrap items-center gap-x-1.5 text-xs text-slate-500">
                  <span>{s.ip_address}</span>
                  {s.location && (
                    <>
                      <span>·</span>
                      <span className="inline-flex items-center gap-0.5">
                        <MapPin className="h-3 w-3" />
                        {s.location}
                      </span>
                    </>
                  )}
                  <span>·</span>
                  {s.is_current_device ? (
                    <span className="font-semibold text-emerald-600">This device</span>
                  ) : (
                    <span>Last active {s.last_active}</span>
                  )}
                </p>
              </div>
              {!s.is_current_device && (
                <button className="btn-ghost !py-1 text-red-600 hover:bg-red-50" onClick={() => revokeSession(s.id)}>
                  Log out
                </button>
              )}
            </div>
          ))}
        </div>
        <LogoutOthersButton onDone={loadSessions} />
      </Section>

      {/* Delete account */}
      <Section title="Delete account" description="Delete your account and all of its resources">
        <div className="space-y-4 rounded-lg border border-red-200 bg-red-50 p-4">
          <div className="text-red-700">
            <p className="font-semibold">Warning</p>
            <p className="text-sm">Please proceed with caution, this cannot be undone.</p>
          </div>
          <DeleteAccountButton onDeleted={() => navigate("/login")} />
        </div>
      </Section>
    </AccountLayout>
  );
}

/** "Log out other sessions" — password-confirmed, revokes every session but this one. */
function LogoutOthersButton({ onDone }: { onDone: () => void }) {
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const [password, setPassword] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const submit: FormEventHandler = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      await api("/settings/browser-sessions", { method: "DELETE", body: { password } });
      toast.success("Other sessions logged out");
      setOpen(false);
      setPassword("");
      onDone();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <button className="btn-secondary mt-3" onClick={() => setOpen(true)}>
        Log out other sessions
      </button>
      <Modal open={open} onClose={() => setOpen(false)} title="Log out other browser sessions">
        <p className="text-sm text-slate-600">
          Enter your password to confirm you want to log out of your other sessions across all your devices.
        </p>
        <form onSubmit={submit} className="mt-4 space-y-3">
          <ErrorText error={error} />
          <input
            className="input"
            type="password"
            placeholder="Current password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete="current-password"
            autoFocus
            required
          />
          <div className="flex justify-end gap-2">
            <button type="button" className="btn-secondary" onClick={() => setOpen(false)} disabled={busy}>
              Cancel
            </button>
            <button className="btn-primary" disabled={busy}>
              {busy ? "Working…" : "Log out other sessions"}
            </button>
          </div>
        </form>
      </Modal>
    </>
  );
}

/** "Delete account" — password-confirmed, irreversible. */
function DeleteAccountButton({ onDeleted }: { onDeleted: () => void }) {
  const [open, setOpen] = useState(false);
  const [password, setPassword] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const submit: FormEventHandler = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      await api("/settings/profile", { method: "DELETE", body: { password } });
      onDeleted();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <button className="btn-danger" onClick={() => setOpen(true)}>
        Delete account
      </button>
      <Modal open={open} onClose={() => setOpen(false)} title="Are you sure you want to delete your account?">
        <p className="text-sm text-slate-600">
          Once your account is deleted, all of its resources and data are permanently deleted. Enter your password to
          confirm.
        </p>
        <form onSubmit={submit} className="mt-4 space-y-3">
          <ErrorText error={error} />
          <input
            className="input"
            type="password"
            placeholder="Current password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete="current-password"
            autoFocus
            required
          />
          <div className="flex justify-end gap-2">
            <button type="button" className="btn-secondary" onClick={() => setOpen(false)} disabled={busy}>
              Cancel
            </button>
            <button className="btn-danger" disabled={busy}>
              {busy ? "Deleting…" : "Delete account"}
            </button>
          </div>
        </form>
      </Modal>
    </>
  );
}
