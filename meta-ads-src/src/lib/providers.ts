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
import { isSheetConfigured, readSyncState, syncPlatformFromSheet } from "./sheetSync";
import { applyAdjustments, inRange, readAdjustments } from "./adjustments";
import type { AccountInfo, DateRange, PlatformSlice } from "./types";

/**
 * One function per platform, all returning the same `PlatformSlice`.
 *
 * This is the seam the combined dashboard is built on. Meta goes to the Graph
 * API. Google and Microsoft read a local store of campaign-by-day rows, which
 * arrive one of two ways: pasted by hand, or fetched from a published Google
 * Sheet that a Google Ads Script fills from inside the ad account.
 *
 * That second route is the point. Reading Google's own API would require a
 * developer token at Basic Access — an application, a design document, a
 * public business domain and a multi-day review, all to read figures we
 * already own. A script running inside the account needs none of it.
 *
 * Should a direct API client ever be worth writing, it replaces the body of
 * one function here and nothing downstream changes.
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
  syncNote?: string,
): PlatformSlice {
  const meta = PLATFORM_META[platform];
  const all = readPlatformRows();
  const rows = rowsInRange(all, platform, range);
  const previousRows = rowsInRange(all, platform, compareRange);

  const hasAny = all.some((r) => r.platform === platform);
  if (!rows.length) {
    const connected = isSheetConfigured(platform);
    return emptySlice(
      platform,
      hasAny,
      [
        syncNote ??
          (hasAny
            ? `No ${meta.label} data recorded for this period.` +
              (connected
                ? " The connected sheet does not cover it — widen the script's lookback, or pick a later range."
                : " Import the report covering it.")
            : connected
              ? `${meta.label} is connected to a sheet but nothing has arrived yet. Run the Google Ads script once, then use Sync now.`
              : `${meta.label} has no data yet. Connect a sheet or paste a report on its tab.`),
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

  const warnings: string[] = [];
  if (syncNote) warnings.push(syncNote);

  if (isSheetConfigured(platform)) {
    const last = readSyncState()[platform];
    if (last?.error) {
      warnings.push(
        `${meta.label}'s sheet could not be read, so these figures are as of the last successful sync: ${last.error}`,
      );
    }

    // The newest day present is the honest freshness signal — a sheet that
    // stopped updating still returns 200 and still parses.
    const newest = rows.reduce((max, r) => (r.date > max ? r.date : max), "");
    const daysBehind = daysBetween(newest, range.until);
    if (newest && daysBehind > 2) {
      warnings.push(
        `${meta.label} data stops at ${newest}, ${daysBehind} days before the end of this range. The export script may have stopped running.`,
      );
    }
  }

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
    warnings,
  };
}

function daysBetween(from: string, to: string): number {
  const a = Date.parse(`${from}T00:00:00Z`);
  const b = Date.parse(`${to}T00:00:00Z`);
  if (!Number.isFinite(a) || !Number.isFinite(b)) return 0;
  return Math.round((b - a) / 86_400_000);
}

export async function loadPlatform(
  platform: Platform,
  range: DateRange,
  compareRange: DateRange,
  currency: string,
): Promise<PlatformSlice> {
  if (platform === "meta") return loadMeta(range, compareRange);

  /*
    Opportunistic refresh: if a sheet is connected and has not been read for a
    while, pull it before rendering. syncPlatformFromSheet enforces its own TTL
    and timeout, so the common case costs nothing and a hanging sheet costs a
    bounded wait rather than the page. A failure here is never fatal — the
    stored rows are still rendered, with the reason attached.
  */
  let syncNote: string | undefined;
  if (isSheetConfigured(platform)) {
    const result = await syncPlatformFromSheet(platform);
    if (!result.ok) syncNote = `Could not refresh ${PLATFORM_META[platform].label}: ${result.error}`;
  }

  return loadManual(platform, range, compareRange, currency, syncNote);
}
