import { useState } from "react";
import { post, api } from "../lib/api";
import { usePagedFetch, fmtDateTime } from "../lib/util";
import { Badge, Card, Empty, Pagination, statusColor } from "../components/ui";
import { useAuth } from "../lib/auth";

type Lookup = { id: number; code: string; name: string; color: string | null };
type Notif = {
  id: number;
  type: string;
  channel: Lookup;
  to: string;
  subject: string;
  body: string;
  status: Lookup;
  created_at: string;
  sent_at?: string | null;
  error?: string | null;
  ref_type?: string | null;
  ref_id?: number | null;
};

export default function Notifications() {
  const { can } = useAuth();
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const { data, reload } = usePagedFetch<Notif>(`/notifications?page=${page}&page_size=${pageSize}`, "notifications", [page, pageSize]);
  const rows = data?.rows;
  const [fulfillModal, setFulfillModal] = useState<{ open: boolean; notification: Notif | null }>({ open: false, notification: null });
  const [paymentMethod, setPaymentMethod] = useState("CASH");
  const [reference, setReference] = useState("");
  const [fulfilling, setFulfilling] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleFulfill = async () => {
    if (!fulfillModal.notification) return;
    setFulfilling(true);
    setError(null);
    try {
      console.log("Fulfilling payment", {
        notification_id: fulfillModal.notification.id,
        payment_method: paymentMethod,
        reference: reference || null,
      });
      await post("/money/fulfill-credit-payment", {
        notification_id: fulfillModal.notification.id,
        payment_method: paymentMethod,
        reference: reference || null,
      });
      setFulfillModal({ open: false, notification: null });
      setPaymentMethod("CASH");
      setReference("");
      reload();
      // Trigger counter refresh
      window.dispatchEvent(new CustomEvent('credit-payments-updated'));
    } catch (err: any) {
      console.error("Failed to fulfill credit payment", err);
      const errorMsg = err?.message || "Failed to fulfill payment";
      if (err?.errors) {
        setError(JSON.stringify(err.errors));
      } else {
        setError(errorMsg);
      }
    } finally {
      setFulfilling(false);
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-extrabold">Notifications</h1>
      </div>
      <p className="text-xs text-slate-500">
        Email &amp; WhatsApp delivery is <b>stubbed in Phase 1</b> (messages are composed, logged and marked sent by the console provider).
        TODO Phase 2: plug real SMTP / WhatsApp Business API in <code>apps/api/src/lib/notify.ts</code> — templates and triggers are already live.
      </p>
      <Card title="Message log">
        <div className="space-y-2">
          {(rows ?? []).map((n) => (
            <div key={n.id} className="rounded-lg border border-slate-100 p-3 text-sm">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                  <Badge color={statusColor(n.status.code.toUpperCase())}>{n.status.name}</Badge>
                  <Badge color={n.channel.code === "email" ? "blue" : "green"}>{n.channel.name}</Badge>
                  <span className="font-bold">{n.type}</span>
                  <span className="text-xs text-slate-400">→ {n.to} · {fmtDateTime(n.created_at)}</span>
                </div>
                {n.type === "credit_payment" && n.ref_type === "reservation" && (
                  <div className="flex gap-2">
                    <button
                      className="btn-sm btn-primary"
                      onClick={() => {
                        if (n.ref_id) {
                          const tenantSlug = window.location.pathname.split('/')[1];
                          window.location.href = `/${tenantSlug}/reservations/${n.ref_id}?from=notifications`;
                        }
                      }}
                    >
                      View Reservation
                    </button>
                    <button
                      className="btn-sm btn-secondary"
                      onClick={() => {
                        console.log("Fulfill button clicked", n);
                        setFulfillModal({ open: true, notification: n });
                      }}
                    >
                      Fulfill
                    </button>
                  </div>
                )}
              </div>
              <div className="mt-1 font-semibold">{n.subject}</div>
              <div className="text-xs text-slate-500">{n.body}</div>
            </div>
          ))}
          {(rows ?? []).length === 0 && <Empty text="No notifications yet" />}
          {data && <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPage={setPage} onPageSize={(n) => { setPageSize(n); setPage(1); }} />}
        </div>
      </Card>

      {fulfillModal.open && fulfillModal.notification && (
        <div
          className="fixed inset-0 bg-black/50 flex items-center justify-center z-[9999]"
          onClick={() => setFulfillModal({ open: false, notification: null })}
        >
          <div
            className="bg-white rounded-lg p-6 w-full max-w-md shadow-xl"
            onClick={(e) => e.stopPropagation()}
          >
            <h2 className="text-lg font-bold mb-4">Fulfill Credit Payment</h2>
            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium mb-1">Reservation</label>
                <div className="text-sm text-gray-600">{fulfillModal.notification.body}</div>
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Payment Method</label>
                <select
                  className="input"
                  value={paymentMethod}
                  onChange={(e) => setPaymentMethod(e.target.value)}
                >
                  <option value="CASH">Cash</option>
                  <option value="CARD">Card</option>
                  <option value="LANKAQR">LankaQR</option>
                  <option value="BANK_TRANSFER">Bank Transfer</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Reference (optional)</label>
                <input
                  className="input"
                  type="text"
                  value={reference}
                  onChange={(e) => setReference(e.target.value)}
                  placeholder="Receipt number, transaction ID, etc."
                />
              </div>
            </div>
            {error && <div className="text-sm text-red-600 mt-2">{error}</div>}
            <div className="flex justify-end gap-2 mt-6">
              <button
                className="btn-secondary"
                onClick={() => setFulfillModal({ open: false, notification: null })}
                disabled={fulfilling}
              >
                Cancel
              </button>
              <button
                className="btn-primary"
                onClick={handleFulfill}
                disabled={fulfilling}
              >
                {fulfilling ? "Processing..." : "Fulfill Payment"}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
