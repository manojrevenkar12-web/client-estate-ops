import type { ReactNode } from "react";

export function Loading({ label, rows = 4 }: { label: string; rows?: number }) {
  return (
    <div role="status" aria-label={label} className="flex flex-col gap-2 py-3">
      {Array.from({ length: rows }, (_, i) => <div key={i} className="h-9 animate-pulse rounded-[var(--radius-field)] bg-rule/60" />)}
    </div>
  );
}

export function Failure({ message, retry }: { message: string; retry?: () => void }) {
  return (
    <div role="alert" className="rounded-[var(--radius-panel)] border-l-4 border-critical bg-sheet px-4 py-3 text-sm">
      <p>{message}</p>
      {retry && <button onClick={retry} className="mt-2 font-semibold text-action underline underline-offset-4">Try again</button>}
    </div>
  );
}

export function Empty({ title, children }: { title: string; children?: ReactNode }) {
  return (
    <div className="py-6 text-sm text-muted">
      <p className="text-base font-semibold text-ink">{title}</p>
      {children && <div className="mt-1 max-w-prose">{children}</div>}
    </div>
  );
}
