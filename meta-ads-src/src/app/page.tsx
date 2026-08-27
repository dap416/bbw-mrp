"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { AdjustmentsPanel } from "@/components/AdjustmentsPanel";
import { AdvicePanel } from "@/components/AdvicePanel";
import { EntityTable } from "@/components/EntityTable";
import { FindingsPanel } from "@/components/FindingsPanel";
import { FunnelPanel } from "@/components/FunnelPanel";
import { HourlyCharts } from "@/components/HourlyCharts";
import { StatTile } from "@/components/StatTile";
import { TrendCharts } from "@/components/TrendCharts";
import { PRESET_LABELS } from "@/lib/dates";
import { count, delta, money, multiple, percent } from "@/lib/format";
import type { DashboardData, Level, Preset } from "@/lib/types";

type CompareMode = "previous_period" | "previous_year";

const PRESETS: Preset[] = [
  "today",
  "yesterday",
  "last_7d",
  "last_14d",
  "last_28d",
  "last_30d",
  "last_90d",
  "this_month",
  "last_month",
];

const LEVELS: { value: Exclude<Level, "account">; label: string }[] = [
  { value: "campaign", label: "Campaigns" },
  { value: "adset", label: "Ad sets" },
  { value: "ad", label: "Ads" },
];

interface ApiError {
  error: string;
  hint?: string;
  /** Set when the fix is "go and configure something". */
  setup?: boolean;
}

export default function Page() {
  const [preset, setPreset] = useState<Preset>("last_28d");
  const [compare, setCompare] = useState<CompareMode>("previous_period");
  const [level, setLevel] = useState<Exclude<Level, "account">>("campaign");
  const [data, setData] = useState<DashboardData | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [loading, setLoading] = useState(true);

  // null means "haven't read the URL yet". The first fetch waits for this, so
  // a ?demo=1 load never races against a real-credentials load it would lose to.
  // It can't be a lazy useState initialiser — this page is prerendered, and
  // window doesn't exist during that pass.
  const [demo, setDemo] = useState<boolean | null>(null);

  useEffect(() => {
    setDemo(new URLSearchParams(window.location.search).get("demo") === "1");
  }, []);

  // Monotonic request id. Responses that arrive after a newer request has
  // started are dropped, so switching preset quickly can't leave the older
  // response on screen.
  const requestId = useRef(0);

  const load = useCallback(async () => {
    if (demo === null) return;

    const id = ++requestId.current;
    setLoading(true);
    setError(null);
    try {
      const res = await fetch(
        `/api/insights?preset=${preset}&compare=${compare}${demo ? "&demo=1" : ""}`,
        { cache: "no-store" },
      );
      const body = await res.json();
      if (id !== requestId.current) return;

      if (!res.ok) {
        setError(body as ApiError);
        setData(null);
        return;
      }
      setData(body as DashboardData);
    } catch (err) {
      if (id !== requestId.current) return;
      setError({
        error: err instanceof Error ? err.message : "Could not reach the API route.",
      });
      setData(null);
    } finally {
      if (id === requestId.current) setLoading(false);
    }
  }, [preset, compare, demo]);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <main
      style={{
        maxWidth: 1280,
        margin: "0 auto",
        padding: "1.5rem 1.25rem 4rem",
        display: "grid",
        gap: "1.25rem",
      }}
    >
      <Header
        data={data}
        preset={preset}
        compare={compare}
        loading={loading}
        onPreset={setPreset}
        onCompare={setCompare}
        onRefresh={load}
      />

      {error && (
        <ErrorPanel
          error={error}
          onDemo={demo === false ? () => setDemo(true) : undefined}
        />
      )}

      {loading && !data && (
        <div className="card" style={{ padding: "3rem", textAlign: "center" }}>
          <p className="secondary" style={{ margin: 0 }}>
            Loading from the Meta Marketing API…
          </p>
        </div>
      )}

      {data && (
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

          {data.partial && <PartialNotice />}

          <Tiles data={data} />

          {/*
            A single-day range has no daily trend, so it gets the within-day
            view instead of an empty chart pair.
          */}
          {data.hourly && data.hourly.length > 0 ? (
            <HourlyCharts
              hourly={data.hourly}
              previousHourly={data.previousHourly}
              currency={data.account.currency}
              targetRoas={data.targets.roas}
              compareLabel={data.compareLabel}
              partial={data.partial}
            />
          ) : (
            <TrendCharts
              daily={data.daily}
              previousDaily={data.previousDaily}
              currency={data.account.currency}
              targetRoas={data.targets.roas}
              compareLabel={data.compareLabel}
            />
          )}
          {/*
            alignItems: start so the analysis card keeps its natural height —
            stretching it to match the findings list leaves a tall empty box
            before anything has been generated.
          */}
          <FunnelPanel totals={data.totals} currency={data.account.currency} />

          <div
            style={{
              display: "grid",
              gap: "1.25rem",
              alignItems: "start",
              gridTemplateColumns: "repeat(auto-fit, minmax(min(100%, 420px), 1fr))",
            }}
          >
            <FindingsPanel findings={data.findings} />
            <AdvicePanel data={data} />
          </div>
          <AdjustmentsPanel data={data} onChanged={load} />
          <EntityTable
            rows={
              level === "campaign" ? data.campaigns
              : level === "adset" ? data.adsets
              : data.ads
            }
            currency={data.account.currency}
            targetRoas={data.targets.roas}
            compareLabel={data.compareLabel}
            levels={LEVELS}
            level={level}
            onLevelChange={setLevel}
          />
          <Footnote data={data} />
        </>
      )}
    </main>
  );
}

/* --- Header & controls --------------------------------------------------- */

function Header({
  data,
  preset,
  compare,
  loading,
  onPreset,
  onCompare,
  onRefresh,
}: {
  data: DashboardData | null;
  preset: Preset;
  compare: CompareMode;
  loading: boolean;
  onPreset: (p: Preset) => void;
  onCompare: (c: CompareMode) => void;
  onRefresh: () => void;
}) {
  return (
    <header
      style={{
        display: "flex",
        alignItems: "flex-end",
        justifyContent: "space-between",
        gap: "1rem",
        flexWrap: "wrap",
      }}
    >
      <div>
        <h1
          style={{
            margin: 0,
            fontSize: "1.375rem",
            fontWeight: 600,
            letterSpacing: "-0.01em",
          }}
        >
          {data ? data.account.name : "Meta Ads Dashboard"}
        </h1>
        <p className="muted" style={{ margin: "0.2rem 0 0", fontSize: "0.8125rem" }}>
          {data
            ? `${data.range.since} to ${data.range.until} · ${data.account.currency} · times in ${data.account.timezone}`
            : "Connecting to your ad account"}
        </p>
      </div>

      {/* Filters sit in one row above the charts. */}
      <div
        className="no-print"
        style={{ display: "flex", gap: "0.5rem", flexWrap: "wrap", alignItems: "center" }}
      >
        <select
          className="control"
          value={preset}
          onChange={(e) => onPreset(e.target.value as Preset)}
          aria-label="Date range"
        >
          {PRESETS.map((p) => (
            <option key={p} value={p}>
              {PRESET_LABELS[p]}
            </option>
          ))}
        </select>

        <select
          className="control"
          value={compare}
          onChange={(e) => onCompare(e.target.value as CompareMode)}
          aria-label="Comparison period"
        >
          <option value="previous_period">vs previous period</option>
          <option value="previous_year">vs last year</option>
        </select>

        <button className="control" onClick={onRefresh} disabled={loading}>
          {loading ? "Refreshing…" : "Refresh"}
        </button>

        <Link
          href="/setup"
          className="control"
          style={{ textDecoration: "none", color: "var(--text-primary)" }}
        >
          Setup
        </Link>

        <ThemeToggle />
      </div>
    </header>
  );
}

function ThemeToggle() {
  const [theme, setTheme] = useState<"light" | "dark" | null>(null);

  useEffect(() => {
    const saved = document.documentElement.dataset.theme;
    if (saved === "light" || saved === "dark") setTheme(saved);
  }, []);

  function toggle() {
    const current =
      theme ??
      (window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light");
    const next = current === "dark" ? "light" : "dark";
    document.documentElement.dataset.theme = next;
    try {
      localStorage.setItem("theme", next);
    } catch {
      // Private browsing — the toggle still works for this session.
    }
    setTheme(next);
  }

  return (
    <button className="control" onClick={toggle} aria-label="Toggle colour theme">
      {theme === "dark" ? "Light" : "Dark"}
    </button>
  );
}

/* --- Tiles --------------------------------------------------------------- */

function Tiles({ data }: { data: DashboardData }) {
  const c = data.account.currency;
  const { totals: t, previousTotals: p } = data;
  const roasTrend = data.daily.map((d) => d.metrics.roas ?? 0);
  const spendTrend = data.daily.map((d) => d.metrics.spend);
  const revenueTrend = data.daily.map((d) => d.metrics.revenue);

  return (
    <section>
      <p
        className="muted"
        style={{ margin: "0 0 0.6rem", fontSize: "0.8125rem" }}
      >
        Change shown against the {data.compareLabel}.
      </p>
      <div
        style={{
          display: "grid",
          gap: "0.85rem",
          gridTemplateColumns: "repeat(auto-fit, minmax(min(100%, 210px), 1fr))",
        }}
      >
      {/* Exactly one hero figure: the number the account lives or dies on. */}
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
        label="Attributed revenue"
        value={money(t.revenue, c, { compact: true })}
        delta={delta(t.revenue, p.revenue)}
        trend={revenueTrend}
      />
      <StatTile
        label="Purchases"
        value={count(t.purchases)}
        delta={delta(t.purchases, p.purchases)}
      />
      <StatTile
        label="Cost per purchase"
        value={money(t.cpa, c)}
        delta={delta(t.cpa, p.cpa, true)}
      />
      <StatTile
        label="Average order value"
        value={money(t.aov, c)}
        delta={delta(t.aov, p.aov)}
      />
      {/*
        Link CTR, not the headline CTR. Meta's default click count folds in
        likes, shares and profile taps, so it flatters the number and is not
        what the rest of the funnel is measured against.
      */}
      <StatTile
        label="Link click-through rate"
        value={percent(t.linkCtr)}
        delta={delta(t.linkCtr, p.linkCtr)}
      />
      <StatTile
        label="Cost per link click"
        value={money(t.costPerLinkClick, c)}
        delta={delta(t.costPerLinkClick, p.costPerLinkClick, true)}
      />
      <StatTile
        label="Cost per 1,000 impressions"
        value={money(t.cpm, c)}
        delta={delta(t.cpm, p.cpm, true)}
      />

      {data.shopify && (
        <StatTile
          label={
            data.shopify.excludedOrders > 0
              ? "Blended ROAS · retail store revenue ÷ Meta spend"
              : "Blended ROAS · all store revenue ÷ Meta spend"
          }
          value={multiple(data.blendedRoas)}
          note={
            data.shopify.excludedOrders > 0
              ? `${money(data.shopify.totalRevenue, data.shopify.currency, { compact: true })} across ${data.shopify.orderCount} orders, after excluding ${money(data.shopify.excludedRevenue, data.shopify.currency, { compact: true })} of wholesale`
              : `${money(data.shopify.totalRevenue, data.shopify.currency, { compact: true })} across ${data.shopify.orderCount} Shopify orders`
          }
          />
        )}
      </div>
    </section>
  );
}

/* --- Chrome -------------------------------------------------------------- */

/**
 * Today's numbers are not final and read worse than they are. Spend lands
 * immediately while attributed conversions arrive over the following hours and
 * days, so an in-progress day almost always shows a lower ROAS than it will
 * settle at. Saying so is the difference between a useful view and a panic.
 */
function PartialNotice() {
  return (
    <div
      className="card"
      style={{
        padding: "0.75rem 1rem",
        borderLeft: "3px solid var(--status-warning)",
        fontSize: "0.875rem",
        lineHeight: 1.55,
      }}
    >
      <strong style={{ fontWeight: 600 }}>This period is still running.</strong>{" "}
      <span className="secondary">
        Spend is reported almost immediately, but attributed purchases keep
        arriving for hours or days afterwards. ROAS will read low now and rise
        as conversions land — don&apos;t judge a campaign on a partial day.
      </span>
    </div>
  );
}

function ErrorPanel({
  error,
  onDemo,
}: {
  error: ApiError;
  onDemo?: () => void;
}) {
  return (
    <div
      className="card"
      style={{ padding: "1.25rem", borderLeft: "3px solid var(--status-critical)" }}
    >
      <h2 style={{ margin: "0 0 0.4rem", fontSize: "0.9375rem", fontWeight: 600 }}>
        Could not load your data
      </h2>
      <p style={{ margin: 0, fontSize: "0.875rem", lineHeight: 1.55 }}>
        {error.error}
      </p>
      {error.hint && (
        <p
          className="secondary"
          style={{ margin: "0.5rem 0 0", fontSize: "0.875rem", lineHeight: 1.55 }}
        >
          {error.hint}
        </p>
      )}
      <div
        className="no-print"
        style={{ display: "flex", gap: "0.5rem", marginTop: "0.85rem", flexWrap: "wrap" }}
      >
        <Link
          href="/setup"
          className="control control-primary"
          style={{ textDecoration: "none" }}
        >
          Open setup
        </Link>
        {onDemo && (
          <button className="control" onClick={onDemo}>
            Show me sample data instead
          </button>
        )}
      </div>
    </div>
  );
}

function Footnote({ data }: { data: DashboardData }) {
  return (
    <p
      className="muted"
      style={{ fontSize: "0.75rem", lineHeight: 1.6, margin: 0 }}
    >
      Revenue and purchase figures are what Meta attributes to your ads under
      your configured attribution window, not a record of what your store took
      in. Meta counts a sale when it can claim it, so these numbers overlap with
      other channels.
      {data.shopify
        ? " Blended ROAS above divides Shopify revenue by all Meta spend, which is the figure your P&L reflects."
        : " Connect Shopify on the setup page to see blended ROAS alongside this."}
      {data.shopify && data.shopify.activeRules.length > 0 && (
        <>
          {" "}
          Excluded from the Shopify figure: {data.shopify.activeRules.join(", ")}.
          These exclusions do not apply to Meta&apos;s attributed revenue, which
          the API reports as a single total with no order detail.
        </>
      )}{" "}
      Compared against the {data.compareLabel} ({data.compareRange.since} to{" "}
      {data.compareRange.until}).
    </p>
  );
}
