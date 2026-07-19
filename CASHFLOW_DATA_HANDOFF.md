# BBW MRP — Cash Flow Dashboard: Data & Module Handoff

**Companion to `DESIGN_HANDOFF.md`.** That file tells you *how* to build a page here (Berry/Bootstrap, Chart.js, card patterns). **This file tells you *what data exists* and *how to get it*** so you can design a strong **cash flow dashboard**.

**Read this first, then imitate `home.php` (layout) using the data functions below.**

> ⚠️ There is already a working cash-flow page (`cashflow.php`) with a full forecast engine (`includes/cashflow.php`). **You do not need to invent the data layer — it exists.** Your job is a better *dashboard*: a clean, at-a-glance view built on the functions and tables documented here. Reuse the engine; don't rebuild it.

---

## 1. The business, in money terms

Blue Bird Waterfowl is a **small manufacturer** (waterfowl calls / camshaft-based products). Cash mechanics:

- **Cash IN** — mostly **Shopify sales** (DTC + wholesale + tradeshows), plus some Amazon. Actual *cash received* is best measured from **QuickBooks cash-basis income** (handles the lag between an order and money landing). Shopify revenue is the *sales run-rate* (order-date), used for trend/seasonality and next-year projection — **not** as received cash.
- **Cash OUT** —
  - **Raw-material purchase orders (POs)** to vendors → the MRP `orders` table. Charged to a credit card on a chosen **`pay_by`** date.
  - **Finished-product imports** (e.g. FP WINGZ / cases from China) bought directly on a card → `fp_purchases` table.
  - **QuickBooks bills (AP)** with vendor due dates.
  - **Recurring operating expenses** (rent, payroll, software…) → `cash_expenses` or a QBO expense estimate.
  - **Debt service** — credit cards + lines of credit (incl. Shopify Capital, modeled as a % of sales).
- **Financing** — bank accounts (cash), credit cards, and **multiple lines of credit** (e.g. Shopify Capital ~$49k, QuickBooks/other ~$85k), each its own facility with its own ceiling.

The whole point of the cash-flow view: **project the next 12 months of cash in vs. cash out, track running bank balance and debt, and tell the owner what to pay from which card/LOC and when.**

---

## 2. Existing engine — entry points (call these, don't re-query)

All in `includes/cashflow.php`. A page does:

```php
require_once(__DIR__."/includes/cashflow.php");
$db       = db_connect();
$data     = build_cashflow_data($db);                  // §3 — the "now" snapshot
$forecast = build_cashflow_forecast($db, $data, 12, $growthPct);  // §5 — 12-mo projection
$recur    = load_recurring_expenses($db);
$events   = load_cash_events($db);
$monthData= build_month_blocks($db, $data, $forecast, $events);   // §5 — rich per-month blocks
$thisMonth= build_this_month($db, $monthData, $data, $forecast);  // §5 — live "This Month" tracker
$syncedAt = cf_synced_at($db);                         // last QBO/Shopify sync timestamp
```

`build_this_month(...)` is the live current-month tracker: cash-in/out split into **received/remaining vs planned**, debt guidance, LOC due-day status (`upcoming`/`drawing`/`cleared`), an intra-month **burn-rate projection with the day cash would cross the buffer**, and a year-over-year vs the same month last year. Ideal source for a "this month" hero panel.

You can pull any individual dataset via the loaders in §3–§4 without re-implementing SQL.

---

## 3. The "now" snapshot — `build_cashflow_data($db)` returns `$data`

The single richest object. Key fields:

| `$data[...]` | Meaning | Source |
|---|---|---|
| `eff_cash` | **Cash on hand** (effective) — manual bank total if entered, else QBO bank total | manual `cash_balances` or QBO `Account` type Bank |
| `eff_credit` | **Total credit/LOC owed** (debt) | manual or QBO liability accounts |
| `cash_source` / `credit_source` | `'manual'` or `'quickbooks'` (provenance badge) | — |
| `ar_total` | **Owed to you** — open Shopify receivables (unpaid/pending orders + open drafts) | Shopify |
| `ap_total` | **Owed by you** = QBO bills + unpaid MRP POs | QBO + `orders` |
| `net_quick` | **Net position** = `eff_cash + ar_total − ap_total` | derived |
| `bills.items[]` | QBO open bills: `vendor, balance, due, date` | QBO `Bill` |
| `bills.total` | total QBO AP | — |
| `pos.items[]` | Unpaid MRP POs: `ref, supplier, part, balance, date, pay_by` | `orders` |
| `pos.total` | total unpaid PO balance | — |
| `ar.items[]` | Shopify receivables: `name, customer, amount, date, type, expected` | Shopify + `ar_schedule` |
| `manual.bank[]` / `manual.credit[]` | per-account rows (see §4a) | `cash_balances` |
| `manual.loc_facilities[]` | per-LOC: `name, ceiling, drawn, payment, available, overdrawn` | `cash_balances` + `loc_ceilings` |
| `manual.credit_available` / `credit_limit_total` | available credit across cards + LOCs | — |
| `manual.due_count` / `update_days` | # of balances older than the staleness threshold | — |
| `queue[]` | **Pay-planner queue** — every obligation (bills + POs) sorted by due date, each with `what, detail, amount, due, running` (running cash after paying it in order) | derived |
| `qb_connected`, `qb_company` | integration status / company name | QBO |

**The four KPI cards the current page shows** (good baseline, improve on them): Cash on Hand (`eff_cash`), Owed to You (`ar_total`), Credit/LOC Owed (`eff_credit`), Net Position (`net_quick`).

---

## 4. Every data source that can feed the dashboard

### 4a. Manual account balances — `cash_balances` (authoritative cash/debt)
Owner enters real balances with an "as of" date (QBO can lag). Loader: `load_manual_balances($db)`.

```
cash_balances(id, label, acct_type['bank'|'credit'|'loc'], balance, credit_limit,
  monthly_payment, as_of, note, qb_account_id, apr, loc_name, due_day, updated_at, user_id)
```
- **bank** rows = cash assets. **credit** = credit card. **loc** = line-of-credit draw (each tagged to a facility via `loc_name`; facility ceilings come from the `loc_ceilings` setting).
- `apr` drives the **avalanche paydown** (highest APR first). `monthly_payment` = scheduled min for LOC loans; card minimums are auto-computed (`card_min_pct` of balance, floor `card_min_floor`).
- `due_day` = day-of-month a LOC payment auto-draws. `as_of` staleness threshold = `balance_update_days` (default 7) → `manual.due_count`.

### 4b. MRP purchase orders — `orders` (internal AP, cash OUT to vendors)
The core MRP money-out table. **This is a PURCHASE order** (money to suppliers), not a sales order.

| Column | Meaning for cash flow |
|---|---|
| `ordval` | Total PO value = `parts.cost × qty` at placement |
| `paidamt` | Amount paid so far (via `payments`) — **unpaid balance = `ordval − paidamt`** |
| `qty` / `recqty` | ordered vs received; "open" = `qty > recqty` |
| `pay_by` (DATE) | **The cash-out timing field** — required on every new PO; forecast lands the unpaid balance in this month (overdue → current month) |
| `orderdate` / `postdate` / `eta` | placed / received / expected-arrival (ETA is logistics, **not** a payment date) |
| `partid` → `parts` → `manufacturer` → `manufacturers.name` | vendor is derived by join (no vendor column on the order) |

Unpaid POs are assembled into `$data['pos']` (see §3). Note: `orders` has **no vendor "terms"/Net-30** concept — `pay_by` is the manually-set charge date.

### 4c. Payments ledger — `payments`
`payments(id, date, ordid→orders.id, amount, ref)`. Each payment inserts a row **and** increments `orders.paidamt`. This is the actual **cash-out history** (use `date` for monthly paid totals). `home.php:247-262` already charts monthly orders-placed vs payments-made.

### 4d. Inventory valuation & future purchasing — `parts` + `trans`
- `parts`: `cost`, `qoh`, `bsl` (reorder target), `imoq` (MOQ rounding), `lead_time`, `omit`, `manufacturer`. Inventory value = `SUM(qoh*cost)`.
- **Future cash-out (reorder pipeline):** `cashflow_reorder_suggestions($db, $limit)` returns parts below stock level with MOQ-rounded `order` qty, `lead_days`, and **`cost` (projected spend)**. Great for a "raw materials to buy soon" cash-out signal.
- `trans` (type `BUILD`, negative qty, trailing 12 mo) = **demand rate** driving reorder timing. `trans` carries quantities only, no dollars.

### 4e. Header KPIs (already computed in `includes/header.php`) — inventory-money context
`On-Hand value` `SUM(qoh*cost)`; `On-Order` un-received PO value `SUM(ordval − recqty/qty·ordval)`; `Not Paid` `SUM(ordval − paidamt)`; `Billed-Not-Received` = OnOrder − NotPaid; `Book Value` = OnHand + BNR. These frame *committed spend still in the pipeline* — useful secondary tiles. (For total AP prefer the cash-flow `ap_total`.)

### 4f. Recurring expenses — `cash_expenses`
`cash_expenses(id, label, amount, category, active, ...)`. Loader `load_recurring_expenses($db)` → `items[]` + monthly `total`. If none entered, forecast falls back to a **QBO expense estimate** (`cf_expense`).

### 4g. Manual cash events — `cash_events`
One-off ins/outs placed in a specific month + week. `cash_events(id, etype['in'|'out'], label, amount, ym'YYYY-MM', week 1-4, paidby['cash'|'card'], paid, ...)`. Loader `load_cash_events($db)`. Drag-and-drop between months in the current UI.

### 4h. Per-month income overrides — `cash_month_actuals`
`cash_month_actuals(ym PK, actual_projection, actual_income, ...)`. Income priority per month: **actual_income → actual_projection → suggested** (auto QBO/Shopify baseline). Loader `load_month_actuals($db)`.

### 4i. Receivable expected dates — `ar_schedule`
`ar_schedule(order_key PK, expected_date, ...)`. Lets the owner slot a big Shopify receivable into the month it's really expected. Loader `load_ar_schedule($db)`; overlaid onto `$data['ar'].items[].expected`.

### 4j. Finished-product imports — `fp_purchases`
`fp_purchases(id, item, qty, unit_cost, total_cost, order_ym'YYYY-MM', card_label, note, ...)`. China FP buys on a card; raises that card's balance in `order_ym`. Loader `load_fp_purchases($db)`.

### 4k. Reconciliation flags — `cashout_paid` / `cashin_received`
Per-month line keys the user ticked as already paid/received, so reconciled items aren't double-counted in future projections. `cashout_paid(ym, line_key)`, `cashin_received(ym, line_key)`.

### 4l. QuickBooks (via nightly-cached wrappers — `data_cache` table)
Fetched live, cached in `data_cache`, refreshed by `cashflow_sync($db)` / the "Refresh now" button. **Call the `cf_*` wrappers — they serve cache and fall back gracefully.**

| Wrapper | Returns | QBO source |
|---|---|---|
| `cf_accounts($db)` | bank / credit-card / LOC balances (`CurrentBalance`) | `Account WHERE Active` |
| `cf_bills($db)` | open AP bills: `Balance, VendorRef.name, DueDate, TxnDate` | `Bill WHERE Balance>0` |
| `cf_income($db)` | **cash-basis monthly income** `by_month['YYYY-MM']` (money truly received) | ProfitAndLoss (Cash) |
| `cf_expense($db)` | avg **monthly expense/burn** | ProfitAndLoss expenses |
| `cf_company($db)` | company name | CompanyInfo |
| `cf_synced_at($db)` | last sync time | — |

OAuth tokens live in `settings` (keys `qb_*`). Connection check: `qb_is_connected()`.

### 4m. Shopify (cached the same way)
| Wrapper | Returns |
|---|---|
| `cf_revenue($db)` | **net sales run-rate** `by_month` (order-date, ex tax/ship) — trend & next-year projection basis |
| `cf_ar($db)` | **open receivables** (unpaid/pending orders + open drafts) → `$data['ar']` |

Shopify creds in `settings` (`shopify_*`). Check: `shopify_is_configured()`.

---

## 5. How the forecast works (so your charts match the numbers)

`build_cashflow_forecast($db, $data, 12, $growthPct)` produces a **rolling window: prior month → +11**, each row:

- **Cash IN** (`income`) per month = priority **actual_income → actual_projection → suggested**. `suggested` = prior-year same-month **QBO income** (preferred) or **Shopify net sales** × `(1 + growth%)`.
- **Cash OUT** (`cash_out`) = recurring expenses + bills/POs due that month (`onetime`, bucketed by `pay_by`/`DueDate`) + debt payment (`debt_pay`).
- **Running** = `end_cash` carried forward; `end_debt` shrinks by payments.
- Also returns a **prior-year Shopify-vs-QBO reconciliation** table (lets the owner trust the projection basis).

`build_month_blocks(...)` is the richer per-month engine: overlays the **Shopify-loan % cash-out**, **tax set-aside**, **card avalanche paydown** (highest APR first, auto card minimums), **LOC draw room**, `fp_purchases`, manual events, and reconciliation flags — producing weekly line items, running cash, running debt, and "which card to charge / what to pay" advice per month.

**Modeling caveats to respect in any new view (already encoded — don't break them):**
1. **Never add Shopify AR or Shopify revenue into the cash-in projection** — QBO cash-basis income is the received-cash source; AR/revenue are context only, or you double-count.
2. Items ticked in `cashin_received` / `cashout_paid` are already in the bank balance — don't re-add as future flows.
3. `eta` ≠ payment date; `pay_by` is the cash-out date.
4. Prefer **manual balances** when present (`cash_source === 'manual'`), and surface the **as-of staleness** (`due_count`) so stale cash isn't trusted blindly.

---

## 6. Tunable settings ("knobs") — `settings` table, via `setting_get/set`

| Setting | Default | Effect |
|---|---|---|
| `shopify_loan_pct` | 25% | Shopify Capital repayment as % of sales (cash out) |
| `cash_buffer` | $30,000 | Min bank cash to keep; surplus routed to debt |
| `tax_monthly` | 0 | Monthly tax set-aside (accrues, paid quarter-end) |
| `card_min_pct` / `card_min_floor` | 4% / $25 | Auto card-minimum = max(% of balance, floor) |
| `loc_ceilings` | — (JSON) | Per-facility LOC ceilings `[{name,ceiling}]` |
| `balance_update_days` | 7 | Balance staleness threshold |
| `cardpay_done_months` / `expenses_done_months` | — | Months already paid (skip in projection) |
| `cashflow_hide_before` | — | Hide months before this `ym` |

Helper accessors: `shopify_loan_pct($db)`, `cash_buffer($db)`, `tax_monthly($db)`, `card_min_pct($db)`, `card_min_floor($db)`, `loc_ceilings($db)`, `balance_update_days($db)`.

---

## 7. AJAX endpoints already built (`ajax/cashflow/`)

`save_balance`, `delete_balance`, `save_expense`, `delete_expense`, `save_event`, `delete_event`, `set_event_paid`, `save_month_actual`, `save_ar_date`, `toggle_cashout_paid`, `reconcile` (bulk month reconcile), `save_settings` (knobs), `sync` (refresh QBO/Shopify), plus AI: `chat`, `chat_get/list/delete`, `apply` (assistant proposes changes you approve). Reuse these for interactivity — the write layer exists.

---

## 8. Recommended dashboard data points (a strong cash-flow dashboard)

**Top KPI row (the "can I make payroll?" glance):**
1. **Cash on Hand** (`eff_cash`) + provenance/as-of badge (`cash_source`, `manual.oldest_asof`, `due_count` stale warning).
2. **Net Position** (`net_quick`) — cash + owed − you owe.
3. **Total Debt** (`eff_credit`) with **credit available** (`manual.credit_available` / `credit_limit_total`).
4. **Owed to You** (`ar_total`) vs **You Owe** (`ap_total`).
5. **Runway** — months until projected `end_cash` drops below `cash_buffer` (derive from `$forecast['rows']`). *High-value, not currently a headline number.*
6. **This month net** — `income − cash_out` from the current forecast row.

**Core charts (Chart.js — see DESIGN_HANDOFF §10):**
- **12-month running cash line** (`$forecast['rows'][].end_cash`) with a `cash_buffer` threshold line — the signature cash-flow chart. Shade months that dip below buffer red.
- **Monthly cash-in vs cash-out bars** (`income` vs `cash_out`), net overlaid as a line.
- **Debt paydown line** (`end_debt` over 12 months).
- **AR vs AP** and/or **aging** doughnut (bills/POs by due bucket from `$data['queue']`).
- **Sales vs Income reconciliation** (prior-year Shopify vs QBO from `$forecast['reconcile']`).

**Actionable panels:**
- **Pay queue** (`$data['queue']`) — obligations by due date with running cash after each (already computed).
- **LOC facilities** (`manual.loc_facilities`) — drawn / ceiling / available, flag `overdrawn`.
- **Upcoming outflows this month** — bills + POs (`pay_by`) + FP purchases + Shopify-loan draw.
- **Raw-material reorders** (`cashflow_reorder_suggestions`) — projected near-term purchasing spend.
- **Stale-balance nudge** — accounts past `balance_update_days`.

---

## 9. Opportunities — data available but not yet pulled (optional, higher value)

The QBO/Shopify clients (`qb_query`, `qb_report`, `shopify_graphql`) already authenticate — these need **no new OAuth**, just a new call + cache key:

- **AR aging buckets** — QBO `AgedReceivables` report or `Invoice WHERE Balance>0` (time incoming cash beyond today's snapshot).
- **AP aging / bill timeline** — QBO `AgedPayables` for a forward payables calendar.
- **Balance Sheet / Cash Flow statement** — QBO `BalanceSheet` / `CashFlow` to reconcile derived vs booked cash.
- **Recurring vendor/payroll outflows** — QBO `Purchase` / `BillPayment` / `Transfer`.
- **Shopify Payments payouts + real Shopify Capital balance** — actual settlement timing and remaining loan balance (today the 25% is an assumption, not fetched).
- **Undeposited funds** — QBO `Account` subtype `UndepositedFunds` (sales-to-bank bridge).

If the dashboard should show these, they'd be added as new `cf_*` cached wrappers following the existing pattern (§4l) — flag it and we can wire them.

---

## 10. Schema quick-reference (all cash-flow tables, self-created idempotently)

```
cash_balances(id, label, acct_type, balance, credit_limit, monthly_payment, as_of, note,
              qb_account_id, apr, loc_name, due_day, updated_at, user_id)
cash_expenses(id, label, amount, category, active, updated_at, user_id)
cash_events(id, etype, label, amount, ym, week, paidby, paid, updated_at, user_id)
cash_month_actuals(ym PK, actual_projection, actual_income, updated_at)
ar_schedule(order_key PK, expected_date, updated_at)
fp_purchases(id, item, qty, unit_cost, total_cost, order_ym, card_label, note, updated_at, user_id)
cashout_paid(ym, line_key, updated_at)          -- PK(ym,line_key); line_key ∈ loan|recurring|bills|tax|ev{id}|locpay{id}
cashin_received(ym, line_key, updated_at)        -- PK(ym,line_key); line_key ∈ sales|ar{n}|ev{id}
cashflow_chats(id, title, messages LONGTEXT json, created_at, updated_at)  -- saved AI assistant conversations
data_cache(ckey PK, cval LONGTEXT json, updated_at)   -- QBO/Shopify nightly cache
settings(skey PK, sval, updated_at)              -- knobs + integration tokens
orders(... ordval, paidamt, qty, recqty, pay_by, orderdate, postdate, eta, partid, orderref, archived)
payments(id, date, ordid, amount, ref)
parts(... cost, qoh, bsl, imoq, lead_time, omit, manufacturer)
```
Cash tables are created on demand by `ensure_*` helpers — a new page that calls the loaders will auto-create anything missing. For brand-new tables/columns, follow the idempotent `setup_*.php` pattern (see `DESIGN_HANDOFF.md` §3).

---

## 11. File map

| File | What |
|---|---|
| `includes/cashflow.php` | **The engine** — all loaders, forecast, month blocks, QBO/Shopify cache wrappers, knobs |
| `cashflow.php` | Current cash-flow page (KPI strip, month blocks, editors, AI assistant) — reference, then improve |
| `ajax/cashflow/*` | Write/sync/AI endpoints |
| `includes/quickbooks.php` | QBO OAuth + `qb_query` / `qb_report` + income/expense parsers |
| `includes/shopify.php` | Shopify GraphQL client — revenue, receivables, sales, inventory |
| `includes/header.php` | Inventory-money KPIs (On-Hand / On-Order / Not-Paid / BNR / Book Value) |
| `home.php` | Layout/Chart.js template to imitate |
| `integrations.php` | Admin UI for QBO/Shopify/Claude credentials |
| `includes/cashflow_ai_memory.md` | Owner-editable **business-rules spec** — the canonical vocabulary (income tiers, avalanche, LOC-draw semantics, PO→card rule). Read it for the domain model in plain English. |

---

_Build on the engine, not around it. When in doubt about a number, trace it to the function in `includes/cashflow.php` that produces it._
