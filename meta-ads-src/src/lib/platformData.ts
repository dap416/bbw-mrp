import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import type { Platform } from "./platforms";
import type { DailyPoint, DateRange, EntityRow, Metrics } from "./types";
import { EMPTY_METRICS, sumMetrics } from "./meta";

/**
 * Hand-entered spend and results for the platforms without an API connection
 * yet — today Google and Microsoft.
 *
 * One row is one campaign on one day. That grain is deliberate: it is the
 * coarsest shape that still supports every question the combined dashboard
 * asks (a daily trend, a campaign table, a period total), and the finest shape
 * anyone will actually retype or export by hand. Rows are keyed by
 * platform+date+campaign so re-importing a corrected export overwrites rather
 * than doubles the spend.
 *
 * When the live APIs are wired up, this file does not have to go away: it stays
 * the fallback for a platform whose credentials are missing, and the store is
 * simply not consulted for one that is connected.
 */

export interface PlatformRow {
  platform: Platform;
  /** YYYY-MM-DD */
  date: string;
  campaign: string;
  spend: number;
  impressions: number;
  clicks: number;
  /** Conversions, however the platform counts them. */
  purchases: number;
  /** Conversion value in the account currency. */
  revenue: number;
}

const STORE_PATH = join(process.cwd(), "platform-data.json");

export function platformDataFilePath(): string {
  return STORE_PATH;
}

export function readPlatformRows(): PlatformRow[] {
  try {
    if (!existsSync(STORE_PATH)) return [];
    const parsed = JSON.parse(readFileSync(STORE_PATH, "utf8"));
    return Array.isArray(parsed) ? (parsed as PlatformRow[]) : [];
  } catch {
    // A corrupt store should cost you Google and Microsoft, not the whole page.
    return [];
  }
}

export function writePlatformRows(rows: PlatformRow[]): void {
  const sorted = [...rows].sort(
    (a, b) =>
      a.platform.localeCompare(b.platform) ||
      a.date.localeCompare(b.date) ||
      a.campaign.localeCompare(b.campaign),
  );
  writeFileSync(STORE_PATH, JSON.stringify(sorted, null, 2), "utf8");
}

function key(row: Pick<PlatformRow, "platform" | "date" | "campaign">): string {
  return `${row.platform}|${row.date}|${row.campaign.toLowerCase()}`;
}

/**
 * Merges incoming rows over the stored ones, last write winning per
 * platform+date+campaign. Returns how the store changed so the UI can say
 * "42 rows added, 8 updated" rather than a bare success.
 */
export function upsertPlatformRows(incoming: PlatformRow[]): {
  added: number;
  updated: number;
} {
  const existing = readPlatformRows();
  const byKey = new Map(existing.map((r) => [key(r), r]));

  let added = 0;
  let updated = 0;
  for (const row of incoming) {
    if (byKey.has(key(row))) updated++;
    else added++;
    byKey.set(key(row), row);
  }

  writePlatformRows([...byKey.values()]);
  return { added, updated };
}

/** Removes every stored row for one platform inside a date range. */
export function deletePlatformRows(platform: Platform, range: DateRange): number {
  const existing = readPlatformRows();
  const kept = existing.filter(
    (r) =>
      r.platform !== platform || r.date < range.since || r.date > range.until,
  );
  const removed = existing.length - kept.length;
  if (removed) writePlatformRows(kept);
  return removed;
}

/* --- Shaping into the dashboard's types ---------------------------------- */

/**
 * A hand-entered row carries five counters; the rest of `Metrics` has no
 * honest value to give. They stay at zero rather than being guessed at, and
 * the UI marks manual platforms so a zero is not read as "no add-to-carts".
 */
function toMetrics(row: PlatformRow): Metrics {
  return {
    ...EMPTY_METRICS,
    spend: row.spend,
    impressions: row.impressions,
    clicks: row.clicks,
    // A manual row has no separate link-click figure, so clicks stand in for
    // it. That keeps cost-per-click honest and leaves the link-specific
    // ratios equal to the general ones rather than zero.
    linkClicks: row.clicks,
    purchases: row.purchases,
    revenue: row.revenue,
  };
}

export function rowsInRange(
  rows: PlatformRow[],
  platform: Platform,
  range: DateRange,
): PlatformRow[] {
  return rows.filter(
    (r) =>
      r.platform === platform && r.date >= range.since && r.date <= range.until,
  );
}

export function totalsFor(rows: PlatformRow[]): Metrics {
  return sumMetrics(rows.map(toMetrics));
}

/**
 * A point per day across the whole range, including days with no rows — a gap
 * in a spend chart should read as a zero-spend day, not as a missing segment.
 */
export function dailyFor(rows: PlatformRow[], range: DateRange): DailyPoint[] {
  const byDate = new Map<string, Metrics[]>();
  for (const row of rows) {
    const list = byDate.get(row.date) ?? [];
    list.push(toMetrics(row));
    byDate.set(row.date, list);
  }

  const out: DailyPoint[] = [];
  for (const date of eachDate(range)) {
    out.push({ date, metrics: sumMetrics(byDate.get(date) ?? []) });
  }
  return out;
}

/** Campaign rows for the period, with the comparison window attached. */
export function campaignsFor(
  rows: PlatformRow[],
  previousRows: PlatformRow[],
  platform: Platform,
): EntityRow[] {
  const group = (list: PlatformRow[]) => {
    const byName = new Map<string, PlatformRow[]>();
    for (const row of list) {
      const name = row.campaign;
      byName.set(name, [...(byName.get(name) ?? []), row]);
    }
    return byName;
  };

  const current = group(rows);
  const previous = group(previousRows);

  return [...current.entries()]
    .map(([name, list]) => {
      const prior = previous.get(name);
      return {
        // Manual data has no platform-side ID, so the name is the identity.
        id: `${platform}:${name}`,
        name,
        level: "campaign" as const,
        current: totalsFor(list),
        previous: prior ? totalsFor(prior) : undefined,
      };
    })
    .sort((a, b) => b.current.spend - a.current.spend);
}

function eachDate(range: DateRange): string[] {
  const out: string[] = [];
  const cursor = new Date(`${range.since}T00:00:00Z`);
  const end = new Date(`${range.until}T00:00:00Z`);
  // A malformed range must not spin forever; the cap is far past any preset.
  for (let i = 0; cursor <= end && i < 800; i++) {
    out.push(cursor.toISOString().slice(0, 10));
    cursor.setUTCDate(cursor.getUTCDate() + 1);
  }
  return out;
}
