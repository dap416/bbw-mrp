import type {
  Finding,
  Metrics,
  PlatformComparisonRow,
  PlatformSlice,
} from "./types";

/**
 * Comparing the three platforms against each other, and saying where the money
 * should move.
 *
 * The advice here is deliberately conservative. Cross-platform ROAS is not a
 * like-for-like number — the platforms attribute differently, and Meta in
 * particular claims view-through conversions the others do not — so a naive
 * "move everything to the highest ROAS" would be confidently wrong. Three
 * guards keep it honest:
 *
 *   1. A platform needs real volume before it is judged at all. Below the
 *      thresholds it is reported but excluded from the reallocation.
 *   2. Only a bounded slice of total spend is ever suggested for a move, so a
 *      single good week cannot recommend abandoning a channel.
 *   3. The suggestion is framed as a test, not a settled answer, and the UI
 *      says so alongside it.
 */

/** Below this spend in the period, a platform's ROAS is noise. */
const MIN_SPEND_TO_JUDGE = 100;
/** Below this many conversions, ROAS swings too much on one order. */
const MIN_PURCHASES_TO_JUDGE = 5;
/** Never suggest moving more than this share of total spend in one go. */
const MAX_SHIFT_SHARE = 0.15;
/** Ignore ROAS gaps narrower than this — inside attribution noise. */
const MIN_ROAS_GAP = 0.25;

export const BALANCE_THRESHOLDS = {
  MIN_SPEND_TO_JUDGE,
  MIN_PURCHASES_TO_JUDGE,
  MAX_SHIFT_SHARE,
};

function share(part: number, whole: number): number {
  return whole > 0 ? part / whole : 0;
}

function fractionalDelta(current: number, previous: number): number | null {
  if (!previous) return null;
  return (current - previous) / previous;
}

/** Is there enough volume here to draw a conclusion from? */
function judgeable(m: Metrics): boolean {
  return m.spend >= MIN_SPEND_TO_JUDGE && m.purchases >= MIN_PURCHASES_TO_JUDGE;
}

function fmtRoas(roas: number | null): string {
  return roas === null ? "no return" : `${roas.toFixed(2)}x`;
}

function money(value: number): string {
  return Math.round(value).toLocaleString();
}

export function buildComparison(
  slices: PlatformSlice[],
): PlatformComparisonRow[] {
  const active = slices.filter((s) => s.totals.spend > 0);
  const totalSpend = active.reduce((sum, s) => sum + s.totals.spend, 0);
  const totalRevenue = active.reduce((sum, s) => sum + s.totals.revenue, 0);

  // The blended return is the benchmark: a platform is "over" or "under"
  // relative to what the whole mix is achieving, not to a fixed target.
  const blended = totalSpend > 0 ? totalRevenue / totalSpend : null;

  const shifts = suggestShifts(active, totalSpend, blended);

  return slices.map((slice) => {
    const m = slice.totals;
    return {
      platform: slice.platform,
      label: slice.label,
      color: slice.color,
      spend: m.spend,
      spendShare: share(m.spend, totalSpend),
      revenue: m.revenue,
      revenueShare: share(m.revenue, totalRevenue),
      roas: m.roas,
      cpa: m.cpa,
      purchases: m.purchases,
      spendDelta: fractionalDelta(m.spend, slice.previousTotals.spend),
      roasDelta:
        m.roas !== null && slice.previousTotals.roas !== null
          ? fractionalDelta(m.roas, slice.previousTotals.roas)
          : null,
      suggestedShift: shifts.get(slice.platform) ?? null,
    };
  });
}

/**
 * Splits a bounded pot of spend away from the below-blended platforms and onto
 * the above-blended ones, in proportion to how far each sits from the blended
 * return. Returns a signed amount per platform; the amounts sum to roughly
 * zero, because this is a reallocation and not a budget increase.
 */
function suggestShifts(
  slices: PlatformSlice[],
  totalSpend: number,
  blended: number | null,
): Map<string, number> {
  const out = new Map<string, number>();
  if (blended === null || totalSpend <= 0) return out;

  const judged = slices.filter(
    (s) => judgeable(s.totals) && s.totals.roas !== null,
  );
  // Two platforms have to be comparable before anything can be said about
  // moving money between them.
  if (judged.length < 2) return out;

  const winners = judged.filter(
    (s) => (s.totals.roas ?? 0) - blended >= MIN_ROAS_GAP,
  );
  const losers = judged.filter(
    (s) => blended - (s.totals.roas ?? 0) >= MIN_ROAS_GAP,
  );
  if (!winners.length || !losers.length) return out;

  // The pot is capped by the overall limit and, separately, by what the
  // underperformers are actually spending — you cannot move money that is
  // not there.
  const loserSpend = losers.reduce((sum, s) => sum + s.totals.spend, 0);
  const pot = Math.min(totalSpend * MAX_SHIFT_SHARE, loserSpend * MAX_SHIFT_SHARE);
  if (pot <= 0) return out;

  const loserWeight = losers.reduce(
    (sum, s) => sum + (blended - (s.totals.roas ?? 0)),
    0,
  );
  for (const s of losers) {
    const weight = (blended - (s.totals.roas ?? 0)) / loserWeight;
    out.set(s.platform, -Math.round(pot * weight));
  }

  const winnerWeight = winners.reduce(
    (sum, s) => sum + ((s.totals.roas ?? 0) - blended),
    0,
  );
  for (const s of winners) {
    const weight = ((s.totals.roas ?? 0) - blended) / winnerWeight;
    out.set(s.platform, Math.round(pot * weight));
  }

  return out;
}

/**
 * Cross-platform findings — the ones that exist only because there is more
 * than one platform. Per-platform findings still come from `rules.ts`.
 */
export function buildBalanceFindings(
  comparison: PlatformComparisonRow[],
  slices: PlatformSlice[],
  targetRoas: number,
): Finding[] {
  const findings: Finding[] = [];
  const spending = comparison.filter((c) => c.spend > 0);
  if (!spending.length) return findings;

  // 1. The reallocation itself, when the engine had enough to say one.
  const movers = comparison.filter(
    (c) => c.suggestedShift !== null && c.suggestedShift !== 0,
  );
  if (movers.length >= 2) {
    const into = movers.filter((m) => (m.suggestedShift ?? 0) > 0);
    const outOf = movers.filter((m) => (m.suggestedShift ?? 0) < 0);
    const amount = into.reduce((s, m) => s + (m.suggestedShift ?? 0), 0);

    const intoNames = into.map((m) => m.label).join(" and ");
    const outOfNames = outOf.map((m) => m.label).join(" and ");

    findings.push({
      id: "balance-reallocate",
      severity: "opportunity",
      title: `Shift about ${money(amount)} from ${outOfNames} to ${intoNames}`,
      detail: `Over this period ${into
        .map((m) => `${m.label} returned ${fmtRoas(m.roas)}`)
        .join(", ")}, against ${outOf
        .map((m) => `${fmtRoas(m.roas)} on ${m.label}`)
        .join(" and ")}. That gap is wide enough to act on, but the platforms attribute conversions differently, so treat it as a test rather than a settled verdict.`,
      action:
        "Move the suggested amount for one full period, then compare blended ROAS against Shopify revenue before moving any more.",
      entities: movers.map((m) => ({
        id: m.platform,
        name: m.label,
        level: "account" as const,
      })),
      impact: Math.abs(amount),
    });
  }

  // 2. Concentration risk. One platform carrying nearly all the spend is a
  //    business risk regardless of how well it is performing.
  const dominant = spending.find((c) => c.spendShare >= 0.8);
  if (dominant && spending.length > 1) {
    findings.push({
      id: "balance-concentration",
      severity: "info",
      title: `${Math.round(dominant.spendShare * 100)}% of ad spend is on ${dominant.label}`,
      detail:
        "A single platform carrying almost all the budget means an account issue, a policy change, or a CPM spike there hits the whole business at once. The others are too small to absorb it.",
      action: `Consider holding a deliberate test budget on the other platforms — enough to stay measurable (roughly ${MIN_SPEND_TO_JUDGE} in spend and ${MIN_PURCHASES_TO_JUDGE} conversions a period) even if they never become primary.`,
      entities: [
        { id: dominant.platform, name: dominant.label, level: "account" as const },
      ],
      impact: dominant.spend,
    });
  }

  // 3. Platforms spending real money below the target return.
  //
  //    Gated on the same volume test as the reallocation, not on spend alone.
  //    Without that, a platform could be told in one finding that it is
  //    underperforming and in the next that there is not enough data to judge
  //    it — both true by their own thresholds, and useless together.
  const byPlatform = new Map(slices.map((s) => [s.platform, s]));
  for (const c of spending) {
    if (c.roas === null || c.roas >= targetRoas) continue;

    const slice = byPlatform.get(c.platform);
    if (!slice || !judgeable(slice.totals)) continue;

    findings.push({
      id: `balance-under-target-${c.platform}`,
      severity: c.roas < targetRoas / 2 ? "critical" : "warning",
      title: `${c.label} is returning ${fmtRoas(c.roas)} against a ${targetRoas.toFixed(1)}x target`,
      detail: `${money(c.spend)} spent for ${money(c.revenue)} attributed revenue over the period.`,
      action:
        "Check that platform's own campaign table for whether this is the whole account or one campaign dragging it down, before cutting the budget.",
      entities: [{ id: c.platform, name: c.label, level: "account" as const }],
      impact: (targetRoas - c.roas) * c.spend,
    });
  }

  // 4. Real money going out, too little data to judge it. Worth naming
  //    explicitly: this is the state where the dashboard cannot help yet.
  for (const slice of slices) {
    const m = slice.totals;
    if (m.spend < MIN_SPEND_TO_JUDGE || judgeable(m)) continue;

    findings.push({
      id: `balance-thin-${slice.platform}`,
      severity: "info",
      title: `${slice.label} has too few conversions to judge`,
      detail: `${money(m.spend)} spent for ${m.purchases} conversion${m.purchases === 1 ? "" : "s"}. Below about ${MIN_PURCHASES_TO_JUDGE} in a period, a single order moves ROAS enough to make the comparison meaningless.`,
      action:
        "Either widen the date range until the count is meaningful, or leave the budget alone until it is — this is not yet evidence of anything.",
      entities: [
        { id: slice.platform, name: slice.label, level: "account" as const },
      ],
      impact: 0,
    });
  }

  // 5. Spend rising while return falls — the classic slow leak.
  for (const c of spending) {
    if (c.spendDelta === null || c.roasDelta === null) continue;
    if (c.spendDelta <= 0.15 || c.roasDelta >= -0.15) continue;

    findings.push({
      id: `balance-diverging-${c.platform}`,
      severity: "warning",
      title: `${c.label}: spend up ${Math.round(c.spendDelta * 100)}%, return down ${Math.round(Math.abs(c.roasDelta) * 100)}%`,
      detail:
        "Scaling into falling efficiency. Usually either audience saturation or a recent budget increase that outran what the account can convert.",
      action:
        "Compare frequency and CPM against the previous period before adding any more budget here.",
      entities: [{ id: c.platform, name: c.label, level: "account" as const }],
      impact: c.spend * Math.abs(c.roasDelta),
    });
  }

  // Biggest money at stake first, matching how rules.ts ranks its own.
  return findings.sort((a, b) => b.impact - a.impact);
}
