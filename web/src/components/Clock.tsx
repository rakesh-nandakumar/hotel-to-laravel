import { useEffect, useState } from "react";

/** Live front-desk clock — seconds tick, shown in the app header. */
export default function Clock() {
  const [now, setNow] = useState(new Date());
  useEffect(() => {
    const t = setInterval(() => setNow(new Date()), 1000);
    return () => clearInterval(t);
  }, []);
  return (
    <div className="select-none text-right leading-tight" title="Hotel time">
      <div className="font-mono text-sm font-bold tabular-nums text-slate-700">
        {now.toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit", second: "2-digit" })}
      </div>
      <div className="text-[10px] font-medium text-slate-400">
        {now.toLocaleDateString("en-GB", { weekday: "short", day: "2-digit", month: "short", year: "numeric" })}
      </div>
    </div>
  );
}
