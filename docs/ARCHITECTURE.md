# ARCHITECTURE.md — CMOP Platform Architecture

## 1. Architecture Style: Modular Monolith

CMOP is a single Laravel 12 deployable, organized as a **modular monolith** using Domain-Driven boundaries. This is a deliberate rejection of microservices for this system:

- One operations team, one deployment cadence — network overhead and distributed-systems complexity of microservices buys nothing at this scale.
- Strong consistency needed across domains (a break's state and its audit record must never diverge) — trivial in-process, hard across service boundaries.
- Modular monolith gives 90% of microservice benefits (bounded contexts, independent domain logic, clear ownership) with 10% of the operational cost.
- If a domain later needs to scale or be extracted independently (e.g., a future high-volume module), the domain boundary already exists as a seam — extraction becomes a deployment change, not a redesign.

## 2. Domain Boundaries

```
app/
  Domains/
    Authentication/     # Users, sessions, Sanctum tokens, login/logout
    Administration/     # RBAC (roles/permissions via Spatie), desk/entity scoping, system config
    Trades/              # Trade & payment data ingestion, normalization, storage
    Reconciliation/      # Matching engine, break detection, matching rules
    Cases/               # Case management: case lifecycle, evidence, notes, timeline
    Workflow/            # Maker-checker engine, approval routing, escalation, SLA
    Audit/               # Immutable audit log (wraps Spatie Activitylog), audit queries
    Reporting/            # Report generation, scheduled exports, dashboard aggregation
    Notifications/       # In-app + email notification dispatch (wraps Laravel Notifications)
    Cases/... (see below)
  Shared/
    Concerns/            # Cross-cutting traits (e.g., HasDeskScope, Auditable)
    ValueObjects/         # Money, TradeReference, etc. shared across domains
    Support/              # Framework-agnostic helpers usable by any domain
```

Each domain module contains only the subfolders it needs from this set:
`Actions/ Services/ DTOs/ Policies/ Events/ Listeners/ Jobs/ Models/ Requests/ Resources/ Enums/ Exceptions/ Queries/ Support/`

**Rule of thumb for what belongs in `Domains/X` vs `Shared/`:** if two or more domains need it and it carries no domain-specific business logic (a value object, a generic trait), it goes in `Shared/`. If it encodes a business rule specific to one domain, it stays in that domain even if another domain references it — via that domain's public Actions/Services, never by reaching into its internals.

## 3. Dependency Rules

1. **Domains depend on `Shared/`, never the reverse.**
2. **Cross-domain calls happen only through a domain's public API** — its `Actions` and `Services` classes. No domain reaches into another domain's `Models` directly to mutate state (read-only Eloquent relationships for display purposes are the one accepted exception, and only for querying, never writing).
3. **`Workflow` and `Audit` are dependency sinks, not sources** — every other domain may call into them (to route an approval, to log an action), but they never call back into domain-specific logic. This keeps maker-checker and audit reusable by every future CMOP module.
4. **`Cases` orchestrates `Reconciliation`, `Workflow`, `Audit`, and `Notifications`** to implement the break investigation lifecycle, but `Reconciliation` has no knowledge of `Cases` — matching/break-detection is a standalone capability a case is opened *against*, not the other way around.
5. Controllers are thin: they call one Action or Service method and return an Inertia response or API Resource. Business logic never lives in a controller.
6. New CMOP modules (see §8) follow the same rules — they may depend on `Shared/`, `Workflow`, `Audit`, `Notifications`, and `Administration`, but not on `Trades`, `Reconciliation`, or `Cases` unless the new module is genuinely extending TBIP.

## 4. Application Layers

```
HTTP Layer        Controllers (Inertia + API), Form Requests, API Resources
   |
Application Layer Actions (single business operation), Services (multi-step orchestration)
   |
Domain Layer       Models, DTOs, Enums, Value Objects, domain Events
   |
Infrastructure     Eloquent/PostgreSQL (Supabase), Redis, Queue, Storage, Mail
```

- **Actions** perform one discrete business operation (e.g., `ProposeBreakResolution`, `ApproveCaseResolution`) and are the primary unit of business logic — invokable, single `handle()`/`execute()` method, easily unit-testable.
- **Services** coordinate multiple Actions/steps when an operation spans concerns (e.g., `BreakDetectionService` runs matching, creates break records, opens cases, and dispatches notifications).
- Controllers never contain conditionals on business state — that logic lives in Actions/Policies.

## 5. Data Flow (Example: Trade Import → Break → Case)

```
File Import (Job) -> Trades::ImportTradeFile Action
   -> normalizes rows -> Trade records persisted
   -> dispatches TradeImported event
Reconciliation::MatchingEngine (queued listener on TradeImported)
   -> attempts match against counterparty/internal records
   -> unmatched/mismatched -> BreakDetected event
Cases::OpenCaseFromBreak (queued listener on BreakDetected)
   -> creates Case, assigns severity/SLA, assigns owner
   -> Notifications: CaseAssigned
Analyst resolves via Cases::ProposeResolution Action
   -> Workflow::RouteForApproval (maker-checker)
Checker approves via Workflow::ApproveStep Action
   -> Cases::CloseCase Action
   -> Audit: every step above writes an audit record synchronously in the same transaction as the state change it describes
```

## 6. Event Flow

Domain events (`TradeImported`, `BreakDetected`, `CaseAssigned`, `ResolutionProposed`, `ResolutionApproved`, `CaseClosed`, `SlaBreached`) are the seam between domains. A domain emits events describing what happened in its own vocabulary; other domains subscribe via listeners rather than being called directly. This keeps `Reconciliation` ignorant of `Cases`, and lets future modules subscribe to existing events without modifying TBIP code.

Audit logging is the one exception to "events for cross-domain communication": audit writes happen synchronously inside the same DB transaction as the state change, not via a queued listener, so an audit record can never be lost to a failed queue job.

## 7. Queue & Scheduler Flow

- **Queues (Redis-backed):** file import processing, matching engine runs, notification dispatch, report generation/export. Each queue-worthy job is idempotent and safe to retry.
- **Scheduler:** nightly/intraday scheduled trade & payment file polling, SLA breach sweep (flags cases past SLA and fires `SlaBreached`), scheduled report generation, stale-session/token cleanup.
- Queue jobs are thin wrappers that call a single Action/Service — the job class itself contains no business logic, so the same Action is testable synchronously without a queue.

## 8. Integration Strategy

Phase 1–6 integrations are file-based (SFTP/manual upload of trade and payment extracts, matched against internal reference data). This is intentional: it keeps the portfolio project self-contained and demoable without depending on external systems, while the domain boundaries (`Trades` domain owns ingestion) mean a future real-time feed adapter is an additive change inside `Trades`, not a redesign.

## 9. Caching Strategy

- Redis for: session store, queue backend, cache driver.
- Cached: RBAC permission resolution (invalidated on role/permission change), dashboard aggregate metrics (short TTL, e.g. 60s, since they're near-real-time but expensive to compute per-request), reference data (desks, entities, break-type taxonomy).
- Never cached: case detail, audit trail, anything used to make an approval decision — correctness over latency for anything control-relevant.

## 10. Scaling Strategy

Modular monolith scales vertically first (bank Ops teams are hundreds, not millions, of users) — horizontal scaling of the web tier and queue workers behind a load balancer is the ceiling this design targets, not sharding or service extraction. If a specific domain (e.g., a high-volume future Settlement Monitoring module) later needs independent scaling, its existing domain boundary and event-based integration make extraction to a separate service a bounded, well-understood change rather than a rewrite.

## 11. Adding a New CMOP Module

This is the concrete test of the modular-monolith bet: adding "Funding & Liquidity" as a second module should require:

1. A new `app/Domains/Funding/` folder following the same subfolder convention.
2. Reuse of `Administration` (RBAC), `Workflow` (maker-checker), `Audit`, and `Notifications` as-is, via their public Actions/Services — zero modification to those domains.
3. New routes, Inertia pages, and a new dashboard section — additive to the existing nav/routing structure, not a restructuring of it.
4. No change to `Trades`, `Reconciliation`, or `Cases` unless the new module genuinely extends trade break investigation.

If adding a module ever requires touching unrelated domains, that's a signal the boundary was drawn wrong and should be revisited — not a normal cost of growth.
