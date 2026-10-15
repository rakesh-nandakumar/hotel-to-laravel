import { useEffect, useState } from "react";
import { Bell, Banknote, X, ArrowLeft } from "lucide-react";
import { api } from "../lib/api";
import { lkr } from "../lib/util";

interface PendingPayment {
  id: number;
  invoice_no: string | null;
  type: string;
  guest_name: string;
  reservation_code: string | null;
  venue_booking_code: string | null;
  total_charges: number;
  total_payments: number;
  balance_due: number;
  credit_payments: number;
  created_at: string;
}

export default function PendingPayments() {
  const [payments, setPayments] = useState<PendingPayment[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterMethod, setFilterMethod] = useState<string>("");

  useEffect(() => {
    loadPayments();
  }, [filterMethod]);

  const loadPayments = async () => {
    try {
      const url = filterMethod
        ? `/money/pending-payments?payment_method=${filterMethod}`
        : "/money/pending-payments";
      const res = await api<PendingPayment[]>(url);
      setPayments(res);
    } catch (err) {
      console.error("Failed to load pending payments", err);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="p-6">
      <div className="flex items-center justify-between mb-6">
        <div className="flex items-center gap-3">
          <Bell className="w-6 h-6 text-amber-500" />
          <h1 className="text-2xl font-bold">Pending Payments</h1>
        </div>
        <div className="flex items-center gap-2">
          <select
            className="input"
            value={filterMethod}
            onChange={(e) => setFilterMethod(e.target.value)}
          >
            <option value="">All Payment Methods</option>
            <option value="CREDIT">Credit Only</option>
          </select>
        </div>
      </div>

      {loading ? (
        <div className="text-center py-12 text-gray-500">Loading...</div>
      ) : payments.length === 0 ? (
        <div className="text-center py-12 text-gray-500">
          <Banknote className="w-12 h-12 mx-auto mb-4 text-gray-300" />
          <p>No pending payments found</p>
        </div>
      ) : (
        <div className="bg-white rounded-lg shadow overflow-hidden">
          <table className="w-full">
            <thead className="bg-gray-50 border-b">
              <tr>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">
                  Invoice
                </th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">
                  Guest/Client
                </th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">
                  Reference
                </th>
                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600">
                  Total Charges
                </th>
                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600">
                  Paid
                </th>
                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600">
                  Credit
                </th>
                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600">
                  Balance Due
                </th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">
                  Date
                </th>
              </tr>
            </thead>
            <tbody>
              {payments.map((p) => (
                <tr key={p.id} className="border-b hover:bg-gray-50">
                  <td className="px-4 py-3 text-sm font-medium">
                    {p.invoice_no || "N/A"}
                  </td>
                  <td className="px-4 py-3 text-sm">{p.guest_name}</td>
                  <td className="px-4 py-3 text-sm text-gray-600">
                    {p.reservation_code || p.venue_booking_code || "N/A"}
                  </td>
                  <td className="px-4 py-3 text-sm text-right">
                    {lkr(p.total_charges)}
                  </td>
                  <td className="px-4 py-3 text-sm text-right text-green-600">
                    {lkr(p.total_payments)}
                  </td>
                  <td className="px-4 py-3 text-sm text-right text-amber-600">
                    {p.credit_payments > 0
                      ? lkr(p.credit_payments)
                      : "-"}
                  </td>
                  <td className="px-4 py-3 text-sm text-right font-bold text-red-600">
                    {lkr(p.balance_due)}
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-600">
                    {new Date(p.created_at).toLocaleDateString()}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
