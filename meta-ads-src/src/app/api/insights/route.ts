import { isAuthorized } from "@/lib/auth";
import { NextResponse } from "next/server";
import {
  comparisonRange,
  dayCount,
  rangeFromPreset,
  todayIn,
  type CompareMode,
} from "@/lib/dates";
import { applyAdjustments, inRange, readAdjustments } from "@/lib/adjustments";
import { getConfig, hasConfig } from "@/lib/config";
import { buildDemoData } from "@/lib/demo";
import {
  MetaError,
  getAccountInfo,
  getAccountTotals,
  getDailySeries,
  getEntityRows,
  getHourlySeries,
  EMPTY_METRICS,
} from "@/lib/meta";
import { buildFindings } from "@/lib/rules";
import { getShopifyRevenue, isShopifyConfigured } from "@/lib/shopify";
import type { DashboardData, DateRange, Preset } from "@/lib/types";

export const dynamic = "force-dynamic";

const VALID_PRESETS: Preset[] = [
  "today", "yesterday", "last_7d", "last_14d", "last_28d",
  "last_30d", "last_90d", "this_month", "last_month",
];

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

export async function GET(request: Request) {
  const url = new URL(request.url);
  const accountId = getConfig("META_AD_ACCOUNT_ID");

  const compareModeParam = (url.searchParams.get("compare") === "previous_year"
    ? "previous_year"
    : "previous_period") as CompareMode;

  // Sample data, for seeing the dashboard before credentials are wired up.
  // Always flagged as demo in the payload's warnings so it cannot be mistaken
  // for a real account.
  if (url.searchParams.get("demo") === "1") {
    const preset = (url.searchParams.get("preset") as Preset | null) ?? "last_28d";
    const demo = buildDemoData(
      VALID_PRESETS.includes(preset) ? preset : "last_28d",
      compareModeParam,
    );

    // The demo honours real stored deductions so the feature can be tried
    // before any credentials exist — and so the cascade is exercised.
    const stored = readAdjustments();
    const active = inRange(stored, demo.range);
    const comparison = inRange(stored, demo.compareRange);
    const adjusted = applyAdjustments(
      {
        totals: demo.totals,
        previousTotals: demo.previousTotals,
        daily: demo.daily,
        previousDaily: demo.previousDaily,
        hourly: demo.hourly,
        previousHourly: demo.previousHourly,
        campaigns: demo.campaigns,
        adsets: demo.adsets,
        ads: demo.ads,
      },
      active,
      comparison,
    );

    return NextResponse.json({
      ...demo,
      totals: adjusted.totals,
      previousTotals: adjusted.previousTotals,
      daily: adjusted.daily,
      previousDaily: adjusted.previousDaily,
      hourly: adjusted.hourly,
      previousHourly: adjusted.previousHourly,
      campaigns: adjusted.campaigns,
      adsets: adjusted.adsets,
      ads: adjusted.ads,
      adjustments: active,
      comparisonAdjustments: comparison,
      adjustedRevenue: active.reduce((s, a) => s + a.amount, 0),
      blendedRoas:
        demo.shopify && adjusted.totals.spend > 0
          ? demo.shopify.totalRevenue / adjusted.totals.spend
          : null,
      canEdit: await isAuthorized(request),
    });
  }

  if (!hasConfig("META_ACCESS_TOKEN")) {
    return NextResponse.json(
      {
        error: "No Meta access token has been added yet.",
        hint: "Open the setup page to paste one in — it takes about two minutes.",
        setup: true,
      },
      { status: 400 },
    );
  }
  if (!accountId) {
    return NextResponse.json(
      {
        error: "No ad account has been chosen yet.",
        hint: "Open the setup page and pick your account from the list.",
        setup: true,
      },
      { status: 400 },
    );
  }

  const compareMode = compareModeParam;

  try {
    // The account's timezone decides what "yesterday" means, so it has to be
    // resolved before any date range can be built.
    const account = await getAccountInfo(accountId);

    const range = resolveRange(url, account.timezone);
    const { range: compareRange, label: compareLabel } = comparisonRange(
      range,
      compareMode,
    );

    const warnings: string[] = [];

    const [
      totals,
      previousTotals,
      daily,
      previousDaily,
      campaigns,
      adsets,
      ads,
    ] = await Promise.all([
      getAccountTotals(accountId, range),
      getAccountTotals(accountId, compareRange),
      getDailySeries(accountId, range),
      getDailySeries(accountId, compareRange),
      getEntityRows(accountId, range, compareRange, "campaign"),
      getEntityRows(accountId, range, compareRange, "adset"),
      getEntityRows(accountId, range, compareRange, "ad"),
    ]);

    // A single-day range has no daily trend to draw, so fall back to the
    // within-day series. The hourly breakdown is not available on every
    // account or objective, so a rejection here just means no chart.
    let hourly = null;
    let previousHourly = null;
    if (dayCount(range) === 1) {
      try {
        [hourly, previousHourly] = await Promise.all([
          getHourlySeries(accountId, range),
          getHourlySeries(accountId, compareRange),
        ]);
      } catch {
        warnings.push(
          "Meta did not return an hourly breakdown for this account, so the within-day charts are unavailable. Every other figure is unaffected.",
        );
      }
    }

    // Shopify is a nice-to-have: a failure here degrades the dashboard, it
    // doesn't break it.
    let shopify = null;
    if (isShopifyConfigured()) {
      try {
        shopify = await getShopifyRevenue(range, account.timezone);
      } catch (err) {
        warnings.push(
          `Shopify data unavailable: ${err instanceof Error ? err.message : "unknown error"}`,
        );
      }
    }

    // Manual deductions are applied before the rules run, so findings and the
    // written analysis both reason about the corrected figures rather than
    // flagging a wholesale order as a suspiciously good campaign.
    const allAdjustments = readAdjustments();
    const activeAdjustments = inRange(allAdjustments, range);
    // Deductions landing in the comparison window matter just as much: an
    // unfiltered wholesale order back there makes this period look like a
    // collapse in every delta on the page.
    const comparisonAdjustments = inRange(allAdjustments, compareRange);

    const adjusted = applyAdjustments(
      {
        totals,
        previousTotals,
        daily,
        previousDaily,
        hourly,
        previousHourly,
        campaigns,
        adsets,
        ads,
      },
      activeAdjustments,
      comparisonAdjustments,
    );
    const adjustedRevenue = activeAdjustments.reduce((s, a) => s + a.amount, 0);

    const targetRoas = Number(getConfig("TARGET_ROAS")) || 2.0;
    const targetCpaRaw = Number(getConfig("TARGET_CPA"));
    const targetCpa = Number.isFinite(targetCpaRaw) && targetCpaRaw > 0 ? targetCpaRaw : null;

    const findings = buildFindings({
      range,
      totals: adjusted.totals,
      previousTotals,
      campaigns: adjusted.campaigns,
      adsets: adjusted.adsets,
      ads: adjusted.ads,
      shopify,
      targetRoas,
      targetCpa,
      currency: account.currency,
    });

    const payload: DashboardData = {
      account,
      range,
      compareRange,
      compareLabel,
      totals: adjusted.totals ?? { ...EMPTY_METRICS },
      previousTotals: adjusted.previousTotals ?? { ...EMPTY_METRICS },
      daily: adjusted.daily,
      previousDaily: adjusted.previousDaily,
      hourly: adjusted.hourly,
      previousHourly: adjusted.previousHourly,
      partial: range.until >= todayIn(account.timezone),
      campaigns: adjusted.campaigns,
      adsets: adjusted.adsets,
      ads: adjusted.ads,
      shopify,
      blendedRoas:
        shopify && adjusted.totals.spend > 0
          ? shopify.totalRevenue / adjusted.totals.spend
          : null,
      findings,
      adjustments: activeAdjustments,
      comparisonAdjustments,
      adjustedRevenue,
      targets: { roas: targetRoas, cpa: targetCpa },
      warnings,
      canEdit: await isAuthorized(request),
    };

    return NextResponse.json(payload);
  } catch (err) {
    if (err instanceof MetaError) {
      return NextResponse.json(
        {
          error: err.message,
          code: err.code,
          hint: err.isAuthError
            ? "Your token is invalid, expired, or lacks ads_read on this account. Generate a new System User token — see README.md."
            : undefined,
        },
        { status: err.isAuthError ? 401 : 502 },
      );
    }
    return NextResponse.json(
      { error: err instanceof Error ? err.message : "Unexpected error" },
      { status: 500 },
    );
  }
}

/** Explicit since/until win over the preset, so custom ranges are possible. */
function resolveRange(url: URL, timezone: string): DateRange {
  const since = url.searchParams.get("since");
  const until = url.searchParams.get("until");

  if (since && until && DATE_RE.test(since) && DATE_RE.test(until)) {
    return since <= until ? { since, until } : { since: until, until: since };
  }

  const preset = url.searchParams.get("preset") as Preset | null;
  const valid = preset && VALID_PRESETS.includes(preset) ? preset : "last_28d";
  return rangeFromPreset(valid, timezone);
}
