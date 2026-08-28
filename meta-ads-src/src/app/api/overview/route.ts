import { NextResponse } from "next/server";
import { isAuthorized } from "@/lib/auth";
import {
  comparisonRange,
  rangeFromPreset,
  todayIn,
  type CompareMode,
} from "@/lib/dates";
import { getConfig } from "@/lib/config";
import { EMPTY_METRICS, sumMetrics } from "@/lib/meta";
import { PLATFORMS } from "@/lib/platforms";
import { loadPlatform } from "@/lib/providers";
import { buildBalanceFindings, buildComparison } from "@/lib/balance";
import { getShopifyRevenue, isShopifyConfigured } from "@/lib/shopify";
import type {
  DailyPoint,
  DateRange,
  OverviewData,
  Preset,
} from "@/lib/types";

export const dynamic = "force-dynamic";

/**
 * The combined roll-up across Meta, Google and Microsoft.
 *
 * Deliberately separate from /api/insights rather than an extra mode on it.
 * That route answers "how is the Meta account doing", down to ad level and
 * hour of day; this one answers "how should the budget be split", which needs
 * breadth across platforms and no depth at all. Sharing them would have made
 * both worse.
 */

const VALID_PRESETS: Preset[] = [
  "today", "yesterday", "last_7d", "last_14d", "last_28d",
  "last_30d", "last_90d", "this_month", "last_month",
];

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

export async function GET(request: Request) {
  const url = new URL(request.url);

  const compareMode = (url.searchParams.get("compare") === "previous_year"
    ? "previous_year"
    : "previous_period") as CompareMode;

  // "Yesterday" has to mean one thing across three platforms, so the range is
  // anchored on a stated reporting timezone rather than on any one account's.
  // Reading it from Meta would mean an API round-trip before the range could
  // even be built, and would leave the other two platforms' ranges hostage to
  // a Meta outage.
  const timezone = getConfig("REPORTING_TIMEZONE") || "America/Los_Angeles";
  const range = resolveRange(url, timezone);
  const { range: compareRange, label: compareLabel } = comparisonRange(
    range,
    compareMode,
  );

  const warnings: string[] = [];
  const currency = getConfig("REPORTING_CURRENCY") || "USD";

  // Platforms load independently and never throw: a slice carries its own
  // warning instead. One broken connection must not empty the other two.
  const platforms = await Promise.all(
    PLATFORMS.map((p) => loadPlatform(p, range, compareRange, currency)),
  );
  for (const slice of platforms) {
    for (const w of slice.warnings) warnings.push(w);
  }

  // Meta is the one platform that reports its own currency, so if it loaded,
  // its answer beats the configured default — that is the currency the spend
  // figures are actually denominated in.
  const reportingCurrency =
    platforms.find((p) => p.platform === "meta")?.account?.currency || currency;

  const totals = sumMetrics(platforms.map((p) => p.totals));
  const previousTotals = sumMetrics(platforms.map((p) => p.previousTotals));

  const { daily, dailyByPlatform } = mergeDaily(platforms, range);

  const targetRoas = Number(getConfig("TARGET_ROAS")) || 2.0;
  const targetCpaRaw = Number(getConfig("TARGET_CPA"));
  const targetCpa =
    Number.isFinite(targetCpaRaw) && targetCpaRaw > 0 ? targetCpaRaw : null;

  const comparison = buildComparison(platforms);
  const findings = buildBalanceFindings(comparison, platforms, targetRoas);

  // Shopify is the cross-check that matters most here: with three platforms
  // each claiming their own conversions, the sum of attributed revenue almost
  // always exceeds what the shop actually took. Blended ROAS against total
  // spend is the figure that cannot be double-counted.
  let shopify = null;
  if (isShopifyConfigured()) {
    try {
      shopify = await getShopifyRevenue(range, timezone);
    } catch (err) {
      warnings.push(
        `Shopify data unavailable: ${err instanceof Error ? err.message : "unknown error"}`,
      );
    }
  }

  const attributedTotal = platforms.reduce((s, p) => s + p.totals.revenue, 0);
  if (shopify && attributedTotal > shopify.totalRevenue * 1.2) {
    warnings.push(
      `The platforms together claim ${Math.round(attributedTotal).toLocaleString()} in revenue against ${Math.round(shopify.totalRevenue).toLocaleString()} actually taken in Shopify. Overlapping attribution — more than one platform claiming the same order — is the usual cause. Blended ROAS below is the figure to trust.`,
    );
  }

  const payload: OverviewData = {
    canEdit: await isAuthorized(request),
    range,
    compareRange,
    compareLabel,
    currency: reportingCurrency,
    partial: range.until >= todayIn(timezone),
    platforms,
    totals: totals ?? { ...EMPTY_METRICS },
    previousTotals: previousTotals ?? { ...EMPTY_METRICS },
    daily,
    dailyByPlatform,
    comparison,
    shopify,
    blendedRoas:
      shopify && totals.spend > 0 ? shopify.totalRevenue / totals.spend : null,
    targets: { roas: targetRoas, cpa: targetCpa },
    findings,
    warnings,
  };

  return NextResponse.json(payload);
}

/**
 * Unions the platforms' daily series onto one date axis. Each platform's
 * series already covers the full range, but they are merged by date rather
 * than by index so a future provider returning a sparse series cannot shift
 * another platform's spend onto the wrong day.
 */
function mergeDaily(
  platforms: { platform: string; daily: DailyPoint[] }[],
  range: DateRange,
): {
  daily: DailyPoint[];
  dailyByPlatform: OverviewData["dailyByPlatform"];
} {
  const dates = new Set<string>();
  for (const p of platforms) for (const d of p.daily) dates.add(d.date);
  // A range with no data at all still gets its endpoints, so charts render an
  // empty axis rather than nothing.
  dates.add(range.since);
  dates.add(range.until);

  const ordered = [...dates].sort();

  const daily: DailyPoint[] = [];
  const dailyByPlatform: OverviewData["dailyByPlatform"] = [];

  for (const date of ordered) {
    const spend: Record<string, number> = {};
    const revenue: Record<string, number> = {};
    const parts = [];

    for (const p of platforms) {
      const point = p.daily.find((d) => d.date === date);
      spend[p.platform] = point?.metrics.spend ?? 0;
      revenue[p.platform] = point?.metrics.revenue ?? 0;
      if (point) parts.push(point.metrics);
    }

    daily.push({ date, metrics: sumMetrics(parts) });
    dailyByPlatform.push({ date, spend, revenue });
  }

  return { daily, dailyByPlatform };
}

/** Explicit since/until win over the preset, so custom ranges are possible. */
function resolveRange(url: URL, timezone: string): DateRange {
  const since = url.searchParams.get("since");
  const until = url.searchParams.get("until");

  if (since && until && DATE_RE.test(since) && DATE_RE.test(until)) {
    return since <= until ? { since, until } : { since: until, until: since };
  }

  const preset = url.searchParams.get("preset") as Preset | null;
  const valid = preset && VALID_PRESETS.includes(preset) ? preset : "today";
  return rangeFromPreset(valid, timezone);
}
