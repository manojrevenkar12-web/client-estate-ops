import { useReport } from "../../core/api";
import { Failure, Loading } from "../../shared/States";
import { count } from "../../shared/format";

export function StudyView() {
  const r = useReport();
  if (r.isPending) return <Loading label="Loading the study" />;
  if (r.isError) return <Failure message={(r.error as Error).message} retry={() => r.refetch()} />;
  const max = Math.max(...r.data.designs.map((d) => d.exposure.high.app_days), 1);
  return (
    <div>
      <p className="max-w-prose text-sm text-muted">
        Dev estate, seed {r.data.seed}: {r.data.apps} apps over {r.data.days} days, the same releases, advisories and outages for every design.
      </p>
      <table className="mt-6 w-full text-sm">
        <thead className="text-left text-muted">
          <tr><th className="py-1 font-normal">Design</th><th className="w-[34%] font-normal">App-days running a high flaw after its fix was out</th>
            <th className="text-right font-normal">Broken deploys</th><th className="text-right font-normal">Pages</th><th className="text-right font-normal">Person-hours</th></tr>
        </thead>
        <tbody>
          {r.data.designs.map((d) => (
            <tr key={d.design} className={`border-t border-rule ${d.design === r.data.proposed ? "font-semibold" : ""}`}>
              <td className="py-2">{d.design.replaceAll("_", " ")}</td>
              <td>
                <div className="flex items-center gap-2">
                  <div className={`h-3 rounded-sm ${d.design === r.data.proposed ? "bg-ok" : d.design === "today" ? "bg-critical" : "bg-muted/50"}`}
                    style={{ width: `${Math.max(1.5, (d.exposure.high.app_days / max) * 80)}%` }} />
                  <span>{count(d.exposure.high.app_days)}</span>
                </div>
              </td>
              <td className="text-right">{d.broken_deploys}</td>
              <td className="text-right">{count(d.pages)}</td>
              <td className="text-right">{count(d.person_hours_total)}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <p className="mt-6 text-sm">Release gate: <span className={`font-semibold ${r.data.gate.pass ? "text-ok" : "text-critical"}`}>{r.data.gate.pass ? "passes" : "fails"}</span></p>
    </div>
  );
}
