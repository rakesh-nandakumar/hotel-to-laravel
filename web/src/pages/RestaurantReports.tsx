import { useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import {
  BarChart3, ChevronLeft, Download, ShoppingCart, ClipboardList, Layers,
  Percent, Grid2x2, Bike, Timer, Wallet, DollarSign,
} from "lucide-react";
import { openPdf } from "../lib/api";
import { useFetch, lkr, todayStr, fmtDateTime, downloadCsv } from "../lib/util";
import { Badge, Card, Empty, ErrorText, ReportGrid, ReportDef, SimpleTable, DateRangeBar, Stat } from "../components/ui";
import { useAuth } from "../lib/auth";
import { useBranding } from "../lib/branding";

const RESTAURANT_REPORTS: ReportDef[] = [
  { key: "pos", label: "POS Sales", description: "Category & payment-method breakdown, best sellers, date range.", icon: <ShoppingCart size={18} />, permission: "restaurant_reports.pos" },
  { key: "menu_performance", label: "Menu Item Performance", description: "Revenue & qty per item, category mix, slow movers.", icon: <ClipboardList size={18} />, permission: "restaurant_reports.menu_performance" },
  { key: "modifiers", label: "Modifier Attach-Rate", description: "Add-on/upsell usage and the revenue it contributes.", icon: <Layers size={18} />, permission: "restaurant_reports.modifiers" },
  { key: "discounts_voids", label: "Discounts & Voids", description: "Discount totals by reason/authorizer, void rate & reasons.", icon: <Percent size={18} />, permission: "restaurant_reports.discounts_voids" },
  { key: "table_server", label: "Table & Server Performance", description: "Sales per table/area and per server.", icon: <Grid2x2 size={18} />, permission: "restaurant_reports.table_server" },
  { key: "delivery", label: "Delivery Performance", description: "Orders/revenue by rider, status funnel, fulfillment time.", icon: <Bike size={18} />, permission: "restaurant_reports.delivery_performance" },
  { key: "kitchen_ticket_time", label: "Kitchen Ticket Time", description: "Avg prep & pickup time by kitchen station.", icon: <Timer size={18} />, permission: "restaurant_reports.kitchen_ticket_time" },
  { key: "shift_sales", label: "Shift Sales Drill-down", description: "Payments & cash variance per shift, date range.", icon: <Wallet size={18} />, permission: "restaurant_reports.shift_sales" },
  { key: "food_cost", label: "Food Cost %", description: "Theoretical recipe cost vs. price per menu item.", icon: <DollarSign size={18} />, permission: "restaurant_reports.food_cost" },
];

export default function RestaurantReports() {
  const { can } = useAuth();
  const nav = useNavigate();
  const { reportKey } = useParams<{ reportKey: string }>();
  const visible = RESTAURANT_REPORTS.filter((r) => !r.permission || can(r.permission));
  const def = visible.find((r) => r.key === reportKey);

  if (!reportKey) {
    return (
      <div className="space-y-4">
        <h1 className="flex items-center gap-2 text-xl font-extrabold"><BarChart3 /> Restaurant Reports</h1>
        <ReportGrid reports={visible} onSelect={(key) => nav(`/restaurant/reports/${key}`)} />
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <button className="btn-ghost text-sm" onClick={() => nav("/restaurant/reports")}><ChevronLeft size={14} /> All reports</button>
      {!def ? (
        <ErrorText error="You don't have access to this report." />
      ) : (
        <>
          <h1 className="flex items-center gap-2 text-xl font-extrabold">{def.icon} {def.label}</h1>
          {reportKey === "pos" && <PosTab />}
          {reportKey === "menu_performance" && <MenuPerformanceTab />}
          {reportKey === "modifiers" && <ModifiersTab />}
          {reportKey === "discounts_voids" && <DiscountsVoidsTab />}
          {reportKey === "table_server" && <TableServerTab />}
          {reportKey === "delivery" && <DeliveryTab />}
          {reportKey === "kitchen_ticket_time" && <KitchenTicketTimeTab />}
          {reportKey === "shift_sales" && <ShiftSalesTab />}
          {reportKey === "food_cost" && <FoodCostTab />}
        </>
      )}
    </div>
  );
}

const RANGE_PRESETS = [
  { label: "7 days", days: 6 },
  { label: "14 days", days: 13 },
  { label: "30 days", days: 29 },
];

function useDateRange() {
  const [from, setFrom] = useState(todayStr(-29));
  const [to, setTo] = useState(todayStr());
  const presets = RANGE_PRESETS.map((p) => ({ label: `Last ${p.label}`, onClick: () => { setFrom(todayStr(-p.days)); setTo(todayStr()); } }));
  return { from, setFrom, to, setTo, presets };
}

// ── POS Sales (moved from the Hotel Reports hub — same /reports/pos endpoint) ─
type PosReport = { from: string; to: string; by_category: Record<string, number>; best_sellers: { name: string; qty: number; amount: number }[]; payment_method_breakdown: Record<string, number>; total_sales: number };

const METHOD_COLOR: Record<string, string> = {
  CASH: "bg-emerald-500", CARD: "bg-sky-500", LANKAQR: "bg-purple-500",
  BANK_TRANSFER: "bg-amber-500", CORPORATE_CREDIT: "bg-indigo-500", LOYALTY_POINTS: "bg-pink-500",
};

function BarRow({ label, amount, max, rank, colorClass }: { label: string; amount: number; max: number; rank?: number; colorClass?: string }) {
  const pct = max > 0 ? Math.max(2, (amount / max) * 100) : 2;
  return (
    <div className="space-y-1">
      <div className="flex items-center justify-between gap-2 text-sm">
        <span className="flex min-w-0 items-center gap-1.5">
          {rank !== undefined && <span className="w-4 shrink-0 text-right text-[11px] font-black tabular-nums text-slate-300">{rank}</span>}
          <span className="truncate">{label}</span>
        </span>
        <b className="shrink-0 tabular-nums">{lkr(amount)}</b>
      </div>
      <div className="h-1.5 overflow-hidden rounded-full bg-slate-100">
        <div className={`h-full rounded-full transition-all ${colorClass ?? "bg-brand-500"}`} style={{ width: `${pct}%` }} />
      </div>
    </div>
  );
}

function PosTab() {
  const { branding } = useBranding();
  const { from, setFrom, to, setTo, presets } = useDateRange();
  const { data } = useFetch<PosReport>(`/reports/pos?from=${from}&to=${to}`, [from, to]);
  const catMax = Math.max(1, ...Object.values(data?.by_category ?? {}));
  const payMax = Math.max(1, ...Object.values(data?.payment_method_breakdown ?? {}));

  const exportCsv = () => {
    if (!data) return;
    downloadCsv(`pos-report-${data.from}-to-${data.to}.csv`, [
      [`${branding.name} — POS report`, `${data.from} to ${data.to}`],
      [],
      ["Category", "Amount (LKR)"],
      ...Object.entries(data.by_category).map(([k, v]) => [k, (v / 100).toFixed(2)]),
      [],
      ["Payment method", "Amount (LKR)"],
      ...Object.entries(data.payment_method_breakdown).map(([k, v]) => [k, (v / 100).toFixed(2)]),
      [],
      ["Best seller", "Qty", "Amount (LKR)"],
      ...data.best_sellers.map((b) => [b.name, b.qty, (b.amount / 100).toFixed(2)]),
    ]);
  };

  return (
    <div className="space-y-3">
      <DateRangeBar from={from} to={to} onFrom={setFrom} onTo={setTo} maxTo={todayStr()} presets={presets} />
      {data ? (
        <div className="grid gap-4 lg:grid-cols-3">
          <div className="lg:col-span-3">
            <Stat label="Total POS sales" value={lkr(data.total_sales)} color="brand" />
          </div>
          <Card title="Sales by category">
            {Object.keys(data.by_category).length === 0 ? <Empty text="—" /> : (
              <div className="space-y-2.5">{Object.entries(data.by_category).map(([k, v]) => <BarRow key={k} label={k} amount={v} max={catMax} />)}</div>
            )}
          </Card>
          <Card title="Payment methods">
            {Object.keys(data.payment_method_breakdown).length === 0 ? <Empty text="—" /> : (
              <div className="space-y-2.5">{Object.entries(data.payment_method_breakdown).map(([k, v]) => <BarRow key={k} label={k} amount={v} max={payMax} colorClass={METHOD_COLOR[k] ?? "bg-slate-400"} />)}</div>
            )}
          </Card>
          <Card title="Best sellers">
            {data.best_sellers.length === 0 ? <Empty text="—" /> : (
              <div className="space-y-2.5">{data.best_sellers.slice(0, 6).map((b, i) => <BarRow key={b.name} label={`${b.name} (${b.qty}×)`} amount={b.amount} max={Math.max(1, ...data.best_sellers.map((x) => x.amount))} rank={i + 1} />)}</div>
            )}
          </Card>
          <div className="flex gap-2 lg:col-span-3">
            <button className="btn-secondary" onClick={exportCsv}><Download size={14} /> Export CSV</button>
            <button className="btn-secondary" onClick={() => openPdf(`/reports/pos/pdf?from=${data.from}&to=${data.to}`)}>Download PDF</button>
          </div>
        </div>
      ) : <Empty text="Loading…" />}
    </div>
  );
}

// ── Menu Item Performance ─────────────────────────────────────────────────────
type MenuPerformance = {
  from: string; to: string; total_revenue: number; by_category: Record<string, number>;
  best_sellers: { name: string; qty: number; amount: number; category: string }[];
  slow_movers: { name: string; qty: number; amount: number; category: string }[];
};

function MenuPerformanceTab() {
  const { from, setFrom, to, setTo, presets } = useDateRange();
  const { data } = useFetch<MenuPerformance>(`/restaurant/reports/menu-performance?from=${from}&to=${to}`, [from, to]);
  const catMax = Math.max(1, ...Object.values(data?.by_category ?? {}));

  return (
    <div className="space-y-3">
      <DateRangeBar from={from} to={to} onFrom={setFrom} onTo={setTo} maxTo={todayStr()} presets={presets} />
      {data ? (
        <div className="grid gap-4 lg:grid-cols-2">
          <div className="lg:col-span-2"><Stat label="Total revenue" value={lkr(data.total_revenue)} color="brand" /></div>
          <Card title="Category mix">
            {Object.keys(data.by_category).length === 0 ? <Empty text="—" /> : (
              <div className="space-y-2.5">{Object.entries(data.by_category).map(([k, v]) => <BarRow key={k} label={k} amount={v} max={catMax} />)}</div>
            )}
          </Card>
          <Card title="Best sellers">
            <SimpleTable
              columns={[{ key: "name", label: "Item" }, { key: "category", label: "Category" }, { key: "qty", label: "Qty", align: "right" }, { key: "amount", label: "Revenue", align: "right", render: (r) => lkr(r.amount) }]}
              rows={data.best_sellers} rowKey={(r) => r.name} empty="No sales in this range"
            />
          </Card>
          <Card title="Slow movers" className="lg:col-span-2">
            <SimpleTable
              columns={[{ key: "name", label: "Item" }, { key: "category", label: "Category" }, { key: "qty", label: "Qty", align: "right" }, { key: "amount", label: "Revenue", align: "right", render: (r) => lkr(r.amount) }]}
              rows={data.slow_movers} rowKey={(r) => r.name} empty="No sales in this range"
            />
          </Card>
        </div>
      ) : <Empty text="Loading…" />}
    </div>
  );
}

// ── Modifier Attach-Rate ───────────────────────────────────────────────────────
type ModifierAttachRate = {
  from: string; to: string; order_items: number; order_items_with_modifiers: number; attach_rate_pct: number;
  modifier_revenue: number; by_modifier: Record<string, { count: number; revenue: number }>;
};

function ModifiersTab() {
  const { from, setFrom, to, setTo, presets } = useDateRange();
  const { data } = useFetch<ModifierAttachRate>(`/restaurant/reports/modifiers?from=${from}&to=${to}`, [from, to]);
  const rows = Object.entries(data?.by_modifier ?? {}).map(([name, v]) => ({ name, ...v }));

  return (
    <div className="space-y-3">
      <DateRangeBar from={from} to={to} onFrom={setFrom} onTo={setTo} maxTo={todayStr()} presets={presets} />
      {data ? (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Stat label="Attach rate" value={`${data.attach_rate_pct}%`} sub={`${data.order_items_with_modifiers}/${data.order_items} items`} />
            <Stat label="Modifier revenue" value={lkr(data.modifier_revenue)} color="brand" />
          </div>
          <Card title="By modifier">
            <SimpleTable
              columns={[{ key: "name", label: "Modifier" }, { key: "count", label: "Times chosen", align: "right" }, { key: "revenue", label: "Revenue", align: "right", render: (r) => lkr(r.revenue) }]}
              rows={rows} rowKey={(r) => r.name} empty="No modifiers used in this range"
            />
          </Card>
        </>
      ) : <Empty text="Loading…" />}
    </div>
  );
}

// ── Discounts & Voids ──────────────────────────────────────────────────────────
type DiscountsVoids = {
  from: string; to: string; total_discount: number; discount_by_reason: Record<string, number>;
  discount_by_authorizer: Record<string, number>; voided_items_count: number; void_rate_pct: number; void_by_reason: Record<string, number>;
};

function DiscountsVoidsTab() {
  const { from, setFrom, to, setTo, presets } = useDateRange();
  const { data } = useFetch<DiscountsVoids>(`/restaurant/reports/discounts-voids?from=${from}&to=${to}`, [from, to]);

  return (
    <div className="space-y-3">
      <DateRangeBar from={from} to={to} onFrom={setFrom} onTo={setTo} maxTo={todayStr()} presets={presets} />
      {data ? (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Stat label="Total discount" value={lkr(data.total_discount)} />
            <Stat label="Voided items" value={data.voided_items_count} />
            <Stat label="Void rate" value={`${data.void_rate_pct}%`} />
          </div>
          <div className="grid gap-4 lg:grid-cols-2">
            <Card title="Discount by reason">
              <SimpleTable
                columns={[{ key: "reason", label: "Reason" }, { key: "amount", label: "Amount", align: "right", render: (r) => lkr(r.amount) }]}
                rows={Object.entries(data.discount_by_reason).map(([reason, amount]) => ({ reason, amount }))}
                rowKey={(r) => r.reason} empty="No discounts in this range"
              />
            </Card>
            <Card title="Discount by authorizer">
              <SimpleTable
                columns={[{ key: "name", label: "Authorized by" }, { key: "amount", label: "Amount", align: "right", render: (r) => lkr(r.amount) }]}
                rows={Object.entries(data.discount_by_authorizer).map(([name, amount]) => ({ name, amount }))}
                rowKey={(r) => r.name} empty="No discounts in this range"
              />
            </Card>
            <Card title="Void reasons" className="lg:col-span-2">
              <SimpleTable
                columns={[{ key: "reason", label: "Reason" }, { key: "count", label: "Count", align: "right" }]}
                rows={Object.entries(data.void_by_reason).map(([reason, count]) => ({ reason, count }))}
                rowKey={(r) => r.reason} empty="No voids in this range"
              />
            </Card>
          </div>
        </>
      ) : <Empty text="Loading…" />}
    </div>
  );
}

// ── Table & Server Performance ─────────────────────────────────────────────────
type TableServer = {
  from: string; to: string; total_orders: number; total_revenue: number;
  by_table: Record<string, { orders: number; revenue: number; avg_order_value: number }>;
  by_server: Record<string, { orders: number; revenue: number; avg_order_value: number }>;
};

function TableServerTab() {
  const { from, setFrom, to, setTo, presets } = useDateRange();
  const { data } = useFetch<TableServer>(`/restaurant/reports/table-server?from=${from}&to=${to}`, [from, to]);
  const tableRows = Object.entries(data?.by_table ?? {}).map(([table, v]) => ({ table, ...v }));
  const serverRows = Object.entries(data?.by_server ?? {}).map(([server, v]) => ({ server, ...v }));

  return (
    <div className="space-y-3">
      <DateRangeBar from={from} to={to} onFrom={setFrom} onTo={setTo} maxTo={todayStr()} presets={presets} />
      {data ? (
        <>
          <div className="grid grid-cols-2 gap-3">
            <Stat label="Orders" value={data.total_orders} />
            <Stat label="Revenue" value={lkr(data.total_revenue)} color="brand" />
          </div>
          <div className="grid gap-4 lg:grid-cols-2">
            <Card title="By table">
              <SimpleTable
                columns={[{ key: "table", label: "Table" }, { key: "orders", label: "Orders", align: "right" }, { key: "avg_order_value", label: "Avg order", align: "right", render: (r) => lkr(r.avg_order_value) }, { key: "revenue", label: "Revenue", align: "right", render: (r) => lkr(r.revenue) }]}
                rows={tableRows} rowKey={(r) => r.table} empty="No dine-in sales in this range"
              />
            </Card>
            <Card title="By server">
              <SimpleTable
                columns={[{ key: "server", label: "Server" }, { key: "orders", label: "Orders", align: "right" }, { key: "avg_order_value", label: "Avg order", align: "right", render: (r) => lkr(r.avg_order_value) }, { key: "revenue", label: "Revenue", align: "right", render: (r) => lkr(r.revenue) }]}
                rows={serverRows} rowKey={(r) => r.server} empty="No sales in this range"
              />
            </Card>
          </div>
        </>
      ) : <Empty text="Loading…" />}
    </div>
  );
}

// ── Delivery Performance ───────────────────────────────────────────────────────
type Delivery = {
  from: string; to: string; total_orders: number; total_revenue: number; by_status: Record<string, number>;
  by_rider: Record<string, { orders: number; revenue: number }>; delivered_count: number; avg_fulfillment_minutes: number;
};

function DeliveryTab() {
  const { from, setFrom, to, setTo, presets } = useDateRange();
  const { data } = useFetch<Delivery>(`/restaurant/reports/delivery?from=${from}&to=${to}`, [from, to]);
  const riderRows = Object.entries(data?.by_rider ?? {}).map(([rider, v]) => ({ rider, ...v }));

  return (
    <div className="space-y-3">
      <DateRangeBar from={from} to={to} onFrom={setFrom} onTo={setTo} maxTo={todayStr()} presets={presets} />
      {data ? (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Stat label="Delivery orders" value={data.total_orders} />
            <Stat label="Revenue" value={lkr(data.total_revenue)} />
            <Stat label="Delivered" value={data.delivered_count} />
            <Stat label="Avg fulfillment" value={`${data.avg_fulfillment_minutes}m`} />
          </div>
          <div className="grid gap-4 lg:grid-cols-2">
            <Card title="Status funnel">
              <div className="grid grid-cols-2 gap-2">
                {Object.entries(data.by_status).map(([code, count]) => (
                  <div key={code} className="rounded-lg bg-slate-50 p-3 text-center"><div className="text-xl font-extrabold">{count}</div><div className="text-xs text-slate-500">{code}</div></div>
                ))}
              </div>
            </Card>
            <Card title="By rider">
              <SimpleTable
                columns={[{ key: "rider", label: "Rider" }, { key: "orders", label: "Orders", align: "right" }, { key: "revenue", label: "Revenue", align: "right", render: (r) => lkr(r.revenue) }]}
                rows={riderRows} rowKey={(r) => r.rider} empty="No delivery orders in this range"
              />
            </Card>
          </div>
        </>
      ) : <Empty text="Loading…" />}
    </div>
  );
}

// ── Kitchen Ticket Time ────────────────────────────────────────────────────────
type KitchenTicketTime = {
  from: string; to: string; tickets: number; avg_prep_minutes: number; avg_pickup_minutes: number;
  by_station: Record<string, { tickets: number; avg_minutes: number }>;
};

function KitchenTicketTimeTab() {
  const { from, setFrom, to, setTo, presets } = useDateRange();
  const { data } = useFetch<KitchenTicketTime>(`/restaurant/reports/kitchen-ticket-time?from=${from}&to=${to}`, [from, to]);
  const rows = Object.entries(data?.by_station ?? {}).map(([station, v]) => ({ station, ...v }));

  return (
    <div className="space-y-3">
      <DateRangeBar from={from} to={to} onFrom={setFrom} onTo={setTo} maxTo={todayStr()} presets={presets} />
      {data ? (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
            <Stat label="Tickets" value={data.tickets} />
            <Stat label="Avg prep time" value={`${data.avg_prep_minutes}m`} />
            <Stat label="Avg pickup time" value={`${data.avg_pickup_minutes}m`} />
          </div>
          <Card title="By kitchen station">
            <SimpleTable
              columns={[{ key: "station", label: "Station" }, { key: "tickets", label: "Tickets", align: "right" }, { key: "avg_minutes", label: "Avg prep (min)", align: "right" }]}
              rows={rows} rowKey={(r) => r.station} empty="No tickets started in this range"
            />
          </Card>
        </>
      ) : <Empty text="Loading…" />}
    </div>
  );
}

// ── Shift Sales Drill-down ─────────────────────────────────────────────────────
type ShiftSales = {
  from: string; to: string;
  shifts: { id: number; staff: string; opened_at: string; closed_at: string | null; collected: number; payment_count: number; variance: number | null }[];
  total_collected: number;
};

function ShiftSalesTab() {
  const { from, setFrom, to, setTo, presets } = useDateRange();
  const { data } = useFetch<ShiftSales>(`/restaurant/reports/shift-sales?from=${from}&to=${to}`, [from, to]);

  return (
    <div className="space-y-3">
      <DateRangeBar from={from} to={to} onFrom={setFrom} onTo={setTo} maxTo={todayStr()} presets={presets} />
      {data ? (
        <>
          <Stat label="Total collected" value={lkr(data.total_collected)} color="brand" />
          <Card title="Shifts">
            <SimpleTable
              columns={[
                { key: "staff", label: "Staff" },
                { key: "opened_at", label: "Opened", render: (r) => fmtDateTime(r.opened_at) },
                { key: "payment_count", label: "Payments", align: "right" },
                { key: "collected", label: "Collected", align: "right", render: (r) => lkr(r.collected) },
                { key: "variance", label: "Variance", align: "right", render: (r) => <Badge color={!r.variance ? "green" : "red"}>{r.variance ? lkr(r.variance) : "balanced"}</Badge> },
              ]}
              rows={data.shifts} rowKey={(r) => r.id} empty="No shifts opened in this range"
            />
          </Card>
        </>
      ) : <Empty text="Loading…" />}
    </div>
  );
}

// ── Food Cost % ─────────────────────────────────────────────────────────────────
type FoodCost = { items: { id: number; name: string; price: number; cost: number | null; food_cost_pct: number | null }[]; avg_food_cost_pct: number | null; items_missing_cost: number };

function FoodCostTab() {
  const { data } = useFetch<FoodCost>(`/restaurant/reports/food-cost`);

  return (
    <div className="space-y-3">
      {data ? (
        <>
          <div className="grid grid-cols-2 gap-3">
            <Stat label="Avg food cost %" value={data.avg_food_cost_pct !== null ? `${data.avg_food_cost_pct}%` : "—"} />
            <Stat label="Items missing a cost" value={data.items_missing_cost} sub="Set ingredient unit cost in Inventory" />
          </div>
          <Card title="Menu items">
            <SimpleTable
              columns={[
                { key: "name", label: "Item" },
                { key: "price", label: "Price", align: "right", render: (r) => lkr(r.price) },
                { key: "cost", label: "Recipe cost", align: "right", render: (r) => (r.cost === null ? "—" : lkr(r.cost)) },
                { key: "food_cost_pct", label: "Food cost %", align: "right", render: (r) => (r.food_cost_pct === null ? "—" : <Badge color={r.food_cost_pct > 35 ? "red" : "green"}>{r.food_cost_pct}%</Badge>) },
              ]}
              rows={data.items} rowKey={(r) => r.id} empty="No active menu items"
            />
          </Card>
        </>
      ) : <Empty text="Loading…" />}
    </div>
  );
}
