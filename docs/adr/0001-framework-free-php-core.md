# 1. A framework-free PHP core, driven by the lab over stdio

## Context
The platform's decisions (version ordering, advisory matching, update lanes, canary verdicts, paging, cost
findings) must be the same code whether they serve the API, a CI pipeline asking for a canary verdict, or the study
that compares designs. Agencies run PHP everywhere; the core should drop into any PHP host.

## Decision
Plain PHP 8.3 with readonly classes, PSR-4 autoloading, PDO and no framework. The API is one class mapping a
method and path to a handler; `bin/estate serve-stdio` answers the same decisions over JSON lines. The lab drives
every design through that process, so the study measures the product rather than a re-implementation.

## Consequences
No framework upgrades to chase in the platform that chases everyone else's upgrades. Routing, validation and auth
are hand-written and tested (`ApiTest`). A Laravel or Symfony shell can wrap `Estate\Http\Api` if the agency prefers.
