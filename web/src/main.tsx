import React from "react";
import ReactDOM from "react-dom/client";
import App from "./App";
import CentralApp from "./CentralApp";
import "./index.css";

const isCentral = window.location.pathname.startsWith("/central");

ReactDOM.createRoot(document.getElementById("root")!).render(
  <React.StrictMode>
    {isCentral ? <CentralApp /> : <App />}
  </React.StrictMode>
);
