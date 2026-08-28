"use client";

import Link from "next/link";
import { api } from "@/lib/basePath";
import { useCallback, useEffect, useRef, useState } from "react";
import { AdjustmentsPanel } from "@/components/AdjustmentsPanel";
import { AdvicePanel } from "@/components/AdvicePanel";
import { EntityTable } from "@/components/EntityTable";
import type { Crumb } from "@/components/EntityTable";
import { FindingsPanel } from "@/components/FindingsPanel";
import { FunnelPanel } from "@/components/FunnelPanel";
import { HourlyCharts } from "@/components/HourlyCharts";
import { OverviewView } from "@/components/OverviewView";
import { PlatformTabs } from "@/components/PlatformTabs";
import { PlatformView } from "@/components/PlatformView";
import { StatTile } from "@/components/StatTile";
import { TrendCharts } from "@/components/TrendCharts";
import { PRESET_LABELS } from "@/lib/dates";
import { count, delta, money, multiple, percent } from "@/lib/format";
import { PLATFORM_META, type PlatformView as View } from "@/lib/platforms";
import type {
  DashboardData,
  EntityRow,
  Level,
  OverviewData,
  Preset,
} from "@/lib/types";

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

/** Per-platform stored-row summary, from /api/platform-data. */
type DataSummary = Record<
  string,
  { rows: number; since: string; until: string; spend: number }
>;

export default function Page() {
  /**
   * Which tab is showing. The combined roll-up is the default: the question
   * the dashboard now exists to answer is how the budget is split, and a
   * single platform's page cannot answer it.
   */
  const [view, setView] = useState<View>("all");
  /**
   * Opens on today. The figures are partial by definition — attributed
   * conversions keep climbing for days after the click — so the page carries a
   * standing notice saying so whenever the range runs to today.
   */
  const [preset, setPreset] = useState<Preset>("today");
  const [compare, setCompare] = useState<CompareMode>("previous_period");
  const [level, setLevel] = useState<Exclude<Level, "account">>("campaign");
  /**
   * Where the breakdown is drilled to. Held as id plus name so the trail keeps
   * reading correctly across a reload of a different period, where the entity
   * may not have delivered and so is absent from the new rows entirely.
   */
  const [focus, setFocus] = useState<{
    campaign?: { id: string; name: string };
    adset?: { id: string; name: string };
  }>({});
  const [data, setData] = useState<DashboardData | null>(null);
  const [overview, setOverview] = useState<OverviewData | null>(null);
  const [summary, setSummary] = useState<DataSummary>({});
  const [error, setError] = useState<ApiError | null>(null);
  const [loading, setLoading] = useState(true);

  // null means "haven't read the URL yet". The first fetch waits for this, so
  // a ?demo=1 load never races against a real-credentials load it would lose to.
  // It can't be a lazy useState initialiser — this page is prerendered, and
  // window doesn't exist during that pass.
  const [demo, setDemo] = useState<boolean | null>(null);

  useEffect(() => {
    const isDemo = new URLSearchParams(window.location.search).get("demo") === "1";
    setDemo(isDemo);
    // The sample data is Meta's alone — there is no demo mode for the roll-up,
    // and landing on a combined view showing one real-looking platform and two
    // empty ones would misrepresent what demo mode is.
    if (isDemo) setView("meta");
  }, []);

  // Monotonic request id. Responses that arrive after a newer request has
  // started are dropped, so switching preset quickly can't leave the older
  // response on screen.
  const requestId = useRef(0);

  /**
   * The two payloads load together for any period.
   *
   * The combined roll-up is fetched even while a single platform's tab is
   * open, because the tab strip shows each platform's spend as its subtitle —
   * switching tabs should be instant, not a second round-trip. The Meta
   * payload is the expensive one (ad-level rows and hourly breakdowns), so it
   * is fetched only when its own tab is showing.
   */
  const load = useCallback(async () => {
    if (demo === null) return;

    const id = ++requestId.current;
    setLoading(true);
    setError(null);

    const query = `preset=${preset}&compare=${compare}`;
    const wantMeta = view === "meta";

    try {
      const [overviewRes, metaRes] = await Promise.all([
        // No roll-up in demo mode — see the demo note above.
        demo ? null : fetch(api(`/api/overview?${query}`), { cache: "no-store" }),
        wantMeta
          ? fetch(api(`/api/insights?${query}${demo ? "&demo=1" : ""}`), {
              cache: "no-store",
            })
          : null,
      ]);
      if (id !== requestId.current) return;

      if (overviewRes) {
        const body = await overviewRes.json();
        if (id !== requestId.current) return;
        if (overviewRes.ok) setOverview(body as OverviewData);
        // A failed roll-up must not blank the Meta tab, which has its own
        // payload and its own error handling below.
        else if (!wantMeta) setError(body as ApiError);
      }

      if (metaRes) {
        const body = await metaRes.json();
        if (id !== requestId.current) return;
        if (!metaRes.ok) {
          setError(body as ApiError);
          setData(null);
        } else {
          setData(body as DashboardData);
        }
      }
    } catch (err) {
      if (id !== requestId.current) return;
      setError({
        error: err instanceof Error ? err.message : "Could not reach the API route.",
      });
      if (wantMeta) setData(null);
    } finally {
      if (id === requestId.current) setLoading(false);
    }
  }, [preset, compare, demo, view]);

  useEffect(() => {
    void load();
  }, [load]);

  /**
   * What is stored for the manual platforms, for the import panel's "currently
   * stored" line. Only readable with Edit access, so a 403 here is expected
   * rather than an error worth surfacing.
   */
  const loadSummary = useCallback(async () => {
    try {
      const res = await fetch(api("/api/platform-data"), { cache: "no-store" });
      if (!res.ok) return;
      const body = await res.json();
      setSummary((body.summary ?? {}) as DataSummary);
    } catch {
      // The panel simply omits the stored-rows line.
    }
  }, []);

  useEffect(() => {
    void loadSummary();
  }, [loadSummary]);

  /** After an import, both the summary and the figures are stale. */
  const onImported = useCallback(() => {
    void loadSummary();
    void load();
  }, [loadSummary, load]);

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
        overview={overview}
        view={view}
        preset={preset}
        compare={compare}
        loading={loading}
        onPreset={setPreset}
        onCompare={setCompare}
        onRefresh={load}
      />

      {/* Hidden in demo mode, which is Meta-only. */}
      {!demo && (
        <PlatformTabs
          view={view}
          onChange={setView}
          subtitles={tabSubtitles(overview)}
        />
      )}

      {error && (
        <ErrorPanel
          error={error}
          onDemo={demo === false ? () => setDemo(true) : undefined}
        />
      )}

      {loading && !data && !overview && (
        <div className="card" style={{ padding: "3rem", textAlign: "center" }}>
          <p className="secondary" style={{ margin: 0 }}>
            {view === "meta"
              ? "Loading from the Meta Marketing API…"
              : "Loading your ad platforms…"}
          </p>
        </div>
      )}

      {view === "all" && overview && <OverviewView data={overview} />}

      {view !== "all" && view !== "meta" && overview && (
        <PlatformView
          slice={
            overview.platforms.find((s) => s.platform === view) ??
            overview.platforms[0]
          }
          data={overview}
          summary={summary[view]}
          onImported={onImported}
        />
      )}

      {view === "meta" && data && (
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
          {data.canEdit && <AdjustmentsPanel data={data} onChanged={load} />}
          <Breakdown
            data={data}
            level={level}
            setLevel={setLevel}
            focus={focus}
            setFocus={setFocus}
          />
          <Footnote data={data} />
        </>
      )}
    </main>
  );
}

/* --- Breakdown ----------------------------------------------------------- */

type Focus = {
  campaign?: { id: string; name: string };
  adset?: { id: string; name: string };
};

/**
 * Holds the campaign → ad set → ad hierarchy together.
 *
 * Meta returns each level as its own flat list; the parent ids ride along on
 * every row, so the tree can be reconstructed here rather than re-queried.
 * Drilling is therefore instant and costs no extra API call.
 */
function Breakdown({
  data,
  level,
  setLevel,
  focus,
  setFocus,
}: {
  data: DashboardData;
  level: Exclude<Level, "account">;
  setLevel: (level: Exclude<Level, "account">) => void;
  focus: Focus;
  setFocus: (focus: Focus) => void;
}) {
  const rows =
    level === "campaign"
      ? data.campaigns
      : level === "adset"
        ? focus.campaign
          ? data.adsets.filter((r) => r.campaignId === focus.campaign!.id)
          : data.adsets
        : focus.adset
          ? data.ads.filter((r) => r.adsetId === focus.adset!.id)
          : focus.campaign
            ? data.ads.filter((r) => r.campaignId === focus.campaign!.id)
            : data.ads;

  const name = (list: EntityRow[], id?: string) =>
    id ? (list.find((r) => r.id === id)?.name ?? null) : null;

  // The last crumb is always the current location and never links to itself,
  // so each level appends its own ending rather than sharing one.
  const crumbs: Crumb[] = [
    {
      label: "All campaigns",
      onClick:
        level === "campaign"
          ? undefined
          : () => {
              setFocus({});
              setLevel("campaign");
            },
    },
  ];
  if (level === "adset") {
    crumbs.push(
      focus.campaign
        ? { label: focus.campaign.name }
        : { label: "All ad sets" },
    );
  } else if (level === "ad") {
    if (focus.campaign) {
      crumbs.push({
        label: focus.campaign.name,
        onClick: () => {
          setFocus({ campaign: focus.campaign });
          setLevel("adset");
        },
      });
    }
    // Without an ad set in the trail we are looking at every ad under whatever
    // is above, which needs saying — otherwise the campaign crumb reads as if
    // its ad sets were on screen.
    crumbs.push({ label: focus.adset ? focus.adset.name : "All ads" });
  }

  return (
    <EntityTable
      rows={rows}
      accountId={data.account.id}
      currency={data.account.currency}
      targetRoas={data.targets.roas}
      compareLabel={data.compareLabel}
      levels={LEVELS}
      level={level}
      onLevelChange={(next) => {
        // Going up abandons the part of the trail that is now below you;
        // going down keeps it, so Ads after Ad sets stays within the ad set.
        if (next === "campaign") setFocus({});
        else if (next === "adset") setFocus({ campaign: focus.campaign });
        setLevel(next);
      }}
      onDrill={
        level === "campaign"
          ? (row) => {
              setFocus({ campaign: { id: row.id, name: row.name } });
              setLevel("adset");
            }
          : level === "adset"
            ? (row) => {
                setFocus({
                  campaign:
                    focus.campaign ??
                    (row.campaignId
                      ? {
                          id: row.campaignId,
                          name:
                            name(data.campaigns, row.campaignId) ?? "Campaign",
                        }
                      : undefined),
                  adset: { id: row.id, name: row.name },
                });
                setLevel("ad");
              }
            : undefined
      }
      drillNoun={level === "campaign" ? "ad sets" : level === "adset" ? "ads" : undefined}
      crumbs={crumbs}
      parentLabel={(row) => {
        // Only worth the space when the crumbs don't already say it.
        if (level === "adset" && !focus.campaign) {
          return name(data.campaigns, row.campaignId);
        }
        if (level === "ad" && !focus.adset) {
          const adset = name(data.adsets, row.adsetId);
          const campaign = focus.campaign
            ? null
            : name(data.campaigns, row.campaignId);
          return [campaign, adset].filter(Boolean).join(" › ") || null;
        }
        return null;
      }}
    />
  );
}

/* --- Header & controls --------------------------------------------------- */

/**
 * Each tab's spend for the period, so the strip carries the shape of the mix
 * without the reader having to open every tab to find it.
 */
function tabSubtitles(
  overview: OverviewData | null,
): Partial<Record<View, string>> {
  if (!overview) return {};

  const out: Partial<Record<View, string>> = {
    all: money(overview.totals.spend, overview.currency, { compact: true }),
  };
  for (const slice of overview.platforms) {
    out[slice.platform] = slice.totals.spend
      ? money(slice.totals.spend, overview.currency, { compact: true })
      : "no spend";
  }
  return out;
}

function Header({
  data,
  overview,
  view,
  preset,
  compare,
  loading,
  onPreset,
  onCompare,
  onRefresh,
}: {
  data: DashboardData | null;
  overview: OverviewData | null;
  view: View;
  preset: Preset;
  compare: CompareMode;
  loading: boolean;
  onPreset: (p: Preset) => void;
  onCompare: (c: CompareMode) => void;
  onRefresh: () => void;
}) {
  // The heading names where you are. On a platform tab that is the account;
  // on the roll-up there is no single account to name.
  const title =
    view === "all"
      ? "Ads Dashboard"
      : view === "meta"
        ? (data?.account.name ?? PLATFORM_META.meta.label)
        : PLATFORM_META[view].label;

  const subtitle =
    view === "all"
      ? overview
        ? `${overview.range.since} to ${overview.range.until} · ${overview.currency} · Meta, Google and Microsoft combined`
        : "Loading every connected ad platform"
      : view === "meta"
        ? data
          ? `${data.range.since} to ${data.range.until} · ${data.account.currency} · times in ${data.account.timezone}`
          : "Connecting to your ad account"
        : overview
          ? `${overview.range.since} to ${overview.range.until} · ${overview.currency} · imported figures`
          : "Loading imported figures";

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
          {title}
        </h1>
        <p className="muted" style={{ margin: "0.2rem 0 0", fontSize: "0.8125rem" }}>
          {subtitle}
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

        {data?.canEdit !== false && (
          <Link
            href="/setup"
            className="control"
            style={{ textDecoration: "none", color: "var(--text-primary)" }}
          >
            Setup
          </Link>
        )}

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
