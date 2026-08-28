import { hasConfig, getConfig } from "./config";
import {
  EMPTY_METRICS,
  MetaError,
  getAccountInfo,
  getAccountTotals,
  getDailySeries,
  getEntityRows,
} from "./meta";
import {
  campaignsFor,
  dailyFor,
  readPlatformRows,
  rowsInRange,
  totalsFor,
} from "./platformData";
import { PLATFORM_META, type Platform } from "./platforms";
import { applyAdjustments, inRange, readAdjustments } from "./adjustments";
import type { AccountInfo, DateRange, PlatformSlice } from "./types";

/**
 * One function per platform, all returning the same `PlatformSlice`.
 *
 * This is the seam the combined dashboard is built on. Meta goes to the Graph
 * API; Google and Microsoft read the hand-entered store, because neither has
 * an API connection yet. Wiring up a real Google Ads or Microsoft Advertising
 * client later means replacing the body of `loadManual` for that platform with
 * a fetch — every caller keeps working, because the shape does not change.
 */

/** An empty slice, used for a platform with nothing configured or stored. */
function emptySlice(
  platform: Platform,
  configured: boolean,
  warnings: string[],
  range: DateRange,
): PlatformSlice {
  const meta = PLATFORM_META[platform];
  return {
    platform,
    label: meta.label,
    color: meta.color,
    source: meta.source,
    configured,
    account: null,
    totals: { ...EMPTY_METRICS },
    previousTotals: { ...EMPTY_METRICS },
    daily: dailyFor([], range),
    campaigns: [],
    warnings,
  };
}

async function loadMeta(
  range: DateRange,
  compareRange: DateRange,
): Promise<PlatformSlice> {
  const meta = PLATFORM_META.meta;
  const accountId = getConfig("META_AD_ACCOUNT_ID");

  if (!hasConfig("META_ACCESS_TOKEN") || !accountId) {
    return emptySlice("meta", false, ["Not connected — add a token and account on the setup page."], range);
  }

  try {
    const account = await getAccountInfo(accountId);
    const [totals, previousTotals, daily, campaigns] = await Promise.all([
      getAccountTotals(accountId, range),
      getAccountTotals(accountId, compareRange),
      getDailySeries(accountId, range),
      getEntityRows(accountId, range, compareRange, "campaign"),
    ]);

    // The same manual deductions the Meta-only page applies. Without this the
    // combined view would show a higher Meta ROAS than the Meta tab does, and
    // the whole point of the roll-up is that the platforms are comparable.
    const stored = readAdjustments();
    const adjusted = applyAdjustments(
      {
        totals,
        previousTotals,
        daily,
        previousDaily: [],
        hourly: null,
        previousHourly: null,
        campaigns,
        adsets: [],
        ads: [],
      },
      inRange(stored, range),
      inRange(stored, compareRange),
    );

    return {
      platform: "meta",
      label: meta.label,
      color: meta.color,
      source: meta.source,
      configured: true,
      account,
      totals: adjusted.totals ?? { ...EMPTY_METRICS },
      previousTotals: adjusted.previousTotals ?? { ...EMPTY_METRICS },
      daily: adjusted.daily,
      campaigns: adjusted.campaigns,
      warnings: [],
    };
  } catch (err) {
    // One platform failing must not empty the other two. The roll-up shows
    // what it has and names what it is missing.
    const message =
      err instanceof MetaError
        ? err.message
        : err instanceof Error
          ? err.message
          : "Could not reach Meta.";
    return emptySlice("meta", true, [`Meta data unavailable: ${message}`], range);
  }
}

/**
 * Google and Microsoft, from the hand-entered/CSV store.
 *
 * `configured` here means "has data for this period" rather than "has
 * credentials", which is the honest distinction while these are manual: an
 * empty period is a prompt to import a report, not a broken connection.
 */
function loadManual(
  platform: Platform,
  range: DateRange,
  compareRange: DateRange,
  currency: string,
): PlatformSlice {
  const meta = PLATFORM_META[platform];
  const all = readPlatformRows();
  const rows = rowsInRange(all, platform, range);
  const previousRows = rowsInRange(all, platform, compareRange);

  const hasAny = all.some((r) => r.platform === platform);
  if (!rows.length) {
    return emptySlice(
      platform,
      hasAny,
      [
        hasAny
          ? `No ${meta.label} data recorded for this period. Import the report covering it.`
          : `${meta.label} has no data yet. Import a campaign-by-day report on the Data tab.`,
      ],
      range,
    );
  }

  const account: AccountInfo = {
    id: platform,
    name: meta.label,
    currency,
    // Manual rows carry dates already resolved in the platform's own timezone,
    // so there is nothing further to convert.
    timezone: "UTC",
  };

  return {
    platform,
    label: meta.label,
    color: meta.color,
    source: meta.source,
    configured: true,
    account,
    totals: totalsFor(rows),
    previousTotals: totalsFor(previousRows),
    daily: dailyFor(rows, range),
    campaigns: campaignsFor(rows, previousRows, platform),
    warnings: [],
  };
}

export async function loadPlatform(
  platform: Platform,
  range: DateRange,
  compareRange: DateRange,
  currency: string,
): Promise<PlatformSlice> {
  if (platform === "meta") return loadMeta(range, compareRange);
  return loadManual(platform, range, compareRange, currency);
}
