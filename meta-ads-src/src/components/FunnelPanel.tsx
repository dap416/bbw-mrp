"use client";

import { count, money, percent } from "@/lib/format";
import type { Metrics } from "@/lib/types";

/**
 * Where customers drop off, stage by stage.
 *
 * The magnitudes span two orders of magnitude, so the bars alone would leave
 * the last stages as invisible slivers. The step-to-step conversion rate is
 * the diagnostic anyway — a stage is weak because of the share it loses, not
 * because it is small — so the rates get the prominence and the bars carry
 * scale in the background.
 *
 * Starts at link clicks, never total clicks: total clicks include likes,
 * shares and profile taps that were never headed for the site, and using them
 * invents a drop-off at the first step that did not happen.
 */

interface Stage {
  key: string;
  label: string;
  value: number;
  /** What this stage means, for the reader who is not sure. */
  hint: string;
}

interface Step {
  from: Stage;
  to: Stage;
  rate: number | null;
  lost: number;
  /** Typical rate for an ecommerce store, as a rough guide. */
  benchmark: number;
  benchmarkNote: string;
}

export function FunnelPanel({
  totals,
  currency,
}: {
  totals: Metrics;
  currency: string;
}) {
  const stages: Stage[] = [
    {
      key: "linkClicks",
      label: "Link clicks",
      value: totals.linkClicks,
      hint: "People who clicked through to your site",
    },
    {
      key: "landingPageViews",
      label: "Landing page views",
      value: totals.landingPageViews,
      hint: "Arrived and the page loaded",
    },
    {
      key: "addToCart",
      label: "Added to cart",
      value: totals.addToCart,
      hint: "Picked something",
    },
    {
      key: "initiateCheckout",
      label: "Started checkout",
      value: totals.initiateCheckout,
      hint: "Committed to buying",
    },
    {
      key: "purchases",
      label: "Purchased",
      value: totals.purchases,
      hint: "Paid",
    },
  ];

  // Rules of thumb, not laws. They are here to tell a normal step from a weak
  // one at a glance; the surrounding copy says they are approximate.
  const benchmarks: { benchmark: number; note: string }[] = [
    { benchmark: 0.8, note: "80%+ is healthy" },
    { benchmark: 0.05, note: "5-10% is typical for cold traffic" },
    { benchmark: 0.5, note: "50%+ is typical" },
    { benchmark: 0.4, note: "40-60% is typical" },
  ];

  const steps: Step[] = [];
  for (let i = 0; i < stages.length - 1; i++) {
    const from = stages[i];
    const to = stages[i + 1];
    steps.push({
      from,
      to,
      rate: from.value > 0 ? to.value / from.value : null,
      lost: Math.max(0, from.value - to.value),
      benchmark: benchmarks[i].benchmark,
      benchmarkNote: benchmarks[i].note,
    });
  }

  const top = stages[0].value;
  if (!top) {
    return (
      <div className="card" style={{ padding: "1.25rem" }}>
        <h2 style={headingStyle}>Where customers drop off</h2>
        <p className="secondary" style={{ margin: 0 }}>
          No link clicks recorded for this period, so there is no funnel to show.
        </p>
      </div>
    );
  }

  // The weakest step is the one furthest below its benchmark in relative
  // terms, so a step at half its expected rate outranks one a few points off.
  const scored = steps
    .filter((s) => s.rate !== null && s.from.value >= 20)
    .map((s) => ({ step: s, shortfall: 1 - s.rate! / s.benchmark }));
  const weakest =
    scored.length > 0
      ? scored.reduce((a, b) => (b.shortfall > a.shortfall ? b : a))
      : null;
  const weakStep = weakest && weakest.shortfall > 0.15 ? weakest.step : null;

  // What closing the weak step to its benchmark would be worth, held at the
  // current AOV and the traffic already paid for.
  const aov = totals.aov ?? (totals.purchases ? totals.revenue / totals.purchases : 0);
  let recovery: { orders: number; revenue: number } | null = null;
  if (weakStep && aov > 0) {
    const atBenchmark = weakStep.from.value * weakStep.benchmark;
    const extraAtStep = Math.max(0, atBenchmark - weakStep.to.value);
    // Carry the improvement through the remaining steps at their current rates.
    let carried = extraAtStep;
    const index = steps.indexOf(weakStep);
    for (let i = index + 1; i < steps.length; i++) {
      carried *= steps[i].rate ?? 0;
    }
    if (carried >= 0.5) {
      recovery = { orders: Math.round(carried), revenue: carried * aov };
    }
  }

  return (
    <div className="card" style={{ padding: "1.25rem" }}>
      <h2 style={headingStyle}>Where customers drop off</h2>
      <p className="muted" style={{ margin: "0 0 1.25rem", fontSize: "0.8125rem", lineHeight: 1.5 }}>
        Each step shows how many continued and how many were lost. Starts at
        link clicks — total clicks include likes and shares that were never
        headed for your site.
      </p>

      <div style={{ display: "grid", gap: 0 }}>
        {stages.map((stage, i) => {
          const step = steps[i];
          const isWeakTarget = weakStep && steps[i] === weakStep;
          return (
            <div key={stage.key}>
              <StageRow stage={stage} share={stage.value / top} />
              {step && (
                <StepRow step={step} isWeak={Boolean(isWeakTarget)} />
              )}
            </div>
          );
        })}
      </div>

      {weakStep && (
        <div
          style={{
            marginTop: "1.25rem",
            padding: "0.85rem 1rem",
            borderRadius: 8,
            borderLeft: "3px solid var(--status-critical)",
            background: "var(--surface-sunken)",
          }}
        >
          <div style={{ fontWeight: 600, fontSize: "0.9375rem", marginBottom: "0.3rem" }}>
            Weakest step: {weakStep.from.label} → {weakStep.to.label}
          </div>
          <p
            className="secondary"
            style={{ margin: 0, fontSize: "0.875rem", lineHeight: 1.55 }}
          >
            {percent(weakStep.rate, 1)} continue, against {percent(weakStep.benchmark, 0)}{" "}
            typical. {count(weakStep.lost)} people were lost here.
            {recovery && (
              <>
                {" "}
                Bringing it to the typical rate would be worth about{" "}
                <strong style={{ color: "var(--text-primary)", fontWeight: 600 }}>
                  {count(recovery.orders)} more orders ({money(recovery.revenue, currency)})
                </strong>{" "}
                on the traffic you already pay for.
              </>
            )}
          </p>
        </div>
      )}

      <p
        className="muted"
        style={{ margin: "1rem 0 0", fontSize: "0.75rem", lineHeight: 1.5 }}
      >
        Typical rates are rules of thumb for ecommerce, not targets. Your own
        history is the better comparison once you have a few months of it.
      </p>
    </div>
  );
}

function StageRow({ stage, share }: { stage: Stage; share: number }) {
  return (
    <div style={{ display: "flex", alignItems: "center", gap: "1rem" }}>
      <div style={{ minWidth: 150, flexShrink: 0 }}>
        <div style={{ fontSize: "0.875rem", fontWeight: 600, color: "var(--text-primary)" }}>
          {stage.label}
        </div>
        <div className="muted" style={{ fontSize: "0.6875rem" }}>
          {stage.hint}
        </div>
      </div>

      {/* Bar carries scale; the number beside it carries the value. */}
      <div style={{ flex: 1, minWidth: 0, display: "flex", alignItems: "center", gap: "0.75rem" }}>
        <div
          style={{
            flex: 1,
            minWidth: 0,
            height: 20,
            background: "var(--surface-sunken)",
            borderRadius: 4,
            overflow: "hidden",
          }}
        >
          <div
            style={{
              width: `${Math.max(share * 100, 0.4)}%`,
              height: "100%",
              background: "var(--series-1)",
              borderRadius: "0 4px 4px 0",
            }}
          />
        </div>
        <div
          className="tabular"
          style={{
            minWidth: 64,
            textAlign: "right",
            fontWeight: 600,
            fontSize: "0.9375rem",
            color: "var(--text-primary)",
          }}
        >
          {count(stage.value)}
        </div>
      </div>
    </div>
  );
}

function StepRow({ step, isWeak }: { step: Step; isWeak: boolean }) {
  const healthy = step.rate !== null && step.rate >= step.benchmark;
  return (
    <div
      style={{
        display: "flex",
        alignItems: "center",
        gap: "1rem",
        padding: "0.35rem 0",
        marginLeft: 150,
      }}
    >
      <div
        style={{
          display: "flex",
          alignItems: "center",
          gap: "0.5rem",
          fontSize: "0.8125rem",
          flexWrap: "wrap",
        }}
      >
        {/* Health reads from the glyph and the words, never colour alone. */}
        <span
          aria-hidden="true"
          style={{
            color: isWeak ? "var(--status-critical)" : healthy ? "var(--status-good)" : "var(--text-muted)",
          }}
        >
          {isWeak ? "▼" : healthy ? "✓" : "↓"}
        </span>
        <span
          style={{
            fontWeight: 600,
            color: isWeak ? "var(--delta-bad)" : "var(--text-primary)",
          }}
        >
          {percent(step.rate, 1)} continue
        </span>
        <span className="muted">
          {count(step.lost)} lost · {step.benchmarkNote}
        </span>
        {isWeak && (
          <span
            style={{
              fontSize: "0.6875rem",
              fontWeight: 700,
              textTransform: "uppercase",
              letterSpacing: "0.06em",
              color: "var(--status-critical)",
            }}
          >
            Weakest step
          </span>
        )}
      </div>
    </div>
  );
}

const headingStyle: React.CSSProperties = {
  margin: "0 0 0.25rem",
  fontSize: "1rem",
  fontWeight: 600,
};
