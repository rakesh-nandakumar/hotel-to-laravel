import { BrowserRouter, Routes, Route, Navigate } from "react-router-dom";
import { ReactNode } from "react";
import { CentralAuthProvider, useCentralAuth } from "./lib/centralAuth";
import { ToastProvider } from "./lib/toast";
import { centralBasename } from "./lib/tenancy";
import CentralLayout from "./components/CentralLayout";
import CentralLogin from "./pages/central/Login";
import CentralTenants from "./pages/central/Tenants";
import CentralTenantDetail from "./pages/central/TenantDetail";

function Guard({ children }: { children: ReactNode }) {
  const { admin, loading } = useCentralAuth();
  if (loading) return null;
  if (!admin) return <Navigate to="/login" replace />;
  return <CentralLayout>{children}</CentralLayout>;
}

/**
 * The "master control" SPA tree — a wholly separate app from the tenant-facing
 * App.tsx (see lib/centralAuth.tsx). Routes are declared basename-relative, so
 * the same tree serves "/" on admin.{base_domain} and "/central/*" under the
 * local-dev path fallback (see lib/tenancy.ts).
 */
export default function CentralApp() {
  return (
    <CentralAuthProvider>
      <ToastProvider>
        <BrowserRouter basename={centralBasename()}>
          <Routes>
            <Route path="/login" element={<CentralLogin />} />
            <Route path="/tenants" element={<Guard><CentralTenants /></Guard>} />
            <Route path="/tenants/:id" element={<Guard><CentralTenantDetail /></Guard>} />
            <Route path="*" element={<Navigate to="/tenants" replace />} />
          </Routes>
        </BrowserRouter>
      </ToastProvider>
    </CentralAuthProvider>
  );
}
