import { getConfig } from "./config";
import type {
  AccountInfo,
  DailyPoint,
  DateRange,
  EntityRow,
  HourlyPoint,
  Level,
  Metrics,
} from "./types";

const GRAPH = "https://graph.facebook.com";

/**
 * READ-ONLY INVARIANT
 *
 * This dashboard never modifies the ad account. Every Meta request goes
 * through `readOnlyFetch` below, which refuses anything that is not a GET.
 *
 * There are three independent layers holding this, so no single mistake can
 * break it:
 *   1. The token is scoped to `ads_read`; Meta rejects writes outright.
 *   2. Every call funnels through the two helpers in this file.
 *   3. `readOnlyFetch` throws before the request leaves the process.
 *
 * If you ever need a write, do not weaken this — it exists so that adding one
 * has to be a deliberate, visible decision rather than an accident.
 */
const MUTATING_METHODS = ["POST", "PUT", "PATCH", "DELETE"];

async function readOnlyFetch(url: string, init?: RequestInit): Promise<Response> {
  const method = (init?.method ?? "GET").toUpperCase();
  if (MUTATING_METHODS.includes(method)) {
    throw new MetaError(
      `Blocked a ${method} request to the Meta API. This dashboard is read-only and must never modify the ad account.`,
    );
  }
  return fetch(url, { ...init, method: "GET", cache: "no-store" });
}

function apiVersion(): string {
  return getConfig("META_API_VERSION") || "v23.0";
}

function accessToken(override?: string): string {
  const token = override || getConfig("META_ACCESS_TOKEN");
  if (!token) {
    throw new MetaError(
      "No Meta access token is configured. Open /setup to add one.",
    );
  }
  return token;
}

function attributionWindows(): string[] {
  const raw = getConfig("META_ATTRIBUTION_WINDOWS") || "7d_click,1d_view";
  return raw
    .split(",")
    .map((s) => s.trim())
    .filter(Boolean);
}

/** Carries Meta's own error text through to the UI — it's usually specific. */
export class MetaError extends Error {
  constructor(
    message: string,
    readonly code?: number,
    readonly subcode?: number,
    readonly type?: string,
  ) {
    super(message);
    this.name = "MetaError";
  }

  /** True for errors the user fixes by re-issuing the token, not by retrying. */
  get isAuthError(): boolean {
    // 190 invalid/expired token, 102 session expired,
    // 10 and 200 permission denied (token lacks ads_read on this account).
    return [190, 102, 10, 200].includes(this.code ?? -1);
  }
}

interface GraphResponse<T> {
  data?: T[];
  paging?: { next?: string; cursors?: { after?: string } };
  error?: {
    message: string;
    type: string;
    code: number;
    error_subcode?: number;
    error_user_msg?: string;
  };
}

async function graphGet<T>(
  path: string,
  params: Record<string, string | number | undefined>,
  /** Used by the setup page to validate a token before it is saved. */
  tokenOverride?: string,
): Promise<T[]> {
  const url = new URL(`${GRAPH}/${apiVersion()}/${path}`);
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== "") url.searchParams.set(key, String(value));
  }
  url.searchParams.set("access_token", accessToken(tokenOverride));

  const rows: T[] = [];
  let next: string | undefined = url.toString();
  let pages = 0;

  while (next && pages < 25) {
    const res: Response = await readOnlyFetch(next);
    const body = (await res.json()) as GraphResponse<T>;

    if (body.error) {
      const e = body.error;
      throw new MetaError(
        e.error_user_msg || e.message,
        e.code,
        e.error_subcode,
        e.type,
      );
    }
    if (!res.ok) {
      throw new MetaError(`Meta API returned HTTP ${res.status}`);
    }

    rows.push(...(body.data ?? []));
    next = body.paging?.next;
    pages += 1;
  }

  return rows;
}

/** Single-object GET (no `data` envelope), e.g. the account itself. */
async function graphGetOne<T>(
  path: string,
  params: Record<string, string | undefined>,
): Promise<T> {
  const url = new URL(`${GRAPH}/${apiVersion()}/${path}`);
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== "") url.searchParams.set(key, value);
  }
  url.searchParams.set("access_token", accessToken());

  const res = await readOnlyFetch(url.toString());
  const body = (await res.json()) as T & { error?: GraphResponse<T>["error"] };

  if (body.error) {
    const e = body.error;
    throw new MetaError(
      e.error_user_msg || e.message,
      e.code,
      e.error_subcode,
      e.type,
    );
  }
  if (!res.ok) throw new MetaError(`Meta API returned HTTP ${res.status}`);
  return body;
}

// --- Action extraction -----------------------------------------------------

interface ActionEntry {
  action_type: string;
  value: string;
  [window: string]: string;
}

/**
 * Meta reports the same conversion under several action_type aliases
 * depending on how the pixel/CAPI is wired. We take the first alias that
 * exists rather than summing, because summing double-counts.
 */
const PURCHASE_ALIASES = [
  "omni_purchase",
  "purchase",
  "offsite_conversion.fb_pixel_purchase",
  "onsite_web_purchase",
];
const ATC_ALIASES = [
  "omni_add_to_cart",
  "add_to_cart",
  "offsite_conversion.fb_pixel_add_to_cart",
];
const CHECKOUT_ALIASES = [
  "omni_initiated_checkout",
  "initiate_checkout",
  "offsite_conversion.fb_pixel_initiate_checkout",
];

function pickAction(entries: ActionEntry[] | undefined, aliases: string[]): number {
  if (!entries?.length) return 0;
  for (const alias of aliases) {
    const hit = entries.find((e) => e.action_type === alias);
    if (hit) {
      const n = Number(hit.value);
      if (Number.isFinite(n)) return n;
    }
  }
  return 0;
}

function exactAction(entries: ActionEntry[] | undefined, type: string): number {
  if (!entries?.length) return 0;
  const hit = entries.find((e) => e.action_type === type);
  const n = hit ? Number(hit.value) : 0;
  return Number.isFinite(n) ? n : 0;
}

function num(v: unknown): number {
  const n = Number(v);
  return Number.isFinite(n) ? n : 0;
}

/** Safe divide — returns null instead of Infinity/NaN so the UI can show "—". */
function ratio(numerator: number, denominator: number): number | null {
  if (!denominator) return null;
  const r = numerator / denominator;
  return Number.isFinite(r) ? r : null;
}

interface RawInsight {
  date_start?: string;
  date_stop?: string;
  spend?: string;
  impressions?: string;
  clicks?: string;
  inline_link_clicks?: string;
  reach?: string;
  frequency?: string;
  actions?: ActionEntry[];
  action_values?: ActionEntry[];
  campaign_id?: string;
  campaign_name?: string;
  adset_id?: string;
  adset_name?: string;
  ad_id?: string;
  ad_name?: string;
}

/**
 * Derives every ratio from the raw counters rather than reading Meta's own
 * ctr/cpc/cpm/purchase_roas fields. Meta rounds those, and rounded ratios
 * don't survive the aggregation we do for period totals.
 */
export function toMetrics(raw: RawInsight): Metrics {
  const spend = num(raw.spend);
  const impressions = num(raw.impressions);
  const clicks = num(raw.clicks);
  // Falls back to the action-array entry on rows where the inline field is
  // absent, then to total clicks so the funnel never divides by zero.
  const linkClicks =
    num(raw.inline_link_clicks) || exactAction(raw.actions, "link_click") || 0;
  const purchases = pickAction(raw.actions, PURCHASE_ALIASES);
  const revenue = pickAction(raw.action_values, PURCHASE_ALIASES);

  return {
    spend,
    impressions,
    clicks,
    linkClicks,
    reach: num(raw.reach),
    frequency: num(raw.frequency),
    purchases,
    revenue,
    roas: ratio(revenue, spend),
    cpa: ratio(spend, purchases),
    aov: ratio(revenue, purchases),
    ctr: ratio(clicks, impressions),
    linkCtr: ratio(linkClicks, impressions),
    cpc: ratio(spend, clicks),
    costPerLinkClick: ratio(spend, linkClicks),
    cpm: impressions ? (spend / impressions) * 1000 : null,
    addToCart: pickAction(raw.actions, ATC_ALIASES),
    initiateCheckout: pickAction(raw.actions, CHECKOUT_ALIASES),
    landingPageViews: exactAction(raw.actions, "landing_page_view"),
  };
}

export const EMPTY_METRICS: Metrics = {
  spend: 0,
  impressions: 0,
  clicks: 0,
  linkClicks: 0,
  reach: 0,
  frequency: 0,
  purchases: 0,
  revenue: 0,
  roas: null,
  cpa: null,
  aov: null,
  ctr: null,
  linkCtr: null,
  cpc: null,
  costPerLinkClick: null,
  cpm: null,
  addToCart: 0,
  initiateCheckout: 0,
  landingPageViews: 0,
};

/**
 * Sums counters and re-derives ratios. Note `reach` is intentionally summed
 * even though reach is not additive across days — we only ever call this on
 * same-day slices, and the field is displayed only for period totals fetched
 * directly from Meta.
 */
export function sumMetrics(rows: Metrics[]): Metrics {
  const t = rows.reduce(
    (acc, m) => {
      acc.spend += m.spend;
      acc.impressions += m.impressions;
      acc.clicks += m.clicks;
      acc.linkClicks += m.linkClicks;
      acc.reach += m.reach;
      acc.purchases += m.purchases;
      acc.revenue += m.revenue;
      acc.addToCart += m.addToCart;
      acc.initiateCheckout += m.initiateCheckout;
      acc.landingPageViews += m.landingPageViews;
      return acc;
    },
    { ...EMPTY_METRICS },
  );

  t.roas = ratio(t.revenue, t.spend);
  t.cpa = ratio(t.spend, t.purchases);
  t.aov = ratio(t.revenue, t.purchases);
  t.ctr = ratio(t.clicks, t.impressions);
  t.linkCtr = ratio(t.linkClicks, t.impressions);
  t.cpc = ratio(t.spend, t.clicks);
  t.costPerLinkClick = ratio(t.spend, t.linkClicks);
  t.cpm = t.impressions ? (t.spend / t.impressions) * 1000 : null;
  t.frequency = t.reach ? t.impressions / t.reach : 0;
  return t;
}

// --- Public API ------------------------------------------------------------

const INSIGHT_FIELDS = [
  "spend",
  "impressions",
  "clicks",
  "inline_link_clicks",
  "reach",
  "frequency",
  "actions",
  "action_values",
].join(",");

/**
 * Parent IDs are requested alongside each level so a deduction applied to an
 * ad can be cascaded up to its ad set and campaign. Without them the ad table
 * and the campaign table would disagree after an adjustment.
 */
const LEVEL_ID_FIELDS: Record<Exclude<Level, "account">, string> = {
  campaign: "campaign_id,campaign_name",
  adset: "adset_id,adset_name,campaign_id",
  ad: "ad_id,ad_name,adset_id,campaign_id",
};

export async function listAdAccounts(
  tokenOverride?: string,
): Promise<AccountInfo[]> {
  const rows = await graphGet<{
    id: string;
    name: string;
    currency: string;
    timezone_name: string;
    account_status: number;
  }>(
    "me/adaccounts",
    { fields: "id,name,currency,timezone_name,account_status", limit: 100 },
    tokenOverride,
  );

  return rows.map((r) => ({
    id: r.id,
    name: r.name,
    currency: r.currency,
    timezone: r.timezone_name,
  }));
}

export async function getAccountInfo(accountId: string): Promise<AccountInfo> {
  const r = await graphGetOne<{
    id: string;
    name: string;
    currency: string;
    timezone_name: string;
  }>(accountId, { fields: "id,name,currency,timezone_name" });

  return {
    id: r.id,
    name: r.name,
    currency: r.currency,
    timezone: r.timezone_name,
  };
}

/** Account-level totals for a window. One row. */
export async function getAccountTotals(
  accountId: string,
  range: DateRange,
): Promise<Metrics> {
  const rows = await graphGet<RawInsight>(`${accountId}/insights`, {
    fields: INSIGHT_FIELDS,
    level: "account",
    time_range: JSON.stringify(range),
    action_attribution_windows: JSON.stringify(attributionWindows()),
    limit: 1,
  });
  return rows.length ? toMetrics(rows[0]) : { ...EMPTY_METRICS };
}

/** Account-level daily series, for the trend chart. */
export async function getDailySeries(
  accountId: string,
  range: DateRange,
): Promise<DailyPoint[]> {
  const rows = await graphGet<RawInsight>(`${accountId}/insights`, {
    fields: INSIGHT_FIELDS,
    level: "account",
    time_range: JSON.stringify(range),
    time_increment: 1,
    action_attribution_windows: JSON.stringify(attributionWindows()),
    limit: 500,
  });

  return rows
    .filter((r) => r.date_start)
    .map((r) => ({ date: r.date_start!, metrics: toMetrics(r) }))
    .sort((a, b) => a.date.localeCompare(b.date));
}

/**
 * Within-day series for a single date, using Meta's hourly breakdown.
 *
 * Aggregated by advertiser time zone so the buckets line up with the account's
 * own day boundaries — the same basis every other figure on the dashboard uses.
 * Meta returns each bucket as "HH:MM:SS - HH:MM:SS"; only the start hour matters.
 */
export async function getHourlySeries(
  accountId: string,
  range: DateRange,
): Promise<HourlyPoint[]> {
  const rows = await graphGet<
    RawInsight & { hourly_stats_aggregated_by_advertiser_time_zone?: string }
  >(`${accountId}/insights`, {
    fields: INSIGHT_FIELDS,
    level: "account",
    time_range: JSON.stringify(range),
    breakdowns: "hourly_stats_aggregated_by_advertiser_time_zone",
    action_attribution_windows: JSON.stringify(attributionWindows()),
    limit: 200,
  });

  const points: HourlyPoint[] = [];
  for (const row of rows) {
    const bucket = row.hourly_stats_aggregated_by_advertiser_time_zone;
    if (!bucket) continue;

    const hour = Number(bucket.slice(0, 2));
    if (!Number.isInteger(hour) || hour < 0 || hour > 23) continue;

    points.push({
      hour,
      label: `${String(hour).padStart(2, "0")}:00`,
      metrics: toMetrics(row),
    });
  }
  return points.sort((a, b) => a.hour - b.hour);
}

/** Per-entity breakdown at a given level. */
export async function getBreakdown(
  accountId: string,
  range: DateRange,
  level: Exclude<Level, "account">,
): Promise<
  Map<
    string,
    { name: string; metrics: Metrics; campaignId?: string; adsetId?: string }
  >
> {
  const rows = await graphGet<RawInsight>(`${accountId}/insights`, {
    fields: `${INSIGHT_FIELDS},${LEVEL_ID_FIELDS[level]}`,
    level,
    time_range: JSON.stringify(range),
    action_attribution_windows: JSON.stringify(attributionWindows()),
    limit: 500,
  });

  const out = new Map<
    string,
    { name: string; metrics: Metrics; campaignId?: string; adsetId?: string }
  >();
  for (const row of rows) {
    const id =
      level === "campaign" ? row.campaign_id
      : level === "adset" ? row.adset_id
      : row.ad_id;
    const name =
      level === "campaign" ? row.campaign_name
      : level === "adset" ? row.adset_name
      : row.ad_name;
    if (!id) continue;
    out.set(id, {
      name: name || id,
      metrics: toMetrics(row),
      campaignId: row.campaign_id,
      adsetId: level === "ad" ? row.adset_id : undefined,
    });
  }
  return out;
}

interface EntityMeta {
  id: string;
  name: string;
  status?: string;
  effective_status?: string;
  daily_budget?: string;
  lifetime_budget?: string;
}

/**
 * Status and budget live on the object, not on insights, so this is a second
 * call. Budgets come back in minor units (cents), hence the /100.
 */
export async function getEntityMeta(
  accountId: string,
  level: Exclude<Level, "account">,
): Promise<Map<string, { status: string; dailyBudget: number | null; lifetimeBudget: number | null }>> {
  const edge =
    level === "campaign" ? "campaigns" : level === "adset" ? "adsets" : "ads";
  // Ads have no budget of their own — asking for the field is an API error.
  const fields =
    level === "ad"
      ? "id,name,effective_status"
      : "id,name,effective_status,daily_budget,lifetime_budget";

  const rows = await graphGet<EntityMeta>(`${accountId}/${edge}`, {
    fields,
    limit: 500,
  });

  const out = new Map<
    string,
    { status: string; dailyBudget: number | null; lifetimeBudget: number | null }
  >();
  for (const r of rows) {
    out.set(r.id, {
      status: r.effective_status || r.status || "UNKNOWN",
      dailyBudget: r.daily_budget ? num(r.daily_budget) / 100 : null,
      lifetimeBudget: r.lifetime_budget ? num(r.lifetime_budget) / 100 : null,
    });
  }
  return out;
}

/**
 * Joins current + comparison breakdowns plus object metadata into the rows
 * the table renders. Entities present in only one window still appear, so a
 * campaign that launched mid-period isn't silently dropped.
 */
export async function getEntityRows(
  accountId: string,
  range: DateRange,
  compareRange: DateRange,
  level: Exclude<Level, "account">,
): Promise<EntityRow[]> {
  const [current, previous, meta] = await Promise.all([
    getBreakdown(accountId, range, level),
    getBreakdown(accountId, compareRange, level),
    getEntityMeta(accountId, level).catch(() => new Map()),
  ]);

  const ids = new Set([...current.keys(), ...previous.keys()]);
  const rows: EntityRow[] = [];

  for (const id of ids) {
    const cur = current.get(id);
    const prev = previous.get(id);
    const m = meta.get(id);
    rows.push({
      id,
      name: cur?.name ?? prev?.name ?? id,
      level,
      campaignId: cur?.campaignId ?? prev?.campaignId,
      adsetId: cur?.adsetId ?? prev?.adsetId,
      status: m?.status,
      dailyBudget: m?.dailyBudget ?? null,
      lifetimeBudget: m?.lifetimeBudget ?? null,
      current: cur?.metrics ?? { ...EMPTY_METRICS },
      previous: prev?.metrics,
    });
  }

  return rows.sort((a, b) => b.current.spend - a.current.spend);
}
