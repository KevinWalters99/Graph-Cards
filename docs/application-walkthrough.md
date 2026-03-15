# Card Graph Application Walkthrough

This document reconstructs what the application is intended to do based on the current code in `cardgraph/public/` and `cardgraph/src/`. It is descriptive, not promotional. Where behavior is implied but not fully wired, that is called out in `areas-of-concern.md`.

## 1. Entry point and shell

The app is a sidebar-driven SPA.

- Login page: `cardgraph/public/login.html`
- Main shell: `cardgraph/public/app.html`
- Main bootstrap: `cardgraph/public/js/app.js`

After login, the app:

1. Verifies the database-backed session.
2. Restores the CSRF token.
3. Loads the signed-in user and role into the sidebar.
4. Loads app-wide alerts and the scrolling ticker.
5. Opens the `Dashboard` tab by default.

## 2. End-to-end intended workflow

The code suggests this primary business workflow:

1. Upload earnings CSVs.
2. Review the imported auction line items.
3. Change shipping/payment statuses and add manual card costs.
4. Upload or enter payouts.
5. Upload PayPal CSVs and assign those charges/income to business buckets.
6. Review the resulting financial rollups.
7. Track buyers, profitability, and forecasting.
8. Optionally import eBay/email purchase data.
9. Optionally record and transcribe auctions, parse card records, and analyze card performance.

## 3. Sidebar tabs

## Dashboard

Purpose: top-level business scorecard for the auction operation.

What it shows:

- total livestreams
- uploaded statements
- items sold
- buyers
- giveaways
- shipments
- tips
- buyer-paid totals
- payout totals and pending payout warning
- total sales, fees, item costs, profit, and profit percentage
- top buyers by item count
- top spenders by dollar amount
- earnings by livestream
- daily trend history

User controls:

- date range filters

What it reveals:

- overall operating performance
- which streams produced the most value
- whether payouts are lagging earnings
- who the best buyers/spenders are in recent periods

## Items & Costs

Purpose: operational ledger for each imported auction line item.

What it shows:

- a scorecard very similar to Dashboard, but scoped to the current filters
- a filterable table of imported line items

Main filters:

- status
- buy format
- livestream
- search
- date range

Main actions:

- upload earnings CSV
- open any row for item detail
- change status
- add manual item cost entries
- delete manual cost entries

Detail modal reveals:

- order and ledger IDs
- sale date and transaction type
- buy format and product category
- buyer and livestream
- price, buyer-paid amount, fees, and net amount
- raw transaction message/description
- status history
- all cost entries on the item

This is the main "clean up the imported auction data" surface.

## Payouts

Purpose: track money paid out from the business.

What it shows:

- total payout amount
- payout counts
- completed vs in-progress totals
- payout ledger table

Main actions:

- add payout manually
- upload payout CSV
- edit payout
- delete payout

Filters:

- status
- date range

This tab is meant to reconcile outbound payouts against earnings.

## Financial Summary

Purpose: roll up the business at longer time horizons.

Sub-tabs:

- `Monthly Overview`
- `Summary Overview`
- `General Costs`

### Monthly Overview

Shows a hierarchical Year -> Quarter -> Month -> Day table.

Columns include:

- auctions
- earnings
- fees
- item costs
- payouts
- general costs
- PayPal out
- PayPal in
- net

What it reveals:

- month-by-month profit picture
- where PayPal cash movement overlaps with auction accounting
- daily breakdowns inside a month

### Summary Overview

Shows:

- yearly summary table
- quarterly summary table

What it reveals:

- higher-level performance trends by year and quarter

### General Costs

Purpose:

- manual entry or CSV import of non-item costs
- examples implied by the UI: overhead, supplies, fixed expenses

Actions:

- add cost
- edit cost
- delete cost
- import general costs CSV

Fields:

- date
- description
- amount
- quantity
- total
- distribute across units or treat as lump sum

## PayPal

Purpose: import PayPal activity, classify it, assign it, and roll it into finance.

Sub-tabs:

- `Detail`
- `Assignment`
- `Category Breakdown`
- `Reconciliation`
- `Assignment Summary`

### Detail

Shows:

- transaction counts by category
- total debits, credits, net
- transaction table with charge category and assignment status

Transaction detail modal reveals:

- raw PayPal transaction fields
- allocations already attached to the transaction
- remaining amount not yet allocated
- add/edit/delete allocation actions

### Assignment

Shows:

- how many transactions are unassigned, partial, assigned, or locked
- table of assignable transactions

Main actions:

- auto-assign by order number matching
- manually assign through the detail modal
- lock allocations for a date range
- unlock allocations for a date range

This is the main reconciliation workflow between raw PayPal money movement and business activity.

### Category Breakdown

Shows:

- counts and dollar totals by charge category
- counts and totals by status
- detailed breakdown by PayPal transaction type

Purpose:

- explain how imported PayPal activity is being classified

### Reconciliation

Shows monthly PayPal flow totals:

- purchases
- refunds
- income
- offsets
- withdrawals
- fees
- net PayPal

Purpose:

- monthly cash movement summary feeding the Financial Summary

### Assignment Summary

Shows:

- allocations by sales source
- monthly assignment percentages

Purpose:

- measure how complete the PayPal allocation workflow is

## Analytics & Forecasting

Purpose: forecast business metrics and track milestone pacing.

Structure:

- a `Summary` sub-tab
- one sub-tab per active metric stored in `CG_AnalyticsMetrics`

Summary view shows:

- one card per active metric
- last completed month values
- sparklines
- milestone pacing cards

Metric-specific view shows:

- last completed value
- current month progress
- month forecast
- trend direction and confidence
- future projection chart
- milestone pacing
- monthly data table

This is the forward-looking planning surface rather than the raw accounting surface.

## Top Buyers

Purpose: rank buyers by auction activity.

Scope options:

- all streams
- this month
- last month
- this quarter
- year-based scopes
- individual livestreams

Metrics shown:

- auctions won
- giveaways won
- item quantities
- giveaway quantities
- total quantities
- total earnings
- total costs
- purchaser vs giveaway-only type

This is the buyer concentration and loyalty view.

## Cards Analytics

Purpose: analyze which cards and entities perform best.

Dimensions:

- player
- team
- maker
- style
- specialty

Top summary cards show:

- cards analyzed
- total revenue
- average price
- unique players
- unique teams
- rookie count
- autograph count
- graded count

Dimension table shows:

- rank
- dimension label
- auction count
- revenue
- average/min/max price
- unique buyers
- stream count
- rookie/auto/graded counts where relevant

The intent is to turn parsed card records into market intelligence.

## Sessions

Purpose: schedule and manage auction recording/transcription sessions.

Each session card is intended to show:

- session name
- session status
- scheduled date/time
- segment count and duration
- transcription progress
- parse progress
- alignment progress

Main actions:

- schedule session
- edit scheduled session
- start recording
- stop recording
- delete session
- monitor running session
- jump into Transcription maintenance screens

This is intended as the lightweight operational view of the transcription pipeline.

## Email Transactions

Purpose: manage purchase history imported from eBay and PayPal emails.

What it shows:

- total spent
- total items
- shipping
- tax
- delivered count
- seller count
- filterable order table

Main actions:

- trigger email import
- open an order
- update order status/type/notes
- delete order

Order detail reveals:

- order metadata
- source of the import
- seller
- item list
- subtotal, shipping, tax, total
- delivery date
- linked PayPal transaction ID

This is the inbound purchasing ledger, mostly for non-auction acquisitions.

## MLB

Purpose: live/reference baseball data for the operator.

Sub-tabs:

- `Scores & Schedule`
- `Team Profile`
- `Postseason`

Scores & Schedule shows:

- date navigation
- game cards
- live states, innings, outs, bases
- standings
- wild card standings

Team Profile shows:

- selected MLB club profile
- affiliates
- roster

Postseason shows:

- last season and current season bracket views

## MiLB

Purpose: equivalent baseball reference view for minor league data.

Sub-tabs mirror MLB:

- `Scores & Schedule`
- `Team Profile`
- `Postseason`

The module tracks level-specific standings and team information.

## Maintenance

Purpose: admin and support tooling.

Visible sub-tabs depend on role.

Common sub-tabs:

- `Upload History`: audit log of uploads, row counts, status, and parsed date ranges
- `Status Types`: lookup table for line-item statuses

Admin-only sub-tabs:

- `Cost Matrix`
- `Analytics Standards`
- `Alerts`
- `Transcription`
- `User Management`

### Cost Matrix

Purpose:

- define pricing rules
- preview/apply/clear calculated costs for a selected auction

It also shows auction scorecards so the operator can see the effect of applying the rules.

### Analytics Standards

Purpose:

- edit metric definitions
- manage milestones and recurring milestone windows

### Alerts

Purpose:

- maintain the app-wide alert bar
- maintain the scrolling ticker configuration

Alerts are also tied to recurring checks like whether certain uploads were completed.

### Transcription

Nested sub-tabs:

- `Audio`
- `Table`
- `Classifiers`
- `AI Parse`

#### Audio

Purpose:

- environment check
- global transcription settings
- full session CRUD and monitoring

Settings cover:

- recording parameters
- silence detection
- max duration
- whisper model/CPU usage
- storage/retention
- acquisition mode

#### Table

Purpose:

- select a session
- transcribe pending segments
- parse transcript text into card records
- view raw transcript text
- review/edit/delete parsed records
- verify records and change resolved player/team/maker/style/specialty values

This is the structured-card extraction stage.

#### Classifiers

Intended purpose:

- manage pattern libraries used by the transcript parsing pipeline

Categories shown in the UI:

- closing phrases
- openings/transitions
- price patterns
- giveaway indicators
- false positives
- structural patterns

#### AI Parse

Intended purpose:

- configure external LLM provider
- choose model and API key
- set extraction parameters
- edit system and segment prompts
- view usage stats

This suggests a planned or partial AI-powered parsing pipeline beyond the rule-based parser.

### User Management

Purpose:

- create users
- edit display name, password, role, active state

## 4. Data dependency map

The app is built around a few major data domains:

- auction data: earnings statements, line items, buyers, livestreams, statuses, item costs
- payout data: payouts ledger
- PayPal data: imported transactions and allocations
- eBay data: imported orders and order items
- financial/analytics data: rollups and milestone definitions
- transcription data: settings, sessions, segments, logs, parse runs, card records
- parser reference data: players, teams, makers, styles, specialties
- sports data: MLB/MiLB lookups and cached responses

## 5. What this means operationally

If the app were fully healthy, the intended sequence from start to finish is:

1. Keep earnings uploads current.
2. Clean up the resulting items and costs.
3. Keep payouts current.
4. Keep PayPal imported and assigned.
5. Use Financial Summary and Analytics to understand performance.
6. Use Top Buyers and Cards Analytics to understand who buys and what sells.
7. Use eBay imports to track acquisition spend.
8. Use sessions/transcription to generate deeper card-level intelligence from auction audio.

The concern document explains where the current codebase does not fully deliver that intended flow.
