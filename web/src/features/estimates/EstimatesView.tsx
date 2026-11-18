import { AnimatePresence, motion } from "motion/react";
import { useState } from "react";
import { useDraft } from "../../core/api";
import { Failure } from "../../shared/States";
import { count } from "../../shared/format";
import { open } from "../../theme/motion";

const sample = "We are a regional brewery and need a new website with 14 page templates. Our editors will manage content in a CMS. " +
  "It must be available in 2 languages. There is a shop with checkout and payments. Integrate with Stripe, HubSpot.";

/** A client brief in, a draft concept and estimate out, for a person to check and send. */
export function EstimatesView() {
  const [brief, setBrief] = useState("");
  const draft = useDraft();
  const d = draft.data;
  return (
    <div className="grid gap-10 lg:grid-cols-2">
      <div>
        <label htmlFor="brief" className="text-sm font-semibold">Client brief</label>
        <textarea id="brief" rows={9} value={brief} onChange={(e) => setBrief(e.target.value)} placeholder="Paste the brief, in English or German."
          className="mt-2 w-full rounded-[var(--radius-field)] border border-rule bg-sheet px-3 py-2" />
        <div className="mt-3 flex gap-3">
          <button onClick={() => draft.mutate(brief)} disabled={brief.trim().length < 20 || draft.isPending}
            className="rounded-[var(--radius-field)] bg-action px-4 py-2 font-semibold text-white disabled:opacity-50">
            {draft.isPending ? "Drafting…" : "Draft the estimate"}
          </button>
          <button onClick={() => setBrief(sample)} className="text-sm font-semibold text-action">Use a sample brief</button>
        </div>
        <p className="mt-3 max-w-prose text-sm text-muted">
          The reader only lists what the brief asks for. The price comes from the agency's own past projects; a person
          checks the draft before anything reaches the client.
        </p>
      </div>
      <div aria-live="polite">
        {draft.isError && <Failure message={(draft.error as Error).message} />}
        {!d && !draft.isError && <p className="pt-7 text-sm text-muted">The draft appears here.</p>}
        <AnimatePresence mode="wait">
          {d && (
            <motion.div key={d.concept + d.quote_hours} initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} transition={open} className="pt-6">
              {d.estimate && (
                <p className="text-2xl font-semibold">
                  {count(d.estimate.p80_hours)} hours <span className="text-base font-normal text-muted">to quote (P80); P50 {count(d.estimate.p50_hours)}</span>
                </p>
              )}
              {d.status === "needs_answers" && (
                <div className="mt-4 rounded-[var(--radius-panel)] border-l-4 border-high bg-sheet px-4 py-3 text-sm">
                  <p className="font-semibold">Ask the client before quoting</p>
                  <ul className="mt-1 list-disc pl-5">{d.questions.map((q) => <li key={q}>{q}</li>)}</ul>
                </div>
              )}
              {d.risks.length > 0 && <ul className="mt-4 list-disc pl-5 text-sm">{d.risks.map((r) => <li key={r}>{r}</li>)}</ul>}
              {d.estimate && (
                <p className="mt-4 text-sm text-muted">
                  Closest past projects: {d.estimate.similar.map((s) => `${s.name} (${count(s.hours)} h)`).join("; ")}
                </p>
              )}
              <pre className="mt-4 whitespace-pre-wrap rounded-[var(--radius-panel)] bg-sheet px-4 py-3 text-sm">{d.concept}</pre>
            </motion.div>
          )}
        </AnimatePresence>
      </div>
    </div>
  );
}
