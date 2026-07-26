# API.md — CMOP REST API Conventions

## 1. Scope

The primary UI is server-rendered via Inertia (Vue 3 pages receive props from controllers directly — no separate API round-trip for normal page navigation). The REST API described here serves: (a) any future non-Inertia consumers (mobile, integrations), and (b) AJAX-style actions from within Inertia pages that don't warrant a full page visit (e.g., inline note submission, live search). All business logic is shared between Inertia controllers and API controllers via the same Actions — the API is a thin second entry point, not a parallel implementation.

## 2. Naming & Structure

- Base path: `/api/v1/...` — versioned from day one.
- Resource-plural nouns, no verbs: `GET /api/v1/cases`, not `/api/v1/getCases`.
- Nesting reflects ownership, max one level deep: `GET /api/v1/cases/{case}/notes`, not deeper chains.
- Actions that aren't pure CRUD are modeled as sub-resources or POST-only endpoints, not RPC-style verbs in the URL: `POST /api/v1/cases/{case}/resolution-proposals`, `POST /api/v1/approval-requests/{approvalRequest}/approve`.

## 3. Core Endpoints (TBIP)

```
GET    /api/v1/trade-breaks
GET    /api/v1/trade-breaks/{tradeBreak}

GET    /api/v1/cases
POST   /api/v1/cases/{case}/notes
POST   /api/v1/cases/{case}/evidence
GET    /api/v1/cases/{case}/timeline
POST   /api/v1/cases/{case}/resolution-proposals

POST   /api/v1/approval-requests/{approvalRequest}/approve
POST   /api/v1/approval-requests/{approvalRequest}/reject

GET    /api/v1/dashboard/summary
GET    /api/v1/dashboard/sla-breaches

GET    /api/v1/reports
POST   /api/v1/reports/{scheduledReport}/run
GET    /api/v1/report-runs/{reportRun}/download
```

Case, break, and file creation are not exposed as direct API writes — trades/payments arrive only via the import pipeline (Trades domain), and cases are only opened by the system on break detection, never via a raw `POST /cases`. This mirrors the business rule that cases originate from breaks, not from arbitrary user input.

## 4. Resources

Every API response wraps Eloquent models in an explicit `JsonResource` — models are never returned raw, preventing accidental exposure of internal columns (e.g., `raw_payload`, soft-delete internals). Resources are named `{Model}Resource` and colocated in the owning domain's `Resources/` folder.

## 5. Filtering, Sorting, Pagination

- Filtering: query params scoped to known filterable fields per endpoint, e.g. `GET /api/v1/cases?status=under_investigation&desk_id=3&severity=high`. Unknown filter keys are rejected (422), not silently ignored — protects against a UI bug silently returning unfiltered data.
- Sorting: `?sort=-sla_due_at` (`-` prefix for descending), whitelisted sortable columns per endpoint.
- Pagination: cursor-based for high-volume list endpoints (`trades`, `payments`, `activity` feeds) to stay stable under concurrent writes; offset-based (`?page=`) acceptable for small, bounded lists (e.g., `scheduled_reports`).

## 6. Validation

- All write endpoints validate via Form Request classes (`app/Domains/{Domain}/Requests/`) — never inline `$request->validate()` in controllers.
- Validation errors return `422` with Laravel's standard `{ "message": ..., "errors": { field: [messages] } }` shape, consumed natively by Inertia's form error handling on the frontend.

## 7. Error Responses

| Status | Meaning | Body |
|---|---|---|
| 401 | Unauthenticated | `{ "message": "Unauthenticated." }` |
| 403 | Authenticated but not authorized (policy denial) | `{ "message": "This action is unauthorized." }` |
| 404 | Resource not found or out of scope for this user | `{ "message": "Not found." }` |
| 409 | Business rule conflict (e.g., approving own request, case already closed) | `{ "message": "<specific business rule violated>" }` |
| 422 | Validation failure | `{ "message": ..., "errors": {...} }` |
| 429 | Rate limited | `{ "message": "Too many requests." }` |

**Note on 403 vs 404**: a case outside the requesting user's desk scope returns `404`, not `403` — this avoids leaking the existence of cases the user has no visibility into, consistent with desk-scoping being a confidentiality boundary, not just a UX filter.

## 8. Authentication

Laravel Sanctum, session-based (cookie) authentication for the Inertia SPA and its AJAX calls — no separate token issuance needed since the browser session is the primary client. Sanctum's SPA authentication mode (matching-domain cookie + CSRF) is used rather than bearer tokens, since there is no separate mobile/third-party client in Phase 1–6 scope.

## 9. Authorization

Every API and Inertia controller action authorizes via a Laravel Policy method (`$this->authorize('approve', $approvalRequest)`) before invoking its Action — policies encode both RBAC permission checks and business rules like maker-checker segregation (see SECURITY.md §3). Authorization is never inferred from what the UI shows or hides.

## 10. Rate Limiting

Standard Laravel throttle middleware: `60` requests/minute per user for general API traffic, tightened to `10`/minute on authentication endpoints (login, password reset) to slow credential-stuffing attempts. Report generation endpoints are additionally limited to prevent queue flooding (`5`/minute per user).
