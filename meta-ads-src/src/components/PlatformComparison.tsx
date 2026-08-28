"use client";

import { count, money, multiple } from "@/lib/format";
import { BALANCE_THRESHOLDS } from "@/lib/balance";
import type { PlatformComparisonRow } from "@/lib/types";

/**
 * The balancing table: what each platform costs, what it returns, and where
 * the engine would move money.
 *
 * The share bar is the point of the table — the eye should land on "Meta is
 * three quarters of the budget" before it reads a single number. Everything
 * else is supporting detail, so it is set quieter and right-aligned for
 * column-wise comparison.
 */
export function PlatformComparison({
  rows,
  currency,
  targetRoas,
  compareLabel,
}: {
  rows: PlatformComparisonRow[];
  currency: string;
  targetRoas: number;
  compareLabel: string;
}) {
  const spending = rows.filter((r) => r.spend > 0);
  const totalSpend = rows.reduce((s, r) => s + r.spend, 0);
  const totalRevenue = rows.reduce((s, r) => s + r.revenue, 0);
  const blended = totalSpend > 0 ? totalRevenue / totalSpend : null;
  const hasShifts = rows.some((r) => r.suggestedShift);

  if (!spending.length) {
    return (
      <div className="card" style={{ padding: "1.25rem" }}>
        <h2 style={headingStyle}>Platform balance</h2>
        <p className="secondary" style={{ margin: 0 }}>
          No spend recorded on any platform for this period. Connect Meta on the
          setup page, and import a Google or Microsoft report on the Data tab.
        </p>
      </div>
    );
  }

  return (
    <div className="card" style={{ padding: "1.25rem" }}>
      <h2 style={headingStyle}>Platform balance</h2>
      <p className="muted" style={{ margin: "0 0 1rem", fontSize: "0.8125rem" }}>
        Share of budget against share of attributed return. Blended across all
        platforms:{" "}
        <strong style={{ color: "var(--text-primary)" }}>
          {multiple(blended)}
        </strong>{" "}
        against a {targetRoas.toFixed(1)}x target.
      </p>

      <div style={{ overflowX: "auto" }}>
        <table
          style={{
            width: "100%",
            borderCollapse: "collapse",
            fontSize: "0.875rem",
            minWidth: 620,
          }}
        >
          <thead>
            <tr>
              <th style={th("left")}>Platform</th>
              <th style={th("left")}>Share of spend</th>
              <th style={th("right")}>Spend</th>
              <th style={th("right")}>Revenue</th>
              <th style={th("right")}>ROAS</th>
              <th style={th("right")}>Conv.</th>
              <th style={th("right")}>CPA</th>
              {hasShifts && <th style={th("right")}>Suggested</th>}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => {
              const idle = row.spend === 0;
              return (
                <tr
                  key={row.platform}
                  style={{
                    borderTop: "1px solid var(--border)",
                    opacity: idle ? 0.55 : 1,
                  }}
                >
                  <td style={td("left")}>
                    <span
                      style={{
                        display: "inline-block",
                        width: 10,
                        height: 10,
                        borderRadius: 2,
                        background: row.color,
                        marginRight: "0.5rem",
                        verticalAlign: "baseline",
                      }}
                      aria-hidden="true"
                    />
                    {row.label}
                  </td>

                  <td style={td("left")}>
                    {idle ? (
                      <span className="muted">No spend</span>
                    ) : (
                      <ShareBar share={row.spendShare} color={row.color} />
                    )}
                  </td>

                  <td style={td("right")}>
                    {money(row.spend, currency, { compact: true })}
                    {row.spendDelta !== null && (
                      <Delta value={row.spendDelta} lowerIsBetter={false} muted />
                    )}
                  </td>

                  <td style={td("right")}>
                    {money(row.revenue, currency, { compact: true })}
                  </td>

                  <td
                    style={{
                      ...td("right"),
                      fontWeight: 600,
                      color:
                        row.roas === null
                          ? "var(--text-muted)"
                          : row.roas >= targetRoas
                            ? "var(--delta-good)"
                            : "var(--delta-bad)",
                    }}
                  >
                    {multiple(row.roas)}
                    {row.roasDelta !== null && (
                      <Delta value={row.roasDelta} lowerIsBetter={false} />
                    )}
                  </td>

                  <td style={td("right")}>{idle ? "—" : count(row.purchases)}</td>
                  <td style={td("right")}>{money(row.cpa, currency)}</td>

                  {hasShifts && (
                    <td style={td("right")}>
                      {row.suggestedShift ? (
                        <span
                          style={{
                            fontWeight: 600,
                            color:
                              row.suggestedShift > 0
                                ? "var(--delta-good)"
                                : "var(--delta-bad)",
                          }}
                        >
                          {row.suggestedShift > 0 ? "+" : "−"}
                          {money(Math.abs(row.suggestedShift), currency, {
                            compact: true,
                          })}
                        </span>
                      ) : (
                        <span className="muted">hold</span>
                      )}
                    </td>
                  )}
                </tr>
              );
            })}

            <tr style={{ borderTop: "2px solid var(--border)", fontWeight: 600 }}>
              <td style={td("left")}>Total</td>
              <td style={td("left")} />
              <td style={td("right")}>
                {money(totalSpend, currency, { compact: true })}
              </td>
              <td style={td("right")}>
                {money(totalRevenue, currency, { compact: true })}
              </td>
              <td style={td("right")}>{multiple(blended)}</td>
              <td style={td("right")}>
                {count(rows.reduce((s, r) => s + r.purchases, 0))}
              </td>
              <td style={td("right")} />
              {hasShifts && <td style={td("right")} />}
            </tr>
          </tbody>
        </table>
      </div>

      <p
        className="muted"
        style={{ margin: "0.9rem 0 0", fontSize: "0.8125rem", lineHeight: 1.5 }}
      >
        Change is against the {compareLabel}.{" "}
        {hasShifts ? (
          <>
            The suggested column reallocates at most{" "}
            {Math.round(BALANCE_THRESHOLDS.MAX_SHIFT_SHARE * 100)}% of spend
            towards the platforms returning above blended. It is a test to run
            for one period, not a settled answer — each platform counts
            conversions its own way, and Meta claims view-through conversions
            the others do not.
          </>
        ) : (
          <>
            No reallocation is suggested: that needs at least two platforms each
            spending {money(BALANCE_THRESHOLDS.MIN_SPEND_TO_JUDGE, currency)} with{" "}
            {BALANCE_THRESHOLDS.MIN_PURCHASES_TO_JUDGE} or more conversions in
            the period, and a return gap wide enough to sit outside attribution
            noise.
          </>
        )}
      </p>
    </div>
  );
}

/** Proportional bar with the percentage beside it, never colour alone. */
function ShareBar({ share, color }: { share: number; color: string }) {
  const pct = Math.round(share * 100);
  return (
    <span style={{ display: "flex", alignItems: "center", gap: "0.5rem" }}>
      <span
        style={{
          flex: "1 1 auto",
          minWidth: 60,
          height: 8,
          borderRadius: 4,
          background: "var(--surface-sunken)",
          overflow: "hidden",
        }}
        aria-hidden="true"
      >
        <span
          style={{
            display: "block",
            width: `${Math.max(share * 100, 1)}%`,
            height: "100%",
            background: color,
          }}
        />
      </span>
      <span style={{ fontVariantNumeric: "tabular-nums", minWidth: 34 }}>
        {pct}%
      </span>
    </span>
  );
}

function Delta({
  value,
  lowerIsBetter,
  muted = false,
}: {
  value: number;
  lowerIsBetter: boolean;
  muted?: boolean;
}) {
  const pct = value * 100;
  const flat = Math.abs(value) < 0.005;
  const improved = lowerIsBetter ? value < 0 : value > 0;

  return (
    <span
      style={{
        display: "block",
        fontSize: "0.75rem",
        fontWeight: 400,
        color:
          muted || flat
            ? "var(--text-muted)"
            : improved
              ? "var(--delta-good)"
              : "var(--delta-bad)",
      }}
    >
      {flat ? "→" : value > 0 ? "↑" : "↓"} {value > 0 ? "+" : ""}
      {pct.toFixed(Math.abs(pct) >= 100 ? 0 : 1)}%
    </span>
  );
}

const headingStyle: React.CSSProperties = {
  margin: "0 0 0.35rem",
  fontSize: "1rem",
  fontWeight: 600,
};

function th(align: "left" | "right"): React.CSSProperties {
  return {
    textAlign: align,
    padding: "0 0.6rem 0.5rem",
    fontWeight: 500,
    fontSize: "0.8125rem",
    color: "var(--text-secondary)",
    whiteSpace: "nowrap",
  };
}

function td(align: "left" | "right"): React.CSSProperties {
  return {
    textAlign: align,
    padding: "0.65rem 0.6rem",
    verticalAlign: "top",
    fontVariantNumeric: "tabular-nums",
    whiteSpace: "nowrap",
  };
}
