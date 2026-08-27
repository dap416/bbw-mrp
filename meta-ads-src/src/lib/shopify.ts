import { getConfig, hasConfig } from "./config";
import type { DateRange, ExclusionReason, ShopifyRevenue } from "./types";

/**
 * Pulls real order revenue so the dashboard can show blended ROAS
 * (total store revenue / total Meta spend) next to Meta's attributed figure.
 * Entirely optional — returns null when the store isn't configured.
 *
 * Orders can be excluded before they reach the revenue figures. A handful of
 * wholesale orders will swamp a month of retail: one £3,000 B2B order against
 * £2,000 of ad spend adds 1.5x to blended ROAS on its own, and no amount of
 * campaign optimisation explains the resulting number. Excluding them is the
 * difference between a metric that tracks ad performance and one that tracks
 * whether a wholesale invoice happened to land this month.
 */

const API_VERSION = "2025-01";

export function isShopifyConfigured(): boolean {
  return hasConfig("SHOPIFY_STORE_DOMAIN") && hasConfig("SHOPIFY_ADMIN_TOKEN");
}

interface ExclusionRules {
  tags: string[];
  above: number | null;
  b2b: boolean;
  draftOrders: boolean;
}

/** Shopify's `sourceName` for an order created from a draft. */
const DRAFT_ORDER_SOURCE = "shopify_draft_order";

function readRules(): ExclusionRules {
  const tags = (getConfig("SHOPIFY_EXCLUDE_TAGS") ?? "")
    .split(",")
    .map((t) => t.trim().toLowerCase())
    .filter(Boolean);

  const aboveRaw = Number(getConfig("SHOPIFY_EXCLUDE_ABOVE"));
  const above = Number.isFinite(aboveRaw) && aboveRaw > 0 ? aboveRaw : null;

  // Both default to on. A B2B company order is wholesale by definition, and a
  // draft order was typed into the admin by a human rather than placed through
  // the storefront — neither is retail demand an ad campaign produced.
  const b2b = (getConfig("SHOPIFY_EXCLUDE_B2B") ?? "true").toLowerCase() !== "false";
  const draftOrders =
    (getConfig("SHOPIFY_EXCLUDE_DRAFT_ORDERS") ?? "true").toLowerCase() !== "false";

  return { tags, above, b2b, draftOrders };
}

export function describeRules(rules: ExclusionRules): string[] {
  const out: string[] = [];
  if (rules.draftOrders) out.push("orders created from a draft");
  if (rules.tags.length) {
    out.push(`orders tagged ${rules.tags.map((t) => `“${t}”`).join(" or ")}`);
  }
  if (rules.b2b) out.push("orders placed by a B2B company account");
  if (rules.above !== null) {
    out.push(`orders of ${rules.above.toLocaleString()} or more`);
  }
  return out;
}

interface OrderNode {
  name: string;
  createdAt: string;
  tags: string[];
  /** "web", "pos", "shopify_draft_order", an app id, or null. */
  sourceName: string | null;
  currentTotalPriceSet: { shopMoney: { amount: string; currencyCode: string } };
  purchasingEntity: { company?: { name: string } | null } | null;
}

interface OrdersResponse {
  data?: {
    orders: {
      edges: { node: OrderNode }[];
      pageInfo: { hasNextPage: boolean; endCursor: string | null };
    };
  };
  errors?: { message: string }[];
}

/**
 * `currentTotalPrice` rather than `totalPrice` so refunds and edits are
 * reflected — otherwise a refunded order keeps inflating blended ROAS forever.
 *
 * Deliberately does not request `customer { tags }`: that needs the
 * `read_customers` scope, and a missing scope fails the whole query rather
 * than just that field. Order tags are how merchants mark wholesale anyway.
 */
const ORDERS_QUERY = `
  query Orders($query: String!, $cursor: String) {
    orders(first: 250, after: $cursor, query: $query, sortKey: CREATED_AT) {
      edges {
        node {
          name
          createdAt
          tags
          sourceName
          currentTotalPriceSet { shopMoney { amount currencyCode } }
          purchasingEntity {
            ... on PurchasingCompany { company { name } }
          }
        }
      }
      pageInfo { hasNextPage endCursor }
    }
  }
`;

export async function getShopifyRevenue(
  range: DateRange,
  timezone: string,
): Promise<ShopifyRevenue | null> {
  if (!isShopifyConfigured()) return null;

  const domain = getConfig("SHOPIFY_STORE_DOMAIN")!.replace(/^https?:\/\//, "");
  const token = getConfig("SHOPIFY_ADMIN_TOKEN")!;
  const endpoint = `https://${domain}/admin/api/${API_VERSION}/graphql.json`;
  const rules = readRules();

  // Shopify's search syntax takes plain dates and interprets them in the
  // shop's own timezone, which is what we want for day-boundary alignment.
  const query = `created_at:>=${range.since} created_at:<=${range.until} test:false`;

  let cursor: string | null = null;
  const byDay = new Map<string, { revenue: number; orders: number }>();
  const reasons = new Map<string, { orders: number; revenue: number }>();

  let totalRevenue = 0;
  let orderCount = 0;
  let grossRevenue = 0;
  let grossOrderCount = 0;
  let currency = "USD";
  let pages = 0;

  /*
   * READ-ONLY INVARIANT
   *
   * The HTTP method is POST because that is how GraphQL transports a query —
   * it is not a write. The document sent is asserted to be a query and never a
   * mutation, and the app's token only needs `read_orders`, so Shopify would
   * reject a write regardless. Keep both: the assertion makes an accidental
   * mutation fail loudly here rather than silently succeed if someone later
   * grants a broader scope.
   */
  if (/\bmutation\b/i.test(ORDERS_QUERY)) {
    throw new Error(
      "Blocked a Shopify GraphQL mutation. This dashboard is read-only and must never modify the store.",
    );
  }

  while (pages < 20) {
    const res: Response = await fetch(endpoint, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-Shopify-Access-Token": token,
      },
      body: JSON.stringify({ query: ORDERS_QUERY, variables: { query, cursor } }),
      cache: "no-store",
    });

    if (!res.ok) {
      throw new Error(
        `Shopify returned HTTP ${res.status}. Check SHOPIFY_ADMIN_TOKEN has the read_orders scope.`,
      );
    }

    const body = (await res.json()) as OrdersResponse;
    if (body.errors?.length) {
      throw new Error(`Shopify: ${body.errors.map((e) => e.message).join("; ")}`);
    }
    if (!body.data) break;

    for (const { node } of body.data.orders.edges) {
      const amount = Number(node.currentTotalPriceSet.shopMoney.amount);
      if (!Number.isFinite(amount)) continue;
      currency = node.currentTotalPriceSet.shopMoney.currencyCode || currency;

      grossRevenue += amount;
      grossOrderCount += 1;

      const excluded = exclusionFor(node, amount, rules);
      if (excluded) {
        const bucket = reasons.get(excluded) ?? { orders: 0, revenue: 0 };
        bucket.orders += 1;
        bucket.revenue += amount;
        reasons.set(excluded, bucket);
        continue;
      }

      const day = dayKeyInTimezone(node.createdAt, timezone);
      const bucket = byDay.get(day) ?? { revenue: 0, orders: 0 };
      bucket.revenue += amount;
      bucket.orders += 1;
      byDay.set(day, bucket);

      totalRevenue += amount;
      orderCount += 1;
    }

    if (!body.data.orders.pageInfo.hasNextPage) break;
    cursor = body.data.orders.pageInfo.endCursor;
    pages += 1;
  }

  const exclusionReasons: ExclusionReason[] = [...reasons.entries()]
    .map(([reason, v]) => ({ reason, ...v }))
    .sort((a, b) => b.revenue - a.revenue);

  return {
    totalRevenue,
    orderCount,
    currency,
    daily: [...byDay.entries()]
      .map(([date, v]) => ({ date, ...v }))
      .sort((a, b) => a.date.localeCompare(b.date)),
    grossRevenue,
    grossOrderCount,
    excludedRevenue: grossRevenue - totalRevenue,
    excludedOrders: grossOrderCount - orderCount,
    exclusionReasons,
    activeRules: describeRules(rules),
  };
}

/**
 * Returns the reason this order is excluded, or null to keep it.
 * Rules are checked most-specific first so the reported reason is the most
 * informative one — an order both tagged wholesale and over the threshold is
 * reported as tagged, which is the rule the merchant actually set.
 */
function exclusionFor(
  order: OrderNode,
  amount: number,
  rules: ExclusionRules,
): string | null {
  // Checked first: a draft order was created in the admin rather than placed
  // through the storefront, which makes it the most definitive signal here —
  // more so than a tag someone has to remember to apply.
  if (rules.draftOrders && order.sourceName === DRAFT_ORDER_SOURCE) {
    return "Created from a draft order";
  }

  const tags = (order.tags ?? []).map((t) => t.toLowerCase());
  const matched = rules.tags.find((rule) => tags.includes(rule));
  if (matched) return `Tagged “${matched}”`;

  if (rules.b2b && order.purchasingEntity?.company) {
    return "B2B company account";
  }

  if (rules.above !== null && amount >= rules.above) {
    return `Over ${rules.above.toLocaleString()} threshold`;
  }

  return null;
}

/**
 * Buckets an ISO timestamp into the ad account's day, so Shopify revenue
 * lines up with Meta spend on the same chart. Without this, a 9pm PT order
 * lands on the next day and every daily comparison is skewed.
 */
function dayKeyInTimezone(iso: string, timezone: string): string {
  try {
    return new Intl.DateTimeFormat("en-CA", {
      timeZone: timezone,
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
    }).format(new Date(iso));
  } catch {
    return iso.slice(0, 10);
  }
}
