import { useState } from "react";
import { Plug, Send, MessageCircle, MessageSquare, Globe, CreditCard } from "lucide-react";
import { post, put } from "../lib/api";
import { useFetch, fmtDateTime } from "../lib/util";
import { Badge, Card, Empty, ErrorText, Field, statusColor } from "../components/ui";

type Setting = { key: string; value: string; type: string; category: string; label: string; hint?: string };
type Parsed = Setting & { parsed: unknown };
type TestResult = { status: { code: string }; error?: string; created_at: string } | null;

function parseValue(raw: string): unknown {
  try {
    return JSON.parse(raw);
  } catch {
    return raw;
  }
}

/**
 * FULL ADMINISTRATOR ONLY — deep technical settings, hidden from the Owner:
 * WhatsApp / SMS delivery credentials, Booking.com channel sync, online
 * payment gateway. Server enforces access; this page never renders for others.
 */
export default function Integrations() {
  const { data, reload } = useFetch<{ settings: Setting[] }>("/hotel-settings");
  const [error, setError] = useState("");
  const [saved, setSaved] = useState("");

  const integ: Parsed[] = (data?.settings ?? []).filter((s) => s.category === "integrations").map((s) => ({ ...s, parsed: parseValue(s.value) }));
  const byPrefix = (prefix: string) => integ.filter((s) => s.key.startsWith(`integrations.${prefix}`));

  const save = (s: Parsed, raw: string | boolean) => {
    let value: unknown = raw;
    if (s.type === "boolean" && typeof raw === "string") value = raw === "true";
    put(`/hotel-settings/${s.key}`, { value })
      .then(() => {
        setError("");
        setSaved(s.key);
        setTimeout(() => setSaved(""), 1500);
        reload();
      })
      .catch((e) => setError(`${s.label}: ${e.message}`));
  };

  const renderSetting = (s: Parsed) => {
    const isSecret = s.key.includes("token") || s.key.includes("secret") || s.key.includes("api_key");
    if (s.type === "boolean") {
      const on = s.parsed === true;
      return (
        <div key={s.key} className="flex items-center justify-between gap-3">
          <div>
            <div className="text-sm font-semibold">{s.label} {saved === s.key && <span className="text-xs text-emerald-600">saved ✓</span>}</div>
            {s.hint && <div className="text-[11px] text-slate-400">{s.hint}</div>}
          </div>
          <button
            onClick={() => save(s, !on)}
            className={`relative h-6 w-11 shrink-0 rounded-full transition ${on ? "bg-brand-600" : "bg-slate-300"}`}
            aria-label={s.label}
          >
            <span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all ${on ? "left-[22px]" : "left-0.5"}`} />
          </button>
        </div>
      );
    }
    return (
      <div key={s.key}>
        <label className="label">
          {s.label} {saved === s.key && <span className="text-emerald-600">saved ✓</span>}
        </label>
        <input
          className="input font-mono text-xs"
          type={isSecret ? "password" : "text"}
          defaultValue={String(s.parsed ?? "")}
          placeholder={isSecret ? "••••••••" : ""}
          onBlur={(e) => e.target.value !== String(s.parsed ?? "") && save(s, e.target.value)}
        />
        {s.hint && <p className="mt-0.5 text-[11px] text-slate-400">{s.hint}</p>}
      </div>
    );
  };

  const sections = [
    { prefix: "whatsapp", title: "WhatsApp (Meta Cloud API)", icon: <MessageCircle size={16} className="text-emerald-600" />, note: "Automated booking confirmations, reminders and receipts via WhatsApp." },
    { prefix: "sms", title: "SMS gateway", icon: <MessageSquare size={16} className="text-sky-600" />, note: "Works with Sri Lankan gateways (notify.lk, Dialog eSMS…) — POST { to, message, sender_id } with a Bearer key." },
    { prefix: "bookingcom", title: "Booking.com channel sync", icon: <Globe size={16} className="text-indigo-600" />, note: "Credentials stored now; live two-way sync is a future build." },
    { prefix: "gateway", title: "Online payment gateway", icon: <CreditCard size={16} className="text-amber-600" />, note: "Credentials stored now; online checkout (website deposits, LankaQR) is a future build." },
  ];

  return (
    <div className="space-y-4">
      <h1 className="flex items-center gap-2 text-xl font-extrabold">
        <Plug /> Integrations <Badge color="purple">FULL ADMIN</Badge>
      </h1>
      <p className="text-xs text-slate-500">
        Technical configuration only visible to Full Administrators — the Owner manages business settings, not credentials.
        Changes take effect immediately; secrets are never written to the audit log.
      </p>
      <ErrorText error={error} />
      {integ.length === 0 && <Empty text="Loading…" />}

      <div className="grid gap-4 lg:grid-cols-2">
        {sections.map((sec) => (
          <Card key={sec.prefix} title={<span className="flex items-center gap-2">{sec.icon} {sec.title}</span>}>
            <p className="mb-3 text-[11px] text-slate-400">{sec.note}</p>
            <div className="space-y-3">{byPrefix(sec.prefix).map(renderSetting)}</div>
          </Card>
        ))}
      </div>

      <TestSend />
    </div>
  );
}

function TestSend() {
  const [channel, setChannel] = useState<"whatsapp" | "sms" | "email">("whatsapp");
  const [to, setTo] = useState("");
  const [result, setResult] = useState<TestResult>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  return (
    <Card title="Send a test message">
      <div className="flex flex-wrap items-end gap-2">
        <Field label="Channel">
          <select className="input !w-40" value={channel} onChange={(e) => setChannel(e.target.value as never)}>
            <option value="whatsapp">WHATSAPP</option>
            <option value="sms">SMS</option>
            <option value="email">EMAIL</option>
          </select>
        </Field>
        <Field label={channel === "email" ? "Email address" : "Phone (with country code)"}>
          <input className="input !w-64" placeholder={channel === "email" ? "you@example.com" : "+94 7X XXX XXXX"} value={to} onChange={(e) => setTo(e.target.value)} />
        </Field>
        <button
          className="btn-primary"
          disabled={busy || to.trim().length < 3}
          onClick={async () => {
            setBusy(true);
            setError("");
            setResult(null);
            try {
              const r = await post<{ notification: TestResult }>("/notifications/test", { channel, to: to.trim() });
              setResult(r.notification);
            } catch (e) {
              setError((e as Error).message);
            } finally {
              setBusy(false);
            }
          }}
        >
          <Send size={15} /> {busy ? "Sending…" : "Send test"}
        </button>
      </div>
      <ErrorText error={error} />
      {result && (
        <div className="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-sm">
          <Badge color={statusColor(result.status.code)}>{result.status.code.toUpperCase()}</Badge>{" "}
          <span className="text-xs text-slate-500">{fmtDateTime(result.created_at)}</span>
          {result.error && <div className={`mt-1 text-xs ${result.status.code === "failed" ? "text-red-600" : "text-amber-700"}`}>{result.error}</div>}
          {!result.error && result.status.code === "sent" && <div className="mt-1 text-xs text-emerald-700">Delivered via the live provider ✓</div>}
        </div>
      )}
    </Card>
  );
}
