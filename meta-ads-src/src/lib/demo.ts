import {
  addDays,
  comparisonRange,
  dayCount,
  rangeFromPreset,
  todayIn,
} from "./dates";
import { EMPTY_METRICS, sumMetrics } from "./meta";
import { buildFindings } from "./rules";
import type {
  DailyPoint,
  DashboardData,
  DateRange,
  EntityRow,
  HourlyPoint,
  Metrics,
  Preset,
} from "./types";

type CompareModeAlias = "previous_period" | "previous_year";

/**
 * Synthetic data so the dashboard can be seen before any credentials exist,
 * and so the layout can be checked without burning API quota. It is always
 * labelled as sample data in the UI — it must never be mistakable for a real
 * account.
 *
 * The numbers are deterministic (seeded by date) so a refresh doesn't reshuffle
 * the charts, and they are shaped to exercise every rule: one campaign with no
 * conversions, one below target, one clear winner, one with high frequency.
 */

/** Small deterministic PRNG — same day in, same numbers out. */
function seeded(seed: number): () => number {
  let s = seed >>> 0;
  return () => {
    s = (s * 1664525 + 1013904223) >>> 0;
    return s / 4294967296;
  };
}

function hashDate(key: string): number {
  let h = 2166136261;
  for (let i = 0; i < key.length; i++) {
    h ^= key.charCodeAt(i);
    h = Math.imul(h, 16777619);
  }
  return h >>> 0;
}

interface Profile {
  name: string;
  status: string;
  dailySpend: number;
  roas: number;
  ctr: number;
  aov: number;
  frequency: number;
  dailyBudget: number;
  /** Multiplier applied to the comparison period, to create movement. */
  previousRoasFactor: number;
}

const PROFILES: Profile[] = [
  {
    name: "Prospecting — Broad — Video",
    status: "ACTIVE",
    dailySpend: 180,
    roas: 2.6,
    ctr: 0.0142,
    aov: 74,
    frequency: 1.6,
    dailyBudget: 200,
    previousRoasFactor: 1.05,
  },
  {
    name: "Retargeting — 30d Site Visitors",
    status: "ACTIVE",
    dailySpend: 60,
    roas: 5.4,
    ctr: 0.0231,
    aov: 82,
    frequency: 3.4,
    dailyBudget: 65,
    previousRoasFactor: 0.92,
  },
  {
    name: "Advantage+ Shopping",
    status: "ACTIVE",
    dailySpend: 240,
    roas: 1.5,
    ctr: 0.0098,
    aov: 68,
    frequency: 2.1,
    dailyBudget: 250,
    previousRoasFactor: 1.38,
  },
  {
    name: "Cold — Interest Stack — Static",
    status: "ACTIVE",
    dailySpend: 95,
    roas: 0,
    ctr: 0.0061,
    aov: 0,
    frequency: 1.3,
    dailyBudget: 100,
    previousRoasFactor: 1,
  },
  {
    name: "Lookalike 1% — Carousel",
    status: "PAUSED",
    dailySpend: 22,
    roas: 3.1,
    ctr: 0.0176,
    aov: 79,
    frequency: 1.9,
    dailyBudget: 40,
    previousRoasFactor: 0.98,
  },
];

function metricsFor(
  profile: Profile,
  days: number,
  rand: () => number,
  roasFactor: number,
): Metrics {
  const jitter = () => 0.85 + rand() * 0.3;

  const spend = profile.dailySpend * days * jitter();
  const roas = profile.roas * roasFactor * jitter();
  const revenue = spend * roas;
  const aov = profile.aov || 0;
  const purchases = aov > 0 ? Math.round(revenue / aov) : 0;

  const cpm = 14 * jitter();
  const impressions = Math.round((spend / cpm) * 1000);
  const clicks = Math.round(impressions * profile.ctr * jitter());
  // Roughly half of all clicks are engagement rather than link clicks, which
  // is typical and is exactly the distinction the funnel rule depends on.
  const linkClicks = Math.round(clicks * (0.45 + rand() * 0.2));
  const reach = Math.round(impressions / (profile.frequency * jitter()));
  const landingPageViews = Math.round(linkClicks * (0.8 + rand() * 0.15));

  return {
    spend,
    impressions,
    clicks,
    linkClicks,
    reach,
    frequency: reach ? impressions / reach : 0,
    purchases,
    revenue: purchases * aov,
    roas: spend ? (purchases * aov) / spend : null,
    cpa: purchases ? spend / purchases : null,
    aov: purchases ? aov : null,
    ctr: impressions ? clicks / impressions : null,
    linkCtr: impressions ? linkClicks / impressions : null,
    cpc: clicks ? spend / clicks : null,
    costPerLinkClick: linkClicks ? spend / linkClicks : null,
    cpm: impressions ? (spend / impressions) * 1000 : null,
    addToCart: Math.round(purchases * (3.4 + rand())),
    initiateCheckout: Math.round(purchases * (1.8 + rand() * 0.6)),
    landingPageViews,
  };
}

function dailyFor(range: DateRange, profiles: Profile[]): DailyPoint[] {
  const days = dayCount(range);
  const points: DailyPoint[] = [];

  for (let i = 0; i < days; i++) {
    const date = addDays(range.since, i);
    const rand = seeded(hashDate(date));
    const perProfile = profiles.map((p) => metricsFor(p, 1, rand, 1));
    points.push({ date, metrics: sumMetrics(perProfile) });
  }
  return points;
}

/**
 * Within-day shape for the demo. Weighted by a rough traffic curve — quiet
 * overnight, building through the morning, peaking early evening — so the
 * hourly charts show something recognisable rather than flat noise.
 */
const HOUR_WEIGHTS = [
  0.2, 0.15, 0.1, 0.1, 0.15, 0.3, 0.6, 1.0, 1.3, 1.4, 1.4, 1.5,
  1.6, 1.5, 1.4, 1.4, 1.5, 1.8, 2.0, 1.9, 1.6, 1.2, 0.7, 0.4,
];

function hourlyFor(
  range: DateRange,
  profiles: Profile[],
  throughHour: number,
): HourlyPoint[] {
  const weightTotal = HOUR_WEIGHTS.reduce((a, b) => a + b, 0);
  const points: HourlyPoint[] = [];

  for (let hour = 0; hour <= throughHour; hour++) {
    const rand = seeded(hashDate(range.since) + hour * 2654435761);
    const share = HOUR_WEIGHTS[hour] / weightTotal;
    const slice = profiles.map((p) =>
      metricsFor({ ...p, dailySpend: p.dailySpend * share * 24 }, 1 / 24, rand, 1),
    );
    points.push({
      hour,
      label: `${String(hour).padStart(2, "0")}:00`,
      metrics: sumMetrics(slice),
    });
  }
  return points;
}

export function buildDemoData(
  preset: Preset,
  compareMode: CompareModeAlias,
): DashboardData {
  const timezone = "America/Los_Angeles";
  const range = rangeFromPreset(preset, timezone);
  const { range: compareRange, label: compareLabel } = comparisonRange(
    range,
    compareMode,
  );

  const days = dayCount(range);
  const compareDays = dayCount(compareRange);

  const today = todayIn(timezone);
  const isPartial = range.until >= today;
  const isSingleDay = days === 1;
  // A real "today" only has data up to the current hour; a past single day is
  // complete. Derived from the wall clock rather than the seed so the demo
  // behaves like the live view.
  const throughHour = isPartial
    ? Number(
        new Intl.DateTimeFormat("en-GB", {
          timeZone: timezone,
          hour: "2-digit",
          hour12: false,
        }).format(new Date()),
      ) % 24
    : 23;

  const campaigns: EntityRow[] = PROFILES.map((p, i) => {
    const rand = seeded(hashDate(range.since) + i * 7919);
    return {
      id: `demo_campaign_${i}`,
      name: p.name,
      level: "campaign" as const,
      status: p.status,
      dailyBudget: p.dailyBudget,
      lifetimeBudget: null,
      current: metricsFor(p, days, rand, 1),
      previous: metricsFor(p, compareDays, rand, p.previousRoasFactor),
    };
  });

  // Ad sets and ads are split off their parent campaign so the numbers stay
  // internally consistent when the user switches level.
  const adsets: EntityRow[] = PROFILES.flatMap((p, i) =>
    ["Ad set A", "Ad set B"].map((suffix, j) => {
      const rand = seeded(hashDate(range.since) + i * 104729 + j * 31);
      const scaled = { ...p, dailySpend: p.dailySpend / 2 };
      return {
        id: `demo_adset_${i}_${j}`,
        name: `${p.name} · ${suffix}`,
        level: "adset" as const,
        status: p.status,
        dailyBudget: null,
        lifetimeBudget: null,
        current: metricsFor(scaled, days, rand, 1),
        previous: metricsFor(scaled, compareDays, rand, p.previousRoasFactor),
      };
    }),
  );

  const ads: EntityRow[] = PROFILES.flatMap((p, i) =>
    ["Hook v1", "Hook v2", "UGC"].map((suffix, j) => {
      const rand = seeded(hashDate(range.since) + i * 15485863 + j * 97);
      const scaled = { ...p, dailySpend: p.dailySpend / 3 };
      return {
        id: `demo_ad_${i}_${j}`,
        name: `${p.name} · ${suffix}`,
        level: "ad" as const,
        status: p.status,
        dailyBudget: null,
        lifetimeBudget: null,
        current: metricsFor(scaled, days, rand, 1),
        previous: metricsFor(scaled, compareDays, rand, p.previousRoasFactor),
      };
    }),
  );

  const totals = sumMetrics(campaigns.map((c) => c.current));
  const previousTotals = sumMetrics(
    campaigns.map((c) => c.previous ?? { ...EMPTY_METRICS }),
  );

  const account = {
    id: "act_demo",
    name: "Sample Account (demo data)",
    currency: "USD",
    timezone,
  };

  const targetRoas = 2.0;

  /*
   * Demo store revenue, deliberately including two wholesale orders so the
   * exclusion path and its findings are visible without a real Shopify
   * connection. Retail revenue runs a little below Meta's attributed figure,
   * which is the normal relationship — Meta claims conversions other channels
   * also claim.
   */
  const retail = totals.revenue * 0.82;
  const wholesale = [3_200, 2_450];
  const excludedRevenue = wholesale.reduce((a, b) => a + b, 0);

  const shopify = {
    totalRevenue: retail,
    orderCount: Math.max(1, Math.round(retail / 74)),
    currency: "USD",
    daily: dailyFor(range, PROFILES).map((d) => ({
      date: d.date,
      revenue: d.metrics.revenue * 0.82,
      orders: Math.round(d.metrics.purchases * 0.9),
    })),
    grossRevenue: retail + excludedRevenue,
    grossOrderCount: Math.max(1, Math.round(retail / 74)) + wholesale.length,
    excludedRevenue,
    excludedOrders: wholesale.length,
    exclusionReasons: [
      { reason: "Created from a draft order", orders: 1, revenue: wholesale[0] },
      { reason: "B2B company account", orders: 1, revenue: wholesale[1] },
    ],
    activeRules: [
      "orders created from a draft",
      "orders placed by a B2B company account",
    ],
  };

  const findings = buildFindings({
    range,
    totals,
    previousTotals,
    campaigns,
    adsets,
    ads,
    shopify,
    targetRoas,
    targetCpa: null,
    currency: account.currency,
  });

  return {
    account,
    range,
    compareRange,
    compareLabel,
    totals,
    previousTotals,
    daily: dailyFor(range, PROFILES),
    previousDaily: dailyFor(compareRange, PROFILES),
    hourly: isSingleDay ? hourlyFor(range, PROFILES, throughHour) : null,
    // The comparison day is complete, so it runs the full 24 hours — which is
    // what makes "am I ahead of yesterday at this hour" answerable.
    previousHourly: isSingleDay ? hourlyFor(compareRange, PROFILES, 23) : null,
    partial: isPartial,
    campaigns,
    adsets,
    ads,
    shopify,
    blendedRoas: totals.spend ? shopify.totalRevenue / totals.spend : null,
    findings,
    adjustments: [],
    comparisonAdjustments: [],
    adjustedRevenue: 0,
    targets: { roas: targetRoas, cpa: null },
    warnings: [
      "Showing sample data, not your ad account. Add META_ACCESS_TOKEN and META_AD_ACCOUNT_ID to .env.local to see real numbers.",
    ],
  };
}
