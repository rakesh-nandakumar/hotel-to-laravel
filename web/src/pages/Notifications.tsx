import { useState } from "react";
import { post } from "../lib/api";
import { usePagedFetch, fmtDateTime } from "../lib/util";
import { Badge, Card, Empty, Pagination, statusColor } from "../components/ui";

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
};

export default function Notifications() {
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const { data, reload } = usePagedFetch<Notif>(`/notifications?page=${page}&page_size=${pageSize}`, "notifications", [page, pageSize]);
  const rows = data?.rows;
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-extrabold">Notifications</h1>
        <button className="btn-secondary" onClick={() => post("/notifications/run-scheduled").then(reload)}>Run scheduled reminders now</button>
      </div>
      <p className="text-xs text-slate-500">
        Email &amp; WhatsApp delivery is <b>stubbed in Phase 1</b> (messages are composed, logged and marked sent by the console provider).
        TODO Phase 2: plug real SMTP / WhatsApp Business API in <code>apps/api/src/lib/notify.ts</code> — templates and triggers are already live.
      </p>
      <Card title="Message log">
        <div className="space-y-2">
          {(rows ?? []).map((n) => (
            <div key={n.id} className="rounded-lg border border-slate-100 p-3 text-sm">
              <div className="flex flex-wrap items-center gap-2">
                <Badge color={statusColor(n.status.code.toUpperCase())}>{n.status.name}</Badge>
                <Badge color={n.channel.code === "email" ? "blue" : "green"}>{n.channel.name}</Badge>
                <span className="font-bold">{n.type}</span>
                <span className="text-xs text-slate-400">→ {n.to} · {fmtDateTime(n.created_at)}</span>
              </div>
              <div className="mt-1 font-semibold">{n.subject}</div>
              <div className="text-xs text-slate-500">{n.body}</div>
              {n.error && <div className="text-xs text-red-600">{n.error}</div>}
            </div>
          ))}
          {(rows ?? []).length === 0 && <Empty text="No notifications yet" />}
          {data && <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPage={setPage} onPageSize={(n) => { setPageSize(n); setPage(1); }} />}
        </div>
      </Card>
    </div>
  );
}
