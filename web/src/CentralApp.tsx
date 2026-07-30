import { BrowserRouter, Routes, Route, Navigate } from "react-router-dom";
import { ReactNode } from "react";
import { CentralAuthProvider, useCentralAuth } from "./lib/centralAuth";
import { ToastProvider } from "./lib/toast";
import CentralLayout from "./components/CentralLayout";
import CentralLogin from "./pages/central/Login";
import CentralTenants from "./pages/central/Tenants";
import CentralTenantDetail from "./pages/central/TenantDetail";

function Guard({ children }: { children: ReactNode }) {
  const { admin, loading } = useCentralAuth();
  if (loading) return null;
  if (!admin) return <Navigate to="/central/login" replace />;
  return <CentralLayout>{children}</CentralLayout>;
}

/** The "master control" SPA tree — a wholly separate app from the tenant-facing App.tsx (see lib/centralAuth.tsx). */
export default function CentralApp() {
  return (
    <CentralAuthProvider>
      <ToastProvider>
        <BrowserRouter>
          <Routes>
            <Route path="/central/login" element={<CentralLogin />} />
            <Route path="/central/tenants" element={<Guard><CentralTenants /></Guard>} />
            <Route path="/central/tenants/:id" element={<Guard><CentralTenantDetail /></Guard>} />
            <Route path="*" element={<Navigate to="/central/tenants" replace />} />
          </Routes>
        </BrowserRouter>
      </ToastProvider>
    </CentralAuthProvider>
  );
}
