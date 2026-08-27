import { dayCount } from "./dates";
import type {
  DateRange,
  EntityRow,
  Finding,
  Metrics,
  ShopifyRevenue,
} from "./types";

/**
 * Deterministic findings. Everything here is objective arithmetic against a
 * threshold — no judgement calls, no LLM. The written analysis builds on top
 * of these rather than re-deriving them, so the narrative and the numbers
 * can't disagree.
 */

export interface RuleContext {
  range: DateRange;
  totals: Metrics;
  previousTotals: Metrics;
  campaigns: EntityRow[];
  adsets: EntityRow[];
  ads: EntityRow[];
  shopify: ShopifyRevenue | null;
  targetRoas: number;
  targetCpa: number | null;
  currency: string;
}

/**
 * Below this many purchases, a ROAS figure is noise — one order either way
 * swings it enough that acting on it is gambling. Findings that would judge
 * an entity's efficiency are suppressed under this threshold, and instead we
 * emit an explicit "not enough data" note.
 */
const MIN_PURCHASES_FOR_JUDGEMENT = 5;

/** Entities under this share of total spend aren't worth surfacing. */
const MIN_SPEND_SHARE = 0.02;

/** Frequency above this in a ≤30 day window means the audience is saturated. */
const FREQUENCY_CEILING = 3.0;

function pctChange(current: number, previous: number): number | null {
  if (!previous) return null;
  return (current - previous) / previous;
}

export function buildFindings(ctx: RuleContext): Finding[] {
  const findings: Finding[] = [];
  const { totals, previousTotals, campaigns, targetRoas, targetCpa } = ctx;
  const days = dayCount(ctx.range);
  const totalSpend = totals.spend;

  if (totalSpend <= 0) {
    return [
      {
        id: "no-spend",
        severity: "info",
        title: "No spend in this period",
        detail:
          "Meta reports zero spend for the selected dates. Either nothing was delivering, or the date range is outside your account's activity.",
        action: "Try a wider date range, or check that campaigns are active.",
        entities: [],
        impact: 0,
      },
    ];
  }

  const material = campaigns.filter(
    (c) => c.current.spend / totalSpend >= MIN_SPEND_SHARE,
  );

  // --- 1. Spend with nothing to show for it -------------------------------
  // The single most expensive mistake, so it leads.
  const zeroConversion = material.filter(
    (c) => c.current.purchases === 0 && c.current.spend > 0,
  );
  if (zeroConversion.length) {
    const wasted = zeroConversion.reduce((s, c) => s + c.current.spend, 0);
    findings.push({
      id: "zero-conversions",
      severity: "critical",
      title: `${zeroConversion.length} campaign${zeroConversion.length === 1 ? "" : "s"} spending with zero purchases`,
      detail: `${fmtMoney(wasted, ctx.currency)} spent over ${days} day${days === 1 ? "" : "s"} produced no attributed purchases. That is ${((wasted / totalSpend) * 100).toFixed(0)}% of account spend returning nothing.`,
      action:
        "Check these are not still in learning phase, then pause or rebuild. If the pixel recently changed, verify tracking before pausing.",
      entities: zeroConversion.map(toEntityRef),
      impact: wasted,
    });
  }

  // --- 2. Below target ROAS, with enough volume to be sure ----------------
  const belowTarget = material.filter(
    (c) =>
      c.current.purchases >= MIN_PURCHASES_FOR_JUDGEMENT &&
      c.current.roas !== null &&
      c.current.roas < targetRoas,
  );
  if (belowTarget.length) {
    const spend = belowTarget.reduce((s, c) => s + c.current.spend, 0);
    const revenue = belowTarget.reduce((s, c) => s + c.current.revenue, 0);
    // What you'd have to give up to get back to target on this spend.
    const shortfall = spend * targetRoas - revenue;
    findings.push({
      id: "below-target-roas",
      severity: "warning",
      title: `${belowTarget.length} campaign${belowTarget.length === 1 ? "" : "s"} below your ${targetRoas.toFixed(1)}x target`,
      detail: `These spent ${fmtMoney(spend, ctx.currency)} and returned ${fmtMoney(revenue, ctx.currency)} (${(revenue / spend).toFixed(2)}x). Hitting target would have required another ${fmtMoney(shortfall, ctx.currency)} in revenue.`,
      action:
        "Cut budget on the worst performer first and move it to your highest-ROAS campaign. Change one thing at a time so you can read the result.",
      entities: belowTarget.map(toEntityRef),
      impact: shortfall,
    });
  }

  // --- 3. Winners that are being starved ----------------------------------
  const winners = campaigns.filter(
    (c) =>
      c.current.purchases >= MIN_PURCHASES_FOR_JUDGEMENT &&
      c.current.roas !== null &&
      c.current.roas >= targetRoas * 1.5 &&
      c.current.spend / totalSpend < 0.25,
  );
  if (winners.length) {
    findings.push({
      id: "scale-winners",
      severity: "opportunity",
      title: `${winners.length} campaign${winners.length === 1 ? "" : "s"} performing well above target on limited budget`,
      detail: `These are returning at least ${(targetRoas * 1.5).toFixed(1)}x but take under a quarter of account spend. ${winners.map((w) => `${w.name} is at ${w.current.roas!.toFixed(2)}x`).join("; ")}.`,
      action:
        "Raise budget by 20-30% and wait 3-4 days before the next increase. Larger jumps reset the learning phase and usually cost you the performance you were scaling.",
      entities: winners.map(toEntityRef),
      impact: winners.reduce((s, c) => s + c.current.revenue, 0),
    });
  }

  // --- 4. Creative fatigue: frequency ------------------------------------
  if (days <= 30) {
    const fatigued = ctx.adsets.filter(
      (a) =>
        a.current.frequency >= FREQUENCY_CEILING &&
        a.current.spend / totalSpend >= MIN_SPEND_SHARE,
    );
    if (fatigued.length) {
      findings.push({
        id: "high-frequency",
        severity: "warning",
        title: `${fatigued.length} ad set${fatigued.length === 1 ? "" : "s"} showing audience saturation`,
        detail: `Frequency has passed ${FREQUENCY_CEILING.toFixed(1)} over ${days} days — the same people are seeing these ads repeatedly. ${fatigued.map((a) => `${a.name} at ${a.current.frequency.toFixed(1)}x`).join("; ")}.`,
        action:
          "Refresh the creative or widen the audience. Rising frequency reliably precedes falling CTR and rising CPA.",
        entities: fatigued.map(toEntityRef),
        impact: fatigued.reduce((s, a) => s + a.current.spend, 0),
      });
    }
  }

  // --- 5. Creative fatigue: CTR decline ----------------------------------
  const ctrDrops = campaigns.filter((c) => {
    if (!c.previous || c.current.ctr === null || c.previous.ctr === null) return false;
    if (c.current.impressions < 5000 || c.previous.impressions < 5000) return false;
    if (c.current.spend / totalSpend < MIN_SPEND_SHARE) return false;
    const change = pctChange(c.current.ctr, c.previous.ctr);
    return change !== null && change <= -0.25;
  });
  if (ctrDrops.length) {
    findings.push({
      id: "ctr-decline",
      severity: "warning",
      title: `Click-through rate falling on ${ctrDrops.length} campaign${ctrDrops.length === 1 ? "" : "s"}`,
      detail: `CTR is down 25% or more versus the comparison period. ${ctrDrops
        .map(
          (c) =>
            `${c.name}: ${(c.previous!.ctr! * 100).toFixed(2)}% to ${(c.current.ctr! * 100).toFixed(2)}%`,
        )
        .join("; ")}.`,
      action:
        "This is usually creative wear-out rather than an audience problem. Rotate in new creative before CPA follows.",
      entities: ctrDrops.map(toEntityRef),
      impact: ctrDrops.reduce((s, c) => s + c.current.spend, 0),
    });
  }

  // --- 6. Auction cost moving against you --------------------------------
  const cpmChange = pctChange(totals.cpm ?? 0, previousTotals.cpm ?? 0);
  if (cpmChange !== null && cpmChange >= 0.2 && (previousTotals.cpm ?? 0) > 0) {
    findings.push({
      id: "cpm-rising",
      severity: "info",
      title: `Cost per 1,000 impressions up ${(cpmChange * 100).toFixed(0)}%`,
      detail: `CPM moved from ${fmtMoney(previousTotals.cpm!, ctx.currency)} to ${fmtMoney(totals.cpm!, ctx.currency)}. Auction pressure is an external cost, not a signal that your ads got worse.`,
      action:
        "Judge ROAS on this basis rather than assuming the campaigns declined. If it persists, broader audiences usually cost less per impression.",
      entities: [],
      impact: 0,
    });
  }

  // --- 7. Where budget actually sits vs where returns are ----------------
  const ranked = [...campaigns]
    .filter((c) => c.current.purchases >= MIN_PURCHASES_FOR_JUDGEMENT)
    .sort((a, b) => (b.current.roas ?? 0) - (a.current.roas ?? 0));
  if (ranked.length >= 3) {
    const best = ranked[0];
    const worst = ranked[ranked.length - 1];
    if (
      best.current.roas !== null &&
      worst.current.roas !== null &&
      best.current.roas >= worst.current.roas * 2 &&
      worst.current.spend > best.current.spend
    ) {
      findings.push({
        id: "budget-misallocation",
        severity: "opportunity",
        title: "Budget is concentrated on the weaker performer",
        detail: `${worst.name} takes ${fmtMoney(worst.current.spend, ctx.currency)} at ${worst.current.roas.toFixed(2)}x, while ${best.name} takes only ${fmtMoney(best.current.spend, ctx.currency)} at ${best.current.roas.toFixed(2)}x.`,
        action: `Shifting spend toward ${best.name} is the highest-confidence move available, because both have enough conversions to trust.`,
        entities: [toEntityRef(best), toEntityRef(worst)],
        impact:
          (best.current.roas - worst.current.roas) *
          Math.min(worst.current.spend, best.current.spend),
      });
    }
  }

  // --- 8. CPA against an explicit target ---------------------------------
  if (targetCpa !== null && totals.cpa !== null && totals.cpa > targetCpa) {
    findings.push({
      id: "cpa-over-target",
      severity: "warning",
      title: `Cost per purchase is ${fmtMoney(totals.cpa, ctx.currency)}, above your ${fmtMoney(targetCpa, ctx.currency)} target`,
      detail: `Across ${totals.purchases} purchases, you are paying ${fmtMoney(totals.cpa - targetCpa, ctx.currency)} more per order than planned — ${fmtMoney((totals.cpa - targetCpa) * totals.purchases, ctx.currency)} over the period.`,
      action:
        "Either the offer needs to convert better or the traffic needs to be cheaper. Check the funnel finding below to see which.",
      entities: [],
      impact: (totals.cpa - targetCpa) * totals.purchases,
    });
  }

  // --- 9. Funnel diagnosis ------------------------------------------------
  // Separates "the ad is bad" from "the landing page is bad".
  //
  // Measured against link clicks, not total clicks. Meta's `clicks` counts
  // likes, comments, shares, profile taps and image expands alongside link
  // clicks — none of which were ever headed for the site. Dividing landing
  // page views by that number invents a drop-off that never happened, and on
  // engagement-heavy creative it can manufacture a 60% "gap" out of nothing.
  if (totals.landingPageViews > 100 && totals.linkClicks > 0) {
    const linkToLpv = totals.landingPageViews / totals.linkClicks;
    if (linkToLpv < 0.7) {
      const lost = totals.linkClicks - totals.landingPageViews;
      findings.push({
        id: "click-lpv-gap",
        severity: "warning",
        title: `${((1 - linkToLpv) * 100).toFixed(0)}% of link clicks never reach your site`,
        detail: `${totals.linkClicks.toLocaleString()} people clicked through but only ${totals.landingPageViews.toLocaleString()} landing page views were recorded — ${lost.toLocaleString()} lost. At ${fmtMoney(totals.costPerLinkClick ?? 0, ctx.currency)} per link click that is about ${fmtMoney(lost * (totals.costPerLinkClick ?? 0), ctx.currency)}. The usual causes are slow page load on mobile and a pixel that fires late or not at all.`,
        action:
          "Open the destination on a phone over mobile data and time it. Then check the pixel fires on page load using Meta's Events Manager test tool. Under 3 seconds and a firing PageView closes most of this gap.",
        entities: [],
        impact: lost * (totals.costPerLinkClick ?? 0),
      });
    }
  }

  // Engagement clicks are not a problem, but a large gap between total and
  // link clicks is worth naming so it is never mistaken for lost traffic.
  if (totals.clicks > 0 && totals.linkClicks > 0) {
    const engagementShare = 1 - totals.linkClicks / totals.clicks;
    if (engagementShare >= 0.35 && totals.clicks > 500) {
      findings.push({
        id: "engagement-clicks",
        severity: "info",
        title: `${(engagementShare * 100).toFixed(0)}% of clicks were engagement, not link clicks`,
        detail: `Of ${totals.clicks.toLocaleString()} total clicks, ${totals.linkClicks.toLocaleString()} were on the link. The rest were likes, comments, shares, profile visits and image expands — real engagement, but never headed for your site.`,
        action:
          "Judge traffic on link clicks and cost per link click, not the headline click count. Ads Manager's default CTR column mixes the two.",
        entities: [],
        impact: 0,
      });
    }
  }
  if (totals.addToCart > 20 && totals.purchases >= 0) {
    const cartToPurchase = totals.addToCart ? totals.purchases / totals.addToCart : 0;
    if (cartToPurchase < 0.15) {
      findings.push({
        id: "cart-abandonment",
        severity: "warning",
        title: `Only ${(cartToPurchase * 100).toFixed(0)}% of add-to-carts become purchases`,
        detail: `${totals.addToCart.toLocaleString()} people added to cart and ${totals.purchases.toLocaleString()} bought. The ads are doing their job — the drop-off is happening on your site.`,
        action:
          "Look at shipping cost reveal, checkout friction, and payment options before changing anything in the ad account.",
        entities: [],
        impact: 0,
      });
    }
  }

  // --- 9b. Wholesale orders leaking into the ad numbers -------------------
  // Blended ROAS is already corrected — these orders were filtered out. Meta's
  // attributed figure is not, and cannot be: the Insights API reports a single
  // revenue total with no order-level detail, so there is nothing to subtract
  // against. Saying so plainly beats letting the two numbers disagree silently.
  if (ctx.shopify && ctx.shopify.excludedRevenue > 0) {
    const s = ctx.shopify;
    const share = s.grossRevenue > 0 ? s.excludedRevenue / s.grossRevenue : 0;
    const breakdown = s.exclusionReasons
      .map((r) => `${r.reason}: ${r.orders} order${r.orders === 1 ? "" : "s"}, ${fmtMoney(r.revenue, s.currency)}`)
      .join("; ");

    // Draft orders never touch the storefront checkout, so the browser pixel
    // cannot fire on them. If Meta is still counting them, the events are
    // arriving server-side — a different setting, in a different place.
    const draftLed = s.exclusionReasons[0]?.reason.startsWith("Created from a draft");

    findings.push({
      id: "wholesale-excluded",
      severity: share >= 0.1 ? "warning" : "info",
      title: `${fmtMoney(s.excludedRevenue, s.currency)} of non-retail revenue excluded from blended ROAS`,
      detail: `${s.excludedOrders} order${s.excludedOrders === 1 ? "" : "s"} were filtered out — ${breakdown}. That is ${(share * 100).toFixed(0)}% of gross store revenue for the period, so blended ROAS would have been overstated by roughly ${(s.excludedRevenue / totalSpend).toFixed(2)}x without this.`,
      action: draftLed
        ? "Meta's attributed revenue above is not filtered, and cannot be — its API reports one total with no order detail. Draft orders never reach the storefront checkout, so the browser pixel cannot be firing on them: if Meta is still counting these, the events are being sent server-side by Shopify's Facebook & Instagram channel. Turn its data sharing down, or exclude draft orders from whatever app sends your Conversions API events."
        : "Meta's attributed revenue above is not filtered — the Insights API reports one total with no order detail, so wholesale purchases that fired the pixel are still counted in it. If these orders go through your normal checkout, stop the pixel firing on them; otherwise treat blended ROAS as the reliable figure.",
      entities: [],
      impact: s.excludedRevenue,
    });

    // If Meta claims more than even the unfiltered store total, the pixel is
    // firing on things that are not orders at all.
    if (totals.revenue > s.grossRevenue * 1.05 && s.grossRevenue > 0) {
      findings.push({
        id: "meta-exceeds-gross",
        severity: "warning",
        title: "Meta claims more revenue than your store took in, wholesale included",
        detail: `Meta attributes ${fmtMoney(totals.revenue, ctx.currency)} while your store recorded ${fmtMoney(s.grossRevenue, s.currency)} across every channel and order type. Attribution overlap alone does not explain exceeding the gross total.`,
        action:
          "Check the pixel is not double-firing on the thank-you page and that purchase events carry the correct value. A refresh-on-confirmation page is the usual cause.",
        entities: [],
        impact: totals.revenue - s.grossRevenue,
      });
    }
  }

  // --- 10. Meta's attribution vs actual store revenue --------------------
  if (ctx.shopify && totalSpend > 0) {
    const blended = ctx.shopify.totalRevenue / totalSpend;
    const attributed = totals.roas ?? 0;
    // Meta claiming more revenue than the store took in is the clearer signal
    // of the two, so it gets its own finding.
    if (totals.revenue > ctx.shopify.totalRevenue * 1.15) {
      findings.push({
        id: "over-attribution",
        severity: "info",
        title: "Meta is claiming more revenue than your store recorded",
        detail: `Meta attributes ${fmtMoney(totals.revenue, ctx.currency)} while Shopify recorded ${fmtMoney(ctx.shopify.totalRevenue, ctx.shopify.currency)} in total across all channels. View-through attribution and cross-device matching both inflate the Meta figure.`,
        action: `Plan against blended ROAS (${blended.toFixed(2)}x) rather than the ${attributed.toFixed(2)}x in Ads Manager. Use Meta's number only to compare campaigns against each other.`,
        entities: [],
        impact: 0,
      });
    } else if (blended < targetRoas && attributed >= targetRoas) {
      findings.push({
        id: "blended-below-target",
        severity: "warning",
        title: "Blended ROAS is below target even though Meta reports above",
        detail: `Total store revenue divided by total Meta spend is ${blended.toFixed(2)}x, against ${attributed.toFixed(2)}x attributed. Meta only sees the conversions it can claim.`,
        action:
          "Treat blended as the number your P&L cares about. If it is below break-even, the account is not profitable regardless of what Ads Manager shows.",
        entities: [],
        impact: 0,
      });
    }
  }

  // --- 11. Explicit note where volume is too low to judge ----------------
  const lowVolume = material.filter(
    (c) => c.current.purchases > 0 && c.current.purchases < MIN_PURCHASES_FOR_JUDGEMENT,
  );
  if (lowVolume.length) {
    findings.push({
      id: "low-volume",
      severity: "info",
      title: `${lowVolume.length} campaign${lowVolume.length === 1 ? "" : "s"} with too few conversions to judge`,
      detail: `Under ${MIN_PURCHASES_FOR_JUDGEMENT} purchases, ROAS swings hard on a single order. ${lowVolume.map((c) => `${c.name} has ${c.current.purchases}`).join("; ")}.`,
      action:
        "Leave these alone or extend the date range. Optimising on this little data is the most common way to kill a campaign that was working.",
      entities: lowVolume.map(toEntityRef),
      impact: 0,
    });
  }

  return findings.sort((a, b) => {
    const rank = { critical: 0, warning: 1, opportunity: 2, info: 3 };
    if (rank[a.severity] !== rank[b.severity]) return rank[a.severity] - rank[b.severity];
    return b.impact - a.impact;
  });
}

function toEntityRef(row: EntityRow) {
  return { id: row.id, name: row.name, level: row.level };
}

function fmtMoney(value: number, currency: string): string {
  try {
    return new Intl.NumberFormat("en-US", {
      style: "currency",
      currency,
      maximumFractionDigits: value >= 100 ? 0 : 2,
    }).format(value);
  } catch {
    return `${value.toFixed(2)} ${currency}`;
  }
}
