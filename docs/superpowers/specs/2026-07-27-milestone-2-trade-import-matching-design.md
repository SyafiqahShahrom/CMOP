# Milestone 2 — Trade Import & Matching: Design

## Context

Milestone 1 (Foundation) shipped auth, RBAC, and a dashboard skeleton. Milestone 2 is the first slice of real TBIP business logic, per `docs/ROADMAP.md` §1: trade file import, an automated matching engine, and break detection, backed by queues. This design covers Milestone 2 only — case management (auto-opening a `Case` from a detected break) is explicitly out of scope, deferred to Milestone 3, per `docs/ROADMAP.md`'s milestone split and the decision recorded below.

Payments are also out of scope for this milestone (trades only) — the payment pipeline is structurally identical and will follow the same pattern once the trade pipeline is proven.

## Decisions

1. **Import format: XLSX via Laravel Excel.** Closer to what a real Ops desk receives from a counterparty than CSV; `maatwebsite/laravel-excel` is already in the fixed stack (`docs/PROJECT.md`).

2. **Two-sided matching.** Each import run tags a file as `internal` or `counterparty` source. Matching pairs an internal trade against a counterparty trade sharing the same `external_trade_id`, within the same desk. This is what `docs/DATABASE.md`'s `matches.matched_trade_id` (described as "counterparty-side record") already implies — a single-sided "validate against static rules" model would leave that column meaningless.

3. **Match criteria: exact trade-ref match, tolerance on amount/dates.** A pair is a clean `MATCH` if `notional_amount` is within a small tolerance (default: exact, configurable per rule — see Decision 5) and `trade_date`/`settlement_date` are identical. Outside tolerance → `MISMATCH` break. No counterpart found by end of the matching pass → `UNMATCHED` break.

4. **Single-pass matching, no waiting window.** Matching runs once, synchronously (via queued listener) immediately after each file import completes, against whatever opposite-side trades already exist for that desk. A trade with no counterpart at that moment becomes an `UNMATCHED` break immediately — there's no grace period for the counterpart file to arrive later. This is a deliberate simplification: a production system would hold newly-imported unmatched trades in a pending state until a cutoff (e.g., end of day) before raising a break, avoiding false positives when the two files arrive minutes apart. Building that requires either a scheduled sweep job or a stateful "pending" status with re-evaluation triggers — real complexity for a foundation-layer milestone with no real counterparty feed to actually race against. Documented here as a known limitation and a natural Milestone 2b/6 enhancement, not an oversight.

5. **Matching rules are data-driven but minimal.** `matching_rules.rule_definition` (JSON) is genuinely interpreted by the matching engine at runtime — not decorative schema with hardcoded PHP tolerances behind it. Milestone 2 seeds exactly one active rule for `applies_to='trade'`. A full rule-builder UI (letting Ops config new rules through the product) remains a documented Milestone 6+ future feature per `docs/ROADMAP.md` §3 — out of scope here.

   Rule JSON shape:
   ```json
   {
     "match_key": "external_trade_id",
     "tolerance": {
       "notional_amount": { "minor_units": 0 },
       "trade_date": { "exact": true },
       "settlement_date": { "exact": true }
     }
   }
   ```

6. **Severity/SLA: hardcoded thresholds, not rule-configurable.** One configurable surface (the matching rule) is enough for this milestone. Severity and `sla_due_at` are computed by a plain PHP `BreakSeverityCalculator` using the notional thresholds from `docs/WORKFLOW.md` §7's table. SLA durations use calendar hours/days, not business-day-aware calculations (holiday calendars are a documented future enhancement, consistent with `docs/ROADMAP.md` §2's existing "no business-day SLA" gap being implicitly accepted at this stage).

7. **Milestone boundary: stop at `BreakDetected`.** Milestone 2 writes `trade_breaks` rows and dispatches `BreakDetected`, but nothing consumes that event yet — no `Cases` domain exists. Milestone 3 adds `Cases::OpenCaseFromBreak` as the event's listener. This keeps the milestones cleanly separated per `docs/ROADMAP.md`, at the cost of `trade_breaks` existing without their paired `case` for one milestone — acceptable since nothing in Milestone 2 depends on the case existing.

8. **`trade_files.source_side` distinguishes internal vs. counterparty, not `trades.side`.** `trades.side` (per the existing `docs/DATABASE.md` schema) means trade direction (buy/sell) and keeps that meaning. A new `source_side` enum (`internal` | `counterparty`) is added to `trade_files` instead — every trade inherits its role from the file it was imported in.

## Architecture & Data Flow

```
Analyst uploads XLSX (source_side + desk) via Inertia form
  -> TradeFilePolicy::create authorizes (own desk only)
  -> Trades::ImportTradeFileAction stores the file, creates a `trade_files` row (status: pending)
  -> dispatches Trades::ImportTradeFileJob (queue: imports)

ImportTradeFileJob
  -> parses XLSX via Support/TradeRowImport (Laravel Excel import class)
  -> normalizes rows -> `trades` records persisted (raw_payload retains full row fidelity)
  -> trade_files.status -> imported, row_count/error_count set
  -> dispatches TradeImported event (payload: trade_file_id)

Reconciliation::RunMatchingEngine (queued listener on TradeImported, queue: matching)
  -> loads the active `matching_rules` row for applies_to=trade
  -> for each new trade in the file:
       - looks for an unmatched opposite-source trade, same desk, same external_trade_id
       - found + within tolerance -> Match row (match_type: auto), both trades -> matched
       - found + outside tolerance -> TradeBreak (type: mismatch), severity/SLA via BreakSeverityCalculator
       - not found -> TradeBreak (type: unmatched), severity/SLA via BreakSeverityCalculator
  -> each TradeBreak creation dispatches BreakDetected (no listener yet — see Decision 7)
```

## Data Model Changes

New migrations (all additive, per `docs/DEPLOYMENT.md` §9):

- `trade_files`: add `source_side` (string, backed by PHP enum `TradeFileSource: internal|counterparty`), not nullable, no default (must be explicit at upload time).
- `matching_rules`: `id`, `name`, `applies_to` (string enum, `trade|payment`), `rule_definition` (json), `is_active` (boolean, default true), `priority` (integer, default 0), timestamps.
- `matches`: `id`, `trade_id` (FK trades, nullable), `payment_id` (FK payments, nullable — unused this milestone), `matched_trade_id` (FK trades, nullable), `matched_payment_id` (FK payments, nullable — unused this milestone), `match_type` (string enum `auto|manual`), `matched_by` (FK users, nullable), `confidence_score` (decimal, nullable), `matched_at` (timestamp), timestamps.
- `trade_breaks`: `id`, `reference` (unique, human-readable — format `TBIP-{year}-{zero-padded-sequence}`), `trade_id` (FK trades, nullable), `payment_id` (FK payments, nullable — unused this milestone), `break_type` (string enum `mismatch|unmatched`), `severity` (string enum `critical|high|medium|low`), `status` (string enum, starts `open`), `detected_at`, `sla_due_at`, `resolved_at` (nullable), timestamps.

`trade_files` and `trades` do not exist yet — Milestone 1 only built `users`/`desks`/Spatie's tables — so this milestone's migrations include the base `trade_files` and `trades` tables (per `docs/DATABASE.md` §2's "Reference/Trades" schema) plus the `source_side` addition, not a retrofit onto pre-existing tables. `payments` is not created this milestone at all (deferred with the rest of the payment pipeline).

Indexes per `docs/DATABASE.md` §4: `trades` on (`external_trade_id`), (`trade_date`), (`counterparty`), (`status`); `trade_breaks` on (`status`), (`severity`), (`sla_due_at`).

## Domain Placement

- **`app/Domains/Trades/`**: `Models/TradeFile.php`, `Models/Trade.php`, `Actions/ImportTradeFileAction.php`, `Jobs/ImportTradeFileJob.php`, `Support/TradeRowImport.php` (Laravel Excel import class), `Enums/TradeFileSource.php`, `Enums/TradeFileStatus.php`, `Enums/TradeStatus.php`, `Events/TradeImported.php`, `Policies/TradeFilePolicy.php`, `Requests/UploadTradeFileRequest.php`.
- **`app/Domains/Reconciliation/`**: `Models/MatchingRule.php`, `Models/Match.php`, `Models/TradeBreak.php`, `Services/MatchingEngine.php`, `Support/BreakSeverityCalculator.php`, `Listeners/RunMatchingEngine.php`, `Events/BreakDetected.php`, `Enums/BreakType.php`, `Enums/BreakSeverity.php`, `Enums/TradeBreakStatus.php`, `Enums/MatchType.php`.

Matches `docs/ARCHITECTURE.md` §3's dependency rule: `Reconciliation` has no knowledge of `Cases` (nothing here references a `Cases` domain that doesn't exist), and cross-domain communication happens via the `TradeImported`/`BreakDetected` events, not direct calls.

## Policies & Desk Scoping

`TradeFilePolicy`:
- `create(User $user): bool` — true for `analyst`, `team_lead`, `admin`.
- `view(User $user, TradeFile $file): bool` — true if `$user->desk_id === $file->desk_id`, or `$user->hasRole('ops_manager')`/`hasRole('compliance')` (cross-desk read, per `docs/SECURITY.md` §3's permission matrix).

A request for a file outside the user's desk (and without cross-desk role) returns 404, not 403, per `docs/API.md` §7. This is also where Milestone 1's final review's parked finding — that `DeskPolicy` didn't demonstrate real desk-scoping — gets addressed for real: `TradeFilePolicy` has a genuine scoping predicate, not just a role check, and later domains (`Cases`, `Workflow`) copy this shape rather than `DeskPolicy`'s role-only one.

## Testing Strategy

- `tests/Fixtures/`: three small XLSX pairs (internal + counterparty each) — clean match, notional mismatch, unmatched row.
- Feature tests use `Excel::fake()` to assert the correct import triggers the right job/rows for isolated upload-flow tests.
- At least one full integration test processes fixtures through the real pipeline synchronously (`Queue::fake()` only where isolating a single unit; the end-to-end test lets `ImportTradeFileJob` and `RunMatchingEngine` actually run) and asserts: clean pair → `Match` row + both trades `matched`; mismatched pair → `TradeBreak` type `mismatch`; unmatched row → `TradeBreak` type `unmatched`, correct severity from `BreakSeverityCalculator`.
- `MatchingEngine` and `BreakSeverityCalculator` get direct unit tests independent of the queue/HTTP layers.
- `TradeFilePolicy` gets a policy test following `DeskPolicyTest`'s shape (Milestone 1 pattern), with an added desk-scoping case.

## UI Scope

- Upload page (`Trades/Upload.vue`): desk (defaulted to the user's own desk), source side, file picker, submit.
- Trade files + breaks list page (`Trades/Index.vue` or similar): filterable by desk/status, showing imported files and any detected breaks — enough to demo the pipeline end-to-end. Not the full operational dashboard (charts, aging, SLA breach watchlist) — that's Milestone 5 per `docs/REPORTING.md`.

## Explicitly Out of Scope (This Milestone)

- Payments (any table/pipeline) — deferred, same pattern as trades, later milestone.
- Case auto-opening on `BreakDetected` — Milestone 3.
- Configurable rule-builder UI — Milestone 6+ per `docs/ROADMAP.md` §3.
- Business-day-aware SLA calculation — future enhancement, calendar-time used instead.
- Waiting-window/pending-match state — single-pass matching only, documented limitation (Decision 4).
- Manual re-matching / manual match override UI — `matches.match_type` supports `manual` in the schema for future use, but no UI/Action creates one this milestone.
