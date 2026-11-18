import type { Exposures, Severity } from "../core/types";

const tone: Record<Severity, string> = {
  critical: "bg-critical-soft text-critical",
  high: "bg-high-soft text-high",
  medium: "bg-medium-soft text-medium",
  low: "bg-low-soft text-low",
};
export const severities: Severity[] = ["critical", "high", "medium", "low"];

export function SeverityChip({ severity }: { severity: Severity }) {
  return <span className={`rounded-[var(--radius-field)] px-1.5 py-0.5 text-xs font-semibold ${tone[severity]}`}>{severity}</span>;
}

/** Four fixed slots, so a column of apps reads like a table: an empty slot is a severity with nothing open. */
export function ExposureStrip({ exposures }: { exposures: Exposures }) {
  return (
    <span className="flex gap-1" aria-label={severities.map((s) => `${exposures[s]} ${s}`).join(", ")}>
      {severities.map((s) => (
        <span key={s} className={`inline-flex h-5 w-6 items-center justify-center rounded-[var(--radius-field)] text-xs font-semibold ${exposures[s] ? tone[s] : "text-rule"}`}>
          {exposures[s] || "·"}
        </span>
      ))}
    </span>
  );
}
