import { BrowserRouter, Routes, Route, Navigate } from "react-router-dom";
import { ReactNode } from "react";
import { AuthProvider, useAuth } from "./lib/auth";
import { BrandingProvider } from "./lib/branding";
import { landingPath } from "./lib/landing";
import { ToastProvider } from "./lib/toast";
import Layout from "./components/Layout";
import Login from "./pages/Login";
import Dashboard from "./pages/Dashboard";
import Reservations from "./pages/Reservations";
import Calendar from "./pages/Calendar";
import ReservationDetail from "./pages/ReservationDetail";
import Rooms from "./pages/Rooms";
import POS from "./pages/POS";
import KOT from "./pages/KOT";
import MenuAdmin from "./pages/MenuAdmin";
import Inventory from "./pages/Inventory";
import Venues from "./pages/Venues";
import Housekeeping from "./pages/Housekeeping";
import Laundry from "./pages/Laundry";
import Maintenance from "./pages/Maintenance";
import Guests from "./pages/Guests";
import Corporate from "./pages/Corporate";
import Shifts from "./pages/Shifts";
import Attendance from "./pages/Attendance";
import Visitors from "./pages/Visitors";
import Reports from "./pages/Reports";
import Notifications from "./pages/Notifications";
import Staff from "./pages/Staff";
import UserDetail from "./pages/UserDetail";
import Roles from "./pages/Roles";
import Integrations from "./pages/Integrations";
import Payroll from "./pages/Payroll";
import AuditLog from "./pages/AuditLog";
import PreCheckIn from "./pages/PreCheckIn";
import VenueInquiry from "./pages/VenueInquiry";
import AccountProfile from "./pages/account/Profile";
import AccountPassword from "./pages/account/Password";
import AccountTwoFactor from "./pages/account/TwoFactor";

/**
 * Gate a route on a `module_key.action` permission (or any-of an array) —
 * Full Administrators bypass every check.
 */
function Guard({ children, permission, fullAdminOnly }: { children: ReactNode; permission?: string | string[]; fullAdminOnly?: boolean }) {
  const { me, loading, can } = useAuth();
  if (loading) return null;
  if (!me) return <Navigate to="/login" replace />;
  const permissions = permission ? (Array.isArray(permission) ? permission : [permission]) : [];
  const allowed = fullAdminOnly ? me.is_full_admin : permissions.length === 0 || permissions.some(can);
  if (!allowed) return <Navigate to={landingPath(me)} replace />;
  return <Layout>{children}</Layout>;
}

export default function App() {
  return (
    <AuthProvider>
      <BrandingProvider>
      <ToastProvider>
        <BrowserRouter>
          <Routes>
          <Route path="/login" element={<Login />} />
          {/* Public guest-facing pages (no login) */}
          <Route path="/pre-checkin" element={<PreCheckIn />} />
          <Route path="/venue-inquiry" element={<VenueInquiry />} />

          <Route path="/" element={<Guard permission="dashboard.access"><Dashboard /></Guard>} />
          <Route path="/reservations" element={<Guard permission="hotel_reservations.access"><Reservations /></Guard>} />
          <Route path="/calendar" element={<Guard permission="hotel_reservations.access"><Calendar /></Guard>} />
          <Route path="/reservations/:id" element={<Guard permission="hotel_reservations.view"><ReservationDetail /></Guard>} />
          <Route path="/rooms" element={<Guard permission="hotel_rooms.access"><Rooms /></Guard>} />
          <Route path="/pos" element={<Guard permission="hotel_orders.access"><POS /></Guard>} />
          <Route path="/kot" element={<Guard permission="hotel_orders.access"><KOT /></Guard>} />
          <Route path="/menu" element={<Guard permission="hotel_menu_items.access"><MenuAdmin /></Guard>} />
          <Route path="/inventory" element={<Guard permission="hotel_ingredients.access"><Inventory /></Guard>} />
          <Route path="/venues" element={<Guard permission="hotel_venues.access"><Venues /></Guard>} />
          <Route path="/housekeeping" element={<Guard permission="hotel_housekeeping.access"><Housekeeping /></Guard>} />
          <Route path="/laundry" element={<Guard permission="hotel_laundry.access"><Laundry /></Guard>} />
          <Route path="/maintenance" element={<Guard permission="hotel_maintenance.access"><Maintenance /></Guard>} />
          <Route path="/guests" element={<Guard permission="hotel_guests.access"><Guests /></Guard>} />
          <Route path="/corporate" element={<Guard permission="hotel_corporate.access"><Corporate /></Guard>} />
          <Route path="/shifts" element={<Guard permission="hotel_shifts.access"><Shifts /></Guard>} />
          <Route path="/attendance" element={<Guard permission="hotel_attendance.access"><Attendance /></Guard>} />
          <Route path="/visitors" element={<Guard permission="hotel_visitors.access"><Visitors /></Guard>} />
          <Route path="/reports" element={<Guard permission="hotel_reports.dashboard"><Reports /></Guard>} />
          <Route path="/notifications" element={<Guard permission="hotel_notifications.access"><Notifications /></Guard>} />
          <Route path="/staff" element={<Guard permission={["user_management_users.access", "hotel_staff.set_pin"]}><Staff /></Guard>} />
          <Route path="/staff/users/:id" element={<Guard permission="user_management_users.view"><UserDetail /></Guard>} />
          <Route path="/roles" element={<Guard permission="user_management_roles.access"><Roles /></Guard>} />
          {/* Personal account settings — any authenticated user manages their own. */}
          <Route path="/account" element={<Guard><AccountProfile /></Guard>} />
          <Route path="/account/password" element={<Guard><AccountPassword /></Guard>} />
          <Route path="/account/two-factor" element={<Guard><AccountTwoFactor /></Guard>} />
          <Route path="/integrations" element={<Guard fullAdminOnly><Integrations /></Guard>} />
          <Route path="/payroll" element={<Guard permission="hotel_payroll.view"><Payroll /></Guard>} />
          <Route path="/audit-log" element={<Guard permission="audit_logs.access"><AuditLog /></Guard>} />
          <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </BrowserRouter>
      </ToastProvider>
      </BrandingProvider>
    </AuthProvider>
  );
}
