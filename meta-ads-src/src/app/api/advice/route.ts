import Anthropic from "@anthropic-ai/sdk";
import { NextResponse } from "next/server";
import { getConfig } from "@/lib/config";
import { dayCount } from "@/lib/dates";
import { money, multiple, percent } from "@/lib/format";
import type { Advice, DashboardData, EntityRow } from "@/lib/types";

export const dynamic = "force-dynamic";
export const maxDuration = 60;

/**
 * Written analysis on top of the rule findings. The rules engine has already
 * done the arithmetic; this call's job is to weigh the findings against each
 * other, spot the ones that share a root cause, and say what to do first.
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
              "Exact campaign/ad set/ad names this applies to, copied verbatim from the data. Empty if account-wide.",
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

const SYSTEM_PROMPT = `You are analysing a Meta (Facebook/Instagram) ads account for the person who owns it and spends the money. They are the decision-maker, not a junior media buyer.

You are given metrics that have already been computed, plus deterministic findings from a rules engine. Do not recompute the arithmetic — trust the numbers as given and reason about what they mean together.

What makes your analysis useful:
- Say what is happening and what to do, in that order. Lead with the outcome.
- Cite the specific figure behind every claim. "ROAS fell to 1.4x on Campaign X" beats "performance declined".
- Connect findings that share a root cause. Rising frequency plus falling CTR plus rising CPA is one story about creative fatigue, not three separate problems.
- Rank by money at stake, not by how easy the fix is.

What to avoid:
- Do not recommend action on campaigns with fewer than 5 purchases. At that volume ROAS is noise; say so instead.
- Do not treat a rise in CPM as a performance problem — it is auction cost, which is outside their control.
- Do not suggest pausing something that may still be in learning phase (roughly the first 50 conversions after a change) without flagging that possibility.
- Do not pad. If there are only two things worth doing, give two.
- Do not restate the rules-engine findings verbatim. They are already displayed above your analysis. Add the judgement they cannot: which matters most, what is likely causing it, and what to expect after acting.

Where Meta's attributed revenue and actual store revenue are both given, the store figure is the one their P&L reflects. Meta's is useful for comparing campaigns against each other, not for judging whether the account is profitable.`;

export async function POST(request: Request) {
  const apiKey = getConfig("ANTHROPIC_API_KEY");
  if (!apiKey) {
    return NextResponse.json(
      {
        error: "No Anthropic API key has been added yet.",
        hint: "Add one on the setup page to enable the written analysis. The findings on the left work without it.",
      },
      { status: 400 },
    );
  }

  let data: DashboardData;
  try {
    data = (await request.json()) as DashboardData;
  } catch {
    return NextResponse.json({ error: "Invalid request body" }, { status: 400 });
  }
  if (!data?.account || !data?.totals) {
    return NextResponse.json(
      { error: "Request body is missing dashboard data" },
      { status: 400 },
    );
  }

  const client = new Anthropic({ apiKey });

  try {
    const response = await client.messages.create({
      model: "claude-opus-5",
      max_tokens: 4000,
      system: SYSTEM_PROMPT,
      // The rules engine has already done the objective work, so this is a
      // bounded judgement task rather than open-ended analysis. Medium effort
      // keeps a dashboard refresh cheap without costing answer quality.
      output_config: {
        effort: "medium",
        format: { type: "json_schema", schema: ADVICE_SCHEMA },
      },
      messages: [{ role: "user", content: buildBrief(data) }],
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
function buildBrief(data: DashboardData): string {
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
