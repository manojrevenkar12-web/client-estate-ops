# 2. Lanes, a release age and a review budget instead of auto-merging everything

## Context
A bot that merges every update the day it passes CI closes advisories fastest (critical p90 under a day) but in the
dev estate it shipped 523 broken deploys and deployed 814 times into client campaign freezes. People patching once
a month break nothing but leave high advisories open for months and never touch major versions.

## Decision
- Fast lane: critical and high advisories, the smallest version that fixes them, the same day; critical ones also
  during a client's freeze.
- Routine lane: once a week, the newest release in the same major that is at least five days old. If a newer
  release in that line is younger, wait: a quick follow-up usually means the older one had a problem. Yanked
  releases and versions that already failed for this app are never proposed.
- Review lane: new major versions, eight reviews a week across the estate, security fixes first, then the
  furthest behind.

## Consequences
Critical p90 0.9 days, high exposure 548 app-days (today: 13,876), 4 broken deploys. Medium and low advisories wait
up to a week. The review budget is the knob between person-hours and how fast the estate leaves old majors behind.
