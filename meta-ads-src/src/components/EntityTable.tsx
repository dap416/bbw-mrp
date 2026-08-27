"use client";

import { useMemo, useState } from "react";
import { adsManagerUrl } from "@/lib/adsManager";
import { delta, money, multiple, percent, count, decimal } from "@/lib/format";
import type { EntityRow, Level } from "@/lib/types";

/**
 * The table view. It is also the accessibility fallback for the charts —
 * every number plotted above is readable here as text, so nothing is gated
 * behind colour perception.
 *
 * It is hierarchical: a campaign row opens its ad sets, an ad set row opens
 * its ads. Flat lists tell you an ad is losing money but not which campaign to
 * go and find it in, which is the whole difficulty of acting on the figures.
 */

type SortKey =
  | "name" | "spend" | "revenue" | "roas" | "purchases" | "cpa"
  | "linkClicks" | "linkCtr" | "cpm" | "frequency";

const COLUMNS: {
  key: SortKey;
  label: string;
  numeric: boolean;
  /** True where a decrease is the good outcome. */
  lowerIsBetter?: boolean;
}[] = [
  { key: "name", label: "Name", numeric: false },
  { key: "spend", label: "Spend", numeric: true },
  { key: "revenue", label: "Revenue", numeric: true },
  { key: "roas", label: "ROAS", numeric: true },
  { key: "purchases", label: "Purchases", numeric: true },
  { key: "cpa", label: "Cost / purchase", numeric: true, lowerIsBetter: true },
  { key: "linkClicks", label: "Link clicks", numeric: true },
  { key: "linkCtr", label: "Link CTR", numeric: true },
  { key: "cpm", label: "CPM", numeric: true, lowerIsBetter: true },
  { key: "frequency", label: "Frequency", numeric: true, lowerIsBetter: true },
];

/** One step of the drill path. The last one is where you are now. */
export interface Crumb {
  label: string;
  /** Absent on the current location, which is not a link back to itself. */
  onClick?: () => void;
}

export function EntityTable({
  rows,
  accountId,
  currency,
  targetRoas,
  compareLabel,
  levels,
  level,
  onLevelChange,
  onDrill,
  drillNoun,
  crumbs,
  parentLabel,
}: {
  rows: EntityRow[];
  /** Ad account id, for the Ads Manager deep links. */
  accountId: string;
  currency: string;
  targetRoas: number;
  compareLabel: string;
  levels: { value: Exclude<Level, "account">; label: string }[];
  level: Exclude<Level, "account">;
  onLevelChange: (level: Exclude<Level, "account">) => void;
  /** Absent at ad level, which has nothing below it to open. */
  onDrill?: (row: EntityRow) => void;
  /** What a drill opens, e.g. "ad sets". Used in the row's title text. */
  drillNoun?: string;
  /** Where we are in the hierarchy. One entry means "showing everything". */
  crumbs: Crumb[];
  /** The row's place in the tree, when the crumbs don't already imply it. */
  parentLabel?: (row: EntityRow) => string | null;
}) {
  const [sortKey, setSortKey] = useState<SortKey>("spend");
  const [descending, setDescending] = useState(true);
  const [activeOnly, setActiveOnly] = useState(false);

  const visible = useMemo(() => {
    const filtered = activeOnly
      ? rows.filter((r) => r.status === "ACTIVE")
      : rows;

    return [...filtered].sort((a, b) => {
      if (sortKey === "name") {
        const cmp = a.name.localeCompare(b.name);
        return descending ? -cmp : cmp;
      }
      // Nulls (no data) always sort last, whichever direction is active —
      // otherwise "sort by best ROAS" surfaces rows that have no ROAS at all.
      const av = a.current[sortKey];
      const bv = b.current[sortKey];
      if (av === null && bv === null) return 0;
      if (av === null) return 1;
      if (bv === null) return -1;
      return descending ? bv - av : av - bv;
    });
  }, [rows, sortKey, descending, activeOnly]);

  function toggleSort(key: SortKey) {
    if (key === sortKey) {
      setDescending((d) => !d);
    } else {
      setSortKey(key);
      setDescending(key !== "name");
    }
  }

  const narrowed = crumbs.length > 1;

  return (
    <div className="card" style={{ padding: "1.25rem 1.25rem 0.5rem" }}>
      <div
        style={{
          display: "flex",
          alignItems: "center",
          justifyContent: "space-between",
          gap: "1rem",
          flexWrap: "wrap",
          marginBottom: "0.9rem",
        }}
      >
        <div>
          <h2 style={{ margin: "0 0 0.2rem", fontSize: "1rem", fontWeight: 600 }}>
            Breakdown
          </h2>

          <nav
            aria-label="Breakdown location"
            style={{
              display: "flex",
              alignItems: "center",
              flexWrap: "wrap",
              gap: "0.3rem",
              fontSize: "0.8125rem",
              marginBottom: "0.2rem",
            }}
          >
            {crumbs.map((crumb, i) => (
              <span
                key={i}
                style={{ display: "flex", alignItems: "center", gap: "0.3rem" }}
              >
                {i > 0 && (
                  <span className="muted" aria-hidden="true">
                    ›
                  </span>
                )}
                {crumb.onClick ? (
                  <button
                    onClick={crumb.onClick}
                    style={{
                      background: "none",
                      border: "none",
                      padding: 0,
                      font: "inherit",
                      color: "var(--text-secondary)",
                      textDecoration: "underline",
                      cursor: "pointer",
                    }}
                  >
                    {crumb.label}
                  </button>
                ) : (
                  <span
                    aria-current="location"
                    style={{ color: "var(--text-primary)", fontWeight: 600 }}
                  >
                    {crumb.label}
                  </span>
                )}
              </span>
            ))}
          </nav>

          <p className="muted" style={{ margin: 0, fontSize: "0.8125rem" }}>
            Small change vs the {compareLabel} shown beneath each value.
            Rows above your {targetRoas.toFixed(1)}x target are marked.
            {onDrill && drillNoun
              ? ` Select a name to see its ${drillNoun}; ↗ opens it in Ads Manager.`
              : " ↗ opens the row in Ads Manager."}
          </p>
        </div>

        <div className="no-print" style={{ display: "flex", gap: "0.5rem", flexWrap: "wrap" }}>
          <div style={{ display: "flex", gap: "0.25rem" }}>
            {levels.map((l) => (
              <button
                key={l.value}
                className="control"
                onClick={() => onLevelChange(l.value)}
                aria-pressed={level === l.value}
                style={
                  level === l.value
                    ? {
                        background: "var(--surface-sunken)",
                        borderColor: "var(--axis)",
                        fontWeight: 600,
                      }
                    : undefined
                }
              >
                {l.label}
              </button>
            ))}
          </div>
          <label
            className="control"
            style={{ display: "flex", alignItems: "center", gap: "0.4rem" }}
          >
            <input
              type="checkbox"
              checked={activeOnly}
              onChange={(e) => setActiveOnly(e.target.checked)}
            />
            Active only
          </label>
        </div>
      </div>

      {visible.length === 0 ? (
        <p className="secondary" style={{ paddingBottom: "1rem" }}>
          {rows.length === 0
            ? narrowed
              ? "Nothing delivered inside this selection during the period."
              : "Nothing delivered at this level during the selected period."
            : "No rows match the active-only filter."}
        </p>
      ) : (
        <div className="scroll-x">
          <table
            style={{
              width: "100%",
              minWidth: 900,
              borderCollapse: "collapse",
              fontSize: "0.8125rem",
            }}
          >
            <thead>
              <tr>
                {COLUMNS.map((col) => (
                  <th
                    key={col.key}
                    scope="col"
                    aria-sort={
                      sortKey === col.key
                        ? descending
                          ? "descending"
                          : "ascending"
                        : "none"
                    }
                    style={{
                      textAlign: col.numeric ? "right" : "left",
                      padding: "0.5rem 0.6rem",
                      borderBottom: "1px solid var(--axis)",
                      whiteSpace: "nowrap",
                      position: "sticky",
                      top: 0,
                      background: "var(--surface)",
                    }}
                  >
                    <button
                      onClick={() => toggleSort(col.key)}
                      style={{
                        background: "none",
                        border: "none",
                        padding: 0,
                        font: "inherit",
                        fontWeight: 600,
                        color:
                          sortKey === col.key
                            ? "var(--text-primary)"
                            : "var(--text-secondary)",
                        cursor: "pointer",
                      }}
                    >
                      {col.label}
                      {sortKey === col.key && (descending ? " ↓" : " ↑")}
                    </button>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {visible.map((row) => {
                const href = adsManagerUrl(row, accountId);
                const parent = parentLabel?.(row) ?? null;
                return (
                  <tr key={row.id} style={{ borderBottom: "1px solid var(--grid)" }}>
                    <td style={{ padding: "0.55rem 0.6rem", maxWidth: 340 }}>
                      <div
                        style={{
                          display: "flex",
                          alignItems: "center",
                          gap: "0.45rem",
                        }}
                      >
                        {/* Status is a dot plus a title, never colour alone. */}
                        <span
                          title={row.status ?? "Unknown status"}
                          aria-hidden="true"
                          style={{
                            width: 7,
                            height: 7,
                            borderRadius: "50%",
                            flexShrink: 0,
                            background:
                              row.status === "ACTIVE"
                                ? "var(--status-good)"
                                : "var(--text-muted)",
                          }}
                        />
                        {onDrill ? (
                          <button
                            onClick={() => onDrill(row)}
                            title={`${row.name} — show its ${drillNoun ?? "contents"}`}
                            style={{
                              background: "none",
                              border: "none",
                              padding: 0,
                              font: "inherit",
                              textAlign: "left",
                              overflow: "hidden",
                              textOverflow: "ellipsis",
                              whiteSpace: "nowrap",
                              color: "var(--text-primary)",
                              textDecoration: "underline",
                              textDecorationStyle: "dotted",
                              cursor: "pointer",
                            }}
                          >
                            {row.name}
                          </button>
                        ) : (
                          <span
                            title={row.name}
                            style={{
                              overflow: "hidden",
                              textOverflow: "ellipsis",
                              whiteSpace: "nowrap",
                              color: "var(--text-primary)",
                            }}
                          >
                            {row.name}
                          </span>
                        )}
                        {href && (
                          <a
                            className="no-print"
                            href={href}
                            target="_blank"
                            rel="noreferrer"
                            aria-label={`Open ${row.name} in Meta Ads Manager`}
                            title={`Open ${row.name} in Meta Ads Manager`}
                            style={{
                              flexShrink: 0,
                              fontSize: "0.75rem",
                              lineHeight: 1,
                              color: "var(--text-secondary)",
                              textDecoration: "none",
                            }}
                          >
                            ↗
                          </a>
                        )}
                      </div>
                      <span className="muted" style={{ fontSize: "0.6875rem" }}>
                        {row.status ?? "—"}
                        {row.dailyBudget
                          ? ` · ${money(row.dailyBudget, currency)}/day`
                          : row.lifetimeBudget
                            ? ` · ${money(row.lifetimeBudget, currency)} lifetime`
                            : ""}
                        {parent ? ` · in ${parent}` : ""}
                      </span>
                    </td>

                    {/*
                      Not compacted here, unlike the stat tiles: in a column of
                      aligned figures, "$13K" beside "$9,520" reads as a
                      different unit rather than a rounder one.
                    */}
                    <Cell
                      value={money(row.current.spend, currency)}
                      delta={delta(row.current.spend, row.previous?.spend)}
                      neutralDelta
                    />
                    <Cell
                      value={money(row.current.revenue, currency)}
                      delta={delta(row.current.revenue, row.previous?.revenue)}
                    />
                    <Cell
                      value={multiple(row.current.roas)}
                      delta={delta(row.current.roas, row.previous?.roas)}
                      highlight={
                        row.current.roas !== null && row.current.roas >= targetRoas
                      }
                    />
                    <Cell
                      value={count(row.current.purchases)}
                      delta={delta(row.current.purchases, row.previous?.purchases)}
                    />
                    <Cell
                      value={money(row.current.cpa, currency)}
                      delta={delta(row.current.cpa, row.previous?.cpa, true)}
                    />
                    {/*
                      Link clicks rather than total clicks: total includes likes,
                      shares and profile taps, so it overstates traffic.
                    */}
                    <Cell
                      value={count(row.current.linkClicks)}
                      delta={delta(row.current.linkClicks, row.previous?.linkClicks)}
                    />
                    <Cell
                      value={percent(row.current.linkCtr)}
                      delta={delta(row.current.linkCtr, row.previous?.linkCtr)}
                    />
                    <Cell
                      value={money(row.current.cpm, currency)}
                      delta={delta(row.current.cpm, row.previous?.cpm, true)}
                    />
                    <Cell
                      value={decimal(row.current.frequency)}
                      delta={delta(row.current.frequency, row.previous?.frequency, true)}
                    />
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

function Cell({
  value,
  delta: d,
  highlight = false,
  neutralDelta = false,
}: {
  value: string;
  delta: ReturnType<typeof delta>;
  /** Marks a value that clears the target. */
  highlight?: boolean;
  /** Spend going up is neither good nor bad — it is a decision, not a result. */
  neutralDelta?: boolean;
}) {
  const tone = neutralDelta ? "neutral" : d.tone;
  return (
    <td
      className="tabular"
      style={{ padding: "0.55rem 0.6rem", textAlign: "right", whiteSpace: "nowrap" }}
    >
      <div
        style={{
          color: "var(--text-primary)",
          fontWeight: highlight ? 700 : 400,
        }}
      >
        {value}
        {highlight && (
          <span
            title="At or above target ROAS"
            style={{ color: "var(--status-good)", marginLeft: "0.25rem" }}
          >
            ✓
          </span>
        )}
      </div>
      <div
        style={{
          fontSize: "0.6875rem",
          color:
            tone === "good" ? "var(--delta-good)"
            : tone === "bad" ? "var(--delta-bad)"
            : "var(--text-muted)",
        }}
      >
        {d.label}
      </div>
    </td>
  );
}
