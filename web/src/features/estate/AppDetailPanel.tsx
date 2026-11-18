import { useApp } from "../../core/api";
import { Empty, Failure, Loading } from "../../shared/States";
import { SeverityChip } from "../../shared/Severity";
import { date, laneLabel, when } from "../../shared/format";

const statusTone: Record<string, string> = {
  deployed: "text-ok",
  "rolled back at canary": "text-high",
  "rolled back after watch": "text-high",
  "rolled back in production": "text-critical",
  rejected: "text-muted",
};

export function AppDetailPanel({ id }: { id: string }) {
  const app = useApp(id);
  if (app.isPending) return <Loading label="Loading the app" />;
  if (app.isError) return <Failure message={(app.error as Error).message} retry={() => app.refetch()} />;
  const { app: a, exposures, jobs, incidents, dependencies } = app.data;
  return (
    <div className="rounded-[var(--radius-panel)] bg-sheet px-6 py-5">
      <h2 className="text-xl font-semibold leading-tight">{a.name}</h2>
      <p className="mt-1 text-sm text-muted">
        {a.stack} on {a.provider.toUpperCase()}, {dependencies.length} dependencies
        {a.frozen ? ", in a client freeze: only critical fixes ship" : ""}
      </p>

      <h3 className="mt-6 text-sm font-semibold">Open advisories</h3>
      {exposures.length === 0 ? (
        <p className="mt-1 text-sm text-ok">No known advisory affects the versions in production.</p>
      ) : (
        <table className="mt-2 w-full text-sm">
          <thead className="text-left text-muted"><tr><th className="py-1 font-normal">Advisory</th><th className="font-normal">Package</th><th className="font-normal">Running</th><th className="font-normal">Fixed in</th></tr></thead>
          <tbody>
            {exposures.map((e) => (
              <tr key={e.advisory + e.package} className="border-t border-rule">
                <td className="py-1.5"><SeverityChip severity={e.severity} /> <span className="ml-1">{e.advisory}</span></td>
                <td>{e.package}</td>
                <td>{e.version}</td>
                <td>{e.fixed_in.length ? e.fixed_in.join(", ") : <span className="text-muted">no fix yet</span>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      <h3 className="mt-6 text-sm font-semibold">Recent update jobs</h3>
      {jobs.length === 0 ? (
        <Empty title="No update jobs yet" />
      ) : (
        <ol className="mt-2 border-l-2 border-rule pl-4">
          {jobs.slice(0, 8).map((j) => (
            <li key={j.id} className="relative pb-3 text-sm last:pb-0">
              <span aria-hidden className="absolute -left-[1.35rem] top-1.5 size-2.5 rounded-full bg-ink" />
              <span className="font-semibold">{laneLabel[j.lane] ?? j.lane}</span>
              <span className={`ml-2 ${statusTone[j.status] ?? "text-muted"}`}>{j.status}</span>
              <span className="ml-2 text-muted">{when(j.created_at)}</span>
              <div className="text-muted">
                {j.changes.map((c) => `${c.name} ${c.from} to ${c.to}${c.fixes.length ? ` (fixes ${c.fixes.join(", ")})` : ""}`).join("; ")}
              </div>
              {j.verdict && <div className="text-xs text-muted">Canary: {j.verdict.reason}</div>}
            </li>
          ))}
        </ol>
      )}

      <h3 className="mt-6 text-sm font-semibold">Incidents</h3>
      {incidents.length === 0 ? (
        <p className="mt-1 text-sm text-muted">None in the period.</p>
      ) : (
        <ul className="mt-1 text-sm">
          {incidents.slice(0, 6).map((i) => (
            <li key={i.id} className="border-t border-rule py-1.5">
              {i.cause === "deploy" ? "A deploy broke it" : "Outage"}: opened {when(i.opened_at)}, {i.action === "rollback" ? "rolled back" : "paged"} {date(i.acted_at)}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
