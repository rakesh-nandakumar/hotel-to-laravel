import { useState } from "react";
import { Clock4, Download } from "lucide-react";
import { post, API_ORIGIN } from "../lib/api";
import { useFetch, usePagedFetch, fmtDateTime } from "../lib/util";
import { Badge, Card, Empty, ErrorText, Pagination } from "../components/ui";
import { useAuth } from "../lib/auth";

type Att = {
  id: number;
  clock_in: string;
  clock_out: string | null;
  hours?: number | null;
  user?: { name: string; roles: { name: string }[] };
};

export default function Attendance() {
  const { can } = useAuth();
  const isManager = can("hotel_attendance.view_all");
  const canClock = can("hotel_attendance.on_duty");
  const { data: mineData, reload: reloadMine } = useFetch<{ attendance: Att[] }>("/attendance/me");
  const mine = mineData?.attendance;
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const { data: allPaged, reload: reloadAll } = usePagedFetch<Att>(
    isManager ? `/attendance?month=${month}&page=${page}&page_size=${pageSize}` : null,
    "attendance",
    [month, page, pageSize],
  );
  const all = allPaged?.rows;
  const [error, setError] = useState("");

  const open = (mine ?? []).find((a) => !a.clock_out);
  const act = (path: string) =>
    post(path)
      .then(() => {
        setError("");
        reloadMine();
        if (isManager) reloadAll();
      })
      .catch((e) => setError(e.message));

  const exportCsv = async () => {
    const res = await fetch(`${API_ORIGIN}/api/attendance/export?month=${month}`, { credentials: "include", headers: { Accept: "text/csv" } });
    if (!res.ok) {
      setError("Could not export CSV");
      return;
    }
    const blob = await res.blob();
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = `attendance-${month}.csv`;
    a.click();
  };

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-extrabold">Staff Attendance</h1>
      <Card title="My clock">
        <div className="flex flex-wrap items-center gap-3 text-sm">
          {open ? (
            <>
              <Badge color="green">CLOCKED IN since {fmtDateTime(open.clock_in)}</Badge>
              {canClock && <button className="btn-danger" onClick={() => act("/attendance/clock-out")}><Clock4 size={15} /> Clock out</button>}
            </>
          ) : (
            <>
              <Badge>Off the clock</Badge>
              {canClock && <button className="btn-primary" onClick={() => act("/attendance/clock-in")}><Clock4 size={15} /> Clock in</button>}
            </>
          )}
        </div>
        <ErrorText error={error} />
        <div className="mt-3 divide-y divide-slate-50 text-sm">
          {(mine ?? []).slice(0, 7).map((a) => (
            <div key={a.id} className="flex justify-between py-1.5">
              <span>{fmtDateTime(a.clock_in)} → {a.clock_out ? fmtDateTime(a.clock_out) : "…"}</span>
              <span className="text-slate-500">{a.clock_out ? `${(((+new Date(a.clock_out)) - (+new Date(a.clock_in))) / 3600000).toFixed(1)} h` : ""}</span>
            </div>
          ))}
        </div>
      </Card>

      {isManager && (
        <Card
          title="All staff hours (payroll reference export — system does not run payroll)"
          actions={
            <div className="flex gap-2">
              <input type="month" className="input !w-40 !py-1" value={month} onChange={(e) => { setMonth(e.target.value); setPage(1); }} />
              {can("hotel_attendance.export") && <button className="btn-secondary !py-1" onClick={exportCsv}><Download size={14} /> CSV</button>}
            </div>
          }
        >
          <div className="overflow-x-auto">
            <table className="w-full min-w-[560px]">
              <thead className="border-b border-slate-100">
                <tr><th className="th">Staff</th><th className="th">Role</th><th className="th">In</th><th className="th">Out</th><th className="th text-right">Hours</th></tr>
              </thead>
              <tbody className="divide-y divide-slate-50">
                {(all ?? []).map((a) => (
                  <tr key={a.id}>
                    <td className="td font-semibold">{a.user?.name}</td>
                    <td className="td text-xs">{a.user?.roles?.map((r) => r.name).join(", ")}</td>
                    <td className="td text-xs">{fmtDateTime(a.clock_in)}</td>
                    <td className="td text-xs">{a.clock_out ? fmtDateTime(a.clock_out) : <Badge color="green">on duty</Badge>}</td>
                    <td className="td text-right">{a.hours ?? "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {(all ?? []).length === 0 && <Empty text="No attendance this month" />}
            {allPaged && <Pagination page={allPaged.page} pageSize={allPaged.pageSize} total={allPaged.total} onPage={setPage} onPageSize={(n) => { setPageSize(n); setPage(1); }} />}
          </div>
        </Card>
      )}
    </div>
  );
}
