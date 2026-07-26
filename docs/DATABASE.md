# DATABASE.md — CMOP / TBIP Data Model

## 1. Naming Conventions

- Tables: `snake_case`, plural (`trade_breaks`, `case_notes`).
- Primary keys: `id` (unsigned bigint, auto-increment). Public-facing references use a separate human-readable `reference` column (e.g., `TBIP-2026-000123`), never the raw id, so ids are never exposed as business identifiers.
- Foreign keys: `<singular_table>_id` (e.g., `trade_break_id`).
- Enum-like columns stored as `string` backed by a PHP `Enum` at the application layer, not MySQL `ENUM` columns — avoids painful migrations when a new status value is added.
- Timestamps: `created_at`, `updated_at` on every table. Domain-specific timestamps use explicit names (`resolved_at`, `approved_at`, `sla_due_at`).
- Money columns: stored as `bigint` in minor units (cents) with a paired `currency` `char(3)` column — never `float`/`decimal` rounding ambiguity for financial amounts.

## 2. Core Tables (TBIP)

### Reference / Administration
- `users` — id, name, email, password, desk_id (nullable, FK), is_active, timestamps
- `desks` — id, name, entity, region
- `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` — Spatie Permission standard schema
- `activity_log` — Spatie Activitylog standard schema (backs the Audit domain's immutable trail)

### Trades
- `trade_files` — id, filename, source_system, uploaded_by (FK users), status, imported_at, row_count, error_count
- `trades` — id, trade_file_id (FK), external_trade_id, instrument, counterparty, trade_date, settlement_date, notional_amount, notional_currency, side, status, raw_payload (json), timestamps
- `payments` — id, trade_file_id (FK), external_payment_id, related_trade_id (FK trades, nullable), amount, currency, value_date, counterparty, status, raw_payload (json), timestamps

### Reconciliation
- `matching_rules` — id, name, applies_to (enum: trade|payment), rule_definition (json), is_active, priority
- `matches` — id, trade_id (FK, nullable), payment_id (FK, nullable), matched_trade_id/matched_payment_id (counterparty-side record), match_type (auto|manual), matched_by (FK users, nullable), confidence_score, matched_at
- `trade_breaks` — id, reference (unique, human-readable), trade_id (FK, nullable), payment_id (FK, nullable), break_type, severity, status, detected_at, sla_due_at, resolved_at, timestamps

### Cases
- `cases` — id, reference (unique), trade_break_id (FK), status, assigned_to (FK users), desk_id (FK), opened_at, closed_at, timestamps
- `case_notes` — id, case_id (FK), author_id (FK users), body, timestamps
- `case_evidence` — id, case_id (FK), uploaded_by (FK users), file_path, original_filename, mime_type, checksum, uploaded_at *(immutable — no update, only insert; corrections are new rows)*
- `case_timeline_events` — id, case_id (FK), event_type, actor_id (FK users, nullable for system events), payload (json), occurred_at *(denormalized read model over Audit for fast case-detail rendering; Audit's `activity_log` remains the source of truth)*

### Workflow (Maker-Checker)
- `approval_requests` — id, approvable_type, approvable_id (polymorphic — e.g., a case resolution), requested_by (FK users), status (pending|approved|rejected), reason, timestamps
- `approval_decisions` — id, approval_request_id (FK), decided_by (FK users), decision, comments, decided_at *(insert-only; a re-decision is a new `approval_request`, never an update to a past decision)*

### Notifications
- `notifications` — Laravel standard notifications table (id uuid, type, notifiable, data json, read_at)

### Reporting
- `scheduled_reports` — id, name, report_type, schedule (cron expression), recipients (json), format, created_by (FK users), is_active
- `report_runs` — id, scheduled_report_id (FK, nullable for ad hoc runs), status, file_path, generated_at, error_message

## 3. Key Relationships

```
trade_files (1) ---- (N) trades
trade_files (1) ---- (N) payments
trades (1) ---- (0..1) trade_breaks
payments (1) ---- (0..1) trade_breaks
trade_breaks (1) ---- (1) cases
cases (1) ---- (N) case_notes
cases (1) ---- (N) case_evidence
cases (1) ---- (N) case_timeline_events
cases (1) ---- (N) approval_requests [polymorphic approvable]
approval_requests (1) ---- (N) approval_decisions
users (1) ---- (N) cases [assigned_to]
desks (1) ---- (N) users
desks (1) ---- (N) cases
```

## 4. Indexes

- `trades`: index on (`external_trade_id`), (`trade_date`), (`counterparty`), (`status`)
- `payments`: index on (`external_payment_id`), (`value_date`), (`status`)
- `trade_breaks`: index on (`status`), (`severity`), (`sla_due_at`) — the dashboard's aging/SLA queries filter and sort on these constantly
- `cases`: index on (`status`), (`assigned_to`), (`desk_id`), unique on (`reference`)
- `approval_requests`: index on (`approvable_type`, `approvable_id`), (`status`)
- `activity_log`: index on (`subject_type`, `subject_id`) — standard Spatie index, critical for case-detail audit lookups

## 5. Soft Deletes Strategy

Soft deletes (`deleted_at`) are used **only** on tables representing user-manageable configuration that must not break historical references if removed: `matching_rules`, `scheduled_reports`, `desks`. 

Transactional/audit-relevant tables (`trades`, `payments`, `trade_breaks`, `cases`, `case_notes`, `case_evidence`, `approval_requests`, `approval_decisions`, `activity_log`) are **never soft-deleted or hard-deleted** by application code — they are the system of record. "Removing" a bad case is a status transition (`cancelled`), not a delete. This is a deliberate constraint, not an oversight: an auditor must be able to trust that every row that ever existed still exists.

## 6. Audit Strategy

- All mutations to audit-relevant models (`trades`, `payments`, `trade_breaks`, `cases`, `approval_requests`, `approval_decisions`) are captured via Spatie Activitylog's `LogsActivity` trait, configured to log all fillable attribute changes plus actor and IP.
- Audit writes happen **synchronously**, inside the same DB transaction as the business mutation (see ARCHITECTURE.md §6) — never via an async listener that could fail independently of the state change it describes.
- `case_timeline_events` is a denormalized, case-scoped read projection built from the same underlying events, purely for fast UI rendering of a case's history; `activity_log` remains the canonical audit source for compliance/audit reporting.

## 7. Historical Data & Retention

- No automated deletion of TBIP transactional data in Phase 1–6 — retention policy (e.g., 7-year regulatory retention typical for trade records) is a Phase 6+/production-hardening concern, documented here as a placeholder for a future `RetentionPolicy` configuration rather than implemented against invented regulatory requirements.
- `trade_files` raw imports are retained alongside derived `trades`/`payments` rows to allow full re-derivation/reconciliation replay if a matching rule bug is discovered.

## 8. Partitioning Discussion

At the data volumes a single Ops desk or bank generates (thousands, not billions, of trades/payments per day), MySQL partitioning is not warranted for Phase 1–6 — a well-indexed single table performs adequately and keeps operational simplicity. If volumes grow materially (a future high-throughput module), `trades`/`payments` are natural candidates for range partitioning by `trade_date`/`value_date`, since queries and the immutability of past partitions align well with that key. This is documented as a future option, not a current implementation.
