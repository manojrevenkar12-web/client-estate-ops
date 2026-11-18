# The held-out estate

`config/heldout.json` keeps the dev estate's calendar and catalogue of package names and changes the rest: seed
907 (other clients, apps, releases, advisories and luck), more Python and Node apps, more advisories with more
critical ones and more fixes only in a new major, more probe blips and longer outages, and client briefs written in
a second set of phrasings ("roughly 18 different layouts", "a private area for dealers", "everything on our TYPO3")
that the estimate reader was never written against.

Every product choice was fixed on dev first: the lanes, the five-day release age and the follow-up rule, the review
budget, canary alpha, minimum effect and durations, the six-hour watch, the paging rule, the cost guards, the
reader's keywords and the gate thresholds. `make heldout` ran the study once and wrote `reports/heldout.lock`; the
study refuses to run the held-out estate again. The README reports what came out, including what got worse.

## What it showed

- Updates, canary, paging and cost guards held: critical p90 1.0 days, high exposure 717 app-days (today 13,212),
  3.0 broken-deploy minutes per app-month, 239 pages with none of them noise, 0 wrong cost findings.
- The estimate reader failed: 218 features misread across 80 briefs written in new phrasings, every brief flagged as
  needing answers, and 88% of the drafted numbers would have overrun by more than 15%. The gate fails on this line.
  Nothing was changed after the run; a better reader needs a fresh held-out set.
