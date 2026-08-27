import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import type {
  Adjustment,
  AdjustmentCandidate,
  DailyPoint,
  DateRange,
  EntityRow,
  HourlyPoint,
  Level,
  Metrics,
} from "./types";

/**
 * Manual deductions for revenue Meta attributed to an ad that was not really
 * ad-driven.
 *
 * This exists because Meta's Insights API reports a single revenue total per
 * row with no order-level detail. When a wholesale order reaches Meta through
 * a server-side integration, there is no way to filter it out at source and no
 * order to subtract against — the only remaining option is to record what you
 * know and take it off the top.
 *
 * Deductions are stored on disk rather than derived, because they represent
 * knowledge the APIs do not have.
 */

const STORE_PATH = join(process.cwd(), "adjustments.json");

export function readAdjustments(): Adjustment[] {
  try {
    if (!existsSync(STORE_PATH)) return [];
    const parsed = JSON.parse(readFileSync(STORE_PATH, "utf8"));
    return Array.isArray(parsed) ? (parsed as Adjustment[]) : [];
  } catch {
    // A corrupt store should not take the dashboard down with it.
    return [];
  }
}

export function writeAdjustments(list: Adjustment[]): void {
  writeFileSync(STORE_PATH, JSON.stringify(list, null, 2), "utf8");
}

export function adjustmentsFilePath(): string {
  return STORE_PATH;
}

export function inRange(list: Adjustment[], range: DateRange): Adjustment[] {
  return list.filter((a) => a.date >= range.since && a.date <= range.until);
}

/* --- Applying ------------------------------------------------------------ */

/**
 * Subtracts revenue and conversions, then re-derives every ratio. Clamped at
 * zero: a deduction larger than the recorded revenue means the entity guess
 * was wrong, and negative revenue would be worse than the original error.
 */
function deduct(m: Metrics, revenue: number, purchases: number): Metrics {
  const next: Metrics = {
    ...m,
    revenue: Math.max(0, m.revenue - revenue),
    purchases: Math.max(0, m.purchases - purchases),
  };

  next.roas = next.spend ? next.revenue / next.spend : null;
  next.cpa = next.purchases ? next.spend / next.purchases : null;
  next.aov = next.purchases ? next.revenue / next.purchases : null;
  return next;
}

export interface ApplyTargets {
  totals: Metrics;
  previousTotals: Metrics;
  daily: DailyPoint[];
  previousDaily: DailyPoint[];
  hourly: HourlyPoint[] | null;
  previousHourly: HourlyPoint[] | null;
  campaigns: EntityRow[];
  adsets: EntityRow[];
  ads: EntityRow[];
}

/**
 * Spreads a deduction across a day's hours, largest first.
 *
 * An adjustment records the date but not the time, because the merchant knows
 * which order it was and not which hour Meta logged it. Taking it off the
 * busiest hours approximates the truth — a single large order lands in a single
 * hour, and that hour is the one carrying unusual revenue — and it keeps the
 * day's total correct, which is what the running-ROAS line actually plots.
 */
function deductAcrossHours(
  points: HourlyPoint[],
  amount: number,
  purchases: number,
): HourlyPoint[] {
  let remainingRevenue = amount;
  let remainingPurchases = purchases;

  const order = [...points]
    .map((p, index) => ({ index, revenue: p.metrics.revenue }))
    .sort((a, b) => b.revenue - a.revenue);

  const next = [...points];
  for (const { index } of order) {
    if (remainingRevenue <= 0 && remainingPurchases <= 0) break;

    const point = next[index];
    const takeRevenue = Math.min(remainingRevenue, point.metrics.revenue);
    const takePurchases = Math.min(remainingPurchases, point.metrics.purchases);
    if (takeRevenue <= 0 && takePurchases <= 0) continue;

    next[index] = {
      ...point,
      metrics: deduct(point.metrics, takeRevenue, takePurchases),
    };
    remainingRevenue -= takeRevenue;
    remainingPurchases -= takePurchases;
  }
  return next;
}

/**
 * Applies deductions to one period's figures — totals, its days, its hours,
 * and the entity they were assigned to, cascading an ad-level deduction up to
 * its ad set and campaign so the tables cannot disagree about the same money.
 *
 * `slot` selects which side of each entity row is being corrected. The
 * comparison period has to be corrected too: a wholesale order sitting in last
 * month makes this month's revenue look like a collapse.
 */
function applyToPeriod(
  adjustments: Adjustment[],
  totals: Metrics,
  daily: DailyPoint[],
  hourly: HourlyPoint[] | null,
  campaigns: EntityRow[],
  adsets: EntityRow[],
  ads: EntityRow[],
  slot: "current" | "previous",
) {
  let nextTotals = totals;
  const nextDaily = [...daily];
  let nextHourly = hourly ? [...hourly] : null;
  const nextCampaigns = [...campaigns];
  const nextAdsets = [...adsets];
  const nextAds = [...ads];

  const updateEntity = (list: EntityRow[], row: EntityRow, amount: number, purchases: number) => {
    const existing = slot === "current" ? row.current : row.previous;
    if (!existing) return;
    list[list.indexOf(row)] = {
      ...row,
      [slot]: deduct(existing, amount, purchases),
    };
  };

  for (const adj of adjustments) {
    const { amount, purchases } = adj;

    nextTotals = deduct(nextTotals, amount, purchases);

    const dayIndex = nextDaily.findIndex((d) => d.date === adj.date);
    if (dayIndex >= 0) {
      nextDaily[dayIndex] = {
        ...nextDaily[dayIndex],
        metrics: deduct(nextDaily[dayIndex].metrics, amount, purchases),
      };
    }

    // The hourly series only ever covers a single day, so it is only touched
    // when that day is the one being adjusted.
    if (nextHourly && nextDaily.length === 1 && nextDaily[0].date === adj.date) {
      nextHourly = deductAcrossHours(nextHourly, amount, purchases);
    } else if (nextHourly && nextDaily.length === 0) {
      nextHourly = deductAcrossHours(nextHourly, amount, purchases);
    }

    if (adj.level === "account" || !adj.entityId) continue;

    // Resolve the parent chain before mutating, so the cascade uses the ids
    // as they were fetched.
    const adRow = nextAds.find((r) => r.id === adj.entityId);
    const adsetRow =
      adj.level === "adset"
        ? nextAdsets.find((r) => r.id === adj.entityId)
        : adRow?.adsetId
          ? nextAdsets.find((r) => r.id === adRow.adsetId)
          : undefined;
    const parentCampaignId =
      adj.level === "campaign"
        ? adj.entityId
        : (adRow?.campaignId ?? adsetRow?.campaignId);
    const campaignRow = parentCampaignId
      ? nextCampaigns.find((r) => r.id === parentCampaignId)
      : undefined;

    if (adj.level === "ad" && adRow) updateEntity(nextAds, adRow, amount, purchases);
    if (adsetRow) updateEntity(nextAdsets, adsetRow, amount, purchases);
    if (campaignRow) updateEntity(nextCampaigns, campaignRow, amount, purchases);
  }

  return {
    totals: nextTotals,
    daily: nextDaily,
    hourly: nextHourly,
    campaigns: nextCampaigns,
    adsets: nextAdsets,
    ads: nextAds,
  };
}

/**
 * Applies deductions to every figure the dashboard shows, on both sides of the
 * comparison. Anything left uncorrected here would quietly contradict the
 * corrected numbers beside it.
 */
export function applyAdjustments(
  targets: ApplyTargets,
  current: Adjustment[],
  comparison: Adjustment[] = [],
): ApplyTargets {
  if (!current.length && !comparison.length) return targets;

  const now = applyToPeriod(
    current,
    targets.totals,
    targets.daily,
    targets.hourly,
    targets.campaigns,
    targets.adsets,
    targets.ads,
    "current",
  );

  const before = applyToPeriod(
    comparison,
    targets.previousTotals,
    targets.previousDaily,
    targets.previousHourly,
    now.campaigns,
    now.adsets,
    now.ads,
    "previous",
  );

  return {
    totals: now.totals,
    previousTotals: before.totals,
    daily: now.daily,
    previousDaily: before.daily,
    hourly: now.hourly,
    previousHourly: before.hourly,
    campaigns: before.campaigns,
    adsets: before.adsets,
    ads: before.ads,
  };
}

/* --- Finding the order --------------------------------------------------- */

/**
 * Ranks entities by how likely each is to contain a given order.
 *
 * The signal is average order value. Meta reports revenue and conversion count
 * per entity but not the orders themselves, so a large revenue figure on its
 * own says nothing — it could be one big order or fifty ordinary ones. What
 * distinguishes them is the average: an ad carrying a £3,000 wholesale order
 * alongside two retail sales shows an AOV in the hundreds against a normal
 * ~£75, and removing the order snaps that average back to typical. An entity
 * where the deduction leaves AOV *worse* is almost certainly the wrong one.
 *
 * Scoped to a single day this is decisive; across a month it is only
 * suggestive, which is why the UI asks for the order date.
 */
export function findCandidates(
  rows: EntityRow[],
  amount: number,
  normalAov: number | null,
): AdjustmentCandidate[] {
  const candidates: AdjustmentCandidate[] = [];

  for (const row of rows) {
    const { revenue, purchases } = row.current;
    if (revenue <= 0 || purchases <= 0) continue;

    const currentAov = revenue / purchases;

    // Can't remove more revenue than the entity recorded.
    if (revenue + 0.01 < amount) {
      continue;
    }

    const residualPurchases = purchases - 1;
    const residualAov =
      residualPurchases > 0 ? (revenue - amount) / residualPurchases : null;

    let score = 0;
    let evidence: string;

    if (normalAov && normalAov > 0) {
      const before = Math.abs(currentAov - normalAov);
      const after =
        residualAov === null
          ? // Only one purchase, and it matches the amount: that IS the order.
            Math.abs(revenue - amount) < 0.01
            ? 0
            : before
          : Math.abs(residualAov - normalAov);

      // Normalised so the score is comparable across entities of any size.
      score = (before - after) / normalAov;

      if (residualAov === null) {
        evidence =
          Math.abs(revenue - amount) < 0.01
            ? `Single purchase of exactly ${fmt(revenue)} — this is the order.`
            : `Single purchase of ${fmt(revenue)}, which does not match the amount.`;
      } else if (currentAov > normalAov * 2) {
        evidence = `Average order value is ${fmt(currentAov)} against a normal ${fmt(normalAov)}. Removing the order brings it to ${fmt(residualAov)}.`;
      } else {
        evidence = `Average order value is ${fmt(currentAov)}, already close to normal. Removing the order would take it to ${fmt(residualAov)}.`;
      }
    } else {
      score = 0;
      evidence = `${purchases} purchase${purchases === 1 ? "" : "s"} totalling ${fmt(revenue)}. No account average available to compare against.`;
    }

    candidates.push({
      id: row.id,
      name: row.name,
      level: row.level,
      revenue,
      purchases,
      currentAov,
      residualAov,
      normalAov,
      score,
      evidence,
    });
  }

  return candidates.sort((a, b) => b.score - a.score).slice(0, 8);
}

function fmt(value: number): string {
  return value.toLocaleString(undefined, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  });
}

/** Validates and normalises a submitted adjustment. Returns an error string. */
export function validateAdjustment(input: Partial<Adjustment>): string | null {
  if (!input.date || !/^\d{4}-\d{2}-\d{2}$/.test(input.date)) {
    return "Pick the date the order was placed.";
  }
  if (typeof input.amount !== "number" || !(input.amount > 0)) {
    return "Enter the order value as a positive number.";
  }
  if (
    typeof input.purchases !== "number" ||
    input.purchases < 0 ||
    !Number.isInteger(input.purchases)
  ) {
    return "Conversions to remove must be a whole number, usually 1.";
  }
  const levels: Level[] = ["account", "campaign", "adset", "ad"];
  if (!input.level || !levels.includes(input.level)) {
    return "Choose where the deduction should apply.";
  }
  if (input.level !== "account" && !input.entityId) {
    return "Choose which campaign, ad set, or ad the order was attributed to.";
  }
  return null;
}
