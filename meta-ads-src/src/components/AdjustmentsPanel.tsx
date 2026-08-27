"use client";

import { api } from "@/lib/basePath";
import { useState } from "react";
import { money } from "@/lib/format";
import type { Adjustment, AdjustmentCandidate, DashboardData, Level } from "@/lib/types";

/**
 * Records revenue Meta attributed to an ad that was not really ad-driven.
 *
 * The flow is: enter what you know (amount and date) → the app searches that
 * day for the ad most likely to be carrying the order → you confirm. It never
 * picks for you, because Meta reports no order-level detail and a wrong guess
 * would take revenue off a genuinely good ad.
 */

export function AdjustmentsPanel({
  data,
  onChanged,
}: {
  data: DashboardData;
  onChanged: () => void;
}) {
  const [open, setOpen] = useState(false);
  const [date, setDate] = useState(data.range.until);
  const [amount, setAmount] = useState("");
  const [note, setNote] = useState("");
  const [level, setLevel] = useState<Exclude<Level, "account">>("ad");

  const [candidates, setCandidates] = useState<AdjustmentCandidate[] | null>(null);
  const [searchMessage, setSearchMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const currency = data.account.currency;
  const parsedAmount = Number(amount);
  const canSearch = Number.isFinite(parsedAmount) && parsedAmount > 0 && Boolean(date);

  async function search() {
    setBusy(true);
    setError(null);
    setCandidates(null);
    setSearchMessage(null);
    try {
      const res = await fetch(api("/api/adjustments"), {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ date, amount: parsedAmount, level }),
      });
      const body = await res.json();
      if (body.error) {
        setError(body.error);
        return;
      }
      setCandidates(body.candidates ?? []);
      setSearchMessage(body.message ?? null);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Search failed");
    } finally {
      setBusy(false);
    }
  }

  async function save(entity: AdjustmentCandidate | null) {
    setBusy(true);
    setError(null);
    try {
      const res = await fetch(api("/api/adjustments"), {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          date,
          amount: parsedAmount,
          purchases: 1,
          level: entity ? entity.level : "account",
          entityId: entity?.id,
          entityName: entity?.name,
          note: note.trim() || undefined,
        }),
      });
      const body = await res.json();
      if (!res.ok) {
        setError(body.error);
        return;
      }
      setAmount("");
      setNote("");
      setCandidates(null);
      setSearchMessage(null);
      setOpen(false);
      onChanged();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Save failed");
    } finally {
      setBusy(false);
    }
  }

  async function remove(id: string) {
    setBusy(true);
    try {
      await fetch(api(`/api/adjustments?id=${encodeURIComponent(id)}`), {
        method: "DELETE",
      });
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="card" style={{ padding: "1.25rem" }}>
      <div
        style={{
          display: "flex",
          alignItems: "flex-start",
          justifyContent: "space-between",
          gap: "1rem",
          flexWrap: "wrap",
        }}
      >
        <div>
          <h2 style={{ margin: "0 0 0.25rem", fontSize: "1rem", fontWeight: 600 }}>
            Manual revenue deductions
          </h2>
          <p className="muted" style={{ margin: 0, fontSize: "0.8125rem", lineHeight: 1.5 }}>
            {data.adjustments.length > 0
              ? `${money(data.adjustedRevenue, currency)} removed from every figure on this page across ${data.adjustments.length} deduction${data.adjustments.length === 1 ? "" : "s"} — totals, charts, tables and findings.`
              : "For wholesale orders Meta counted that you cannot filter at source."}
            {data.comparisonAdjustments.length > 0 && (
              <>
                {" "}
                A further {data.comparisonAdjustments.length} deduction
                {data.comparisonAdjustments.length === 1 ? " falls" : "s fall"} in
                the {data.compareLabel} and {data.comparisonAdjustments.length === 1 ? "has" : "have"}{" "}
                been applied there too, so the change figures compare like with
                like.
              </>
            )}
          </p>
        </div>
        <button
          className="control no-print"
          onClick={() => setOpen((o) => !o)}
          disabled={busy}
        >
          {open ? "Cancel" : "Add a deduction"}
        </button>
      </div>

      {open && (
        <div
          style={{
            marginTop: "1rem",
            padding: "1rem",
            background: "var(--surface-sunken)",
            borderRadius: 8,
          }}
        >
          <div style={{ display: "flex", gap: "0.75rem", flexWrap: "wrap" }}>
            <label style={{ flex: "1 1 150px" }}>
              <span style={labelStyle}>Order date</span>
              <input
                type="date"
                value={date}
                min={data.range.since}
                max={data.range.until}
                onChange={(e) => setDate(e.target.value)}
                style={inputStyle}
              />
            </label>
            <label style={{ flex: "1 1 150px" }}>
              <span style={labelStyle}>Order value ({currency})</span>
              <input
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
                placeholder="3200"
                inputMode="decimal"
                style={inputStyle}
              />
            </label>
            <label style={{ flex: "1 1 130px" }}>
              <span style={labelStyle}>Search at</span>
              <select
                value={level}
                onChange={(e) => setLevel(e.target.value as Exclude<Level, "account">)}
                style={inputStyle}
              >
                <option value="ad">Ad</option>
                <option value="adset">Ad set</option>
                <option value="campaign">Campaign</option>
              </select>
            </label>
          </div>

          <label style={{ display: "block", marginTop: "0.75rem" }}>
            <span style={labelStyle}>Note (optional)</span>
            <input
              value={note}
              onChange={(e) => setNote(e.target.value)}
              placeholder="Draft order #1042 — Acme Trading"
              style={inputStyle}
            />
          </label>

          <div style={{ display: "flex", gap: "0.5rem", marginTop: "0.85rem", flexWrap: "wrap" }}>
            <button
              className="control control-primary"
              onClick={search}
              disabled={!canSearch || busy}
            >
              {busy ? "Searching…" : "Find which ad has it"}
            </button>
            <button
              className="control"
              onClick={() => save(null)}
              disabled={!canSearch || busy}
              title="Removes it from account totals without assigning it to any campaign"
            >
              Just take it off the account total
            </button>
          </div>

          {error && (
            <p style={{ margin: "0.75rem 0 0", fontSize: "0.875rem", color: "var(--delta-bad)" }}>
              {error}
            </p>
          )}

          {searchMessage && (
            <p
              className="secondary"
              style={{ margin: "0.75rem 0 0", fontSize: "0.875rem", lineHeight: 1.5 }}
            >
              {searchMessage}
            </p>
          )}

          {candidates && candidates.length > 0 && (
            <div style={{ marginTop: "1rem" }}>
              <p
                className="secondary"
                style={{ margin: "0 0 0.5rem", fontSize: "0.8125rem", lineHeight: 1.5 }}
              >
                Ranked by how much removing {money(parsedAmount, currency)} makes
                each one look normal for that day. Pick the match — the first is
                usually right, but check the evidence.
              </p>
              <div style={{ display: "grid", gap: "0.5rem" }}>
                {candidates.map((c, i) => (
                  <button
                    key={c.id}
                    className="control"
                    onClick={() => save(c)}
                    disabled={busy}
                    style={{
                      textAlign: "left",
                      padding: "0.65rem 0.75rem",
                      borderColor: i === 0 ? "var(--series-1)" : undefined,
                    }}
                  >
                    <div
                      style={{
                        display: "flex",
                        justifyContent: "space-between",
                        gap: "0.75rem",
                        alignItems: "baseline",
                      }}
                    >
                      <strong style={{ fontWeight: 600 }}>{c.name}</strong>
                      {i === 0 && (
                        <span
                          style={{
                            fontSize: "0.6875rem",
                            fontWeight: 700,
                            textTransform: "uppercase",
                            letterSpacing: "0.06em",
                            color: "var(--series-1)",
                          }}
                        >
                          Best match
                        </span>
                      )}
                    </div>
                    <div
                      className="muted"
                      style={{ fontSize: "0.75rem", marginTop: "0.25rem", lineHeight: 1.45 }}
                    >
                      {money(c.revenue, currency)} from {c.purchases} purchase
                      {c.purchases === 1 ? "" : "s"} that day. {c.evidence}
                    </div>
                  </button>
                ))}
              </div>
            </div>
          )}
        </div>
      )}

      {data.adjustments.length > 0 && (
        <ul
          style={{
            listStyle: "none",
            margin: "1rem 0 0",
            padding: 0,
            display: "grid",
            gap: "0.4rem",
          }}
        >
          {data.adjustments.map((a: Adjustment) => (
            <li
              key={a.id}
              style={{
                display: "flex",
                alignItems: "center",
                gap: "0.75rem",
                padding: "0.5rem 0.65rem",
                background: "var(--surface-sunken)",
                borderRadius: 6,
                fontSize: "0.8125rem",
              }}
            >
              <span className="tabular" style={{ fontWeight: 600 }}>
                −{money(a.amount, currency)}
              </span>
              <span className="muted">{a.date}</span>
              <span
                className="secondary"
                style={{
                  overflow: "hidden",
                  textOverflow: "ellipsis",
                  whiteSpace: "nowrap",
                }}
              >
                {a.entityName ?? "Account total"}
                {a.note ? ` · ${a.note}` : ""}
              </span>
              <button
                className="control no-print"
                onClick={() => remove(a.id)}
                disabled={busy}
                style={{ marginLeft: "auto", padding: "0.15rem 0.5rem" }}
                aria-label={`Remove deduction of ${a.amount} on ${a.date}`}
              >
                Undo
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

const labelStyle: React.CSSProperties = {
  display: "block",
  fontSize: "0.8125rem",
  color: "var(--text-secondary)",
  marginBottom: "0.3rem",
};

const inputStyle: React.CSSProperties = {
  width: "100%",
  padding: "0.45rem 0.6rem",
  borderRadius: 8,
  border: "1px solid var(--border)",
  background: "var(--surface)",
  color: "var(--text-primary)",
  fontSize: "0.875rem",
  fontFamily: "inherit",
};
