import { useFindings } from "../../core/api";
import { Empty, Failure, Loading } from "../../shared/States";
import { actionLabel, euros } from "../../shared/format";

export function CostView() {
  const f = useFindings();
  if (f.isPending) return <Loading label="Loading cost findings" rows={6} />;
  if (f.isError) return <Failure message={(f.error as Error).message} retry={() => f.refetch()} />;
  const act = f.data.filter((x) => x.action !== "keep");
  if (act.length === 0) return <Empty title="No idle spend found">Every resource across the client accounts is busy, sized to its load, or kept on purpose.</Empty>;
  const total = act.reduce((n, x) => n + x.monthly_saving_eur, 0);
  const kept = f.data.filter((x) => x.action === "keep");
  return (
    <div>
      <p className="text-2xl font-semibold">{euros(total)} <span className="text-base font-normal text-muted">a month across {new Set(act.map((x) => x.client_id)).size} client accounts</span></p>
      {kept.length > 0 && <p className="mt-1 text-sm text-muted">{kept.length} idle resources kept on purpose: busy in the same weeks last year.</p>}
      <table className="mt-6 w-full text-sm">
        <thead className="text-left text-muted"><tr><th className="py-1 font-normal">Resource</th><th className="font-normal">Client</th><th className="font-normal">Action</th><th className="font-normal">Why</th><th className="text-right font-normal">Saving a month</th></tr></thead>
        <tbody>
          {f.data.map((x) => (
            <tr key={x.resource} className="border-t border-rule">
              <td className="py-1.5">{x.resource}</td>
              <td>{x.client_id}</td>
              <td className={x.action === "keep" ? "text-muted" : "font-semibold"}>{actionLabel[x.action] ?? x.action}</td>
              <td className="text-muted">{x.reason}</td>
              <td className="text-right">{x.action === "keep" ? "" : euros(x.monthly_saving_eur)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
