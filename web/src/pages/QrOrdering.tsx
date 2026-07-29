import { useState } from "react";
import { QrCode, Copy, Check } from "lucide-react";
import { post, put, API_ORIGIN } from "../lib/api";
import { useFetch } from "../lib/util";
import { Badge, ConfirmDialog, Empty, ErrorText, Modal, Tabs } from "../components/ui";
import { useAuth } from "../lib/auth";

type QrInfo = { id: number; token: string; enabled: boolean; url: string; created_at: string | null } | null;
type RoomRow = { id: number; number: string; room_type: string | null; qr: QrInfo };
type TableRow = { id: number; table_no: string; area: string | null; qr: QrInfo };
type Active = { label: string; qr: NonNullable<QrInfo> };

/** Admin management of QR ordering — generate/regenerate the per-room and per-table guest ordering links (§ coding_principles "Data Table Standards", small bounded dataset — same card-grid convention as Tables.tsx, not a paginated table). */
export default function QrOrdering() {
  const { can } = useAuth();
  const [tab, setTab] = useState<"rooms" | "tables">("rooms");
  const { data, reload } = useFetch<{ rooms: RoomRow[]; tables: TableRow[] }>("/qr-ordering");
  const [active, setActive] = useState<Active | null>(null);
  const [error, setError] = useState("");
  const canCreate = can("hotel_qr_ordering.create");

  const generate = (body: { room_id: number } | { dining_table_id: number }) =>
    post("/qr-ordering", body).then(reload).catch((e) => setError(e.message));

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="flex items-center gap-2 text-xl font-extrabold"><QrCode /> QR Ordering</h1>
        <Tabs tabs={[{ id: "rooms" as const, label: "Rooms" }, { id: "tables" as const, label: "Tables" }]} active={tab} onChange={setTab} />
      </div>
      <p className="text-xs text-slate-500">
        Generate a QR code for a room or restaurant table — guests scan it to browse the menu and order from their
        phone. Room orders bill to the guest&apos;s room; table orders land on that table&apos;s bill.
      </p>
      <ErrorText error={error} />

      {tab === "rooms" && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
          {(data?.rooms ?? []).map((r) => (
            <PointCard
              key={r.id}
              label={`Room ${r.number}`}
              sub={r.room_type}
              qr={r.qr}
              canCreate={canCreate}
              onGenerate={() => generate({ room_id: r.id })}
              onView={() => r.qr && setActive({ label: `Room ${r.number}`, qr: r.qr })}
            />
          ))}
          {data && data.rooms.length === 0 && <Empty text="No rooms yet" />}
        </div>
      )}
      {tab === "tables" && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
          {(data?.tables ?? []).map((t) => (
            <PointCard
              key={t.id}
              label={t.table_no}
              sub={t.area}
              qr={t.qr}
              canCreate={canCreate}
              onGenerate={() => generate({ dining_table_id: t.id })}
              onView={() => t.qr && setActive({ label: `Table ${t.table_no}`, qr: t.qr })}
            />
          ))}
          {data && data.tables.length === 0 && <Empty text="No dining tables yet" />}
        </div>
      )}

      {active && <QrDetailModal active={active} onClose={() => setActive(null)} onChanged={reload} />}
    </div>
  );
}

function PointCard({
  label, sub, qr, canCreate, onGenerate, onView,
}: {
  label: string; sub?: string | null; qr: QrInfo; canCreate: boolean; onGenerate: () => void; onView: () => void;
}) {
  return (
    <div className="card p-3">
      <div className="text-lg font-black">{label}</div>
      {sub && <div className="truncate text-xs text-slate-500">{sub}</div>}
      {qr ? (
        <div className="mt-2 space-y-1.5">
          <Badge color={qr.enabled ? "green" : "slate"}>{qr.enabled ? "ENABLED" : "DISABLED"}</Badge>
          <button className="btn-secondary w-full !py-1.5 text-xs" onClick={onView}>View QR</button>
        </div>
      ) : canCreate ? (
        <button className="btn-primary mt-2 w-full !py-1.5 text-xs" onClick={onGenerate}>Generate QR</button>
      ) : (
        <div className="mt-2 text-xs text-slate-400">Not generated</div>
      )}
    </div>
  );
}

function QrDetailModal({ active, onClose, onChanged }: { active: Active; onClose: () => void; onChanged: () => void }) {
  const { can } = useAuth();
  const { label, qr } = active;
  const canEdit = can("hotel_qr_ordering.edit");
  const canRegenerate = can("hotel_qr_ordering.regenerate");
  const [copied, setCopied] = useState(false);
  const [confirmRegen, setConfirmRegen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const copy = () => {
    navigator.clipboard.writeText(qr.url).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    });
  };

  const toggle = () =>
    put(`/qr-ordering/${qr.id}`, { enabled: !qr.enabled }).then(onChanged).catch((e) => setError(e.message));

  const regenerate = async () => {
    setBusy(true);
    setError("");
    try {
      await post(`/qr-ordering/${qr.id}/regenerate`);
      setConfirmRegen(false);
      onChanged();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open onClose={onClose} title={`${label} — QR code`}>
      <div className="flex flex-col items-center gap-3">
        <img
          src={`${API_ORIGIN}/api/qr-ordering/${qr.id}/image`}
          alt={`QR code for ${label}`}
          className="h-52 w-52 rounded-lg border border-slate-100 bg-white p-2"
        />
        <div className="flex w-full items-center gap-2">
          <input className="input flex-1 !text-xs" readOnly value={qr.url} onFocus={(e) => e.target.select()} />
          <button className="btn-secondary !py-2" onClick={copy} aria-label="Copy link">
            {copied ? <Check size={14} /> : <Copy size={14} />}
          </button>
        </div>
        <ErrorText error={error} />
        <div className="flex w-full items-center justify-between gap-2 border-t border-slate-100 pt-3">
          <button
            disabled={!canEdit}
            onClick={toggle}
            className="flex items-center gap-2 text-sm font-semibold disabled:opacity-50"
          >
            <span className={`relative h-6 w-11 rounded-full transition ${qr.enabled ? "bg-brand-600" : "bg-slate-300"}`}>
              <span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all ${qr.enabled ? "left-[22px]" : "left-0.5"}`} />
            </span>
            {qr.enabled ? "Enabled" : "Disabled"}
          </button>
          {canRegenerate && (
            <button className="btn-secondary !py-1.5 text-xs" onClick={() => setConfirmRegen(true)}>Regenerate</button>
          )}
        </div>
      </div>
      <ConfirmDialog
        open={confirmRegen}
        title="Regenerate QR code?"
        message="The old printed QR code stops working the moment you do this — anyone still holding it (or a sticker at the table/room) won't be able to order until it's replaced with the new one."
        confirmLabel="Regenerate"
        tone="danger"
        busy={busy}
        onConfirm={regenerate}
        onClose={() => setConfirmRegen(false)}
      />
    </Modal>
  );
}
