# PROJECT.md — Capital Markets Operations Platform (CMOP)

## 1. Vision

CMOP is an internal, enterprise-grade operations platform for Capital Markets Operations, Trade Support, Risk, and Compliance teams. It is built as a **modular monolith**: a single deployable Laravel application organized into cleanly bounded domains, designed so that new operational modules can be added over time without restructuring the platform.

The flagship module, **TBIP (Trade Break & Payment Investigation Platform)**, delivers trade break detection, reconciliation, case management, and payment exception investigation — the daily workflow of a Middle Office / Trade Support desk.

CMOP is not a customer-facing product. It is internal tooling: the kind of system an Operations Technology team at a Tier-1 bank builds and maintains for years.

## 2. Problem Statement

Capital Markets Operations teams reconcile trade and payment data across multiple systems (front office booking systems, custodians, clearing houses, correspondent banks, internal ledgers). Mismatches — "breaks" — arise from timing differences, data entry errors, missed confirmations, failed settlements, and counterparty disputes.

Today, this reconciliation and investigation work is frequently done through:

- Manual spreadsheet reconciliation
- Email threads as the system of record for investigation history
- No enforced segregation of duties between the person who identifies a break and the person who resolves/approves it
- No consistent audit trail suitable for internal audit or regulatory review
- No structured SLA or escalation tracking

This creates operational risk (breaks going unnoticed or unresolved past SLA), audit risk (no defensible record of who did what and when), and inefficiency (analysts re-deriving context that should already be captured).

## 3. Business Value

- **Risk reduction** — breaks are systematically detected, tracked, and escalated rather than relying on manual spreadsheet review.
- **Audit defensibility** — every state transition, approval, and data change is logged immutably and attributable to a user.
- **Segregation of duties** — maker-checker controls ensure no single analyst can both identify and unilaterally close a break above a materiality threshold.
- **Operational efficiency** — case management centralizes evidence, notes, and communication history that today lives in scattered inboxes.
- **Management visibility** — real-time dashboards on open breaks, aging, SLA breaches, and team throughput replace ad hoc status reporting.

## 4. Stakeholders

| Stakeholder | Interest |
|---|---|
| Operations Analyst | Daily user; investigates and resolves breaks and payment exceptions |
| Trade Support Team Lead | Assigns work, monitors team SLA performance, escalation owner |
| Operations Manager | Oversees throughput, aging, staffing; consumes management reports |
| Risk Team | Reviews high-materiality and aged breaks for risk exposure |
| Compliance Officer | Reviews audit trail, maker-checker adherence, regulatory reporting |
| Internal Audit | Periodically reviews controls, segregation of duties, audit log integrity |
| Engineering/Platform Team | Builds, operates, and extends CMOP as new modules are added |

## 5. User Personas

**Amara — Operations Analyst (Maker)**
Front-line user. Triages newly detected breaks, investigates root cause using trade/payment data, attaches evidence, proposes resolution. Cannot approve her own resolutions above materiality thresholds.

**Daniel — Trade Support Team Lead (Checker)**
Reviews and approves/rejects proposed resolutions, reassigns cases, manages team queue, monitors SLA dashboard for their desk.

**Priya — Operations Manager**
Consumes aggregate dashboards and scheduled management reports. Rarely touches individual cases; cares about aging trends, breach rates, and volume by break type/desk.

**Marcus — Compliance Officer**
Read-mostly access to audit trails, maker-checker records, and compliance reports. Needs confidence that controls were followed and evidence is tamper-evident.

**Engineering/Platform Team**
Maintains CMOP, onboards new modules, manages releases, monitors system health.

## 6. Success Metrics

- % of breaks resolved within SLA (target defined per break severity tier)
- Mean time to resolution (MTTR) by break type and desk
- % of resolutions correctly routed through maker-checker (zero self-approval violations)
- Audit trail completeness (100% of state transitions logged — measured, not assumed)
- Reduction in aged (>5 business day) open breaks over time
- User adoption: % of break investigation work conducted in CMOP vs. outside channels (email/spreadsheet)

## 7. Scope (TBIP Module — Phase 1–6)

In scope:

- Trade and payment data import (file-based, scheduled and manual)
- Automated matching engine and break detection
- Case management for investigating and resolving breaks
- Maker-checker approval workflow for resolutions
- Full audit logging of all state changes
- Role-based access control (RBAC)
- Notifications (in-app, email) for assignment, SLA breach, escalation
- Operational, management, and compliance reporting with scheduled exports
- Executive dashboard (open breaks, aging, SLA, throughput)

## 8. Out of Scope (Phase 1–6)

- Direct integration with live trading/settlement systems (Phase 1–6 uses file-based import; real-time integration is a future roadmap item)
- Automated resolution / auto-remediation of breaks (system supports human-in-the-loop resolution only)
- Multi-currency FX netting or settlement instruction generation
- Customer/counterparty-facing functionality of any kind
- Non-TBIP CMOP modules (Funding & Liquidity, Settlement Monitoring, etc.) — future roadmap only

## 9. Core Business Rules

1. A break cannot be closed without a resolution reason and, above the configured materiality threshold, checker approval.
2. The maker (analyst who proposes a resolution) can never be the checker (approver) of that same case.
3. All case state transitions are recorded in an immutable audit log with actor, timestamp, and before/after state.
4. Breaks are automatically assigned a severity tier based on configurable rules (e.g., notional threshold, age, break type); severity determines SLA.
5. SLA clocks pause only for explicitly logged "pending external party" states, and resume automatically otherwise.
6. Users can only act on cases within their assigned desk/entity scope, enforced via policies, not just UI hiding.
7. Evidence attachments are retained and immutable once attached to a case; corrections require a new attachment, not overwriting.

## 10. Constraints

- Must run as a single deployable modular monolith (no microservices) per architectural mandate.
- Must be operable by a small platform team (favor operational simplicity over exotic infrastructure).
- All data must be auditable to a standard consistent with internal bank audit and regulatory expectations, even though this is a portfolio project and not a regulated production system.
- Stack is fixed: Laravel 12 / PHP 8.3+ / MySQL / Redis / Vue 3 / Inertia (see ARCHITECTURE.md).

## 11. Future Roadmap (Beyond TBIP)

CMOP is designed so the following modules can be added as new top-level domains without restructuring the platform (see ARCHITECTURE.md §"Adding a New CMOP Module"):

- **Funding & Liquidity** — intraday liquidity monitoring, funding exception tracking
- **Settlement Monitoring** — real-time settlement status tracking across custodians/CSDs
- **Operational Reporting** — cross-module reporting and regulatory submission tooling
- **Risk Dashboards** — aggregated operational risk indicators across all CMOP modules

Each future module will follow the same domain structure, RBAC model, audit strategy, and maker-checker patterns established by TBIP.
