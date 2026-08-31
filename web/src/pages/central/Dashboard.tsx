import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { api } from "../../lib/api";
import { tenantUrl } from "../../lib/tenancy";
import { Card, Badge, SimpleTable, Stat, statusColor } from "../../components/ui";

type Dashboard = {
  counts: {
    total: number;
    by_status: { trial: number; active: number; suspended: number; cancelled: number };
    by_environment: { live: number; test: number };
    admins: number;
    users: number;
  };
  recent_tenants: {
    id: number;
    name: string;
    slug: string;
    status: string;
    environment: string;
    users_count: number;
  }[];
};

export default function CentralDashboard() {
  const [data, setData] = useState<Dashboard | null>(null);

  useEffect(() => {
    api<Dashboard>("/central/dashboard").then(setData);
  }, []);

  if (!data) return <p className="py-8 text-center text-sm text-slate-400">Loading…</p>;

  return (
    <div className="space-y-4">
      <h1 className="text-lg font-black text-slate-900">Dashboard</h1>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Stat label="Tenants" value={data.counts.total} />
        <Stat label="Live / Test" value={`${data.counts.by_environment.live} / ${data.counts.by_environment.test}`} />
        <Stat label="Platform users" value={data.counts.users} />
      </div>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Stat label="Trial" value={data.counts.by_status.trial} color="sky" />
        <Stat label="Active" value={data.counts.by_status.active} color="emerald" />
        <Stat label="Suspended" value={data.counts.by_status.suspended} color="amber" />
        <Stat label="Cancelled" value={data.counts.by_status.cancelled} color="slate" />
      </div>

      <Card title="Recent tenants">
        <SimpleTable
          columns={[
            {
              key: "name",
              label: "Business",
              render: (t) => (
                <Link to={`/tenants/${t.id}`} className="font-semibold text-brand-600 hover:underline">
                  {t.name}
                </Link>
              ),
            },
            { key: "slug", label: "URL", render: (t) => <span className="text-slate-500">{tenantUrl(t.slug)}</span> },
            { key: "status", label: "Status", render: (t) => <Badge color={statusColor(t.status)}>{t.status}</Badge> },
            { key: "environment", label: "Env", render: (t) => <Badge color={t.environment === "test" ? "purple" : "slate"}>{t.environment}</Badge> },
            { key: "users_count", label: "Users", align: "right" },
          ]}
          rows={data.recent_tenants}
          rowKey={(t) => t.id}
          empty="No tenants yet"
        />
      </Card>
    </div>
  );
}