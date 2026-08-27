/** Shared domain types. Everything the UI renders is shaped here. */

export type Level = "account" | "campaign" | "adset" | "ad";

export type Preset =
  | "today"
  | "yesterday"
  | "last_7d"
  | "last_14d"
  | "last_28d"
  | "last_30d"
  | "last_90d"
  | "this_month"
  | "last_month";

export interface DateRange {
  since: string; // YYYY-MM-DD
  until: string; // YYYY-MM-DD
}

/**
 * The normalized metric set. Every row in the dashboard — account totals,
 * a campaign, a single day — is one of these, so comparisons and rollups
 * only ever have to deal with one shape.
 */
export interface Metrics {
  spend: number;
  impressions: number;
  /**
   * Every click on the ad, including likes, shares, comments, profile taps and
   * image expands. Not the number of people who headed for your site — use
   * `linkClicks` for anything funnel-related.
   */
  clicks: number;
  /** Clicks on the link to your destination. The honest top of the funnel. */
  linkClicks: number;
  reach: number;
  frequency: number;
  /** Purchase conversions attributed by Meta. */
  purchases: number;
  /** Purchase conversion value attributed by Meta, in account currency. */
  revenue: number;
  /** revenue / spend. null when spend is 0. */
  roas: number | null;
  /** spend / purchases. null when there are no purchases. */
  cpa: number | null;
  /** revenue / purchases. null when there are no purchases. */
  aov: number | null;
  /** clicks / impressions, as a fraction (0.012 = 1.2%). */
  ctr: number | null;
  /** linkClicks / impressions. The one that reflects intent to visit. */
  linkCtr: number | null;
  /** spend / clicks. */
  cpc: number | null;
  /** spend / linkClicks. What a visit actually costs you. */
  costPerLinkClick: number | null;
  /** spend per 1000 impressions. */
  cpm: number | null;
  /** Adds to cart, for funnel diagnosis. */
  addToCart: number;
  /** Checkouts initiated, for funnel diagnosis. */
  initiateCheckout: number;
  /** Landing page views — the honest denominator for click quality. */
  landingPageViews: number;
}

/** A named entity (campaign/adset/ad) with its metrics for a period. */
export interface EntityRow {
  id: string;
  name: string;
  level: Level;
  /** Parent IDs, so an ad-level adjustment can cascade up to its campaign. */
  campaignId?: string;
  adsetId?: string;
  /** Meta delivery status, e.g. ACTIVE / PAUSED. Absent at account level. */
  status?: string;
  /** Daily budget in account currency, when the entity carries one. */
  dailyBudget?: number | null;
  /** Lifetime budget in account currency, when the entity carries one. */
  lifetimeBudget?: number | null;
  current: Metrics;
  /** Same entity over the comparison window. Absent if it didn't run then. */
  previous?: Metrics;
}

export interface DailyPoint {
  date: string; // YYYY-MM-DD
  metrics: Metrics;
}

/** Within-day detail, used when the range is a single day. */
export interface HourlyPoint {
  /** 0-23, in the ad account's timezone. */
  hour: number;
  /** "09:00" */
  label: string;
  metrics: Metrics;
}

export interface AccountInfo {
  id: string;
  name: string;
  currency: string;
  timezone: string;
}

/** Why an order was left out of the revenue figures. */
export interface ExclusionReason {
  reason: string;
  orders: number;
  revenue: number;
}

/** Revenue pulled from Shopify, for the blended-ROAS cross-check. */
export interface ShopifyRevenue {
  /** Net of exclusions — this is the figure used everywhere. */
  totalRevenue: number;
  orderCount: number;
  currency: string;
  daily: { date: string; revenue: number; orders: number }[];
  /** What the total would have been with nothing excluded. */
  grossRevenue: number;
  grossOrderCount: number;
  excludedRevenue: number;
  excludedOrders: number;
  exclusionReasons: ExclusionReason[];
  /** Human-readable summary of the active rules, for the UI. */
  activeRules: string[];
}

/**
 * A manual deduction for revenue Meta attributed to an ad that was not really
 * ad-driven — most often a wholesale order that reached Meta through a
 * server-side integration and therefore cannot be filtered out at source.
 */
export interface Adjustment {
  id: string;
  /** Date of the order being removed, YYYY-MM-DD. */
  date: string;
  /** Revenue to deduct, in account currency. */
  amount: number;
  /** Conversions to deduct along with it. Usually 1. */
  purchases: number;
  /** Where the deduction lands. Cascades upward from ad to account. */
  level: Level;
  entityId?: string;
  entityName?: string;
  note?: string;
  createdAt: string;
}

/** One possible home for a deduction, with the evidence for it. */
export interface AdjustmentCandidate {
  id: string;
  name: string;
  level: Level;
  revenue: number;
  purchases: number;
  /** Average order value as reported. */
  currentAov: number;
  /** What AOV becomes once the amount is removed. */
  residualAov: number | null;
  /** The account's normal AOV, for comparison. */
  normalAov: number | null;
  /** Higher means removing the amount makes this entity look more typical. */
  score: number;
  /** Plain-language reason this is or isn't a likely match. */
  evidence: string;
}

export type Severity = "critical" | "warning" | "opportunity" | "info";

/** One deterministic finding from the rules engine. */
export interface Finding {
  id: string;
  severity: Severity;
  /** Short headline, e.g. "3 campaigns below target ROAS". */
  title: string;
  /** One or two sentences of plain-language explanation. */
  detail: string;
  /** The concrete next step. */
  action: string;
  /** Entities this finding is about, for deep-linking in the UI. */
  entities: { id: string; name: string; level: Level }[];
  /** Money at stake per period — used to rank findings. */
  impact: number;
}

export interface Advice {
  /** 2-4 sentence read of the account. */
  summary: string;
  /** Ranked, specific actions. */
  actions: {
    priority: "now" | "this_week" | "monitor";
    title: string;
    reasoning: string;
    /** Names of the campaigns/adsets/ads this applies to. */
    targets: string[];
  }[];
  /** Things that look concerning but are explained by something benign. */
  caveats: string[];
}

/** The full payload the dashboard renders. */
export interface DashboardData {
  /**
   * Whether this viewer may change things — Setup and revenue Adjustments — or
   * only read the figures. Mirrors Edit vs View on the MRP permission area, and
   * is advisory for the UI only: the endpoints enforce it for themselves.
   */
  canEdit: boolean;
  account: AccountInfo;
  range: DateRange;
  compareRange: DateRange;
  compareLabel: string;
  totals: Metrics;
  previousTotals: Metrics;
  daily: DailyPoint[];
  previousDaily: DailyPoint[];
  /** Populated only for single-day ranges; null when the API refused it. */
  hourly: HourlyPoint[] | null;
  previousHourly: HourlyPoint[] | null;
  /**
   * True when the range runs to today, so the figures are still moving.
   * Attributed conversions in particular keep climbing for days.
   */
  partial: boolean;
  campaigns: EntityRow[];
  adsets: EntityRow[];
  ads: EntityRow[];
  shopify: ShopifyRevenue | null;
  /** Shopify revenue / Meta spend. null when Shopify isn't configured. */
  blendedRoas: number | null;
  findings: Finding[];
  /** Manual deductions applied to the figures above, for this period. */
  adjustments: Adjustment[];
  /**
   * Deductions applied to the comparison period. Not shown in the headline
   * figures, but they move every delta on the page, so the UI names them.
   */
  comparisonAdjustments: Adjustment[];
  adjustedRevenue: number;
  targets: { roas: number; cpa: number | null };
  /** Non-fatal problems (e.g. Shopify failed but Meta worked). */
  warnings: string[];
}
