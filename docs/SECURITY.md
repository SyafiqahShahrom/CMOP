# SECURITY.md — CMOP / TBIP Security Model

## 1. Authentication

- Laravel Sanctum, session-based (cookie) authentication for the Inertia SPA — see API.md §8.
- Passwords hashed via Laravel's default (bcrypt/argon2id), never stored or logged in plaintext.
- Login throttled (10/minute per IP+email combination) to slow credential-stuffing; account lockout after repeated failures is a Phase 6 hardening item, not implemented against an invented threshold in earlier phases.
- Session cookies: `HttpOnly`, `Secure` (production), `SameSite=Lax` — standard Laravel session config, no custom token handling that would widen the attack surface.

## 2. RBAC (Role-Based Access Control)

Implemented via Spatie Permission. Roles are the primary unit of access; permissions are assigned to roles, not directly to users, so access changes are auditable role changes rather than ad hoc per-user grants.

**Core roles (TBIP):**

| Role | Typical persona | Scope |
|---|---|---|
| `analyst` | Amara (maker) | Own desk; can propose, never approve own proposals |
| `team_lead` | Daniel (checker) | Own desk; approve/reject, reassign, manage queue |
| `ops_manager` | Priya | Read-only across desks in their entity; dashboards and reports |
| `compliance` | Marcus | Read-only, cross-entity; audit trail and compliance reports |
| `admin` | Platform team | RBAC/config administration, no case-data write access by default |

Roles are deliberately coarse; fine-grained business rules (e.g., "cannot approve your own proposal") are enforced in Policies, not modeled as additional permissions — see §3.

## 3. Permission Matrix (TBIP Core Actions)

| Action | analyst | team_lead | ops_manager | compliance | admin |
|---|---|---|---|---|---|
| View case (own desk) | ✅ | ✅ | ✅ | ✅ | ❌* |
| View case (other desk) | ❌ | ❌ | ✅ (own entity) | ✅ | ❌* |
| Propose resolution | ✅ | ✅ | ❌ | ❌ | ❌ |
| Approve/reject resolution | ❌ | ✅ | ❌ | ❌ | ❌ |
| Approve own proposal | ❌ | ❌ | ❌ | ❌ | ❌ |
| Reassign case | ❌ | ✅ | ❌ | ❌ | ❌ |
| Manage RBAC/config | ❌ | ❌ | ❌ | ❌ | ✅ |
| View audit trail | ❌ | ✅ (own desk) | ✅ | ✅ | ❌* |
| Run/schedule reports | ❌ | ✅ (own desk) | ✅ | ✅ | ❌* |

\* `admin` is a configuration role, not an operational one — case data, audit trail, and reports are out of its default permission set, consistent with least privilege; an admin who also needs operational access is granted a second role explicitly, which is itself an auditable, logged decision.

"Approve own proposal" has no ✅ cell for any role — this is a hard-coded Policy invariant (§4), not a permission that could be misconfigured on.

## 4. Policies

Every authorizable action has a corresponding Laravel Policy method, colocated in its owning domain (`app/Domains/{Domain}/Policies/`). Policies compose two kinds of checks:

1. **RBAC check** — does the user's role grant this permission at all (delegates to Spatie's `can()`).
2. **Business-rule check** — desk/entity scoping, and maker-checker segregation (`$approvalRequest->requested_by !== $user->id`).

Policies are the single source of truth for authorization — controllers call `$this->authorize(...)`, never re-implement the check. Desk scoping is enforced at the Policy/query level (via a `HasDeskScope` concern applied to relevant Eloquent queries), not only in the UI, so a scoped-out case is unreachable even via direct URL/API access (see API.md §7 on 404-not-403 for scope violations).

## 5. Maker-Checker Enforcement

- Enforced at the Action layer, not just the Policy layer: `ApproveResolution` and `RejectResolution` Actions independently re-verify `requested_by !== decided_by` before writing, so the invariant holds even if a Policy were ever miswired.
- No UI-only enforcement — hiding the "Approve" button for the proposing analyst is a UX nicety, not the control.
- See WORKFLOW.md §5 for the full maker-checker lifecycle.

## 6. Sensitive Data

- No customer PII is handled — TBIP operates on trade/payment/counterparty reference data, not retail customer records. Counterparty identifiers are institutional (LEI/BIC-style), not personal.
- `trades.raw_payload` / `payments.raw_payload` (json) may contain source-system-specific fields; these are never rendered directly to non-privileged roles and are excluded from API `JsonResource` output by default (see API.md §4).
- Evidence attachments (`case_evidence`) may contain sensitive counterparty correspondence — access is case-scoped via the same desk/entity Policy rules as the case itself, not a separate ACL.

## 7. Encryption

- Data in transit: TLS terminated at the load balancer/Nginx (see DEPLOYMENT.md); HTTP redirects to HTTPS in all non-local environments.
- Data at rest: database-level encryption is an infrastructure/managed-MySQL concern (assumed provided by the hosting platform), not application-implemented — the application does not roll its own row-level encryption for Phase 1–6, since it would complicate querying/indexing without a demonstrated regulatory requirement in this portfolio project's scope.
- Application secrets (DB credentials, mail credentials, `APP_KEY`) via environment variables, never committed — see DEPLOYMENT.md §3.

## 8. Security Headers

Standard Laravel/Nginx security headers applied at the edge: `Content-Security-Policy` (restrictive, Inertia/Vue-compatible), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Strict-Transport-Security` (production only). CSRF protection via Laravel's default token middleware for all state-changing requests, including Sanctum SPA cookie auth.

## 9. Logging

- **Audit logging** (business-relevant state changes) via Spatie Activitylog — see DATABASE.md §6 and ARCHITECTURE.md §6. This is the compliance-facing record, distinct from operational logs below.
- **Operational/application logs** (errors, queue failures, request logs) via Laravel's standard logging stack — never log passwords, session tokens, or full `raw_payload` contents at levels that could be forwarded to a lower-trust log aggregator.
- Failed authentication and authorization attempts (403s, failed logins) are logged for security monitoring, distinct from the business audit trail.

## 10. Threat Model

**In scope threats (mitigated by design):**

| Threat | Mitigation |
|---|---|
| Unauthorized cross-desk data access | Policy-enforced desk/entity scoping at query level, 404 on scope violation |
| Self-approval (maker approves own resolution) | Hard invariant at Action layer, independent of Policy/UI |
| Audit trail tampering or gaps | Synchronous, same-transaction audit writes; no soft/hard delete on transactional tables |
| Privilege escalation via role misconfiguration | Roles/permissions changes are themselves audit-logged; `admin` role excludes operational data access by default |
| Credential stuffing / brute force login | Rate limiting on auth endpoints |
| Injection (SQL, XSS) | Eloquent parameterized queries throughout; Vue's default template escaping; CSP headers |
| CSRF | Laravel CSRF middleware on all state-changing routes |

**Explicitly out of scope (documented, not implemented, for this portfolio project):**

- Nation-state-grade threat actors, physical security, insider threat program (background checks, etc.) — organizational controls outside application scope.
- Formal penetration testing / third-party security audit — recommended before any real production use, not performed as part of this build.
- Regulatory certifications (SOC 2, PCI-DSS, etc.) — no real customer data is processed, so certification is not applicable, but the audit/RBAC/maker-checker design intentionally mirrors what such certifications would expect to see.
