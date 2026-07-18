import { FormEventHandler, useState } from "react";
import { api, ApiFail } from "../../lib/api";
import { useToast } from "../../lib/toast";
import { Field, ErrorText } from "../../components/ui";
import AccountLayout, { Section } from "./AccountLayout";

const firstError = (errors: Record<string, string[]> | undefined, field: string) => errors?.[field]?.[0] ?? "";

export default function AccountPassword() {
  const toast = useToast();
  const [current, setCurrent] = useState("");
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState("");

  const submit: FormEventHandler = async (e) => {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    setFormError("");
    try {
      await api("/settings/password", {
        method: "PUT",
        body: { current_password: current, password, password_confirmation: confirm },
      });
      toast.success("Password updated", "Your new password is now active.");
      setCurrent("");
      setPassword("");
      setConfirm("");
    } catch (err) {
      if (err instanceof ApiFail && err.errors) setErrors(err.errors);
      setFormError((err as Error).message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <AccountLayout>
      <Section title="Update password" description="Use a long, random password to keep your account secure">
        <form onSubmit={submit} className="space-y-4">
          <ErrorText error={formError && Object.keys(errors).length === 0 ? formError : ""} />
          <Field label="Current password">
            <input
              className="input"
              type="password"
              value={current}
              onChange={(e) => setCurrent(e.target.value)}
              autoComplete="current-password"
              required
            />
            {firstError(errors, "current_password") && (
              <p className="mt-1 text-xs font-medium text-red-600">{firstError(errors, "current_password")}</p>
            )}
          </Field>
          <Field label="New password" hint="At least 12 characters, with upper & lower case and a number.">
            <input
              className="input"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              autoComplete="new-password"
              required
            />
            {firstError(errors, "password") && (
              <p className="mt-1 text-xs font-medium text-red-600">{firstError(errors, "password")}</p>
            )}
          </Field>
          <Field label="Confirm new password">
            <input
              className="input"
              type="password"
              value={confirm}
              onChange={(e) => setConfirm(e.target.value)}
              autoComplete="new-password"
              required
            />
          </Field>
          <div className="pt-1">
            <button className="btn-primary" disabled={saving}>
              {saving ? "Saving…" : "Save password"}
            </button>
          </div>
        </form>
      </Section>
    </AccountLayout>
  );
}
