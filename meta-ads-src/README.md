# Meta Ads Dashboard

A local dashboard for your Meta ad account: ROAS and the metrics around it,
compared against a previous period, with findings and advice on what to change.

Runs on **http://localhost:3100** (port 3000 is deliberately left alone — change
it in `package.json` if 3100 is also taken).

```
npm install
npm run dev
```

For an always-available dashboard instead of a terminal you have to remember to
start, see [Keeping it running](#keeping-it-running).

Then open <http://localhost:3100>. With no credentials configured you'll get an
error panel with a **"Show me sample data instead"** button, which loads a fully
populated demo so you can see the layout before wiring anything up. That view is
also at <http://localhost:3100/?demo=1> and is always labelled as sample data.

---

## Keeping it running

`scripts/install-autostart.ps1` registers a per-user scheduled task,
**MetaAdsDashboard**, with two triggers: at logon, and every 5 minutes forever.
The repeating one is what makes this dependable. A logon trigger alone leaves
the dashboard down until you next sign in if anything kills the whole process
tree, which is exactly what happened once — the task exited `0xC000013A`
(`STATUS_CONTROL_C_EXIT`), a console control event that took the supervisor and
the server together. Re-running while it is already up is a no-op: the task is
`IgnoreNew` and `serve.ps1` holds a named mutex. Worst-case recovery is now
5 minutes with no intervention.

Run it once:

```
powershell -NoProfile -ExecutionPolicy Bypass -File C:\meta\scripts\install-autostart.ps1
```

The task runs `scripts/serve.ps1`, which serves the **production build** on
`127.0.0.1:3100` — localhost only, so nothing on the network can reach your
tokens. Credentials still hot-reload, because `src/lib/config.ts` reads
`.env.local` per request; **code** changes do not, so after editing `src/` you
need:

```
npm run build
powershell -File C:\meta\scripts\stop.ps1
Start-ScheduledTask -TaskName MetaAdsDashboard
```

Use `stop.ps1` here rather than `Restart-ScheduledTask`. A supervisor will not
kill a server that still answers on `/`, so a restart on its own would adopt the
running one and keep serving the old build.

A hand-started `npm run dev` on 3100 takes precedence: `serve.ps1` sees the port
held by a process it did not start, logs it, and waits until you stop it. Output
and restarts go to `logs/dashboard.log` (rotated at 5 MB).

| | |
|---|---|
| Status | `Get-ScheduledTaskInfo -TaskName MetaAdsDashboard` |
| Stop for now | `powershell -File C:\meta\scripts\stop.ps1` |
| Start again | `Start-ScheduledTask -TaskName MetaAdsDashboard` |
| Remove entirely | `powershell -File C:\meta\scripts\stop.ps1; Unregister-ScheduledTask -TaskName MetaAdsDashboard -Confirm:$false` |

Use `stop.ps1` rather than `Stop-ScheduledTask` on its own. Task Scheduler kills
`serve.ps1` without killing the `node` process it launched, leaving a server on
the port with no supervisor. That is survivable — the next start recognises the
orphan by `logs/server.pid` and replaces it, which is also what makes
`Restart-ScheduledTask` work after a rebuild — but `stop.ps1` is the clean exit.

---

## Setup

Open **<http://localhost:3100/setup>** and paste your keys in. It validates the
token against Meta before saving, lists the ad accounts that token can actually
see so you can pick one, and writes everything to `.env.local` itself. Changes
take effect immediately — no restart.

The setup page only answers requests from this computer. `next dev` listens on
every network interface, so without that check anyone on the same wifi could
read or replace your stored tokens.

Everything below is the manual equivalent, if you'd rather edit the file
yourself.

## Getting your Meta credentials

You need two things: an access token with `ads_read`, and your ad account ID.

### The token

Use a **System User** token. The token you can generate in Graph API Explorer
works, but expires in about an hour, which makes it useless for a dashboard you
open every morning. System User tokens do not expire.

1. Go to [business.facebook.com](https://business.facebook.com) → **Business Settings**
2. **Users → System Users** → **Add**, give it a name, role **Employee**
3. With that system user selected, **Add Assets** → **Ad Accounts** → pick your
   account → enable **View performance** (that is all this dashboard needs — it
   never writes)
4. **Generate new token** → select your Meta app → tick **`ads_read`** → Generate
5. Copy it immediately; it is shown once

If you have no Meta app yet, create one at
[developers.facebook.com/apps](https://developers.facebook.com/apps) — type
**Business** — and add the **Marketing API** product. The app does not need to be
submitted for review; `ads_read` on your own account works in development mode.

### The account ID

```
copy .env.local.example .env.local
```

Paste the token into `META_ACCESS_TOKEN`, restart `npm run dev`, then open
<http://localhost:3100/api/accounts>. That lists every ad account the token can
read, with its `act_…` id. Put the one you want into `META_AD_ACCOUNT_ID` and
restart again.

---

## Configuration

Everything lives in `.env.local` (gitignored). Only the first two are required.

| Variable | Purpose |
|---|---|
| `META_ACCESS_TOKEN` | System User token with `ads_read`. |
| `META_AD_ACCOUNT_ID` | e.g. `act_123456789012345`. |
| `META_API_VERSION` | Graph API version, default `v23.0`. Bump if Meta deprecates it — the app surfaces Meta's own error text when a version is rejected. |
| `META_ATTRIBUTION_WINDOWS` | Default `7d_click,1d_view`, matching Ads Manager. Set to `7d_click` to exclude view-through and see a stricter number. |
| `TARGET_ROAS` | Default `2.0`. Drives the findings and the target line on the ROAS chart. Set it to your actual break-even. |
| `TARGET_CPA` | Optional. Enables the cost-per-purchase finding. |
| `SHOPIFY_STORE_DOMAIN` / `SHOPIFY_ADMIN_TOKEN` | Optional. Enables blended ROAS. |
| `ANTHROPIC_API_KEY` | Optional. Enables the written analysis. |

### Shopify (optional)

Shopify admin → **Settings → Apps and sales channels → Develop apps → Create an
app** → **Configure Admin API scopes** → enable **`read_orders`** (add
`read_all_orders` if you need orders older than 60 days) → **Install app** →
copy the Admin API access token.

This gives you **blended ROAS**: total store revenue divided by total Meta spend.
It is usually the more honest number — see "What the numbers mean" below.

### Claude (optional)

Get a key at [console.anthropic.com](https://console.anthropic.com/settings/keys).
The written analysis costs a few cents per run and only fires when you click
**Analyse this period** — it is never called automatically.

---

## What the numbers mean

**Meta's revenue figures are claims, not receipts.** Meta counts a purchase when
it believes one of its ads contributed, under your attribution window. That
includes people who merely *saw* an ad and bought later, and it overlaps with
every other channel making the same claim. Two consequences:

- **Attributed ROAS is good for comparing campaigns to each other.** Both
  campaigns are measured the same way, so the ranking is meaningful.
- **Attributed ROAS is bad for judging whether the account is profitable.** For
  that you want blended ROAS, which is why the Shopify connection exists. If Meta
  says 3.1x and blended says 1.4x, the second number is the one your bank
  balance agrees with.

**Wholesale orders wreck ROAS.** One £3,000 B2B order against £2,000 of ad spend
adds 1.5x to blended ROAS on its own, which makes the metric track invoicing
rather than ad performance. Setup step 5 excludes them from the Shopify figures
by order tag, by Shopify B2B company account, or by an order-value threshold —
and the dashboard reports exactly what it removed rather than quietly shrinking
the number.

That fixes blended ROAS. It **cannot** fix Meta's attributed revenue: the
Insights API returns one revenue total per row with no order-level detail, so
there is nothing to subtract against. If wholesale customers order through your
normal checkout, the pixel fires and Meta counts them. The real fix is upstream
— suppress the purchase event for wholesale orders, or route them through draft
orders so the pixel never fires. Until then, treat blended ROAS as the honest
figure; the dashboard flags the gap when it sees one.

**Small numbers lie.** Below roughly 5 purchases, a campaign's ROAS moves more on
one order than on anything you did. The findings panel deliberately refuses to
recommend action under that threshold and says so instead.

**Rising CPM is not your fault.** Cost per thousand impressions is auction
pressure — competitors bidding, seasonality, audience size. It is reported as
context, not as a problem to fix.

---

## How it's put together

```
src/
  app/
    page.tsx              dashboard shell, filters, tile layout
    api/
      insights/route.ts   fetches Meta + Shopify, runs rules, returns everything
      advice/route.ts     sends the computed numbers to Claude for the write-up
      accounts/route.ts   lists ad accounts your token can see (setup helper)
  lib/
    meta.ts               Marketing API client, action-type normalisation
    rules.ts              the deterministic findings
    shopify.ts            order revenue for blended ROAS
    dates.ts              date maths in the ad account's timezone
    demo.ts               synthetic data for the sample dashboard
    format.ts             display formatting and deltas
  components/             tiles, charts, tables, panels
```

Two decisions worth knowing about:

**Ratios are always derived, never read.** Meta returns its own `ctr`, `cpc`,
`purchase_roas` and so on, but they are rounded, and rounded ratios do not survive
being aggregated into period totals. Everything is recomputed from the raw
counters instead.

**The rules engine runs before Claude, and Claude sees its output.** The
arithmetic is deterministic and testable; the language model's job is to weigh
findings against each other and spot shared root causes. This is why the
narrative and the numbers can never contradict each other.

**Spend and ROAS are on separate charts.** Putting two unrelated scales on one
pair of axes makes the crossing point look meaningful when it isn't.

---

## Troubleshooting

| Symptom | Cause |
|---|---|
| `Invalid OAuth access token` | Token expired (you used Graph Explorer) or was revoked. Generate a System User token. |
| `(#200) Requires ads_read permission` | The token has the right scope but the ad account isn't assigned to that system user. Business Settings → System Users → Add Assets. |
| Empty dashboard, no error | The account had no delivery in that window. Try a wider range. |
| Revenue is 0 but purchases exist | The pixel is firing purchase events without a value. Fix `value` and `currency` in the event payload. |
| Numbers differ from Ads Manager | Almost always the attribution window. Check `META_ATTRIBUTION_WINDOWS` against the setting in Ads Manager's column menu. |
| `Unsupported get request` | Graph API version retired. Bump `META_API_VERSION`. |

---

## Read-only by construction

This dashboard never modifies your ad account or your store. Three independent
layers hold that, so no single mistake can break it:

1. **Token scope.** The Meta token needs only `ads_read`; the Shopify token only
   `read_orders`. Writes require `ads_management` / `write_orders`, which are
   never requested. Both platforms would reject a write outright.
2. **A single chokepoint.** Every Meta request goes through `readOnlyFetch` in
   `src/lib/meta.ts`. There is no other path to the Graph API.
3. **A runtime guard.** `readOnlyFetch` throws on any POST/PUT/PATCH/DELETE
   before the request leaves the process. The Shopify client likewise refuses to
   send a GraphQL document containing a mutation — its POST is how GraphQL
   transports a *query*, not a write.

Manual revenue deductions change only what this dashboard displays. They are
stored in `adjustments.json` on your machine and are never sent to Meta; Meta
continues to report its own figures unchanged. Delete that file and the display
reverts.

If you extend this app, do not weaken the guard. It exists so that adding a
write has to be a deliberate, visible decision rather than an accident.
