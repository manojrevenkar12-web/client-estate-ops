import { useState } from "react";
import { CostView } from "../features/cost/CostView";
import { EstateView } from "../features/estate/EstateView";
import { EstimatesView } from "../features/estimates/EstimatesView";
import { StudyView } from "../features/study/StudyView";

const tabs = [
  { id: "estate", label: "Estate", view: EstateView },
  { id: "cost", label: "Cloud cost", view: CostView },
  { id: "estimates", label: "Estimates", view: EstimatesView },
  { id: "study", label: "Design study", view: StudyView },
] as const;

export function App() {
  const [tab, setTab] = useState<(typeof tabs)[number]["id"]>("estate");
  const View = tabs.find((t) => t.id === tab)!.view;
  return (
    <div className="mx-auto max-w-[var(--width-page)] px-6 pb-16">
      <header className="flex flex-wrap items-end justify-between gap-4 border-b border-rule pt-8 pb-3">
        <div>
          <h1 className="text-xl font-semibold leading-none">Estate desk</h1>
          <p className="mt-1 text-sm text-muted">The agency's delivered apps: what is exposed, what shipped, what it costs. Sandbox data.</p>
        </div>
        <nav aria-label="Sections" role="tablist" className="flex gap-1">
          {tabs.map((t) => (
            <button key={t.id} role="tab" aria-selected={tab === t.id} onClick={() => setTab(t.id)}
              className={`rounded-[var(--radius-field)] px-3 py-1.5 text-sm ${tab === t.id ? "bg-ink text-white" : "text-muted hover:text-ink"}`}>
              {t.label}
            </button>
          ))}
        </nav>
      </header>
      <main className="pt-8"><View /></main>
    </div>
  );
}
