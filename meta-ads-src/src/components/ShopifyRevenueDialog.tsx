"use client";

import { useEffect, useRef } from "react";
import { count, money } from "@/lib/format";
import type { DateRange, ShopifyRevenue } from "@/lib/types";

/**
 * What the Shopify figure is made of, opened from the revenue tile.
 *
 * The tile can only carry one line, and the number it shows is the one
 * everything else divides by — so the moment it looks wrong there is nowhere
 * to go and check. This is that place: gross takings, every order the rules
 * removed and why, and the caveats that no exclusion rule covers.
 */
export function ShopifyRevenueDialog({
  shopify,
  range,
  onClose,
}: {
  shopify: ShopifyRevenue;
  range: DateRange;
  onClose: () => void;
}) {
  const closeRef = useRef<HTMLButtonElement>(null);

  // Escape closes, and focus starts inside the dialog rather than back on the
  // page behind it.
  useEffect(() => {
    closeRef.current?.focus();
    function onKey(e: KeyboardEvent) {
      if (e.key === "Escape") onClose();
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  const c = shopify.currency;
  const excludedShare =
    shopify.grossRevenue > 0 ? shopify.excludedRevenue / shopify.grossRevenue : 0;

  return (
    <div
      onClick={onClose}
      style={{
        position: "fixed",
        inset: 0,
        zIndex: 50,
        background: "rgba(0, 0, 0, 0.55)",
        display: "flex",
        alignItems: "flex-start",
        justifyContent: "center",
        padding: "2rem 1rem",
        overflowY: "auto",
      }}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-label="How the Shopify revenue figure is built"
        className="card"
        // Clicks inside must not reach the backdrop's close handler.
        onClick={(e) => e.stopPropagation()}
        style={{ maxWidth: 620, width: "100%", padding: "1.25rem 1.4rem 1.4rem" }}
      >
        <div
          style={{
            display: "flex",
            alignItems: "flex-start",
            justifyContent: "space-between",
            gap: "1rem",
          }}
        >
          <div>
            <h2 style={{ margin: 0, fontSize: "1.0625rem", fontWeight: 600 }}>
              Shopify revenue
            </h2>
            <p className="muted" style={{ margin: "0.2rem 0 0", fontSize: "0.8125rem" }}>
              Orders created {range.since} to {range.until}, in {c}.
            </p>
          </div>
          <button ref={closeRef} className="control" onClick={onClose}>
            Close
          </button>
        </div>

        {/* The arithmetic, in the order it happens. */}
        <table style={{ width: "100%", borderCollapse: "collapse", marginTop: "1.1rem" }}>
          <tbody>
            <Row
              label="All orders in the period"
              detail={`${count(shopify.grossOrderCount)} orders`}
              value={money(shopify.grossRevenue, c)}
            />

            {shopify.exclusionReasons.length === 0 ? (
              <tr>
                <td colSpan={2} style={{ ...cell, color: "var(--text-secondary)" }}>
                  Nothing was excluded in this period.
                </td>
              </tr>
            ) : (
              shopify.exclusionReasons.map((r) => (
                <Row
                  key={r.reason}
                  label={r.reason}
                  detail={`${count(r.orders)} order${r.orders === 1 ? "" : "s"}`}
                  value={`− ${money(r.revenue, c)}`}
                  muted
                />
              ))
            )}

            <Row
              label="Counted as online retail revenue"
              detail={`${count(shopify.orderCount)} orders`}
              value={money(shopify.totalRevenue, c)}
              strong
            />
          </tbody>
        </table>

        {shopify.excludedOrders > 0 && (
          <p className="secondary" style={{ margin: "0.9rem 0 0", fontSize: "0.8125rem" }}>
            {(excludedShare * 100).toFixed(1)}% of the period&apos;s takings were
            excluded. Each order is counted once, under the first rule it matched.
          </p>
        )}

        <Section title="Rules in force">
          {shopify.activeRules.length > 0 ? (
            <ul style={listStyle}>
              {shopify.activeRules.map((r) => (
                <li key={r}>{r}</li>
              ))}
            </ul>
          ) : (
            <p style={paraStyle}>
              No exclusion rules are switched on, so every order counts. Set them
              up on the setup page.
            </p>
          )}
        </Section>

        <Section title="What this figure is">
          <ul style={listStyle}>
            <li>
              Every remaining order counts, whatever brought it in — ads, organic
              search, email, or a repeat customer. Blended ROAS asks whether the
              shop as a whole paid for the ad bill, not what ads produced.
            </li>
            <li>
              Order totals are current totals, so refunds and edits are already
              reflected. They include shipping and tax.
            </li>
            <li>Test orders are never counted.</li>
            <li>
              Orders are dated by when they were created, in the shop&apos;s own
              timezone, so they line up with the day&apos;s ad spend.
            </li>
          </ul>
        </Section>

        <Section title="What it does not cover">
          <p style={paraStyle}>
            These rules clean up the blended figure only. Meta&apos;s own
            attributed revenue arrives as one total with no order detail, so a
            wholesale or in-person order that fired the pixel is still inside it —
            that is what the manual adjustments are for. A cancelled order also
            still counts unless the money was actually refunded.
          </p>
        </Section>
      </div>
    </div>
  );
}

function Row({
  label,
  detail,
  value,
  muted = false,
  strong = false,
}: {
  label: string;
  detail: string;
  value: string;
  muted?: boolean;
  strong?: boolean;
}) {
  return (
    <tr style={strong ? { borderTop: "1px solid var(--border)" } : undefined}>
      <td style={{ ...cell, color: muted ? "var(--text-secondary)" : "var(--text-primary)" }}>
        <span style={{ fontWeight: strong ? 600 : 400 }}>{label}</span>
        <span className="muted" style={{ display: "block", fontSize: "0.75rem" }}>
          {detail}
        </span>
      </td>
      <td
        className="tabular"
        style={{
          ...cell,
          textAlign: "right",
          whiteSpace: "nowrap",
          fontWeight: strong ? 600 : 400,
          color: muted ? "var(--text-secondary)" : "var(--text-primary)",
        }}
      >
        {value}
      </td>
    </tr>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div style={{ marginTop: "1.1rem" }}>
      <h3
        style={{
          margin: "0 0 0.35rem",
          fontSize: "0.75rem",
          fontWeight: 700,
          textTransform: "uppercase",
          letterSpacing: "0.06em",
          color: "var(--text-secondary)",
        }}
      >
        {title}
      </h3>
      {children}
    </div>
  );
}

const cell: React.CSSProperties = {
  padding: "0.4rem 0",
  fontSize: "0.875rem",
  verticalAlign: "top",
};

const listStyle: React.CSSProperties = {
  margin: 0,
  paddingLeft: "1.1rem",
  fontSize: "0.8125rem",
  lineHeight: 1.6,
  color: "var(--text-secondary)",
};

const paraStyle: React.CSSProperties = {
  margin: 0,
  fontSize: "0.8125rem",
  lineHeight: 1.6,
  color: "var(--text-secondary)",
};
