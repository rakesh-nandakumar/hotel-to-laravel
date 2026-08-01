import React from "react";
import ReactDOM from "react-dom/client";
import App from "./App";
import CentralApp from "./CentralApp";
import { shouldMountCentralApp } from "./lib/tenancy";
import "./index.css";

// Master control lives on its own host (admin.{base_domain}); every other host
// is a tenant's own app. See lib/tenancy.ts for the local-dev path fallback.
const isCentral = shouldMountCentralApp();

ReactDOM.createRoot(document.getElementById("root")!).render(
  <React.StrictMode>
    {isCentral ? <CentralApp /> : <App />}
  </React.StrictMode>
);
