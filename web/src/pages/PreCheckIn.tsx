import { useEffect, useState } from "react";
import { api, post } from "../lib/api";
import { ErrorText, Field } from "../components/ui";

type Branding = { name: string; check_in_time: string };

/** Public online pre-check-in — guest submits details before arrival (§4.1). */
export default function PreCheckIn() {
  const [brand, setBrand] = useState<Branding | null>(null);
  const [f, setF] = useState({ code: "", full_name: "", id_number: "", phone: "", email: "", nationality: "", eta: "", notes: "" });
  const [done, setDone] = useState(false);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api<Branding>("/public/branding").then(setBrand).catch(() => {});
  }, []);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      // Backend returns a plain 404 with a deliberately vague message when the
      // code doesn't match or the booking isn't awaiting arrival — surfaced
      // via ApiFail.message the same way as any other error here.
      await post<{ ok: boolean; message: string }>("/public/pre-checkin", f);
      setDone(true);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-brand-900 p-4">
      <div className="card w-full max-w-lg p-6">
        <h1 className="text-xl font-black">{brand?.name ?? "Mount View Hotel"}</h1>
        <p className="mb-4 text-sm text-slate-500">Online pre-check-in — save time at the front desk. Check-in from {brand?.check_in_time ?? "14:00"}.</p>
        {done ? (
          <div className="rounded-xl bg-emerald-50 p-6 text-center">
            <div className="text-3xl">✅</div>
            <p className="mt-2 font-bold text-emerald-800">Pre-check-in received — see you soon!</p>
          </div>
        ) : (
          <form onSubmit={submit} className="space-y-3">
            <Field label="Booking code (from your confirmation) *">
              <input className="input" value={f.code} onChange={(e) => setF({ ...f, code: e.target.value })} placeholder="RSV-0002" required />
            </Field>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="Full name *"><input className="input" value={f.full_name} onChange={(e) => setF({ ...f, full_name: e.target.value })} required /></Field>
              <Field label="ID / passport number *"><input className="input" value={f.id_number} onChange={(e) => setF({ ...f, id_number: e.target.value })} required /></Field>
              <Field label="Phone (WhatsApp)"><input className="input" value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} /></Field>
              <Field label="Email"><input className="input" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} /></Field>
              <Field label="Nationality"><input className="input" value={f.nationality} onChange={(e) => setF({ ...f, nationality: e.target.value })} /></Field>
              <Field label="Estimated arrival time"><input className="input" type="time" value={f.eta} onChange={(e) => setF({ ...f, eta: e.target.value })} /></Field>
            </div>
            <Field label="Special requests"><textarea className="input" rows={2} value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} /></Field>
            <ErrorText error={error} />
            <button className="btn-primary w-full !py-3" disabled={busy}>{busy ? "Submitting…" : "Submit pre-check-in"}</button>
          </form>
        )}
      </div>
    </div>
  );
}
