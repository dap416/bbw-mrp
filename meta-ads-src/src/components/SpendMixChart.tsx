"use client";

import { useState } from "react";
import { money, shortDate } from "@/lib/format";
import { PLATFORMS, PLATFORM_META } from "@/lib/platforms";
import type { OverviewData } from "@/lib/types";

/**
 * Daily spend, stacked by platform.
 *
 * Stacked rather than three separate lines because the question this answers
 * is "what is the mix, and is it drifting" — a stack shows the total and the
 * split in one read, where lines make you sum by eye. Hovering names the exact
 * figures rather than relying on the reader estimating from the axis.
 */
export function SpendMixChart({
  data,
  currency,
}: {
  data: OverviewData;
  currency: string;
}) {
  const [hover, setHover] = useState<number | null>(null);

  const points = data.dailyByPlatform;
  if (points.length < 2) return null;

  const totalFor = (i: number) =>
    PLATFORMS.reduce((sum, p) => sum + (points[i].spend[p] ?? 0), 0);

  const max = Math.max(...points.map((_, i) => totalFor(i)), 1);

  // A wide viewBox scaled to 100% width: the bars stay proportional at any
  // container size without needing a resize observer.
  const W = 1000;
  const H = 220;
  const PAD = { top: 12, right: 8, bottom: 26, left: 52 };
  const plotW = W - PAD.left - PAD.right;
  const plotH = H - PAD.top - PAD.bottom;

  const slotW = plotW / points.length;
  const barW = Math.max(slotW * 0.72, 1);

  const y = (value: number) => PAD.top + plotH - (value / max) * plotH;

  // Four gridlines is enough to read a value off without becoming a ledger.
  const ticks = [0, 0.25, 0.5, 0.75, 1].map((f) => max * f);

  const active = hover === null ? null : points[hover];

  return (
    <div className="card" style={{ padding: "1.25rem" }}>
      <div
        style={{
          display: "flex",
          justifyContent: "space-between",
          alignItems: "baseline",
          gap: "1rem",
          flexWrap: "wrap",
          marginBottom: "0.5rem",
        }}
      >
        <h2 style={{ margin: 0, fontSize: "1rem", fontWeight: 600 }}>
          Daily spend by platform
        </h2>
        <Legend />
      </div>

      <svg
        viewBox={`0 0 ${W} ${H}`}
        width="100%"
        height={H}
        role="img"
        aria-label={`Daily ad spend from ${data.range.since} to ${data.range.until}, stacked by platform.`}
        onMouseLeave={() => setHover(null)}
        style={{ display: "block", overflow: "visible" }}
      >
        {ticks.map((value) => (
          <g key={value}>
            <line
              x1={PAD.left}
              x2={W - PAD.right}
              y1={y(value)}
              y2={y(value)}
              stroke="var(--grid)"
              strokeWidth={1}
            />
            <text
              x={PAD.left - 8}
              y={y(value) + 4}
              textAnchor="end"
              fontSize={11}
              fill="var(--text-muted)"
            >
              {money(value, currency, { compact: true })}
            </text>
          </g>
        ))}

        {points.map((point, i) => {
          const x = PAD.left + i * slotW + (slotW - barW) / 2;
          let cursor = PAD.top + plotH;

          return (
            <g
              key={point.date}
              onMouseEnter={() => setHover(i)}
              /* A transparent full-height hit area, so hovering the gap above
                 a short bar still selects that day. */
            >
              <rect
                x={PAD.left + i * slotW}
                y={PAD.top}
                width={slotW}
                height={plotH}
                fill="transparent"
              />
              {PLATFORMS.map((platform) => {
                const value = point.spend[platform] ?? 0;
                if (value <= 0) return null;
                const h = (value / max) * plotH;
                cursor -= h;
                return (
                  <rect
                    key={platform}
                    x={x}
                    y={cursor}
                    width={barW}
                    height={h}
                    fill={PLATFORM_META[platform].color}
                    opacity={hover === null || hover === i ? 1 : 0.35}
                  />
                );
              })}
            </g>
          );
        })}

        {/* Roughly six date labels, however long the range is. */}
        {points.map((point, i) => {
          const step = Math.max(1, Math.round(points.length / 6));
          if (i % step !== 0) return null;
          return (
            <text
              key={point.date}
              x={PAD.left + i * slotW + slotW / 2}
              y={H - 8}
              textAnchor="middle"
              fontSize={11}
              fill="var(--text-muted)"
            >
              {shortDate(point.date)}
            </text>
          );
        })}
      </svg>

      <div
        aria-live="polite"
        style={{
          marginTop: "0.6rem",
          minHeight: "1.4rem",
          fontSize: "0.8125rem",
        }}
      >
        {active ? (
          <span className="secondary">
            <strong style={{ color: "var(--text-primary)" }}>
              {shortDate(active.date)}
            </strong>
            {"  ·  "}
            {PLATFORMS.filter((p) => (active.spend[p] ?? 0) > 0)
              .map(
                (p) =>
                  `${PLATFORM_META[p].short} ${money(active.spend[p], currency)}`,
              )
              .join("  ·  ") || "no spend"}
          </span>
        ) : (
          <span className="muted">Hover a day for the exact split.</span>
        )}
      </div>
    </div>
  );
}

function Legend() {
  return (
    <div
      style={{
        display: "flex",
        gap: "0.9rem",
        flexWrap: "wrap",
        fontSize: "0.8125rem",
      }}
    >
      {PLATFORMS.map((p) => (
        <span
          key={p}
          style={{ display: "flex", alignItems: "center", gap: "0.35rem" }}
        >
          <span
            aria-hidden="true"
            style={{
              width: 10,
              height: 10,
              borderRadius: 2,
              background: PLATFORM_META[p].color,
            }}
          />
          <span className="secondary">{PLATFORM_META[p].short}</span>
        </span>
      ))}
    </div>
  );
}
