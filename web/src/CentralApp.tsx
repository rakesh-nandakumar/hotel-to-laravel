import { BrowserRouter, Routes, Route, Navigate } from "react-router-dom";
import { ReactNode } from "react";
import { CentralAuthProvider, useCentralAuth } from "./lib/centralAuth";
import { ToastProvider } from "./lib/toast";
import CentralLayout from "./components/CentralLayout";
import CentralLogin from "./pages/central/Login";
import CentralDashboard from "./pages/central/Dashboard";
import CentralTenants from "./pages/central/Tenants";
import CentralTenantDetail from "./pages/central/TenantDetail";
import CentralAdmins from "./pages/central/Admins";

function Guard({ children }: { children: ReactNode }) {
  const { admin, loading } = useCentralAuth();
  if (loading) return null;
  if (!admin) return <Navigate to="/login" replace />;
  return <CentralLayout>{children}</CentralLayout>;
}

/**
 * The "master control" SPA tree — a wholly separate app from the tenant-facing
 * App.tsx (see lib/centralAuth.tsx). Mounted only on the central host (the
 * apex or {central_subdomain}.{base}), decided by the /api/host-context boot
 * gate in main.tsx — the panel owns the whole origin, so its routes sit at "/".
 */
export default function CentralApp() {
  return (
    <CentralAuthProvider>
      <ToastProvider>
        <BrowserRouter future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>
          <Routes>
            <Route path="/login" element={<CentralLogin />} />
            <Route path="/" element={<Guard><CentralDashboard /></Guard>} />
            <Route path="/tenants" element={<Guard><CentralTenants /></Guard>} />
            <Route path="/tenants/:id" element={<Guard><CentralTenantDetail /></Guard>} />
            <Route path="/admins" element={<Guard><CentralAdmins /></Guard>} />
            <Route path="*" element={<Navigate to="/tenants" replace />} />
          </Routes>
        </BrowserRouter>
      </ToastProvider>
    </CentralAuthProvider>
  );
}
