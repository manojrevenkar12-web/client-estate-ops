# 5. Cost findings that cannot remove something needed, and estimates priced from history

## Context
The common clean-up script deletes anything idle for two weeks. In the dev estate it would have deleted 19 campaign
sites before their season, 33 staging environments and 2 volumes detached for a migration. Estimates written by a
person overran by more than 15% on 65% of projects; a model asked for an estimate alone did worse.

## Decision
Cost: keep what was busy in the same weeks last year or is tagged keep; schedule idle staging; snapshot before
deleting a volume; downsize only when p99 CPU is low too. Estimates: the reader proposes features, a log-linear
reference class fitted on the agency's past projects prices them, the quote is the P80, and a brief that leaves out
pages or the size of a migration gets questions before a number.

## Consequences
€20,692 a month found with nothing wrong (the script: €10,118 with 54 wrong). Estimates overrun on 9% of projects
and over-quote by more than 1.6× on 19%; a person decides how to present the range.
