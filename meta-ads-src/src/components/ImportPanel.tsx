"use client";

import { useState } from "react";
import { api } from "@/lib/basePath";
import { PLATFORM_META, type Platform } from "@/lib/platforms";

/**
 * Importing a Google or Microsoft report by paste.
 *
 * Paste rather than file upload, deliberately: the flow is "download the
 * report, open it, select all, paste here", which needs no file picker and
 * works identically from a phone. Re-importing an overlapping period is safe —
 * rows are keyed by platform, date and campaign, so a corrected export
 * overwrites rather than doubling the spend.
 */

const INSTRUCTIONS: Record<Platform, { where: string; columns: string }> = {
  meta: { where: "", columns: "" },
  google: {
    where:
      "Google Ads → Reports → Predefined reports → Campaign, then set the date range, add a Day segment, and download as CSV.",
    columns: "Day, Campaign, Cost, Impressions, Clicks, Conversions, Conv. value",
  },
  microsoft: {
    where:
      "Microsoft Advertising → Reports → Campaign performance, set the range, choose Daily for the time breakdown, and download as CSV.",
    columns:
      "Date (or Gregorian date), Campaign, Spend, Impressions, Clicks, Conversions, Revenue",
  },
};

interface Summary {
  rows: number;
  since: string;
  until: string;
  spend: number;
}

export function ImportPanel({
  platform,
  summary,
  onImported,
}: {
  platform: Exclude<Platform, "meta">;
  summary?: Summary;
  onImported: () => void;
}) {
  const [csv, setCsv] = useState("");
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<{
    ok: boolean;
    message: string;
    warnings?: string[];
  } | null>(null);

  const meta = PLATFORM_META[platform];
  const guide = INSTRUCTIONS[platform];

  async function submit() {
    if (!csv.trim() || busy) return;
    setBusy(true);
    setResult(null);

    try {
      const res = await fetch(api("/api/platform-data"), {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ platform, csv }),
      });
      const body = await res.json();

      if (!res.ok) {
        setResult({ ok: false, message: body.error ?? "Import failed." });
        return;
      }

      setResult({
        ok: true,
        message: body.message ?? "Imported.",
        warnings: body.warnings,
      });
      setCsv("");
      onImported();
    } catch (err) {
      setResult({
        ok: false,
        message:
          err instanceof Error ? err.message : "Could not reach the server.",
      });
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="card" style={{ padding: "1.25rem" }}>
      <h2 style={{ margin: "0 0 0.35rem", fontSize: "1rem", fontWeight: 600 }}>
        Import {meta.label} data
      </h2>

      <p
        className="muted"
        style={{ margin: "0 0 1rem", fontSize: "0.8125rem", lineHeight: 1.5 }}
      >
        {meta.label} has no API connection yet, so its figures are imported from
        a report export. {guide.where}
        <br />
        Wanted columns: <code>{guide.columns}</code>. Extra columns are ignored,
        and re-importing the same days replaces them rather than adding to them.
      </p>

      {summary && (
        <p
          className="secondary"
          style={{ margin: "0 0 1rem", fontSize: "0.8125rem" }}
        >
          Currently stored: {summary.rows.toLocaleString()} rows covering{" "}
          {summary.since} to {summary.until}.
        </p>
      )}

      <textarea
        className="control"
        value={csv}
        onChange={(e) => setCsv(e.target.value)}
        placeholder={"Day,Campaign,Cost,Impressions,Clicks,Conversions,Conv. value\n2026-08-01,Brand — Search,42.18,3104,188,4,612.40"}
        rows={8}
        spellCheck={false}
        aria-label={`${meta.label} report CSV`}
        style={{
          width: "100%",
          fontFamily: "ui-monospace, SFMono-Regular, Menlo, monospace",
          fontSize: "0.8125rem",
          lineHeight: 1.5,
          resize: "vertical",
        }}
      />

      <div
        style={{
          display: "flex",
          gap: "0.75rem",
          alignItems: "center",
          marginTop: "0.75rem",
          flexWrap: "wrap",
        }}
      >
        <button className="control" onClick={submit} disabled={busy || !csv.trim()}>
          {busy ? "Importing…" : "Import"}
        </button>
        {csv.trim() && !busy && (
          <span className="muted" style={{ fontSize: "0.8125rem" }}>
            {csv.trim().split(/\r?\n/).length} lines pasted
          </span>
        )}
      </div>

      {result && (
        <div
          style={{
            marginTop: "0.9rem",
            padding: "0.75rem 0.9rem",
            borderRadius: 6,
            background: "var(--surface-sunken)",
            borderLeft: `3px solid ${
              result.ok ? "var(--status-good)" : "var(--status-critical)"
            }`,
            fontSize: "0.875rem",
          }}
        >
          <div style={{ color: "var(--text-primary)" }}>{result.message}</div>
          {result.warnings?.map((w) => (
            <div
              key={w}
              className="secondary"
              style={{ marginTop: "0.4rem", fontSize: "0.8125rem" }}
            >
              {w}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
