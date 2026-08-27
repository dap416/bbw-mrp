"use client";

import { Sparkline } from "./Sparkline";
import type { Delta } from "@/lib/format";

/**
 * Stat tile: label, value, optional delta vs a named period, optional trend.
 * The delta's colour encodes direction × whether up is good, and it always
 * carries an arrow glyph so the meaning never rests on colour alone.
 */
export function StatTile({
  label,
  value,
  delta,
  trend,
  hero = false,
  note,
}: {
  label: string;
  value: string;
  delta?: Delta;
  trend?: number[];
  /** Exactly one tile per view should be the hero. */
  hero?: boolean;
  note?: string;
}) {
  return (
    <div className="card" style={{ padding: hero ? "1.25rem 1.4rem" : "1rem 1.1rem" }}>
      <div
        style={{
          fontSize: "0.8125rem",
          color: "var(--text-secondary)",
          marginBottom: "0.4rem",
        }}
      >
        {label}
      </div>

      <div
        style={{
          fontSize: hero ? "3rem" : "1.75rem",
          fontWeight: 600,
          lineHeight: 1.05,
          letterSpacing: "-0.02em",
          color: "var(--text-primary)",
        }}
      >
        {value}
      </div>

      <div
        style={{
          display: "flex",
          alignItems: "center",
          justifyContent: "space-between",
          gap: "0.75rem",
          marginTop: "0.6rem",
          minHeight: 24,
        }}
      >
        {/*
          The comparison period is named once above the grid rather than on
          every tile — repeating it eight times is noise, and it forced the
          delta onto three wrapped lines wherever a sparkline shared the row.
        */}
        {delta && delta.change !== null ? (
          <span
            style={{
              fontSize: "0.8125rem",
              fontWeight: 500,
              whiteSpace: "nowrap",
              color:
                delta.tone === "good" ? "var(--delta-good)"
                : delta.tone === "bad" ? "var(--delta-bad)"
                : "var(--text-muted)",
            }}
          >
            {delta.tone === "neutral" ? "→" : delta.change > 0 ? "↑" : "↓"} {delta.label}
          </span>
        ) : (
          <span
            className="muted"
            style={{ fontSize: "0.8125rem", lineHeight: 1.35 }}
          >
            {note ?? "No comparison"}
          </span>
        )}

        {trend && trend.length > 1 && <Sparkline values={trend} />}
      </div>
    </div>
  );
}
