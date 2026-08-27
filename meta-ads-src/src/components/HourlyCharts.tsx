"use client";

import {
  Bar,
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
import { money, multiple } from "@/lib/format";
import type { HourlyPoint } from "@/lib/types";

/**
 * Within-day view, shown when the range is a single day.
 *
 * Spend is per hour, because that is genuinely a per-hour quantity. ROAS is
 * cumulative from midnight: hour-by-hour ROAS swings wildly on one order and
 * would make a quiet morning look like a catastrophe. The running total is
 * what actually answers "how is today going".
 */

interface Row {
  hour: number;
  label: string;
  spend: number;
  revenue: number;
  cumulativeRoas: number | null;
  previousCumulativeRoas: number | null;
  cumulativeSpend: number;
  cumulativeRevenue: number;
}

export function HourlyCharts({
  hourly,
  previousHourly,
  currency,
  targetRoas,
  compareLabel,
  partial,
}: {
  hourly: HourlyPoint[];
  previousHourly: HourlyPoint[] | null;
  currency: string;
  targetRoas: number;
  compareLabel: string;
  partial: boolean;
}) {
  if (!hourly.length) return null;

  const cumulative = runningRoas(hourly);
  const previousCumulative = previousHourly ? runningRoas(previousHourly) : null;

  // Every hour up to the last one with delivery. Padding to 24 on a partial
  // day would draw a long flat tail that reads as "spend stopped".
  const lastHour = Math.max(...hourly.map((h) => h.hour));

  const data: Row[] = [];
  for (let hour = 0; hour <= lastHour; hour++) {
    const point = hourly.find((h) => h.hour === hour);
    data.push({
      hour,
      label: `${String(hour).padStart(2, "0")}:00`,
      spend: point?.metrics.spend ?? 0,
      revenue: point?.metrics.revenue ?? 0,
      cumulativeRoas: cumulative.get(hour) ?? null,
      previousCumulativeRoas: previousCumulative?.get(hour) ?? null,
      cumulativeSpend: runningTotal(hourly, hour, (m) => m.spend),
      cumulativeRevenue: runningTotal(hourly, hour, (m) => m.revenue),
    });
  }

  const hasComparison = data.some((r) => r.previousCumulativeRoas !== null);

  return (
    <div
      style={{
        display: "grid",
        gap: "1rem",
        gridTemplateColumns: "repeat(auto-fit, minmax(min(100%, 380px), 1fr))",
      }}
    >
      <Card
        title="Spend and revenue by hour"
        subtitle={
          partial
            ? `Through ${String(lastHour).padStart(2, "0")}:59, account time`
            : "Account time"
        }
      >
        <ResponsiveContainer width="100%" height={260}>
          <ComposedChart data={data} margin={{ top: 8, right: 12, bottom: 4, left: 4 }}>
            <CartesianGrid vertical={false} />
            <XAxis dataKey="label" tickLine={false} minTickGap={20} />
            <YAxis
              tickFormatter={(v: number) => compactMoney(v, currency)}
              tickLine={false}
              axisLine={false}
              width={64}
            />
            <Tooltip content={<HourTooltip currency={currency} />} />
            <Legend content={<FlatLegend first="Spend" />} />
            {/*
              Bars, not lines: hourly spend is a quantity per bucket rather
              than a reading at an instant, and bars stop the eye from
              interpolating a value between two hours.
            */}
            <Bar
              dataKey="spend"
              name="Spend"
              fill="var(--series-1)"
              radius={[4, 4, 0, 0]}
              maxBarSize={24}
            />
            <Bar
              dataKey="revenue"
              name="Attributed revenue"
              fill="var(--series-2)"
              radius={[4, 4, 0, 0]}
              maxBarSize={24}
            />
          </ComposedChart>
        </ResponsiveContainer>
      </Card>

      <Card
        title="Running ROAS through the day"
        subtitle={
          hasComparison
            ? `Cumulative from midnight, against the ${compareLabel}`
            : "Cumulative from midnight"
        }
      >
        <ResponsiveContainer width="100%" height={260}>
          <ComposedChart data={data} margin={{ top: 8, right: 56, bottom: 4, left: 4 }}>
            <CartesianGrid vertical={false} />
            <XAxis dataKey="label" tickLine={false} minTickGap={20} />
            <YAxis
              tickFormatter={(v: number) => `${v.toFixed(1)}x`}
              tickLine={false}
              axisLine={false}
              width={48}
            />
            <Tooltip
              content={<RoasTooltip compareLabel={compareLabel} currency={currency} />}
            />
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
            {hasComparison && <Legend content={<FlatLegend first="Today" />} />}
            {hasComparison && (
              <Line
                type="monotone"
                dataKey="previousCumulativeRoas"
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
              dataKey="cumulativeRoas"
              name="Today"
              stroke="var(--series-1)"
              strokeWidth={2}
              dot={false}
              connectNulls
              activeDot={{ r: 4, strokeWidth: 2, stroke: "var(--surface)" }}
            />
          </ComposedChart>
        </ResponsiveContainer>
      </Card>
    </div>
  );
}

/* --- Maths --------------------------------------------------------------- */

/** Cumulative revenue ÷ cumulative spend at the end of each hour. */
function runningRoas(points: HourlyPoint[]): Map<number, number | null> {
  const out = new Map<number, number | null>();
  const sorted = [...points].sort((a, b) => a.hour - b.hour);

  let spend = 0;
  let revenue = 0;
  let cursor = 0;

  for (let hour = 0; hour <= 23; hour++) {
    while (cursor < sorted.length && sorted[cursor].hour === hour) {
      spend += sorted[cursor].metrics.spend;
      revenue += sorted[cursor].metrics.revenue;
      cursor++;
    }
    out.set(hour, spend > 0 ? revenue / spend : null);
  }
  return out;
}

function runningTotal(
  points: HourlyPoint[],
  throughHour: number,
  pick: (m: HourlyPoint["metrics"]) => number,
): number {
  return points
    .filter((p) => p.hour <= throughHour)
    .reduce((sum, p) => sum + pick(p.metrics), 0);
}

/* --- Chrome -------------------------------------------------------------- */

function Card({
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
      <p className="muted" style={{ margin: "0 0 0.75rem", fontSize: "0.8125rem" }}>
        {subtitle}
      </p>
      {children}
    </div>
  );
}

interface TooltipProps {
  active?: boolean;
  payload?: { payload?: Row }[];
}

function Shell({ children }: { children: React.ReactNode }) {
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

function Row_({
  color,
  name,
  value,
}: {
  color?: string;
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
      {color ? (
        <span
          style={{
            width: 10,
            height: 10,
            borderRadius: 3,
            background: color,
            flexShrink: 0,
          }}
        />
      ) : (
        <span style={{ width: 10, flexShrink: 0 }} />
      )}
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

function HourTooltip({ active, payload, currency }: TooltipProps & { currency: string }) {
  if (!active || !payload?.length) return null;
  const row = payload[0]?.payload;
  if (!row) return null;

  return (
    <Shell>
      <div style={{ fontWeight: 600 }}>
        {row.label}–{String(row.hour).padStart(2, "0")}:59
      </div>
      <Row_ color="var(--series-1)" name="Spend" value={money(row.spend, currency)} />
      <Row_
        color="var(--series-2)"
        name="Attributed revenue"
        value={money(row.revenue, currency)}
      />
      <div
        className="muted"
        style={{ marginTop: "0.4rem", fontSize: "0.75rem", lineHeight: 1.45 }}
      >
        By end of this hour: {money(row.cumulativeSpend, currency)} spent,{" "}
        {money(row.cumulativeRevenue, currency)} back
      </div>
    </Shell>
  );
}

function RoasTooltip({
  active,
  payload,
  compareLabel,
  currency,
}: TooltipProps & { compareLabel: string; currency: string }) {
  if (!active || !payload?.length) return null;
  const row = payload[0]?.payload;
  if (!row) return null;

  return (
    <Shell>
      <div style={{ fontWeight: 600 }}>By {row.label}</div>
      <Row_
        color="var(--series-1)"
        name="Today"
        value={multiple(row.cumulativeRoas)}
      />
      {row.previousCumulativeRoas !== null && (
        <Row_
          color="var(--series-2)"
          name={capitalise(compareLabel)}
          value={multiple(row.previousCumulativeRoas)}
        />
      )}
      <div
        className="muted"
        style={{ marginTop: "0.4rem", fontSize: "0.75rem", lineHeight: 1.45 }}
      >
        {money(row.cumulativeRevenue, currency)} from{" "}
        {money(row.cumulativeSpend, currency)} so far
      </div>
    </Shell>
  );
}

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
