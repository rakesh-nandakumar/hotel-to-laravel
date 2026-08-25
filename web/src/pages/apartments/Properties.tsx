import { useState } from "react";
import { Plus } from "lucide-react";
import { post, put } from "../../lib/api";
import { useFetch } from "../../lib/util";
import { Badge, Empty, ErrorText, Field, Modal } from "../../components/ui";
import { useAuth } from "../../lib/auth";

type Property = {
  id: number; name: string; address: string | null; phone: string | null; email: string | null;
  notes: string | null; active: boolean; units_count: number;
};

export default function Properties() {
  const { can } = useAuth();
  const { data, reload } = useFetch<{ properties: Property[] }>("/apartments/properties");
  const properties = data?.properties ?? [];
  const [editing, setEditing] = useState<Property | null>(null);
  const [openNew, setOpenNew] = useState(false);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-extrabold">Apartment Properties</h1>
        {can("apartment_properties.create") && (
          <button className="btn-primary" onClick={() => setOpenNew(true)}><Plus size={16} /> New property</button>
        )}
      </div>

      <div className="card overflow-x-auto">
        <table className="w-full min-w-[640px]">
          <thead className="border-b border-slate-100">
            <tr>
              <th className="th">Name</th>
              <th className="th">Address</th>
              <th className="th">Contact</th>
              <th className="th text-right">Units</th>
              <th className="th">Status</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-50">
            {properties.map((p) => (
              <tr
                key={p.id}
                className={can("apartment_properties.edit") ? "cursor-pointer hover:bg-slate-50" : ""}
                onClick={can("apartment_properties.edit") ? () => setEditing(p) : undefined}
              >
                <td className="td font-semibold">{p.name}</td>
                <td className="td text-xs">{p.address ?? "—"}</td>
                <td className="td text-xs">{[p.phone, p.email].filter(Boolean).join(" · ") || "—"}</td>
                <td className="td text-right">{p.units_count}</td>
                <td className="td"><Badge color={p.active ? "green" : "gray"}>{p.active ? "Active" : "Inactive"}</Badge></td>
              </tr>
            ))}
          </tbody>
        </table>
        {properties.length === 0 && <Empty text="No properties yet" />}
      </div>

      {editing && <PropertyEditor property={editing} onClose={() => { setEditing(null); reload(); }} />}
      {openNew && <PropertyEditor onClose={() => { setOpenNew(false); reload(); }} />}
    </div>
  );
}

function PropertyEditor({ property, onClose }: { property?: Property; onClose: () => void }) {
  const [f, setF] = useState({
    name: property?.name ?? "", address: property?.address ?? "", phone: property?.phone ?? "",
    email: property?.email ?? "", notes: property?.notes ?? "",
  });
  const [error, setError] = useState("");
  return (
    <Modal open onClose={onClose} title={property ? "Edit property" : "New property"}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Name *"><input className="input" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
        <Field label="Address"><input className="input" value={f.address} onChange={(e) => setF({ ...f, address: e.target.value })} /></Field>
        <Field label="Phone"><input className="input" value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} /></Field>
        <Field label="Email"><input className="input" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} /></Field>
      </div>
      <Field label="Notes"><textarea className="input" rows={2} value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} /></Field>
      <ErrorText error={error} />
      <button
        className="btn-primary mt-4 w-full"
        disabled={!f.name.trim()}
        onClick={() => {
          const body = { name: f.name, address: f.address, phone: f.phone, email: f.email, notes: f.notes };
          (property ? put(`/apartments/properties/${property.id}`, body) : post("/apartments/properties", body))
            .then(onClose)
            .catch((e) => setError(e.message));
        }}
      >
        Save
      </button>
    </Modal>
  );
}
