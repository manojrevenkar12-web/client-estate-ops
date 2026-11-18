# 3. A canary with an exact test on quiet sites, and a watch after rollout

## Context
Client apps range from 4 to 400 requests a minute. A z-test on a quiet site's canary declares an update broken on
one unlucky error; a z-test on a busy site declares a harmless 0.05-point change broken. With two thousand canaries
in half a year, a 1% false-alarm rate is twenty pointless rollbacks.

## Decision
`CanaryJudge`: one-sided test at alpha 0.002 (exact Poisson tail when fewer than ten errors are expected, z-test
otherwise), plus a minimum effect of 0.4 points; too few requests extends the canary twice, then decides on what it
has; a failed health check rolls back at once. Majors get a three-hour canary. After a full rollout the same judge
compares six hours of production errors with the six hours before.

## Consequences
29 broken updates stopped at the canary (8 false alarms), 4 more caught by the watch (12 false alarms). Without the
watch, broken-deploy minutes rise from 3.8 to 26 per app-month; without both, to 120.
