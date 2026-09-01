"use client";

import { AdvicePanel } from "./AdvicePanel";
import { FindingsPanel } from "./FindingsPanel";
import { PlatformComparison } from "./PlatformComparison";
import { SpendMixChart } from "./SpendMixChart";
import { StatTile } from "./StatTile";
import { count, delta, money, multiple } from "@/lib/format";
import type { OverviewData } from "@/lib/types";

/**
 * The combined view across all three platforms.
 *
 * Its job is narrower than the Meta tab's: not "how is this account doing" but
 * "is the budget in the right places". So there is no funnel, no ad-level
 * table and no hourly breakdown here — those questions are answered inside a
 * platform, and putting them on the roll-up would only invite comparing
 * numbers that are not comparable.
 */
export function OverviewView({ data }: { data: OverviewData }) {
  const c = data.currency;
  const { totals: t, previousTotals: p } = data;

  const spendTrend = data.daily.map((d) => d.metrics.spend);
  const roasTrend = data.daily.map((d) => d.metrics.roas ?? 0);

  const live = data.platforms.filter((s) => s.totals.spend > 0);

  return (
    <>
      {data.warnings.map((w) => (
        <div
          key={w}
          className="card"
          style={{
            padding: "0.75rem 1rem",
            borderLeft: "3px solid var(--status-warning)",
            fontSize: "0.875rem",
          }}
        >
          <span className="secondary">{w}</span>
        </div>
      ))}

      {data.partial && (
        <div
          className="card"
          style={{
            padding: "0.75rem 1rem",
            borderLeft: "3px solid var(--status-warning)",
            fontSize: "0.875rem",
          }}
        >
          <span className="secondary">
            This range runs to today, so every figure is still moving —
            attributed conversions in particular keep climbing for days after
            the click.
          </span>
        </div>
      )}

      <section>
        <p className="muted" style={{ margin: "0 0 0.6rem", fontSize: "0.8125rem" }}>
          Across {live.length || "no"} platform{live.length === 1 ? "" : "s"} with
          spend this period. Change shown against the {data.compareLabel}.
        </p>

        <div
          style={{
            display: "grid",
            gap: "0.85rem",
            gridTemplateColumns: "repeat(auto-fit, minmax(min(100%, 210px), 1fr))",
          }}
        >
          {/*
            Blended ROAS is the hero when Shopify is connected, because it is
            the only return figure here that cannot be double-counted: three
            platforms each claiming the same order inflate attributed revenue,
            but the shop's own takings are the shop's own takings.
          */}
          {data.blendedRoas !== null ? (
            <StatTile
              hero
              label={`Blended ROAS · Shopify revenue ÷ all ad spend · target ${data.targets.roas.toFixed(1)}x`}
              value={multiple(data.blendedRoas)}
              trend={roasTrend}
              note="Shop revenue over total spend"
            />
          ) : (
            <StatTile
              hero
              label={`Attributed ROAS · target ${data.targets.roas.toFixed(1)}x`}
              value={multiple(t.roas)}
              delta={delta(t.roas, p.roas)}
              trend={roasTrend}
              note="Connect Shopify for a blended figure"
            />
          )}

          <StatTile
            label="Total ad spend"
            value={money(t.spend, c, { compact: true })}
            delta={delta(t.spend, p.spend)}
            trend={spendTrend}
          />
          <StatTile
            label="Attributed revenue"
            value={money(t.revenue, c, { compact: true })}
            delta={delta(t.revenue, p.revenue)}
            note="Summed across platforms — may double-count"
          />
          <StatTile
            label="Attributed conversions"
            value={count(t.purchases)}
            delta={delta(t.purchases, p.purchases)}
          />
          <StatTile
            label="Blended cost per conversion"
            value={money(t.cpa, c)}
            delta={delta(t.cpa, p.cpa, true)}
          />
          {data.shopify && (
            <StatTile
              label="Shopify revenue"
              value={money(data.shopify.totalRevenue, c, { compact: true })}
              note={`${count(data.shopify.orderCount)} orders, net of exclusions`}
            />
          )}
        </div>
      </section>

      <SpendMixChart data={data} currency={c} />

      <PlatformComparison
        rows={data.comparison}
        currency={c}
        targetRoas={data.targets.roas}
        compareLabel={data.compareLabel}
      />

      {/*
        alignItems: start so the analysis card keeps its natural height —
        stretching it to match the findings list leaves a tall empty box
        before anything has been generated.
      */}
      <div
        style={{
          display: "grid",
          gap: "1.25rem",
          alignItems: "start",
          gridTemplateColumns: "repeat(auto-fit, minmax(min(100%, 420px), 1fr))",
        }}
      >
        <FindingsPanel findings={data.findings} />
        <AdvicePanel
          payload={{ scope: "all", data }}
          blurb="Claude weighs all three platforms against each other and says where the budget should sit."
          buttonLabel="Analyse all platforms"
        />
      </div>
    </>
  );
}
