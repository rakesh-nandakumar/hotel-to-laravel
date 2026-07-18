import { useEffect, useState } from "react";
import { api, post } from "../lib/api";
import { useBranding } from "../lib/branding";
import { ErrorText, Field } from "../components/ui";
import { lkr, todayStr } from "../lib/util";

type Venue = { id: number; name: string; max_capacity: number; facilities: string[] | null; hourly_rate: number; half_day_rate: number; full_day_rate: number };

/**
 * Public venue inquiry form — open to outside customers (§4.5). This is a
 * lead-capture form, not a real booking: the backend hardcodes a full-day
 * duration and records an INQUIRY, with no rental fee/deposit/folio charge
 * for the frontend to collect or display.
 */
export default function VenueInquiry() {
  const { branding } = useBranding();
  const [venues, setVenues] = useState<Venue[]>([]);
  const [f, setF] = useState({ venue_id: "", client_name: "", client_phone: "", client_email: "", event_type: "Wedding", date: todayStr(30), guest_count: "100", notes: "" });
  const [ref, setRef] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const venue = venues.find((v) => String(v.id) === f.venue_id);

  useEffect(() => {
    api<Venue[]>("/public/venues").then(setVenues).catch(() => {});
  }, []);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      const data = await post<{ ok: boolean; reference: string; message: string }>("/public/venue-inquiry", {
        venue_id: Number(f.venue_id),
        client_name: f.client_name,
        client_phone: f.client_phone,
        client_email: f.client_email || undefined,
        event_type: f.event_type || undefined,
        date: f.date,
        guest_count: parseInt(f.guest_count) || 1,
        notes: f.notes || undefined,
      });
      setRef(data.reference);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-brand-900 p-4">
      <div className="card w-full max-w-lg p-6">
        <h1 className="text-xl font-black">{branding.name} — Events</h1>
        <p className="mb-4 text-sm text-slate-500">Wedding halls & rooftop venue inquiry. Venue rental is separate from catering — you're welcome to bring your own chefs.</p>
        {ref ? (
          <div className="rounded-xl bg-emerald-50 p-6 text-center">
            <div className="text-3xl">🎉</div>
            <p className="mt-2 font-bold text-emerald-800">Inquiry received! Reference: {ref}</p>
            <p className="text-sm text-emerald-700">Our events team will contact you shortly.</p>
          </div>
        ) : (
          <form onSubmit={submit} className="space-y-3">
            <Field label="Venue *">
              <select className="input" value={f.venue_id} onChange={(e) => setF({ ...f, venue_id: e.target.value })} required>
                <option value="">Select a venue…</option>
                {venues.map((v) => (
                  <option key={v.id} value={v.id}>{v.name} — seats {v.max_capacity}</option>
                ))}
              </select>
            </Field>
            {venue && (
              <div className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                From {lkr(venue.hourly_rate)}/hour · half-day {lkr(venue.half_day_rate)} · full-day {lkr(venue.full_day_rate)}
                {venue.facilities && venue.facilities.length > 0 && <> · {venue.facilities.join(", ")}</>}
              </div>
            )}
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="Your name *"><input className="input" value={f.client_name} onChange={(e) => setF({ ...f, client_name: e.target.value })} required /></Field>
              <Field label="Phone *"><input className="input" value={f.client_phone} onChange={(e) => setF({ ...f, client_phone: e.target.value })} required /></Field>
              <Field label="Email"><input className="input" value={f.client_email} onChange={(e) => setF({ ...f, client_email: e.target.value })} /></Field>
              <Field label="Event type">
                <select className="input" value={f.event_type} onChange={(e) => setF({ ...f, event_type: e.target.value })}>
                  {["Wedding", "Birthday party", "Corporate event", "Other"].map((t) => <option key={t}>{t}</option>)}
                </select>
              </Field>
              <Field label="Preferred date *"><input type="date" className="input" value={f.date} onChange={(e) => setF({ ...f, date: e.target.value })} required /></Field>
              <Field label="Expected guests *"><input className="input" inputMode="numeric" value={f.guest_count} onChange={(e) => setF({ ...f, guest_count: e.target.value })} required /></Field>
            </div>
            <Field label="Tell us about your event"><textarea className="input" rows={2} value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} /></Field>
            <ErrorText error={error} />
            <button className="btn-primary w-full !py-3" disabled={busy}>{busy ? "Sending…" : "Send inquiry"}</button>
          </form>
        )}
      </div>
    </div>
  );
}
