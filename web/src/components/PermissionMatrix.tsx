import { useMemo, useState } from "react";
import { Check, ChevronDown, Minus, Search } from "lucide-react";
import clsx from "clsx";

/** One permission module (a `module_key` + the actions it exposes). */
export type MatrixModule = { key: string; label: string; actions: string[] };
/** A collapsible group of modules, e.g. "Front Desk" → reservations, rooms… */
export type MatrixSection = { section: string; modules: MatrixModule[] };

const perm = (moduleKey: string, action: string) => `${moduleKey}.${action}`;

/** "view_all" → "View all", "checkout" → "Checkout". */
function humanize(action: string): string {
  const s = action.replace(/_/g, " ");
  return s.charAt(0).toUpperCase() + s.slice(1);
}

type TriState = "none" | "some" | "all";

/** Checkbox that also renders an indeterminate ("some selected") state. */
function TriCheck({ state, disabled, onClick, label }: { state: TriState; disabled?: boolean; onClick: () => void; label: string }) {
  return (
    <button
      type="button"
      disabled={disabled}
      onClick={onClick}
      aria-label={label}
      className={clsx(
        "flex h-4 w-4 items-center justify-center rounded border transition",
        state === "all" && "border-brand-600 bg-brand-600 text-white",
        state === "some" && "border-brand-600 bg-brand-50 text-brand-700",
        state === "none" && "border-slate-300 bg-white text-transparent hover:border-slate-400",
        disabled && "cursor-not-allowed opacity-40",
      )}
    >
      {state === "some" ? <Minus size={12} /> : <Check size={12} />}
    </button>
  );
}

/**
 * Interactive permission grid used by both the user and role editors. It edits
 * a flat set of `module_key.action` names (the *effective* set for users; the
 * granted set for roles) and reports the next set via `onChange`.
 *
 * `grantable` scopes what the current actor may toggle: `null` means everything
 * (Full Administrator), otherwise only listed permissions are interactive —
 * mirroring the server's anti-escalation guard so the UI can't offer to grant
 * something the backend would reject.
 */
export default function PermissionMatrix({
  matrix, value, onChange, grantable, disabled = false,
}: {
  matrix: MatrixSection[];
  value: string[];
  onChange: (next: string[]) => void;
  grantable: string[] | null;
  disabled?: boolean;
}) {
  const selected = useMemo(() => new Set(value), [value]);
  const grantableSet = useMemo(() => (grantable === null ? null : new Set(grantable)), [grantable]);
  const [query, setQuery] = useState("");
  const [collapsed, setCollapsed] = useState<Set<string>>(new Set());

  const canGrant = (name: string) => !disabled && (grantableSet === null || grantableSet.has(name));

  const q = query.trim().toLowerCase();
  const moduleTextMatches = (m: MatrixModule) => m.label.toLowerCase().includes(q) || m.key.toLowerCase().includes(q);
  const actionMatches = (a: string) => a.includes(q) || humanize(a).toLowerCase().includes(q);
  const visibleActions = (m: MatrixModule) =>
    !q || moduleTextMatches(m) ? m.actions : m.actions.filter(actionMatches);

  /** Set membership for a batch of permission names, respecting what's grantable. */
  const applyBatch = (names: string[], on: boolean) => {
    const next = new Set(selected);
    for (const name of names) {
      if (!canGrant(name)) continue; // never touch what the actor can't grant
      if (on) {
        next.add(name);
      } else {
        next.delete(name);
      }
    }
    onChange([...next]);
  };

  const toggleOne = (name: string) => {
    if (!canGrant(name)) return;
    applyBatch([name], !selected.has(name));
  };

  const stateOf = (names: string[]): TriState => {
    const grantables = names.filter(canGrant);
    if (grantables.length === 0) return names.some((n) => selected.has(n)) ? "all" : "none";
    const on = grantables.filter((n) => selected.has(n)).length;
    return on === 0 ? "none" : on === grantables.length ? "all" : "some";
  };

  const moduleNames = (m: MatrixModule) => m.actions.map((a) => perm(m.key, a));
  const sectionNames = (s: MatrixSection) => s.modules.flatMap(moduleNames);

  const toggleCollapse = (section: string) =>
    setCollapsed((c) => {
      const next = new Set(c);
      next.has(section) ? next.delete(section) : next.add(section);
      return next;
    });

  return (
    <div className="space-y-3">
      <div className="relative">
        <Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
        <input
          className="input !pl-9"
          placeholder="Filter permissions…"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
        />
      </div>

      {matrix.map((section) => {
        const modules = section.modules.filter((m) => !q || moduleTextMatches(m) || m.actions.some(actionMatches));
        if (modules.length === 0) return null;
        const open = !collapsed.has(section.section) || q !== "";
        const secState = stateOf(sectionNames(section));

        return (
          <div key={section.section} className="overflow-hidden rounded-xl border border-slate-200">
            <div className="flex items-center gap-2 bg-slate-50 px-3 py-2">
              <TriCheck
                state={secState}
                disabled={disabled}
                label={`Select all in ${section.section}`}
                onClick={() => applyBatch(sectionNames(section), secState !== "all")}
              />
              <button type="button" className="flex flex-1 items-center gap-1.5 text-left" onClick={() => toggleCollapse(section.section)}>
                <span className="text-sm font-bold text-slate-700">{section.section}</span>
                <ChevronDown size={15} className={clsx("text-slate-400 transition-transform", !open && "-rotate-90")} />
              </button>
            </div>

            {open && (
              <div className="divide-y divide-slate-100">
                {modules.map((m) => {
                  const modState = stateOf(moduleNames(m));
                  return (
                    <div key={m.key} className="flex flex-col gap-2 px-3 py-2.5 sm:flex-row sm:items-start">
                      <div className="flex min-w-[9rem] items-center gap-2 sm:pt-1">
                        <TriCheck
                          state={modState}
                          disabled={disabled}
                          label={`All ${m.label}`}
                          onClick={() => applyBatch(moduleNames(m), modState !== "all")}
                        />
                        <span className="text-sm font-semibold text-slate-700">{m.label}</span>
                      </div>
                      <div className="flex flex-1 flex-wrap gap-1.5">
                        {visibleActions(m).map((action) => {
                          const name = perm(m.key, action);
                          const on = selected.has(name);
                          const grantableHere = canGrant(name);
                          return (
                            <button
                              key={action}
                              type="button"
                              disabled={!grantableHere}
                              onClick={() => toggleOne(name)}
                              title={name}
                              className={clsx(
                                "inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-semibold transition",
                                on
                                  ? "border-brand-500 bg-brand-50 text-brand-700"
                                  : "border-slate-200 bg-white text-slate-500 hover:border-slate-300",
                                !grantableHere && "cursor-not-allowed opacity-40 hover:border-slate-200",
                              )}
                            >
                              {on && <Check size={12} />}
                              {humanize(action)}
                            </button>
                          );
                        })}
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
}
