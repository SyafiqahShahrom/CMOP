# ROADMAP.md — CMOP / TBIP Delivery Roadmap

## 1. Milestones (TBIP Phases 1–6)

### Milestone 1 — Foundation
Authentication (Sanctum), RBAC (Spatie Permission, roles/permissions per SECURITY.md §2-3), base Inertia/Vue layout, dashboard skeleton (static shell, no live data yet), Docker Compose local environment, CI pipeline (lint, static analysis, test scaffolding).
*Exit criteria: a user can log in, see a role-appropriate empty dashboard shell, and CI runs green on an empty-but-real test suite.*

### Milestone 2 — Trade Import & Matching
Trade/payment file import pipeline, normalization, `matching_rules` + matching engine, break detection, supporting queues (`imports`, `matching`).
*Exit criteria: importing a sample trade/payment file set produces correctly matched records and correctly detected breaks, verified by feature tests against known fixture data.*

### Milestone 3 — Case Management
Case lifecycle (WORKFLOW.md §4), evidence upload, notes, timeline view, auto-case-opening from detected breaks.
*Exit criteria: a detected break opens a case automatically, an analyst can investigate it end-to-end (notes, evidence) through the UI.*

### Milestone 4 — Controls
Maker-checker approval flow, synchronous audit logging (Spatie Activitylog wiring per DATABASE.md §6), notifications (assignment, SLA breach, escalation), first cut of operational/compliance reports.
*Exit criteria: a case cannot close without checker approval by a distinct user, every state transition is present in the audit trail, and the maker-checker adherence report (REPORTING.md §4) shows zero violations against test data.*

### Milestone 5 — Analytics
Executive dashboard with live aggregates, scheduled report generation/export (CSV/PDF/XLSX), management reports, performance tuning of dashboard/report queries.
*Exit criteria: the executive dashboard renders live KPIs (REPORTING.md §8) within acceptable latency, and a scheduled report runs end-to-end (generation → notification → download).*

### Milestone 6 — Hardening
Production Docker/CI-CD finalization (DEPLOYMENT.md), caching strategy implementation, monitoring/alerting, full test coverage pass, security review pass against SECURITY.md's threat model.
*Exit criteria: the application is deployed to the target production-shaped environment (Render), CI/CD deploys on merge to `main`, and the project is demoable end-to-end as a portfolio piece.*

## 2. Technical Debt (Tracked, Not Blocking)

- Retention policy (DATABASE.md §7) is documented as a placeholder, not implemented — deferred until a concrete regulatory retention requirement is defined, rather than built against an invented one.
- Login lockout policy beyond rate limiting (SECURITY.md §1) — acceptable for Phase 1-6 scope, flagged for a real production deployment.
- Partitioning (DATABASE.md §8) is a documented future option only, revisited if data volume assumptions change.
- No real-time trade/settlement system integration (ARCHITECTURE.md §8) — file-based import is the Phase 1-6 boundary by design.

## 3. Future Features (Post-Milestone-6, Still Within TBIP)

- Configurable matching rule UI (currently `rule_definition` json is admin-authored directly; a rule-builder UI would lower the barrier for non-engineering Ops config changes).
- Bulk case actions (bulk reassignment, bulk escalation) for high-volume desks.
- Configurable per-desk SLA tiers beyond the default table in WORKFLOW.md §7.
- Saved/custom report definitions (self-service report building) beyond the fixed set in REPORTING.md §2-4.

## 4. Future CMOP Modules (Beyond TBIP)

Candidate modules, in rough order of architectural similarity to TBIP (and therefore expected build effort, lowest first) — see PROJECT.md §11 and ARCHITECTURE.md §11 for the platform-level rationale:

| Module | Description | Notes |
|---|---|---|
| **Settlement Monitoring** | Real-time settlement status tracking across custodians/CSDs | Closest to TBIP's shape (import → status → exception → case); could reuse `Cases`/`Workflow` most directly |
| **Funding & Liquidity** | Intraday liquidity monitoring, funding exception tracking | Similar exception-driven pattern to break detection; new domain-specific data model |
| **Operational Reporting** | Cross-module reporting and regulatory submission tooling | Depends on multiple modules existing first; extends the `Reporting` domain's aggregation surface across domains rather than introducing a new business workflow |
| **Risk Dashboards** | Aggregated operational risk indicators across all CMOP modules | Last, by design — a cross-cutting view is only meaningful once there's more than one module to aggregate across |

Each is expected to follow ARCHITECTURE.md §11's checklist: new domain folder, reuse of `Administration`/`Workflow`/`Audit`/`Notifications` as-is, additive routing — no modification to `Trades`, `Reconciliation`, or `Cases` unless the module is a genuine TBIP extension (e.g., Settlement Monitoring plausibly is; the other three are not).

## 5. Non-Goals (Permanent, Not Just Deferred)

- Microservice decomposition (ARCHITECTURE.md §1) — a deliberate, durable architectural choice, not a stepping stone.
- Customer/counterparty-facing functionality of any kind (PROJECT.md §8) — CMOP is internal tooling by definition; a customer-facing surface would be a different product, not a CMOP module.
- Automated/unattended break resolution — human-in-the-loop resolution with maker-checker is a control requirement, not a current technical limitation to be engineered away.
