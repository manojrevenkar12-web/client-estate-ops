import { AnimatePresence, motion } from "motion/react";
import { useMemo, useState } from "react";
import { useEstate } from "../../core/api";
import type { AppSummary, Client } from "../../core/types";
import { Empty, Failure, Loading } from "../../shared/States";
import { ExposureStrip, severities } from "../../shared/Severity";
import { date } from "../../shared/format";
import { settle } from "../../theme/motion";
import { AppDetailPanel } from "./AppDetailPanel";

const weight = (a: AppSummary) => a.exposures.critical * 1000 + a.exposures.high * 100 + a.exposures.medium * 10 + a.exposures.low;

/** The estate: every client's apps, worst exposure first, and one app's detail beside it. */
export function EstateView() {
  const estate = useEstate();
  const [selected, setSelected] = useState<string | null>(null);
  const [onlyExposed, setOnlyExposed] = useState(false);
  const clients = useMemo(() => {
    const cs = (estate.data?.clients ?? []).map((c) => ({ ...c, apps: [...c.apps].sort((a, b) => weight(b) - weight(a)) }));
    return cs
      .map((c) => ({ ...c, apps: onlyExposed ? c.apps.filter((a) => a.exposures.critical + a.exposures.high > 0) : c.apps }))
      .filter((c) => c.apps.length > 0)
      .sort((a, b) => Math.max(0, ...b.apps.map(weight)) - Math.max(0, ...a.apps.map(weight)));
  }, [estate.data, onlyExposed]);

  if (estate.isPending) return <Loading label="Loading the estate" rows={8} />;
  if (estate.isError) return <Failure message={(estate.error as Error).message} retry={() => estate.refetch()} />;
  const totals = severities.map((s) => [s, estate.data.clients.flatMap((c) => c.apps).reduce((n, a) => n + a.exposures[s], 0)] as const);

  return (
    <div className="grid gap-8 lg:grid-cols-[26rem_1fr]">
      <section aria-label="Clients and apps">
        <p className="text-sm text-muted">
          As of {date(estate.data.as_of)}: {totals.map(([s, n]) => `${n} ${s}`).join(", ")} advisories open in production.
        </p>
        <label className="mt-3 flex items-center gap-2 text-sm">
          <input type="checkbox" checked={onlyExposed} onChange={(e) => setOnlyExposed(e.target.checked)} />
          Only apps with a critical or high advisory open
        </label>
        {clients.length === 0 && (
          <Empty title="Nothing critical or high is open">Every app runs a version without a known critical or high flaw.</Empty>
        )}
        <div className="mt-4 flex flex-col gap-5">
          {clients.map((c) => <ClientBlock key={c.id} client={c} selected={selected} onSelect={setSelected} />)}
        </div>
      </section>
      <section aria-live="polite" className="lg:sticky lg:top-6 lg:self-start">
        <AnimatePresence mode="wait">
          {selected === null ? (
            <Empty title="Open an app">
              Pick an app to see which advisories it is exposed to and the version that fixes each, what the platform
              shipped or held and why, and every incident.
            </Empty>
          ) : (
            <motion.div key={selected} initial={{ opacity: 0, x: 12 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0 }} transition={settle}>
              <AppDetailPanel id={selected} />
            </motion.div>
          )}
        </AnimatePresence>
      </section>
    </div>
  );
}

function ClientBlock({ client, selected, onSelect }: { client: Client; selected: string | null; onSelect: (id: string) => void }) {
  return (
    <div>
      <div className="flex items-baseline justify-between border-b border-rule pb-1">
        <h2 className="font-semibold">{client.name}</h2>
        {client.monthly_savings_eur > 0 && <span className="text-xs text-ok">€{Math.round(client.monthly_savings_eur)} a month to save</span>}
      </div>
      <ul>
        {client.apps.map((a) => (
          <li key={a.id}>
            <button onClick={() => onSelect(a.id)} aria-pressed={selected === a.id}
              className={`grid w-full grid-cols-[1fr_auto] items-center gap-3 rounded-[var(--radius-field)] px-2 py-1.5 text-left text-sm hover:bg-sheet ${selected === a.id ? "bg-sheet font-semibold" : ""}`}>
              <span className="truncate">
                {a.name} <span className="font-normal text-muted">{a.stack}, {a.provider}{a.frozen ? ", frozen" : ""}</span>
              </span>
              <ExposureStrip exposures={a.exposures} />
            </button>
          </li>
        ))}
      </ul>
    </div>
  );
}
