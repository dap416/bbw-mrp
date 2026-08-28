"use client";

import { EntityTable } from "./EntityTable";
import { ImportPanel } from "./ImportPanel";
import { StatTile } from "./StatTile";
import { count, delta, money, multiple, percent } from "@/lib/format";
import type { OverviewData, PlatformSlice } from "@/lib/types";

/**
 * A single platform's view, for the platforms whose figures are imported
 * rather than fetched — today Google and Microsoft.
 *
 * Meta keeps its own far richer page; this is deliberately the smaller thing
 * that hand-entered data can honestly support. Where a metric has no source,
 * the tile is absent rather than showing a zero: a zero add-to-cart count
 * reads as a broken funnel, when the truth is that the import has no such
 * column.
 */
export function PlatformView({
  slice,
  data,
  summary,
  onImported,
}: {
  slice: PlatformSlice;
  data: OverviewData;
  summary?: { rows: number; since: string; until: string; spend: number };
  onImported: () => void;
}) {
  const c = data.currency;
  const t = slice.totals;
  const p = slice.previousTotals;
  const hasData = t.spend > 0 || slice.campaigns.length > 0;

  const spendTrend = slice.daily.map((d) => d.metrics.spend);
  const roasTrend = slice.daily.map((d) => d.metrics.roas ?? 0);

  return (
    <>
      {slice.warnings.map((w) => (
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

      {hasData && (
        <>
          <section>
            <p
              className="muted"
              style={{ margin: "0 0 0.6rem", fontSize: "0.8125rem" }}
            >
              Imported figures, {data.range.since} to {data.range.until}. Change
              shown against the {data.compareLabel}.
            </p>

            <div
              style={{
                display: "grid",
                gap: "0.85rem",
                gridTemplateColumns:
                  "repeat(auto-fit, minmax(min(100%, 210px), 1fr))",
              }}
            >
              <StatTile
                hero
                label={`Return on ad spend · target ${data.targets.roas.toFixed(1)}x`}
                value={multiple(t.roas)}
                delta={delta(t.roas, p.roas)}
                trend={roasTrend}
              />
              <StatTile
                label="Spend"
                value={money(t.spend, c, { compact: true })}
                delta={delta(t.spend, p.spend)}
                trend={spendTrend}
              />
              <StatTile
                label="Revenue"
                value={money(t.revenue, c, { compact: true })}
                delta={delta(t.revenue, p.revenue)}
              />
              <StatTile
                label="Conversions"
                value={count(t.purchases)}
                delta={delta(t.purchases, p.purchases)}
              />
              <StatTile
                label="Cost per conversion"
                value={money(t.cpa, c)}
                delta={delta(t.cpa, p.cpa, true)}
              />
              <StatTile
                label="Clicks"
                value={count(t.clicks)}
                delta={delta(t.clicks, p.clicks)}
              />
              <StatTile
                label="CTR"
                value={percent(t.ctr)}
                delta={delta(t.ctr, p.ctr)}
              />
              <StatTile
                label="CPM"
                value={money(t.cpm, c)}
                delta={delta(t.cpm, p.cpm, true)}
              />
            </div>
          </section>

          {slice.campaigns.length > 0 && (
            <EntityTable
              rows={slice.campaigns}
              /*
                Not a Meta ad account id, so the Ads Manager deep links
                correctly resolve to nothing — a link into the wrong platform's
                console would be worse than no link.
              */
              accountId={slice.platform}
              currency={c}
              targetRoas={data.targets.roas}
              compareLabel={data.compareLabel}
              levels={[{ value: "campaign", label: "Campaigns" }]}
              level="campaign"
              onLevelChange={() => {}}
              crumbs={[{ label: "All campaigns" }]}
            />
          )}
        </>
      )}

      {data.canEdit ? (
        <ImportPanel
          platform={slice.platform as "google" | "microsoft"}
          summary={summary}
          onImported={onImported}
        />
      ) : (
        <div className="card" style={{ padding: "1.25rem" }}>
          <p className="secondary" style={{ margin: 0 }}>
            {slice.label} figures are imported from a report export. Importing
            needs Edit access to Meta Ads in the MRP.
          </p>
        </div>
      )}
    </>
  );
}
