# 4. Page only when two regions agree twice

## Context
Uptime tools page on any failed probe by default. In the dev estate 97% of those pages were one region's network
blip; every real outage failed from every region.

## Decision
`Pager`: an incident opens when two regions fail in the same round for two consecutive rounds. A failure within an
hour of a deploy is attributed to it and answered with an automatic rollback. The GCP module's alert policy and the
AWS health check encode the same rule, so the cloud's own alerting agrees with the platform.

## Consequences
4,919 pages became 148, none of them noise; median detection of a real outage is one minute later than paging on
anything. An outage visible from a single region only would not page; the world has none, and production would.
