import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { useCentralAuth } from "../../lib/centralAuth";
import { ErrorText } from "../../components/ui";
import { ShieldCheck } from "lucide-react";

export default function CentralLogin() {
  const { login } = useCentralAuth();
  const nav = useNavigate();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      await login(email, password);
      nav("/tenants");
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-950 px-6">
      <div className="w-full max-w-sm">
        <div className="mb-6 flex flex-col items-center gap-2 text-white">
          <ShieldCheck size={32} className="text-brand-400" />
          <h1 className="text-xl font-black">Master Control</h1>
          <p className="text-xs text-slate-400">Platform operator sign-in</p>
        </div>
        <form onSubmit={submit} className="card modal-panel space-y-3 p-5">
          <ErrorText error={error} />
          <input
            className="input"
            type="email"
            placeholder="you@platform.com"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
            autoFocus
          />
          <input
            className="input"
            type="password"
            placeholder="Password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
          />
          <button className="btn-primary w-full !py-3" disabled={busy}>
            {busy ? "Signing in…" : "Sign in"}
          </button>
        </form>
      </div>
    </div>
  );
}
