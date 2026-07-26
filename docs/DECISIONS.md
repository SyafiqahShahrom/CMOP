# DECISIONS.md — Architecture Decision Records

Each ADR follows: Problem, Options Considered, Decision, Tradeoffs. Numbered sequentially; superseded ADRs are marked, never deleted.

---

## ADR-001: Platform/Module Naming — CMOP as Platform, TBIP as First Module

**Problem**: The system needs a name. Naming it directly after trade break investigation (e.g., "Trade Break Manager") would describe only the first capability, making it awkward to introduce unrelated future capabilities (funding, settlement monitoring, risk dashboards) under the same product without the name feeling outgrown.

**Options considered**:
1. Single name describing only trade break investigation (e.g., "Trade Break Manager").
2. Two-tier naming: a platform name (CMOP) with the trade break workflow as a named module (TBIP) within it.
3. Generic platform name with no distinct module identity — treat everything as one undifferentiated "CMOP" feature set.

**Decision**: Option 2. **CMOP (Capital Markets Operations Platform)** is the platform; **TBIP (Trade Break & Payment Investigation Platform)** is its first, flagship module. All Phase 1-6 documentation and code refers to both names consistently — the platform's architecture (domains, RBAC, maker-checker, audit) is designed platform-first, demonstrated module-first through TBIP.

**Tradeoffs**: Costs a small amount of naming/documentation overhead (two names to keep straight, an ADR to explain the split) versus Option 1. Buys a domain boundary that is real from day one rather than retrofitted — when a second module is added, it's additive to an existing multi-module story, not a rename/restructure of a single-purpose product. Option 3 was rejected because "no distinct module identity" contradicts the explicit modular-monolith goal (ARCHITECTURE.md §1) — the module boundary needs to exist in naming and documentation, not just in a folder structure nobody references by name.

---

## ADR-002: Modular Monolith over Microservices

**Problem**: How should CMOP's domains (Trades, Reconciliation, Cases, Workflow, Audit, etc.) be deployed and organized — as independently deployable services, or as one application?

**Options considered**:
1. Microservices — one deployable per domain, communicating over the network.
2. Modular monolith — one deployable, domains as in-process bounded contexts with enforced dependency rules.
3. Unstructured monolith — one deployable, no enforced domain boundaries (typical rapid-CRUD Laravel app structure).

**Decision**: Option 2. See ARCHITECTURE.md §1 for the full rationale.

**Tradeoffs**: Gives up independent per-domain scaling and deployment (Option 1's main benefit) and gives up nothing meaningful at this system's realistic scale (hundreds, not millions, of users — ARCHITECTURE.md §10). In exchange, gains transactional consistency across domains (a break's state and its audit record can never diverge, since they commit in the same DB transaction — DATABASE.md §6) and dramatically lower operational complexity for a small platform team. Rejected Option 3 because "no enforced boundaries" is exactly the failure mode DDD organization exists to prevent — it would degrade into the same maintainability problems microservices are sometimes (wrongly) reached for to solve.

---

## ADR-003: Money Stored as Integer Minor Units, Not Decimal/Float

**Problem**: How should monetary amounts (`notional_amount`, `payments.amount`) be stored and manipulated?

**Options considered**:
1. `float`/`double` columns.
2. `decimal(p,s)` columns.
3. `bigint` storing minor units (cents), paired with a `currency char(3)` column.

**Decision**: Option 3. See DATABASE.md §1.

**Tradeoffs**: Requires every monetary value to be explicitly converted at the domain boundary (a `Money` value object in `Shared/ValueObjects`, per ARCHITECTURE.md §2, wraps this so application code never manipulates raw minor-unit integers directly) — a small, one-time discipline cost. In exchange, eliminates an entire class of floating-point rounding bugs (Option 1) that are unacceptable in a financial reconciliation system, and avoids `decimal` arithmetic's subtler cross-database/ORM rounding inconsistencies (Option 2) in favor of exact integer arithmetic that behaves identically everywhere.

---

## ADR-004: Enum-like Status Columns as Application Enums, Not MySQL `ENUM`

**Problem**: How should status/type columns (`trade_breaks.status`, `cases.status`, `break_type`, etc.) be represented at the schema level?

**Options considered**:
1. MySQL native `ENUM` column type.
2. `string`/`varchar` column, validated and typed via a PHP backed `Enum` at the application layer.
3. Separate lookup table with a foreign key.

**Decision**: Option 2. See DATABASE.md §1.

**Tradeoffs**: Loses database-level enforcement of valid values (a raw SQL `UPDATE` could theoretically write an invalid string) versus Option 1 — accepted because all writes go through Eloquent/Actions in this codebase, never raw SQL, so the enforcement point is the application layer regardless. Gains painless addition of new status values (a code change + migration to widen a `varchar` if ever needed, versus an `ALTER TABLE ... MODIFY ENUM` that risks a table rewrite in MySQL) and native PHP enum ergonomics (match expressions, IDE autocomplete). Rejected Option 3 (lookup table) as unnecessary indirection — these are small, code-defined vocabularies, not user-managed reference data, so a lookup table would add a join for no real flexibility benefit.

---

## ADR-005: Case-Break Cardinality — Strict 1:1

**Problem**: Should a single detected break be able to spawn multiple cases, or should multiple breaks be consolidatable into one case?

**Options considered**:
1. 1:1 — every break opens exactly one case, always.
2. N:1 — multiple related breaks can be consolidated into a single case.
3. 1:N — a single break could theoretically have multiple cases opened against it over time (e.g., after a rejected/reopened cycle).

**Decision**: Option 1, strict 1:1. See DATABASE.md §3 and WORKFLOW.md §3.

**Tradeoffs**: Gives up the analyst convenience of investigating genuinely related breaks (e.g., the same counterparty's whole batch of failed trades on one bad day) under one case — that pattern isn't supported and would require opening multiple cases with cross-references at the note level instead. In exchange, keeps the state machine simple and unambiguous: a case's status and a break's status are always in lockstep, audit queries never need to reason about many-to-many case/break relationships, and "which case is this break's case" is never an ambiguous question. This is flagged in ROADMAP.md §3 as a candidate future feature (case consolidation) if real usage shows the convenience is worth the added complexity — deliberately not built ahead of that evidence.

---

## ADR-006: File-Based Integration Only for Phase 1–6

**Problem**: Should TBIP integrate with source systems (front-office booking, custodians, clearing houses) via real-time feeds/APIs, or via file import?

**Options considered**:
1. Real-time API/message-bus integration with each source system.
2. File-based import (SFTP/manual upload of extracts).
3. Hybrid — files for some sources, real-time for others.

**Decision**: Option 2. See ARCHITECTURE.md §8.

**Tradeoffs**: Real trading desks increasingly expect near-real-time break detection, which Option 1 would deliver and Option 2 does not — this is a genuine functional limitation, not just a simplification. Accepted because this is a portfolio project without real source systems to integrate with; building against invented APIs would add complexity without adding a demonstrable capability, and would make the project impossible to run/demo standalone. The `Trades` domain owns ingestion behind its own Action/Service boundary specifically so a future real-time adapter is additive (a new event source feeding the same `ImportTradeFile`-equivalent pipeline), not a redesign — Option 3 (hybrid) is effectively the natural evolution once a real-time source exists, not a decision that needs to be made now.

---

## ADR-007: Audit Writes Synchronous, Not Queued

**Problem**: Should audit log entries (Spatie Activitylog) be written synchronously with the business mutation, or dispatched asynchronously via a queued listener like other cross-domain side effects?

**Options considered**:
1. Synchronous — audit write happens in the same DB transaction as the state change.
2. Asynchronous — audit write happens via a queued listener on the domain event, consistent with how other cross-domain effects (notifications, etc.) are handled.

**Decision**: Option 1. See ARCHITECTURE.md §6 and DATABASE.md §6.

**Tradeoffs**: Breaks the project's general pattern of "events for cross-domain communication" (ARCHITECTURE.md §6) as a deliberate, called-out exception, and adds (marginal) latency to the request that makes the mutation. In exchange, guarantees an audit record can never be lost to a failed/delayed queue job — for a system whose core value proposition is audit defensibility (PROJECT.md §3), an eventually-consistent or possibly-lost audit trail would undermine the product's reason for existing. This tradeoff is judged worth making precisely because audit-write volume is low relative to typical async workloads (queue file imports, notifications), so the latency cost is negligible in practice.
