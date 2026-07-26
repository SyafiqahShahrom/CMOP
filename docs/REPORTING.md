# REPORTING.md — CMOP / TBIP Reporting & Analytics

## 1. Report Categories

Reports fall into three audiences, matching the personas in PROJECT.md §5, each with different cadence and depth requirements:

- **Operational** — for analysts/team leads, near-real-time, action-oriented (what's open, what's breaching).
- **Management** — for Ops Managers, trend-oriented, periodic (throughput, aging, staffing signals).
- **Compliance** — for Compliance/Internal Audit, control-adherence-oriented (maker-checker integrity, audit completeness).

## 2. Operational Reports

| Report | Content | Consumers |
|---|---|---|
| Open Breaks by Desk | Current open cases, severity, age, assignee | Analyst, Team Lead |
| SLA Breach Watchlist | Cases within X hours of `sla_due_at`, or already breached | Analyst, Team Lead |
| My Queue | Cases assigned to the current user, sorted by SLA urgency | Analyst |
| Pending Approvals | Cases in `Pending Approval` awaiting a checker on this desk | Team Lead |

These are live dashboard views (queried on demand, short-TTL cached per ARCHITECTURE.md §9), not scheduled exports.

## 3. Management Reports

| Report | Content | Cadence |
|---|---|---|
| Break Volume Trend | New breaks detected, by type/desk, over time | Weekly (scheduled) |
| Aging Report | Distribution of open case age (0-1d, 1-3d, 3-5d, >5d) by desk | Weekly (scheduled) |
| MTTR by Break Type | Mean time to resolution, trailing 30/90 days | Monthly (scheduled) |
| Throughput by Analyst | Cases resolved per analyst, trailing period (staffing signal, not individual performance scoring) | Monthly (scheduled) |

## 4. Compliance Reports

| Report | Content | Cadence |
|---|---|---|
| Maker-Checker Adherence | Every approval decision with maker/checker identities confirmed distinct; zero-violation attestation | Monthly (scheduled) |
| Audit Completeness | State transitions vs. corresponding audit log entries, reconciled 1:1 | Monthly (scheduled) |
| SLA Extension Log | Every logged SLA extension with approving Team Lead, per WORKFLOW.md §7 | On demand + monthly |
| Escalation Log | All manual/automatic escalations with resolution outcome | On demand + monthly |

## 5. Executive Dashboard

Single-page summary (Inertia + ApexCharts) surfacing, at-a-glance and entity-wide (subject to the viewer's desk/entity scope, per SECURITY.md §4):

- Open breaks count, by severity (stacked bar or donut)
- SLA breach rate, trailing 30 days (line chart)
- Aging distribution (bar chart, buckets per §3)
- Throughput trend (resolved cases/week, line chart)

Backed by `GET /api/v1/dashboard/summary` (API.md §3); aggregates are precomputed/cached (short TTL) rather than computed inline per request, since the dashboard is the highest-traffic read path.

## 6. Scheduled Reports

- Modeled by `scheduled_reports` / `report_runs` (DATABASE.md §2) — a report definition (type, cron schedule, recipients, format) generates dated `report_runs` on execution.
- Generation runs as a queued job (Reporting domain), never inline on a web request — report queries can be expensive (e.g., 90-day MTTR aggregation) and must not block the request cycle.
- On completion, `report_runs.file_path` is set and recipients are notified (email, with a secure download link — not the file itself as an attachment, to avoid emailing sensitive data insecurely for larger reports; small operational reports may attach directly).
- A failed run records `error_message` and does not silently retry indefinitely — visible in the Reports UI for a Team Lead/Admin to re-trigger manually (`POST /api/v1/reports/{scheduledReport}/run`).

## 7. Export Formats

- **CSV** — default for tabular management/compliance reports; simplest to reconcile in a spreadsheet, which is the realistic downstream tool for most consumers.
- **PDF** (via DomPDF) — for reports intended for formal distribution/archival (compliance attestations, executive summaries) where layout fidelity matters more than re-manipulation.
- **XLSX** (via Laravel Excel) — for reports consumers are expected to further filter/pivot themselves (aging report, throughput by analyst).

Format is a property of the report definition, not a runtime toggle per download — a compliance attestation report is always PDF, for example, since consistent formal output matters for that audience.

## 8. KPIs

The KPIs tracked in dashboards/reports map directly to PROJECT.md §6 Success Metrics:

- SLA adherence rate (% resolved within SLA, by severity tier)
- MTTR (mean time to resolution), by break type and desk
- Maker-checker violation count (target: always zero — a non-zero value is itself an incident, not a metric to trend)
- Audit completeness rate
- Aged-case count (>5 business days open)

## 9. Charts

ApexCharts on the frontend, fed by pre-aggregated data from the API (never raw case-level data shipped to the browser for charting) — aggregation happens server-side (SQL `GROUP BY` / cached aggregates), keeping payload size and client-side computation minimal. Chart types are chosen for the data shape, not decoration: time series as line charts, categorical breakdowns as bar/donut, no 3D or non-standard chart types that would obscure the underlying numbers.
