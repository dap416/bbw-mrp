"use client";

import {
  CartesianGrid,
  ComposedChart,
  Legend,
  Line,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { longDate, money, multiple, shortDate } from "@/lib/format";
import type { DailyPoint } from "@/lib/types";

/**
 * Two charts rather than one dual-axis chart. Spend and ROAS have unrelated
 * scales, and putting them on two y-axes lets the crossing point imply a
 * relationship that the data does not contain.
 */

interface SpendRow {
  date: string;
  spend: number;
  revenue: number;
}

interface RoasRow {
  date: string;
  label: string;
  current: number | null;
  previous: number | null;
  previousDate: string | null;
}

export function TrendCharts({
  daily,
  previousDaily,
  currency,
  targetRoas,
  compareLabel,
}: {
  daily: DailyPoint[];
  previousDaily: DailyPoint[];
  currency: string;
  targetRoas: number;
  compareLabel: string;
}) {
  if (daily.length < 2) {
    return (
      <div className="card" style={{ padding: "2rem", textAlign: "center" }}>
        <p className="secondary" style={{ margin: 0 }}>
          A single day of data has no trend to plot. Pick a wider range to see
          the charts.
        </p>
      </div>
    );
  }

  const spendData: SpendRow[] = daily.map((d) => ({
    date: d.date,
    spend: d.metrics.spend,
    revenue: d.metrics.revenue,
  }));

  // The comparison period has different calendar dates, so it is aligned by
  // position in the window — day 1 against day 1 — and the tooltip names both
  // dates so the alignment is never guessed at.
  const roasData: RoasRow[] = daily.map((d, i) => {
    const prev = previousDaily[i];
    return {
      date: d.date,
      label: shortDate(d.date),
      current: d.metrics.roas,
      previous: prev?.metrics.roas ?? null,
      previousDate: prev?.date ?? null,
    };
  });

  const hasComparison = roasData.some((r) => r.previous !== null);

  /*
   * ROAS sits in a narrow band well above zero, so a zero-anchored axis
   * squeezes every movement into the top of the plot. Position encodes value
   * on a line chart (unlike bar length), so a fitted domain is honest here —
   * and the target line stays inside it as a fixed anchor for scale.
   */
  const roasDomain = ((): [number, number] => {
    const values = roasData
      .flatMap((r) => [r.current, r.previous])
      .filter((v): v is number => v !== null && Number.isFinite(v));
    if (!values.length) return [0, Math.max(targetRoas * 1.5, 1)];

    const lo = Math.min(...values, targetRoas);
    const hi = Math.max(...values, targetRoas);
    const pad = Math.max((hi - lo) * 0.18, 0.15);
    return [Math.max(0, lo - pad), hi + pad];
  })();

  return (
    <div
      style={{
        display: "grid",
        gap: "1rem",
        gridTemplateColumns: "repeat(auto-fit, minmax(min(100%, 380px), 1fr))",
      }}
    >
      <ChartCard
        title="Spend and attributed revenue"
        subtitle="Daily, in account currency"
      >
        <ResponsiveContainer width="100%" height={260}>
          <ComposedChart data={spendData} margin={{ top: 8, right: 12, bottom: 4, left: 4 }}>
            <CartesianGrid vertical={false} />
            <XAxis
              dataKey="date"
              tickFormatter={shortDate}
              tickLine={false}
              minTickGap={24}
            />
            <YAxis
              tickFormatter={(v: number) => compactMoney(v, currency)}
              tickLine={false}
              axisLine={false}
              width={64}
            />
            <Tooltip
              content={
                <MoneyTooltip
                  currency={currency}
                  names={{ spend: "Spend", revenue: "Attributed revenue" }}
                />
              }
            />
            <Legend content={<FlatLegend first="Spend" />} />
            {/*
              Lines, not areas. Two translucent area fills stack in the region
              where they overlap, and the compound colour reads as a third
              series that isn't in the data.
            */}
            <Line
              type="monotone"
              dataKey="revenue"
              name="Attributed revenue"
              stroke="var(--series-2)"
              strokeWidth={2}
              dot={false}
              activeDot={{ r: 4, strokeWidth: 2, stroke: "var(--surface)" }}
            />
            <Line
              type="monotone"
              dataKey="spend"
              name="Spend"
              stroke="var(--series-1)"
              strokeWidth={2}
              dot={false}
              activeDot={{ r: 4, strokeWidth: 2, stroke: "var(--surface)" }}
            />
          </ComposedChart>
        </ResponsiveContainer>
      </ChartCard>

      <ChartCard
        title="Return on ad spend"
        subtitle={
          hasComparison
            ? `Daily, current period against the ${compareLabel}`
            : "Daily"
        }
      >
        <ResponsiveContainer width="100%" height={260}>
          {/* Right margin holds the target label clear of the plot. */}
          <ComposedChart data={roasData} margin={{ top: 8, right: 56, bottom: 4, left: 4 }}>
            <CartesianGrid vertical={false} />
            <XAxis dataKey="label" tickLine={false} minTickGap={24} />
            <YAxis
              domain={roasDomain}
              tickFormatter={(v: number) => `${v.toFixed(1)}x`}
              tickLine={false}
              axisLine={false}
              width={48}
            />
            <Tooltip content={<RoasTooltip compareLabel={compareLabel} />} />
            {/*
              The target is a reference, not a series — labelled in the margin
              so it needs no legend entry and never sits on top of the data.
            */}
            <ReferenceLine
              y={targetRoas}
              stroke="var(--text-muted)"
              strokeWidth={1}
              label={{
                value: `target ${targetRoas.toFixed(1)}x`,
                position: "right",
                fill: "var(--text-muted)",
                fontSize: 11,
              }}
            />
            {hasComparison && <Legend content={<FlatLegend first="Current period" />} />}
            {hasComparison && (
              <Line
                type="monotone"
                dataKey="previous"
                name={capitalise(compareLabel)}
                stroke="var(--series-2)"
                strokeWidth={2}
                dot={false}
                connectNulls
                activeDot={{ r: 4, strokeWidth: 2, stroke: "var(--surface)" }}
              />
            )}
            <Line
              type="monotone"
              dataKey="current"
              name="Current period"
              stroke="var(--series-1)"
              strokeWidth={2}
              dot={false}
              connectNulls
              activeDot={{ r: 4, strokeWidth: 2, stroke: "var(--surface)" }}
            />
          </ComposedChart>
        </ResponsiveContainer>
      </ChartCard>
    </div>
  );
}

function ChartCard({
  title,
  subtitle,
  children,
}: {
  title: string;
  subtitle: string;
  children: React.ReactNode;
}) {
  return (
    <div className="card" style={{ padding: "1.1rem 1rem 0.75rem" }}>
      <h3
        style={{
          margin: "0 0 0.15rem",
          fontSize: "0.9375rem",
          fontWeight: 600,
          color: "var(--text-primary)",
        }}
      >
        {title}
      </h3>
      <p
        className="muted"
        style={{ margin: "0 0 0.75rem", fontSize: "0.8125rem" }}
      >
        {subtitle}
      </p>
      {children}
    </div>
  );
}

/* --- Tooltips ------------------------------------------------------------ */

interface TooltipProps {
  active?: boolean;
  label?: string | number;
  payload?: { dataKey?: string | number; value?: number; payload?: RoasRow }[];
}

function TooltipShell({ children }: { children: React.ReactNode }) {
  return (
    <div
      className="card"
      style={{
        padding: "0.6rem 0.75rem",
        fontSize: "0.8125rem",
        boxShadow: "0 4px 16px rgba(0,0,0,0.12)",
      }}
    >
      {children}
    </div>
  );
}

function TooltipRow({
  color,
  name,
  value,
}: {
  color: string;
  name: string;
  value: string;
}) {
  return (
    <div
      style={{
        display: "flex",
        alignItems: "center",
        gap: "0.5rem",
        marginTop: "0.3rem",
      }}
    >
      {/* Identity rides the swatch; the text stays in ink tokens. */}
      <span
        style={{
          width: 10,
          height: 10,
          borderRadius: 3,
          background: color,
          flexShrink: 0,
        }}
      />
      <span className="secondary">{name}</span>
      <span
        className="tabular"
        style={{ marginLeft: "auto", fontWeight: 600, color: "var(--text-primary)" }}
      >
        {value}
      </span>
    </div>
  );
}

function MoneyTooltip({
  active,
  label,
  payload,
  currency,
  names,
}: TooltipProps & { currency: string; names: Record<string, string> }) {
  if (!active || !payload?.length) return null;
  const order = ["spend", "revenue"];
  return (
    <TooltipShell>
      <div style={{ fontWeight: 600 }}>{longDate(String(label))}</div>
      {order.map((key) => {
        const entry = payload.find((p) => p.dataKey === key);
        if (!entry) return null;
        return (
          <TooltipRow
            key={key}
            color={key === "spend" ? "var(--series-1)" : "var(--series-2)"}
            name={names[key]}
            value={money(entry.value ?? 0, currency)}
          />
        );
      })}
    </TooltipShell>
  );
}

function RoasTooltip({
  active,
  payload,
  compareLabel,
}: TooltipProps & { compareLabel: string }) {
  if (!active || !payload?.length) return null;
  const row = payload[0]?.payload;
  if (!row) return null;

  return (
    <TooltipShell>
      <div style={{ fontWeight: 600 }}>{longDate(row.date)}</div>
      <TooltipRow
        color="var(--series-1)"
        name="Current period"
        value={multiple(row.current)}
      />
      {row.previous !== null && (
        <>
          <TooltipRow
            color="var(--series-2)"
            name={capitalise(compareLabel)}
            value={multiple(row.previous)}
          />
          {row.previousDate && (
            <div
              className="muted"
              style={{ marginTop: "0.35rem", fontSize: "0.75rem" }}
            >
              compared against {longDate(row.previousDate)}
            </div>
          )}
        </>
      )}
    </TooltipShell>
  );
}

/**
 * Legend swatches carry identity; the labels stay in text tokens.
 * `first` names the primary series so it leads the legend regardless of the
 * render order, which is dictated by z-order (primary drawn last, on top).
 */
function FlatLegend({
  payload,
  first,
}: {
  payload?: { value?: string; color?: string }[];
  first?: string;
}) {
  if (!payload?.length) return null;
  const ordered = first
    ? [...payload].sort(
        (a, b) => Number(b.value === first) - Number(a.value === first),
      )
    : payload;
  return (
    <div
      style={{
        display: "flex",
        flexWrap: "wrap",
        gap: "1rem",
        justifyContent: "center",
        padding: "0.5rem 0 0.15rem",
        fontSize: "0.8125rem",
      }}
    >
      {ordered.map((entry) => (
        <span
          key={entry.value}
          style={{ display: "flex", alignItems: "center", gap: "0.4rem" }}
        >
          <span
            style={{
              width: 14,
              height: 3,
              borderRadius: 2,
              background: entry.color,
            }}
          />
          <span className="secondary">{entry.value}</span>
        </span>
      ))}
    </div>
  );
}

/* --- Helpers ------------------------------------------------------------- */

function compactMoney(value: number, currency: string): string {
  try {
    return new Intl.NumberFormat("en-US", {
      style: "currency",
      currency,
      notation: "compact",
      maximumFractionDigits: 1,
    }).format(value);
  } catch {
    return String(Math.round(value));
  }
}

function capitalise(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1);
}
