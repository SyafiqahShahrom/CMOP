# WORKFLOW.md — TBIP Lifecycles

## 1. Trade Lifecycle

```
Imported -> Normalized -> Matched -> (Matched: no further action)
                                  \-> Unmatched/Mismatched -> Break Detected
```

- **Imported**: raw row persisted from a `trade_file` exactly as received (plus `raw_payload` json for full fidelity).
- **Normalized**: mapped into canonical `trades` schema (instrument, counterparty, notional, dates).
- **Matched**: the matching engine (Reconciliation domain) attempted to pair this trade against the counterparty-side or internal reference record.
- Trades are **never re-imported** to "fix" a bad row — a correction arrives as a new file/row; the original is retained for audit.

## 2. Payment Lifecycle

```
Imported -> Normalized -> Matched to Trade (if applicable) -> Matched/Unmatched
```

Payments follow the same import/normalize/match pattern as trades, and may optionally link to a `trades` row (`related_trade_id`) when the payment corresponds to settlement of a known trade. An unmatched or mismatched payment (wrong amount, wrong value date, no corresponding trade) generates a break exactly as a trade mismatch does.

## 3. Break Lifecycle

```
Detected -> Severity Assigned -> Case Opened (auto) -> [see Case Lifecycle] -> Break Closed (on case close)
```

- **Detected**: `Reconciliation::MatchingEngine` finds no match, or a match with fields outside tolerance, for a trade or payment.
- **Severity Assigned**: rule-based (configurable), e.g. based on notional size, break type, and counterparty — determines the SLA tier (see §7).
- A break is 1:1 with a case — detecting a break always opens exactly one case; a break's status mirrors its case's status.

## 4. Case Lifecycle

```
Opened -> Assigned -> Under Investigation -> Resolution Proposed -> Pending Approval
   -> Approved -> Closed
   -> Rejected -> Under Investigation (returns to analyst with checker comments)

Any state (except Closed) -> Escalated (parallel flag, not a replacement state)
```

- **Opened**: created automatically when a break is detected; severity/SLA already attached.
- **Assigned**: auto-assigned by desk/round-robin rule, or manually reassigned by a Team Lead.
- **Under Investigation**: analyst is actively working the case — adding notes, attaching evidence.
- **Resolution Proposed**: analyst (maker) submits a proposed resolution with a reason code and narrative.
- **Pending Approval**: routed to maker-checker (see §5); the proposing analyst cannot approve their own case.
- **Approved -> Closed**: checker approves; case and its underlying break close together.
- **Rejected**: checker sends it back with comments; case returns to Under Investigation, not to Opened — investigation context is preserved.
- **Escalated**: an orthogonal flag (not a lifecycle state) settable at any point before Closed, raised automatically on SLA breach or manually by an analyst/lead; drives notification routing (see §8) without interrupting the underlying state machine.

Below the configured materiality threshold, a case may be configured (per desk policy) to auto-close on resolution proposal without a separate checker step — but this is a desk-level configuration choice, not a code path that bypasses the audit log; the auto-approval is itself logged as a system-approved decision.

## 5. Maker-Checker

- Implemented generically in the `Workflow` domain via `approval_requests`/`approval_decisions`, polymorphically attachable to any approvable entity (currently: case resolutions; future modules reuse the same mechanism).
- **Hard rule**: `requested_by` on an `approval_request` and `decided_by` on its `approval_decisions` can never be the same user — enforced at the Action layer (`ApproveResolution` Action checks this before writing), not just in the UI.
- A rejected approval request is left as `rejected` and a **new** `approval_request` is created on re-proposal — history of every prior attempt is preserved, never overwritten.
- Only users holding the `checker` capability for the case's desk (via RBAC, see SECURITY.md) can act on its `approval_requests`.

## 6. Approval Flow (Detail)

```
Analyst: ProposeResolution Action
   -> creates approval_request (status: pending)
   -> Case status -> Pending Approval
   -> Notification to eligible checkers on the desk

Checker: reviews case, evidence, proposed resolution
   -> ApproveResolution Action (status: approved) -> Case -> Approved -> Closed
   -> or RejectResolution Action (status: rejected, comments required) -> Case -> Under Investigation
```

## 7. SLA

SLA is derived from break severity at detection time and stored as `sla_due_at` on the `trade_breaks` row:

| Severity | Example trigger | SLA |
|---|---|---|
| Critical | Notional above high threshold, or settlement-date break | 4 business hours |
| High | Above materiality threshold | 1 business day |
| Medium | Standard break, below materiality threshold | 3 business days |
| Low | Informational / low-notional | 5 business days |

A scheduled job sweeps open cases hourly; any case past `sla_due_at` and not yet Closed fires `SlaBreached`, which sets the Escalated flag and triggers escalation notification (§8). SLA clocks do not pause automatically — a "Pending External Party" note type exists for analysts to document waiting-on-counterparty periods, but the due date itself only changes via an explicit, logged SLA extension action requiring Team Lead approval (itself routed through the same maker-checker mechanism).

## 8. Escalation

- **Automatic**: SLA breach (§7) escalates to the case's Team Lead and flags the case on the dashboard.
- **Manual**: an analyst or Team Lead can escalate a case at any time with a reason (e.g., counterparty dispute, suspected fraud indicator) — escalation notifies the Operations Manager for that desk.
- Escalation never bypasses maker-checker — an escalated case still requires checker approval to close; escalation changes visibility and urgency, not the control.

## 9. Notifications

| Event | Recipient | Channel |
|---|---|---|
| Case assigned | Assignee | In-app + email |
| Resolution proposed | Eligible checkers on desk | In-app + email |
| Resolution approved/rejected | Proposing analyst | In-app + email |
| SLA breach | Assignee + Team Lead | In-app + email |
| Manual escalation | Operations Manager | In-app + email |
| Scheduled report ready | Report recipients | Email (with attachment/link) |

All notifications are also recorded as `case_timeline_events` where case-scoped, so the case detail view shows a complete communication history alongside the audit trail.
