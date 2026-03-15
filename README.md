# Card Graph

Card Graph is a PHP + vanilla JavaScript single-page app for running a baseball card auction operation. It centralizes auction earnings imports, item-level cost tracking, payout tracking, PayPal reconciliation, buyer analysis, eBay purchase imports, transcription-driven card parsing, and MLB/MiLB side data used by the alert/ticker system.

This README reflects the code currently in the repository, not an idealized future state. For the detailed reconstruction, read [docs/application-walkthrough.md](docs/application-walkthrough.md). For the known gaps and breakages, read [docs/areas-of-concern.md](docs/areas-of-concern.md).

## What the application is supposed to do

At a high level, the app is meant to support this operating loop:

1. Log in through `cardgraph/public/login.html`.
2. Upload weekly earnings CSVs from the auction platform.
3. Review item-level sales, statuses, and manual cost basis entries.
4. Track outgoing payouts.
5. Upload and classify PayPal transactions, then assign them to Auction, eBay, or Private-Collection activity.
6. Review rollups in Dashboard, Financial Summary, Analytics, and Top Buyers.
7. Optionally import eBay/email purchase activity.
8. Optionally run the transcription pipeline to turn recorded auction audio into structured card records and downstream card analytics.
9. Use Maintenance for admin operations, standards, alerts, users, and transcription settings.

## Main tabs

- `Dashboard`: high-level scorecards, top buyers/spenders, livestream performance, and daily trends.
- `Items & Costs`: item-level ledger view for uploaded earnings data, with status changes and manual cost entry.
- `Payouts`: manual or CSV-managed payout ledger.
- `Financial Summary`: yearly, quarterly, monthly, and general-cost rollups.
- `PayPal`: raw transaction detail, allocation workflow, category breakdowns, reconciliation, and assignment summary.
- `Analytics`: forecasting and milestone pacing across defined business metrics.
- `Top Buyers`: ranking buyers by stream, month, quarter, year, or all-time.
- `Cards Analytics`: performance by player, team, maker, style, or specialty, based on aligned transcription/card records.
- `Sessions`: scheduled recording/transcription jobs for auctions.
- `Email Transactions`: imported eBay/PayPal purchase history from emails.
- `MLB` / `MiLB`: schedules, standings, team profiles, and postseason views.
- `Maintenance`: upload history, statuses, admin tooling, alerts, transcription controls, and users.

## Roles

- `user`: can access the normal authenticated app surface.
- `admin`: required for Maintenance admin tooling and most transcription management.

Some role behavior is inconsistent in the current codebase; see the concerns document.

## Data flow

### Core auction accounting flow

- Earnings CSV upload creates/updates:
  `CG_EarningsStatements`, `CG_AuctionLineItems`, `CG_Livestreams`, `CG_Buyers`, `CG_UploadLog`
- Manual item costs write to:
  `CG_ItemCosts`
- Payout CSVs and manual entries write to:
  `CG_Payouts`
- PayPal CSV uploads write to:
  `CG_PayPalTransactions`, then allocations write to `CG_PayPalAllocations`
- Financial and analytics tabs aggregate those tables into rollups

### Transcription/card analytics flow

- Scheduled session records auction audio into segmented transcription jobs
- Transcript text can be parsed into `CG_TranscriptionRecords`
- Parsed card records are intended to be reviewed, verified, and aligned back to auction orders
- Cards Analytics is intended to summarize those aligned records

## Project layout

The active application lives under [`cardgraph/`](./cardgraph):

- `cardgraph/public/`: web root, HTML, JS, CSS, `.htaccess`
- `cardgraph/src/`: PHP controllers, auth, parsers, helpers
- `cardgraph/sql/`: schema and feature migrations
- `cardgraph/tools/`: Python and shell helpers for transcription and eBay import

There is also a second top-level `public/` and `src/` tree in the repo root. Based on the code, `cardgraph/` is the authoritative app. Treat the duplicate top-level tree as suspect until it is intentionally reconciled.

## Setup reality

The repo contains a setup script and NAS guide, but they only cover the base install. The current app depends on many later migrations and partially implemented features. Do not assume a fresh setup from `setup.php` alone will produce a working environment for the full current UI.

## Review docs

- [Detailed walkthrough](docs/application-walkthrough.md)
- [Areas of concern](docs/areas-of-concern.md)
