# Areas of Concern

This is a code-review style problem list based on the repository state on 2026-03-14. These are not fixes. They are the main things likely to break usage, confuse maintenance, or make the current UI diverge from the actual backend/schema.

## High-impact issues

1. General Costs is implemented in the UI and controllers, but the database table is not created anywhere in the repo.
Evidence:
`cardgraph/public/js/financial-summary.js`, `cardgraph/src/Controllers/FinancialSummaryController.php`, `cardgraph/src/GeneralCostsCsvParser.php`
Problem:
All of those depend on `CG_GeneralCosts`, but no migration creates it.
Impact:
The `Financial Summary -> General Costs` sub-tab and any summary queries that touch general costs will fail on a schema built only from this repo.

2. General-cost uploads also depend on an `upload_type` enum value that is never added in migrations.
Evidence:
`cardgraph/src/Controllers/UploadController.php` writes `upload_type = 'general_costs'`
`cardgraph/sql/001_create_tables.sql` only allows `earnings` and `payouts`
`cardgraph/sql/005_paypal.sql` expands that only to `earnings`, `payouts`, `paypal`
Impact:
Even if a `CG_GeneralCosts` table existed outside the repo, the upload log insert can still fail on a fresh database.

3. Cards Analytics depends on alignment columns that do not exist in the checked-in schema.
Evidence:
`cardgraph/src/Controllers/CardsAnalyticsController.php` joins on `r.matched_order_id` and filters on `r.is_aligned` and `r.is_graded`
`cardgraph/sql/014_table_transcriptions.sql` creates `CG_TranscriptionRecords` without those columns
Impact:
The `Cards Analytics` tab is likely broken or permanently empty unless the production database has manual changes not represented in source control.

4. The transcription `Classifiers` and `AI Parse` screens have frontend code but no backend routes, controllers, or tables.
Evidence:
`cardgraph/public/js/classifications.js` calls `/api/transcription/classifiers`
`cardgraph/public/js/ai-parse.js` calls `/api/transcription/ai-config*`
`cardgraph/public/index.php` defines no such routes
Repo-wide search also finds no matching controller methods or schema tables
Impact:
Those admin screens are currently UI shells, not working features.

5. Parser admin exists, but there is no navigation path to it in the main app.
Evidence:
`cardgraph/public/js/parser.js` expects a `maint-panel-parser` container
`cardgraph/public/js/maintenance.js` never creates that panel, sub-tab, or init call
Impact:
The player/team/maker/style/specialty maintenance tools and MLB refresh actions exist in code but are unreachable from the current UI.

6. The main `Sessions` tab is exposed in primary navigation, but the backend endpoint it uses is admin-only.
Evidence:
`cardgraph/public/js/sessions.js` calls `/api/transcription/sessions`
`cardgraph/src/Controllers/TranscriptionController.php::listSessions()` calls `Auth::requireAdmin()`
Impact:
Non-admin users can click into `Sessions`, but the tab will fail to load.

7. The Sessions UI expects alignment progress fields that the backend never returns.
Evidence:
`cardgraph/public/js/sessions.js` reads `has_alignment` and `aligned_count`
`cardgraph/src/Controllers/TranscriptionController.php::listSessions()` does not supply those fields
Impact:
Alignment status in session cards is misleading at best and nonfunctional at worst.

## Medium-impact issues

8. Fresh setup instructions are outdated relative to the current application surface.
Evidence:
`cardgraph/setup.php` only runs `001_create_tables.sql` and `002_seed_data.sql`
`cardgraph/NAS_SETUP_GUIDE.md` only documents base setup
Current app depends on later migrations such as analytics, PayPal, alerts, transcription, parser support, and table transcriptions
Impact:
A clean install using the documented process will not match the UI the user sees.

9. `cardgraph/public/run_migration.php` is not a migration runner; it is a live database diagnostic script committed into the public web root.
Evidence:
The file directly queries `CG_EbayOrders`, uses hard-coded date ranges, and prints raw results
Impact:
This is confusing, easy to misuse, and not something that should normally be web-accessible in production.

10. The repository contains two overlapping app trees.
Evidence:
Top-level `public/` and `src/` exist beside `cardgraph/public/` and `cardgraph/src/`
Impact:
It is easy to patch the wrong files, document the wrong entry points, or misunderstand which app version is authoritative.

11. A large amount of UI rendering interpolates database/imported text directly into `innerHTML`.
Evidence:
Examples include `line-items.js`, `paypal.js`, `ebay.js`, `analytics-admin.js`, and other modal/table renderers
Impact:
Malformed imported content can break layouts, and unescaped values create a persistent XSS risk.

## Lower-level drift and maintenance concerns

12. Comments and docs often describe a simpler app than the codebase now contains.
Examples:
`setup.php` and `NAS_SETUP_GUIDE.md` still read like a base auction tracker setup, while the actual code now includes PayPal allocation, alerts, transcription, parser support, MLB/MiLB, and cards analytics
Impact:
New maintainers will trust documentation that no longer matches the real dependency graph.

13. Some advanced features appear to depend on out-of-repo/manual production schema changes.
Examples:
`CG_GeneralCosts`, alignment fields for `CG_TranscriptionRecords`
Impact:
The codebase is not currently a reliable source of truth for rebuilding the application from scratch.

## Suggested interpretation

The app is not a single cleanly finished system right now. It is a working core auction-accounting application with several newer layers added on top:

- solid core: auth, dashboard, line items, payouts, PayPal, top buyers, eBay imports, MLB/MiLB
- partially integrated: financial summary general costs, sessions in top nav
- present in code but incompletely wired: parser admin, cards analytics alignment pipeline
- largely stubbed at backend level: transcription classifiers and AI parse config

That split matters because the README and walkthrough should describe the app as it is intended to operate, while this file marks which parts should be treated as suspect before anyone relies on them.
