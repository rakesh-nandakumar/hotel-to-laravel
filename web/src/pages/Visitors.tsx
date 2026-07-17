import { useState } from "react";
import { Plus } from "lucide-react";
import { post } from "../lib/api";
import { usePagedFetch, fmtDateTime } from "../lib/util";
import { Badge, Card, Empty, ErrorText, Pagination } from "../components/ui";

type Log = {
  id: number; name: string; vehicle_no?: string | null; purpose?: string | null; time_in: string; time_out?: string | null;
  logged_by: { id: number; name: string };
};

export default function Visitors() {
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const { data, reload } = usePagedFetch<Log>(`/visitors?page=${page}&page_size=${pageSize}`, "visitors", [page, pageSize]);
  const logs = data?.rows;
  const [f, setF] = useState({ name: "", vehicleNo: "", purpose: "" });
  const [error, setError] = useState("");

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-extrabold">Visitor & Vehicle Log</h1>
      <p className="text-xs text-slate-500">Security role — no financial access. Guest parking capacity is set in Settings (default 10 vehicles).</p>
      <Card title="Sign in a visitor / vehicle">
        <div className="flex flex-wrap gap-2">
          <input className="input !w-52" placeholder="Visitor name *" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
          <input className="input !w-40" placeholder="Vehicle no." value={f.vehicleNo} onChange={(e) => setF({ ...f, vehicleNo: e.target.value })} />
          <input className="input !w-52" placeholder="Purpose" value={f.purpose} onChange={(e) => setF({ ...f, purpose: e.target.value })} />
          <button
            className="btn-primary"
            disabled={!f.name.trim()}
            onClick={() =>
              post("/visitors", { name: f.name.trim(), vehicle_no: f.vehicleNo || undefined, purpose: f.purpose || undefined })
                .then(() => { setF({ name: "", vehicleNo: "", purpose: "" }); reload(); })
                .catch((e) => setError(e.message))
            }
          >
            <Plus size={15} /> Sign in
          </button>
        </div>
        <ErrorText error={error} />
      </Card>
      <Card title="Log">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px]">
            <thead className="border-b border-slate-100">
              <tr><th className="th">Visitor</th><th className="th">Vehicle</th><th className="th">Purpose</th><th className="th">In</th><th className="th">Out</th><th className="th">By</th><th className="th" /></tr>
            </thead>
            <tbody className="divide-y divide-slate-50">
              {(logs ?? []).map((l) => (
                <tr key={l.id}>
                  <td className="td font-semibold">{l.name}</td>
                  <td className="td">{l.vehicle_no ?? "—"}</td>
                  <td className="td text-xs">{l.purpose ?? "—"}</td>
                  <td className="td text-xs">{fmtDateTime(l.time_in)}</td>
                  <td className="td text-xs">{l.time_out ? fmtDateTime(l.time_out) : <Badge color="green">on site</Badge>}</td>
                  <td className="td text-xs text-slate-400">{l.logged_by.name}</td>
                  <td className="td text-right">
                    {!l.time_out && (
                      <button className="btn-secondary !py-1 text-xs" onClick={() => post(`/visitors/${l.id}/out`).then(reload)}>Sign out</button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {(logs ?? []).length === 0 && <Empty text="No visitors logged" />}
          {data && <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPage={setPage} onPageSize={(n) => { setPageSize(n); setPage(1); }} />}
        </div>
      </Card>
    </div>
  );
}
