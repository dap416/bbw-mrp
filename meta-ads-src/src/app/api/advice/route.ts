import Anthropic from "@anthropic-ai/sdk";
import { NextResponse } from "next/server";
import { getConfig } from "@/lib/config";
import { dayCount } from "@/lib/dates";
import { money, multiple, percent } from "@/lib/format";
import { isPlatform, type Platform } from "@/lib/platforms";
import type {
  Advice,
  DashboardData,
  EntityRow,
  OverviewData,
  PlatformSlice,
} from "@/lib/types";

export const dynamic = "force-dynamic";
export const maxDuration = 60;

/**
 * Written analysis on top of the rule findings. The rules engine has already
 * done the arithmetic; this call's job is to weigh the findings against each
 * other, spot the ones that share a root cause, and say what to do first.
 *
 * Three scopes share this endpoint, because they differ only in what is put in
 * front of the model:
 *   - "meta"     one API-fed account, with funnel and creative detail
 *   - "platform" one imported platform (Google, Microsoft), far thinner data
 *   - "all"      the roll-up, where the question is where the budget should sit
 *
 * All three return the same schema, so one panel renders them all.
 */

const ADVICE_SCHEMA = {
  type: "object",
  properties: {
    summary: {
      type: "string",
      description:
        "2-4 sentences. What is actually happening in this account and whether it is working. No preamble.",
    },
    actions: {
      type: "array",
      description:
        "Ranked recommendations, most important first. Between 1 and 5. Omit rather than pad.",
      items: {
        type: "object",
        properties: {
          priority: {
            type: "string",
            enum: ["now", "this_week", "monitor"],
            description:
              "'now' only for things costing money today. 'monitor' for things to watch without acting yet.",
          },
          title: {
            type: "string",
            description: "The action itself, as an imperative. Under 80 characters.",
          },
          reasoning: {
            type: "string",
            description:
              "1-3 sentences citing the specific numbers that justify this. Say what you expect to happen.",
          },
          targets: {
            type: "array",
            description:
              "Exact campaign/ad set/ad/platform names this applies to, copied verbatim from the data. Empty if account-wide.",
            items: { type: "string" },
          },
        },
        required: ["priority", "title", "reasoning", "targets"],
        additionalProperties: false,
      },
    },
    caveats: {
      type: "array",
      description:
        "Things that look alarming in the data but have a benign explanation, or where the data is too thin to act on. Empty array if none.",
      items: { type: "string" },
    },
  },
  required: ["summary", "actions", "caveats"],
  additionalProperties: false,
} as const;

/** Rules that hold whatever is being analysed. */
const SHARED_RULES = `What makes your analysis useful:
- Say what is happening and what to do, in that order. Lead with the outcome.
- Cite the specific figure behind every claim. "ROAS fell to 1.4x on Campaign X" beats "performance declined".
- Connect findings that share a root cause rather than listing them separately.
- Rank by money at stake, not by how easy the fix is.

What to avoid:
- Do not recommend action on an entity with fewer than 5 conversions. At that volume ROAS is noise; say so instead.
- Do not treat a rise in CPM as a performance problem — it is auction cost, which is outside their control.
- Do not pad. If there are only two things worth doing, give two.
- Do not restate findings that are already displayed to the user. Add the judgement they cannot: which matters most, what is likely causing it, and what to expect after acting.`;

const META_PROMPT = `You are analysing a Meta (Facebook/Instagram) ads account for the person who owns it and spends the money. They are the decision-maker, not a junior media buyer.

You are given metrics that have already been computed, plus deterministic findings from a rules engine. Do not recompute the arithmetic — trust the numbers as given and reason about what they mean together.

${SHARED_RULES}
- Do not suggest pausing something that may still be in learning phase (roughly the first 50 conversions after a change) without flagging that possibility.

Rising frequency plus falling CTR plus rising CPA is one story about creative fatigue, not three separate problems.

Where Meta's attributed revenue and actual store revenue are both given, the store figure is the one their P&L reflects. Meta's is useful for comparing campaigns against each other, not for judging whether the account is profitable.`;

const PLATFORM_PROMPT = `You are analysing a single ad platform's performance for the person who owns the account and spends the money. They are the decision-maker, not a junior media buyer.

${SHARED_RULES}

This platform's figures are IMPORTED from a scheduled report export, not read from a live API. That constrains what you may conclude, and you must respect it:
- The import carries spend, impressions, clicks, conversions and conversion value. It does NOT carry funnel steps, reach, frequency, audience or creative-level data. Never infer creative fatigue, audience saturation or landing-page problems here — the columns that would evidence them do not exist. If a question needs that data, say which report they would have to pull instead.
- Conversions are as the platform attributed them. A platform reporting zero conversions on a few dozen clicks is ordinary low volume, not proof that tracking is broken. Raise tracking as a possibility only when clicks are substantial and conversions are still exactly zero, and label it a possibility rather than a finding.
- A gap between the last imported date and the end of the period means missing data, not a collapse in spend. Say so plainly rather than reading it as a performance drop.

Judge this platform on its own terms. Cross-platform budget decisions belong to the combined view, so do not tell them to move money to another platform from here.`;

const OVERVIEW_PROMPT = `You are analysing an advertiser's whole paid-media mix across Meta, Google and Microsoft, for the person who owns the budget. The question here is not "how is one campaign doing" but "is the money in the right places".

${SHARED_RULES}

Rules specific to the roll-up:
- Attributed revenue summed across platforms DOUBLE-COUNTS. Each platform claims credit for orders the others may also claim. Where a blended figure (actual shop revenue over total ad spend) is given, that is the only trustworthy measure of whether the whole mix pays for itself. Use per-platform attributed ROAS to rank platforms against each other, never to state absolute profitability.
- Platforms sit at very different spend levels and maturities. A platform with a few dollars of spend and no conversions has not underperformed — it has not been tested yet. Recommend either funding it to a level that could produce a readable result, or stopping it; do not judge its ROAS.
- Meta's figures come from its API; Google and Microsoft are imported from report exports, so their data can be staler and thinner. Weigh conclusions accordingly and say when a comparison is not like-for-like.
- Budget-shift recommendations must name the source platform, the destination platform, and an approximate amount or share. "Shift spend toward the better performer" is useless; "move roughly $200/week from Microsoft to Meta" is actionable.`;

type AdviceRequest =
  | { scope: "meta"; data: DashboardData }
  | { scope: "platform"; platform: Platform; data: OverviewData }
  | { scope: "all"; data: OverviewData };

/**
 * Accepts the tagged shape the panel now sends, and still accepts a bare
 * DashboardData body — that is what the Meta tab posted before the other two
 * scopes existed, so a browser holding a stale bundle keeps working.
 */
function parseRequest(body: unknown): AdviceRequest | { error: string } {
  if (!body || typeof body !== "object") {
    return { error: "Invalid request body" };
  }
  const b = body as Record<string, unknown>;

  if (!("scope" in b)) {
    if (b.account && b.totals) return { scope: "meta", data: body as DashboardData };
    return { error: "Request body is missing dashboard data" };
  }

  if (!b.data || typeof b.data !== "object") {
    return { error: "Request body is missing dashboard data" };
  }

  if (b.scope === "meta") {
    const d = b.data as DashboardData;
    if (!d.account || !d.totals) {
      return { error: "Request body is missing dashboard data" };
    }
    return { scope: "meta", data: d };
  }

  if (b.scope === "all" || b.scope === "platform") {
    const d = b.data as OverviewData;
    if (!d.platforms || !d.totals) {
      return { error: "Request body is missing overview data" };
    }
    if (b.scope === "all") return { scope: "all", data: d };
    if (!isPlatform(b.platform)) return { error: "Unknown platform" };
    return { scope: "platform", platform: b.platform, data: d };
  }

  return { error: "Unknown analysis scope" };
}

export async function POST(request: Request) {
  const apiKey = getConfig("ANTHROPIC_API_KEY");
  if (!apiKey) {
    return NextResponse.json(
      {
        error: "No Anthropic API key has been added yet.",
        hint: "Add one on the setup page to enable the written analysis. The figures on this page work without it.",
      },
      { status: 400 },
    );
  }

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return NextResponse.json({ error: "Invalid request body" }, { status: 400 });
  }

  const parsed = parseRequest(body);
  if ("error" in parsed) {
    return NextResponse.json({ error: parsed.error }, { status: 400 });
  }

  let system: string;
  let brief: string;

  if (parsed.scope === "meta") {
    system = META_PROMPT;
    brief = buildMetaBrief(parsed.data);
  } else if (parsed.scope === "all") {
    system = OVERVIEW_PROMPT;
    brief = buildOverviewBrief(parsed.data);
  } else {
    const slice = parsed.data.platforms.find((s) => s.platform === parsed.platform);
    if (!slice) {
      return NextResponse.json(
        { error: "That platform has no data in this period." },
        { status: 400 },
      );
    }
    system = PLATFORM_PROMPT;
    brief = buildPlatformBrief(slice, parsed.data);
  }

  const client = new Anthropic({ apiKey });

  try {
    const response = await client.messages.create({
      model: "claude-opus-5",
      max_tokens: 4000,
      system,
      // The rules engine has already done the objective work, so this is a
      // bounded judgement task rather than open-ended analysis. Medium effort
      // keeps a dashboard refresh cheap without costing answer quality.
      output_config: {
        effort: "medium",
        format: { type: "json_schema", schema: ADVICE_SCHEMA },
      },
      messages: [{ role: "user", content: brief }],
    });

    if (response.stop_reason === "refusal") {
      return NextResponse.json(
        { error: "The model declined to analyse this data." },
        { status: 502 },
      );
    }

    const text = response.content.find((b) => b.type === "text");
    if (!text || text.type !== "text") {
      return NextResponse.json(
        { error: "No analysis returned." },
        { status: 502 },
      );
    }

    const advice = JSON.parse(text.text) as Advice;
    return NextResponse.json({
      advice,
      usage: {
        inputTokens: response.usage.input_tokens,
        outputTokens: response.usage.output_tokens,
      },
    });
  } catch (err) {
    if (err instanceof Anthropic.RateLimitError) {
      return NextResponse.json(
        { error: "Rate limited by the Claude API. Try again shortly." },
        { status: 429 },
      );
    }
    if (err instanceof Anthropic.AuthenticationError) {
      return NextResponse.json(
        { error: "ANTHROPIC_API_KEY is invalid." },
        { status: 401 },
      );
    }
    if (err instanceof Anthropic.APIError) {
      return NextResponse.json(
        { error: `Claude API error: ${err.message}` },
        { status: 502 },
      );
    }
    return NextResponse.json(
      { error: err instanceof Error ? err.message : "Unexpected error" },
      { status: 500 },
    );
  }
}

/**
 * Renders the dashboard state as text. Tables rather than raw JSON, because
 * the model reads a labelled table more reliably than nested objects, and it
 * keeps the token count down on accounts with hundreds of ads.
 */
function buildMetaBrief(data: DashboardData): string {
  const c = data.account.currency;
  const days = dayCount(data.range);
  const { totals: t, previousTotals: p } = data;

  const lines: string[] = [];

  lines.push(`# Account: ${data.account.name} (${c})`);
  lines.push(
    `Period: ${data.range.since} to ${data.range.until} (${days} day${days === 1 ? "" : "s"})`,
  );
  lines.push(`Compared against: ${data.compareLabel} (${data.compareRange.since} to ${data.compareRange.until})`);
  lines.push(
    `Their target ROAS: ${data.targets.roas.toFixed(2)}x${
      data.targets.cpa ? `, target cost per purchase: ${money(data.targets.cpa, c)}` : ""
    }`,
  );
  lines.push("");

  lines.push("## Account totals (current vs comparison)");
  lines.push("| Metric | Current | Previous |");
  lines.push("|---|---|---|");
  lines.push(`| Spend | ${money(t.spend, c)} | ${money(p.spend, c)} |`);
  lines.push(`| Attributed revenue | ${money(t.revenue, c)} | ${money(p.revenue, c)} |`);
  lines.push(`| ROAS | ${multiple(t.roas)} | ${multiple(p.roas)} |`);
  lines.push(`| Purchases | ${t.purchases} | ${p.purchases} |`);
  lines.push(`| Cost per purchase | ${money(t.cpa, c)} | ${money(p.cpa, c)} |`);
  lines.push(`| Average order value | ${money(t.aov, c)} | ${money(p.aov, c)} |`);
  lines.push(`| Link CTR | ${percent(t.linkCtr)} | ${percent(p.linkCtr)} |`);
  lines.push(`| Cost per link click | ${money(t.costPerLinkClick, c)} | ${money(p.costPerLinkClick, c)} |`);
  lines.push(`| CPM | ${money(t.cpm, c)} | ${money(p.cpm, c)} |`);
  lines.push(`| Frequency | ${t.frequency.toFixed(2)} | ${p.frequency.toFixed(2)} |`);
  lines.push(`| Reach | ${t.reach.toLocaleString()} | ${p.reach.toLocaleString()} |`);
  lines.push("");

  lines.push("## Funnel (current period)");
  lines.push(
    `Link clicks ${t.linkClicks.toLocaleString()} → landing page views ${t.landingPageViews.toLocaleString()} → add to cart ${t.addToCart.toLocaleString()} → checkout started ${t.initiateCheckout.toLocaleString()} → purchases ${t.purchases.toLocaleString()}`,
  );
  lines.push(
    `Total clicks were ${t.clicks.toLocaleString()}, but only link clicks head to the site — the difference is likes, comments, shares, profile visits and image expands. Judge the funnel from link clicks; comparing landing page views to total clicks invents a drop-off that did not happen.`,
  );
  lines.push("");

  if (data.shopify) {
    lines.push("## Actual store revenue (Shopify, all channels)");
    lines.push(
      `${money(data.shopify.totalRevenue, data.shopify.currency)} across ${data.shopify.orderCount} orders.`,
    );
    lines.push(
      `Blended ROAS (all store revenue / all Meta spend): ${multiple(data.blendedRoas)}. Meta's attributed ROAS: ${multiple(t.roas)}.`,
    );
    lines.push("");
  }

  lines.push(entityTable("Campaigns", data.campaigns, c, 15));
  lines.push(entityTable("Ad sets", data.adsets, c, 12));
  lines.push(entityTable("Ads", data.ads, c, 12));

  if (data.findings.length) {
    lines.push("## Findings already computed and shown to the user");
    for (const f of data.findings) {
      lines.push(`- [${f.severity}] ${f.title} — ${f.detail}`);
    }
    lines.push("");
  }

  lines.push(
    "Write the analysis. Use the exact entity names above when referring to campaigns, ad sets, or ads.",
  );

  return lines.join("\n");
}

/**
 * One imported platform. Deliberately narrower than the Meta brief: only the
 * metrics this data can actually fill are mentioned, so the model is never
 * invited to reason about funnel steps that were never imported.
 */
function buildPlatformBrief(slice: PlatformSlice, data: OverviewData): string {
  const c = data.currency;
  const days = dayCount(data.range);
  const t = slice.totals;
  const p = slice.previousTotals;

  const lines: string[] = [];

  lines.push(`# ${slice.label}${slice.account ? ` — ${slice.account.name}` : ""} (${c})`);
  lines.push(
    `Period: ${data.range.since} to ${data.range.until} (${days} day${days === 1 ? "" : "s"})`,
  );
  lines.push(
    `Compared against: ${data.compareLabel} (${data.compareRange.since} to ${data.compareRange.until})`,
  );
  lines.push(
    `Their target ROAS: ${data.targets.roas.toFixed(2)}x${
      data.targets.cpa ? `, target cost per conversion: ${money(data.targets.cpa, c)}` : ""
    }`,
  );
  lines.push(
    `Data source: ${slice.source === "api" ? "live API" : "imported from a scheduled report export"}.`,
  );
  if (data.partial) {
    lines.push(
      "This range runs to today, so the figures are still moving and conversions keep being attributed for days after the click.",
    );
  }
  lines.push("");

  lines.push("## Platform totals (current vs comparison)");
  lines.push("| Metric | Current | Previous |");
  lines.push("|---|---|---|");
  lines.push(`| Spend | ${money(t.spend, c)} | ${money(p.spend, c)} |`);
  lines.push(`| Attributed revenue | ${money(t.revenue, c)} | ${money(p.revenue, c)} |`);
  lines.push(`| ROAS | ${multiple(t.roas)} | ${multiple(p.roas)} |`);
  lines.push(`| Conversions | ${t.purchases} | ${p.purchases} |`);
  lines.push(`| Cost per conversion | ${money(t.cpa, c)} | ${money(p.cpa, c)} |`);
  lines.push(`| Clicks | ${t.clicks.toLocaleString()} | ${p.clicks.toLocaleString()} |`);
  lines.push(
    `| Impressions | ${t.impressions.toLocaleString()} | ${p.impressions.toLocaleString()} |`,
  );
  lines.push(`| CTR | ${percent(t.ctr)} | ${percent(p.ctr)} |`);
  lines.push(`| CPC | ${money(t.cpc, c)} | ${money(p.cpc, c)} |`);
  lines.push(`| CPM | ${money(t.cpm, c)} | ${money(p.cpm, c)} |`);
  lines.push("");

  if (slice.daily.length) {
    const withSpend = slice.daily.filter((d) => d.metrics.spend > 0);
    const last = slice.daily[slice.daily.length - 1];
    lines.push("## Import coverage");
    lines.push(
      `${withSpend.length} of ${slice.daily.length} days in this period have imported rows carrying spend. Last day present in the import: ${last.date}.`,
    );
    lines.push("");
  }

  lines.push(entityTable("Campaigns", slice.campaigns, c, 20));

  lines.push("## Where this platform sits in the wider mix (context only)");
  lines.push("| Platform | Spend | Revenue | ROAS | Conversions |");
  lines.push("|---|---|---|---|---|");
  for (const s of data.platforms) {
    lines.push(
      `| ${s.label}${s.platform === slice.platform ? " (this one)" : ""} | ${money(s.totals.spend, c)} | ${money(s.totals.revenue, c)} | ${multiple(s.totals.roas)} | ${s.totals.purchases} |`,
    );
  }
  lines.push(
    "That table is background, so you know this platform's scale relative to the others. Do not make budget-shift recommendations from it — that is the combined view's job.",
  );
  lines.push("");

  if (slice.warnings.length) {
    lines.push("## Data warnings already shown to the user");
    for (const w of slice.warnings) lines.push(`- ${w}`);
    lines.push("");
  }

  lines.push(
    `Write the analysis of ${slice.label} only. Use the exact campaign names above when referring to campaigns.`,
  );

  return lines.join("\n");
}

/** The roll-up. The question is allocation, so the platform table leads. */
function buildOverviewBrief(data: OverviewData): string {
  const c = data.currency;
  const days = dayCount(data.range);
  const { totals: t, previousTotals: p } = data;

  const lines: string[] = [];

  lines.push(`# Combined paid media across all platforms (${c})`);
  lines.push(
    `Period: ${data.range.since} to ${data.range.until} (${days} day${days === 1 ? "" : "s"})`,
  );
  lines.push(
    `Compared against: ${data.compareLabel} (${data.compareRange.since} to ${data.compareRange.until})`,
  );
  lines.push(
    `Their target ROAS: ${data.targets.roas.toFixed(2)}x${
      data.targets.cpa ? `, target cost per conversion: ${money(data.targets.cpa, c)}` : ""
    }`,
  );
  if (data.partial) {
    lines.push("This range runs to today, so every figure is still moving.");
  }
  lines.push("");

  lines.push("## Combined totals (current vs comparison)");
  lines.push("| Metric | Current | Previous |");
  lines.push("|---|---|---|");
  lines.push(`| Total ad spend | ${money(t.spend, c)} | ${money(p.spend, c)} |`);
  lines.push(
    `| Attributed revenue (summed, may double-count) | ${money(t.revenue, c)} | ${money(p.revenue, c)} |`,
  );
  lines.push(`| Attributed ROAS | ${multiple(t.roas)} | ${multiple(p.roas)} |`);
  lines.push(`| Attributed conversions | ${t.purchases} | ${p.purchases} |`);
  lines.push(`| Blended cost per conversion | ${money(t.cpa, c)} | ${money(p.cpa, c)} |`);
  lines.push("");

  if (data.shopify) {
    lines.push("## Actual store revenue (Shopify, all channels)");
    lines.push(
      `${money(data.shopify.totalRevenue, data.shopify.currency)} across ${data.shopify.orderCount} orders, net of exclusions.`,
    );
    lines.push(
      `Blended ROAS (shop revenue / total ad spend across every platform): ${multiple(data.blendedRoas)}. This is the trustworthy profitability figure; the summed attributed ROAS above is not.`,
    );
    if (data.shopify.activeRules.length) {
      lines.push(`Revenue exclusion rules in force: ${data.shopify.activeRules.join("; ")}.`);
    }
    lines.push("");
  } else {
    lines.push(
      "Shopify is not connected, so there is no blended figure and no independent check on the attributed revenue below. Say so where it limits a conclusion.",
    );
    lines.push("");
  }

  lines.push("## Per-platform this period");
  lines.push(
    "| Platform | Source | Spend | Share of spend | Revenue | ROAS | Conversions | CPA | Spend change | ROAS change |",
  );
  lines.push("|---|---|---|---|---|---|---|---|---|---|");
  for (const row of data.comparison) {
    const slice = data.platforms.find((s) => s.platform === row.platform);
    lines.push(
      `| ${row.label} | ${slice?.source === "api" ? "API" : "imported"} | ${money(row.spend, c)} | ${percent(row.spendShare)} | ${money(row.revenue, c)} | ${multiple(row.roas)} | ${row.purchases} | ${money(row.cpa, c)} | ${percent(row.spendDelta)} | ${percent(row.roasDelta)} |`,
    );
  }
  lines.push("");

  const suggested = data.comparison.filter((r) => r.suggestedShift !== null);
  if (suggested.length) {
    lines.push("## Reallocation the rules engine already computed");
    for (const r of suggested) {
      const amount = r.suggestedShift as number;
      lines.push(
        `- ${r.label}: ${amount >= 0 ? "add" : "remove"} ${money(Math.abs(amount), c)} to even out return across the mix.`,
      );
    }
    lines.push(
      "Treat those as arithmetic, not as a decision. Say whether each is worth acting on given how thin the underlying conversion counts are.",
    );
    lines.push("");
  }

  const unconfigured = data.platforms.filter((s) => !s.configured);
  if (unconfigured.length) {
    lines.push(
      `Not connected at all this period: ${unconfigured.map((s) => s.label).join(", ")}. That is an absence of data, not zero performance.`,
    );
    lines.push("");
  }

  for (const slice of data.platforms) {
    if (!slice.campaigns.length) continue;
    lines.push(entityTable(`${slice.label} campaigns`, slice.campaigns, c, 8));
  }

  if (data.findings.length) {
    lines.push("## Findings already computed and shown to the user");
    for (const f of data.findings) {
      lines.push(`- [${f.severity}] ${f.title} — ${f.detail}`);
    }
    lines.push("");
  }

  if (data.warnings.length) {
    lines.push("## Data warnings already shown to the user");
    for (const w of data.warnings) lines.push(`- ${w}`);
    lines.push("");
  }

  lines.push(
    "Write the analysis. What they are asking is where the budget should sit across these platforms, and whether the mix as a whole is paying for itself. Name platforms and campaigns exactly as written above.",
  );

  return lines.join("\n");
}

function entityTable(
  heading: string,
  rows: EntityRow[],
  currency: string,
  limit: number,
): string {
  if (!rows.length) return "";

  const shown = rows.slice(0, limit);
  const out: string[] = [
    `## ${heading} (top ${shown.length} by spend${rows.length > limit ? ` of ${rows.length}` : ""})`,
    "| Name | Status | Spend | Revenue | ROAS | Purchases | CPA | CTR | Freq | ROAS prev |",
    "|---|---|---|---|---|---|---|---|---|---|",
  ];

  for (const r of shown) {
    out.push(
      `| ${r.name} | ${r.status ?? "—"} | ${money(r.current.spend, currency)} | ${money(r.current.revenue, currency)} | ${multiple(r.current.roas)} | ${r.current.purchases} | ${money(r.current.cpa, currency)} | ${percent(r.current.ctr)} | ${r.current.frequency.toFixed(2)} | ${multiple(r.previous?.roas ?? null)} |`,
    );
  }
  out.push("");
  return out.join("\n");
}
