"use client";

import { api } from "@/lib/basePath";
import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import type { AccountInfo } from "@/lib/types";

/**
 * Paste-and-save configuration, so nobody has to hand-edit a dotfile.
 *
 * The flow is deliberately: paste token → test it → pick an account from the
 * list the token actually returned → save. Validating before writing means a
 * bad paste is caught here rather than surfacing as a confusing API error on
 * the dashboard later.
 */

type Fields = Record<string, string>;

const EMPTY: Fields = {
  META_ACCESS_TOKEN: "",
  META_AD_ACCOUNT_ID: "",
  TARGET_ROAS: "",
  TARGET_CPA: "",
  REPORTING_TIMEZONE: "",
  REPORTING_CURRENCY: "",
  GOOGLE_SHEET_CSV_URL: "",
  MICROSOFT_SHEET_CSV_URL: "",
  ANTHROPIC_API_KEY: "",
  SHOPIFY_STORE_DOMAIN: "",
  SHOPIFY_ADMIN_TOKEN: "",
  SHOPIFY_EXCLUDE_TAGS: "",
  SHOPIFY_EXCLUDE_ABOVE: "",
  SHOPIFY_EXCLUDE_B2B: "",
};

export default function SetupPage() {
  const [fields, setFields] = useState<Fields>(EMPTY);
  const [present, setPresent] = useState<Record<string, boolean>>({});
  const [path, setPath] = useState("");
  const [accounts, setAccounts] = useState<AccountInfo[] | null>(null);
  const [testing, setTesting] = useState(false);
  const [saving, setSaving] = useState(false);
  const [testResult, setTestResult] = useState<
    { ok: boolean; message: string; hint?: string } | null
  >(null);
  const [saved, setSaved] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    try {
      const res = await fetch(api("/api/setup"), { cache: "no-store" });
      const body = await res.json();
      if (!res.ok) {
        setLoadError(body.error ?? "Could not read your settings.");
        return;
      }
      setFields({ ...EMPTY, ...body.values });
      setPresent(body.present ?? {});
      setPath(body.path ?? "");
    } catch (err) {
      setLoadError(err instanceof Error ? err.message : "Could not reach the server.");
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  function set(key: string, value: string) {
    setFields((f) => ({ ...f, [key]: value }));
    setSaved(false);
  }

  async function testToken() {
    setTesting(true);
    setTestResult(null);
    setAccounts(null);
    try {
      const res = await fetch(api("/api/setup"), {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ token: fields.META_ACCESS_TOKEN }),
      });
      const body = await res.json();
      if (body.ok) {
        setAccounts(body.accounts as AccountInfo[]);
        setTestResult({ ok: true, message: body.message });
      } else {
        setTestResult({ ok: false, message: body.error, hint: body.hint });
      }
    } catch (err) {
      setTestResult({
        ok: false,
        message: err instanceof Error ? err.message : "Request failed",
      });
    } finally {
      setTesting(false);
    }
  }

  async function save() {
    setSaving(true);
    setSaved(false);
    setTestResult(null);
    try {
      const res = await fetch(api("/api/setup"), {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(fields),
      });
      const body = await res.json();
      if (!res.ok) {
        setTestResult({ ok: false, message: body.error });
        return;
      }
      setSaved(true);
      await refresh();
    } catch (err) {
      setTestResult({
        ok: false,
        message: err instanceof Error ? err.message : "Request failed",
      });
    } finally {
      setSaving(false);
    }
  }

  const ready = present.META_ACCESS_TOKEN && present.META_AD_ACCOUNT_ID;

  if (loadError) {
    return (
      <main style={pageStyle}>
        <div className="card" style={{ padding: "1.5rem", borderLeft: "3px solid var(--status-critical)" }}>
          <h1 style={{ margin: "0 0 0.5rem", fontSize: "1.125rem" }}>
            Setup is not available
          </h1>
          <p className="secondary" style={{ margin: 0, lineHeight: 1.55 }}>
            {loadError}
          </p>
        </div>
      </main>
    );
  }

  return (
    <main style={pageStyle}>
      <header style={{ marginBottom: "1.5rem" }}>
        <h1 style={{ margin: "0 0 0.3rem", fontSize: "1.375rem", fontWeight: 600 }}>
          Setup
        </h1>
        <p className="secondary" style={{ margin: 0, fontSize: "0.875rem", lineHeight: 1.55 }}>
          Paste your keys below and press Save. They are written to{" "}
          <code style={codeStyle}>{path || ".env.local"}</code> on the server
          and never sent anywhere except to Meta, Shopify, and Anthropic when
          the dashboard loads data.
        </p>
      </header>

      {/* --- Step 1: the token ------------------------------------------- */}
      <Section
        step="1"
        title="Meta access token"
        required
        done={Boolean(present.META_ACCESS_TOKEN)}
      >
        <p style={helpStyle}>
          Get one at{" "}
          <a
            href="https://developers.facebook.com/tools/explorer"
            target="_blank"
            rel="noreferrer"
            style={linkStyle}
          >
            developers.facebook.com/tools/explorer
          </a>
          : pick your app, open the Permissions dropdown, tick{" "}
          <code style={codeStyle}>ads_read</code>, then press Generate Access
          Token and copy the long string.
        </p>
        <p style={helpStyle}>
          That token expires in about an hour. Once things are working, swap it
          for a permanent one from Business Settings → System Users → Generate
          New Token — same field, no other changes.
        </p>

        <textarea
          value={fields.META_ACCESS_TOKEN}
          onChange={(e) => set("META_ACCESS_TOKEN", e.target.value)}
          placeholder="EAAG..."
          rows={3}
          spellCheck={false}
          style={{ ...inputStyle, resize: "vertical", fontFamily: "ui-monospace, monospace" }}
        />

        <div style={{ display: "flex", gap: "0.5rem", marginTop: "0.6rem", flexWrap: "wrap" }}>
          <button className="control" onClick={testToken} disabled={testing}>
            {testing ? "Checking with Meta…" : "Test this token"}
          </button>
        </div>

        {testResult && (
          <div
            style={{
              marginTop: "0.75rem",
              padding: "0.7rem 0.85rem",
              borderRadius: 8,
              borderLeft: `3px solid ${testResult.ok ? "var(--status-good)" : "var(--status-critical)"}`,
              background: "var(--surface-sunken)",
              fontSize: "0.875rem",
              lineHeight: 1.5,
            }}
          >
            <div style={{ color: "var(--text-primary)" }}>
              {testResult.ok ? "✓ " : "✕ "}
              {testResult.message}
            </div>
            {testResult.hint && (
              <div className="secondary" style={{ marginTop: "0.3rem" }}>
                {testResult.hint}
              </div>
            )}
          </div>
        )}
      </Section>

      {/* --- Step 2: the account ------------------------------------------ */}
      <Section
        step="2"
        title="Ad account"
        required
        done={Boolean(present.META_AD_ACCOUNT_ID)}
      >
        {accounts && accounts.length > 0 ? (
          <>
            <p style={helpStyle}>
              These are the accounts your token can read. Pick one:
            </p>
            <div style={{ display: "grid", gap: "0.4rem" }}>
              {accounts.map((a) => {
                const selected = fields.META_AD_ACCOUNT_ID === a.id;
                return (
                  <button
                    key={a.id}
                    onClick={() => set("META_AD_ACCOUNT_ID", a.id)}
                    className="control"
                    style={{
                      textAlign: "left",
                      display: "flex",
                      justifyContent: "space-between",
                      alignItems: "center",
                      gap: "1rem",
                      borderColor: selected ? "var(--series-1)" : undefined,
                      background: selected ? "var(--surface-sunken)" : undefined,
                    }}
                  >
                    <span>
                      <strong style={{ fontWeight: 600 }}>{a.name}</strong>
                      <span className="muted" style={{ marginLeft: "0.5rem" }}>
                        {a.currency}
                      </span>
                      <br />
                      <span className="muted" style={{ fontSize: "0.75rem" }}>
                        {a.id}
                      </span>
                    </span>
                    <span style={{ color: "var(--status-good)", fontWeight: 700 }}>
                      {selected ? "✓" : ""}
                    </span>
                  </button>
                );
              })}
            </div>
          </>
        ) : (
          <p style={helpStyle}>
            Press <em>Test this token</em> above and your accounts will appear
            here to choose from. Or paste the ID directly if you know it.
          </p>
        )}

        <input
          value={fields.META_AD_ACCOUNT_ID}
          onChange={(e) => set("META_AD_ACCOUNT_ID", e.target.value)}
          placeholder="act_123456789012345"
          spellCheck={false}
          style={{ ...inputStyle, marginTop: "0.6rem", fontFamily: "ui-monospace, monospace" }}
        />
      </Section>

      {/* --- Step 3: targets ---------------------------------------------- */}
      <Section step="3" title="Your targets" done={Boolean(present.TARGET_ROAS)}>
        <p style={helpStyle}>
          Break-even ROAS is what the dashboard measures campaigns against. If
          you are not sure, use your gross margin: at 50% margin, break-even is
          about 2.0.
        </p>
        <div style={{ display: "flex", gap: "0.75rem", flexWrap: "wrap" }}>
          <label style={{ flex: "1 1 180px" }}>
            <span style={labelStyle}>Target ROAS</span>
            <input
              value={fields.TARGET_ROAS}
              onChange={(e) => set("TARGET_ROAS", e.target.value)}
              placeholder="2.0"
              inputMode="decimal"
              style={inputStyle}
            />
          </label>
          <label style={{ flex: "1 1 180px" }}>
            <span style={labelStyle}>Target cost per purchase (optional)</span>
            <input
              value={fields.TARGET_CPA}
              onChange={(e) => set("TARGET_CPA", e.target.value)}
              placeholder="e.g. 35"
              inputMode="decimal"
              style={inputStyle}
            />
          </label>
        </div>

        <p style={{ ...helpStyle, marginTop: "1.1rem" }}>
          The combined view spans three platforms, so &ldquo;yesterday&rdquo; and
          the currency label have to be stated once rather than taken from any
          one account. Both are optional &mdash; they default to Pacific time and
          USD, and the Meta account&rsquo;s own currency wins wherever it is
          connected.
        </p>
        <div style={{ display: "flex", gap: "0.75rem", flexWrap: "wrap" }}>
          <label style={{ flex: "1 1 180px" }}>
            <span style={labelStyle}>Reporting timezone</span>
            <input
              value={fields.REPORTING_TIMEZONE}
              onChange={(e) => set("REPORTING_TIMEZONE", e.target.value)}
              placeholder="America/Los_Angeles"
              style={inputStyle}
            />
          </label>
          <label style={{ flex: "1 1 180px" }}>
            <span style={labelStyle}>Reporting currency</span>
            <input
              value={fields.REPORTING_CURRENCY}
              onChange={(e) => set("REPORTING_CURRENCY", e.target.value)}
              placeholder="USD"
              style={inputStyle}
            />
          </label>
        </div>

        <p style={{ ...helpStyle, marginTop: "1.1rem" }}>
          Google and Microsoft have no API connection here, and getting one is
          disproportionate: Google&rsquo;s API needs a developer token at Basic
          Access, which means an application, a design document, a public
          business domain and a multi-day review &mdash; to read figures you
          already own.
          <br />
          <br />
          Instead, run <code>scripts/google-ads-export.js</code> inside Google
          Ads on a daily schedule. It writes your campaign figures to a Google
          Sheet; publish that sheet as CSV (File &gt; Share &gt; Publish to web
          &gt; Comma-separated values) and paste the URL here. The dashboard
          re-reads it on its own. Leave blank to keep pasting reports by hand.
          <br />
          <br />
          A published sheet needs no sign-in, so treat its URL as a secret:
          anyone holding it can read the figures.
        </p>
        <label style={{ display: "block", marginBottom: "0.9rem" }}>
          <span style={labelStyle}>Google Ads &mdash; published sheet CSV URL</span>
          <input
            value={fields.GOOGLE_SHEET_CSV_URL}
            onChange={(e) => set("GOOGLE_SHEET_CSV_URL", e.target.value)}
            placeholder="https://docs.google.com/spreadsheets/d/e/…/pub?gid=0&single=true&output=csv"
            spellCheck={false}
            style={inputStyle}
          />
        </label>
        <label style={{ display: "block" }}>
          <span style={labelStyle}>
            Microsoft Ads &mdash; published sheet CSV URL (optional)
          </span>
          <input
            value={fields.MICROSOFT_SHEET_CSV_URL}
            onChange={(e) => set("MICROSOFT_SHEET_CSV_URL", e.target.value)}
            placeholder="Same idea, if you keep Microsoft figures in a sheet"
            spellCheck={false}
            style={inputStyle}
          />
        </label>
      </Section>

      {/* --- Step 4: optional extras -------------------------------------- */}
      <Section
        step="4"
        title="Optional extras"
        done={Boolean(present.ANTHROPIC_API_KEY || present.SHOPIFY_ADMIN_TOKEN)}
      >
        <p style={helpStyle}>
          Everything above already gives you the full dashboard. These two add
          the written analysis and the blended-ROAS cross-check.
        </p>

        <label style={{ display: "block", marginBottom: "0.9rem" }}>
          <span style={labelStyle}>
            Anthropic API key — enables the written analysis
          </span>
          <input
            value={fields.ANTHROPIC_API_KEY}
            onChange={(e) => set("ANTHROPIC_API_KEY", e.target.value)}
            placeholder="sk-ant-..."
            spellCheck={false}
            style={{ ...inputStyle, fontFamily: "ui-monospace, monospace" }}
          />
          <span style={{ ...helpStyle, display: "block", marginTop: "0.3rem" }}>
            From{" "}
            <a
              href="https://console.anthropic.com/settings/keys"
              target="_blank"
              rel="noreferrer"
              style={linkStyle}
            >
              console.anthropic.com
            </a>
            . Costs a few cents per analysis, and only runs when you click the
            button.
          </span>
        </label>

        <div style={{ display: "flex", gap: "0.75rem", flexWrap: "wrap" }}>
          <label style={{ flex: "1 1 220px" }}>
            <span style={labelStyle}>Shopify store domain</span>
            <input
              value={fields.SHOPIFY_STORE_DOMAIN}
              onChange={(e) => set("SHOPIFY_STORE_DOMAIN", e.target.value)}
              placeholder="your-store.myshopify.com"
              spellCheck={false}
              style={inputStyle}
            />
          </label>
          <label style={{ flex: "1 1 220px" }}>
            <span style={labelStyle}>Shopify admin API token</span>
            <input
              value={fields.SHOPIFY_ADMIN_TOKEN}
              onChange={(e) => set("SHOPIFY_ADMIN_TOKEN", e.target.value)}
              placeholder="shpat_..."
              spellCheck={false}
              style={{ ...inputStyle, fontFamily: "ui-monospace, monospace" }}
            />
          </label>
        </div>
        <span style={{ ...helpStyle, display: "block", marginTop: "0.3rem" }}>
          Shopify admin → Settings → Apps and sales channels → Develop apps →
          Create an app → Admin API scopes → tick <code style={codeStyle}>read_orders</code>{" "}
          → Install.
        </span>
      </Section>

      {/* --- Step 5: wholesale exclusions --------------------------------- */}
      <Section
        step="5"
        title="Exclude wholesale orders"
        done={Boolean(present.SHOPIFY_EXCLUDE_TAGS || present.SHOPIFY_EXCLUDE_ABOVE)}
      >
        <p style={helpStyle}>
          A few large wholesale orders will swamp a month of retail. One £3,000
          B2B order against £2,000 of ad spend adds 1.5x to blended ROAS by
          itself, which makes the number track invoicing rather than ad
          performance. These rules keep those orders out of the Shopify figures.
        </p>

        <label
          style={{
            display: "flex",
            alignItems: "flex-start",
            gap: "0.5rem",
            marginBottom: "0.9rem",
          }}
        >
          <input
            type="checkbox"
            checked={fields.SHOPIFY_EXCLUDE_DRAFT_ORDERS !== "false"}
            onChange={(e) =>
              set("SHOPIFY_EXCLUDE_DRAFT_ORDERS", e.target.checked ? "true" : "false")
            }
            style={{ marginTop: "0.2rem" }}
          />
          <span style={{ fontSize: "0.875rem", color: "var(--text-primary)" }}>
            Exclude every order created from a draft
            <span style={{ ...helpStyle, display: "block", margin: "0.2rem 0 0" }}>
              On by default, and the most reliable rule of the four — a draft
              order was typed into the admin rather than placed through your
              storefront, so it is never demand an ad produced. Catches wholesale
              even when nobody remembered to tag it.
            </span>
          </span>
        </label>

        <label style={{ display: "block", marginBottom: "0.9rem" }}>
          <span style={labelStyle}>
            Exclude orders with these tags (comma-separated)
          </span>
          <input
            value={fields.SHOPIFY_EXCLUDE_TAGS}
            onChange={(e) => set("SHOPIFY_EXCLUDE_TAGS", e.target.value)}
            placeholder="wholesale, b2b, trade"
            spellCheck={false}
            style={inputStyle}
          />
          <span style={{ ...helpStyle, display: "block", marginTop: "0.3rem" }}>
            Matches the tags on the order itself, case-insensitively. The most
            reliable rule, if you tag wholesale orders in Shopify.
          </span>
        </label>

        <label style={{ display: "block", marginBottom: "0.9rem" }}>
          <span style={labelStyle}>
            Also exclude any order at or above this value (optional)
          </span>
          <input
            value={fields.SHOPIFY_EXCLUDE_ABOVE}
            onChange={(e) => set("SHOPIFY_EXCLUDE_ABOVE", e.target.value)}
            placeholder="e.g. 1000"
            inputMode="decimal"
            style={inputStyle}
          />
          <span style={{ ...helpStyle, display: "block", marginTop: "0.3rem" }}>
            A blunt catch-all for untagged wholesale. Set it well above your
            largest genuine retail order, or you will quietly delete your best
            customers from the numbers.
          </span>
        </label>

        <label style={{ display: "flex", alignItems: "flex-start", gap: "0.5rem" }}>
          <input
            type="checkbox"
            checked={fields.SHOPIFY_EXCLUDE_B2B !== "false"}
            onChange={(e) =>
              set("SHOPIFY_EXCLUDE_B2B", e.target.checked ? "true" : "false")
            }
            style={{ marginTop: "0.2rem" }}
          />
          <span style={{ fontSize: "0.875rem", color: "var(--text-primary)" }}>
            Exclude orders placed through a Shopify B2B company account
            <span style={{ ...helpStyle, display: "block", margin: "0.2rem 0 0" }}>
              On by default. A company-account order is wholesale by definition.
            </span>
          </span>
        </label>

        <p
          style={{
            ...helpStyle,
            margin: "0.9rem 0 0",
            paddingTop: "0.7rem",
            borderTop: "1px solid var(--border)",
          }}
        >
          <strong style={{ color: "var(--text-primary)", fontWeight: 600 }}>
            One limitation worth knowing.
          </strong>{" "}
          These rules clean up <em>blended</em> ROAS, because the dashboard
          fetches your orders and can filter them. They cannot clean up
          Meta&apos;s own attributed revenue — its API returns a single revenue
          total with no order-level detail, so there is nothing to subtract
          against.
        </p>
        <p style={{ ...helpStyle, margin: "0.6rem 0 0" }}>
          If you use draft orders and Meta still appears to be counting them,
          the events are not coming from the browser pixel — a draft order never
          reaches your storefront checkout, so the pixel cannot fire. They are
          being sent server-side, almost always by Shopify&apos;s{" "}
          <strong style={{ color: "var(--text-primary)", fontWeight: 600 }}>
            Facebook &amp; Instagram
          </strong>{" "}
          sales channel, which forwards every order through the Conversions API
          regardless of how it was created. Shopify admin → Sales channels →
          Facebook &amp; Instagram → Settings → Data sharing.
        </p>
      </Section>

      {/* --- Save --------------------------------------------------------- */}
      <div
        style={{
          position: "sticky",
          bottom: 0,
          background: "var(--page)",
          borderTop: "1px solid var(--border)",
          padding: "1rem 0",
          display: "flex",
          alignItems: "center",
          gap: "1rem",
          flexWrap: "wrap",
        }}
      >
        <button
          className="control control-primary"
          onClick={save}
          disabled={saving}
          style={{ padding: "0.55rem 1.1rem", fontWeight: 600 }}
        >
          {saving ? "Saving…" : "Save settings"}
        </button>

        {saved && (
          <span style={{ color: "var(--delta-good)", fontSize: "0.875rem" }}>
            ✓ Saved. No restart needed.
          </span>
        )}

        <Link
          href="/"
          className="control"
          style={{ textDecoration: "none", color: "var(--text-primary)" }}
        >
          {ready ? "Open the dashboard →" : "Back to dashboard"}
        </Link>

        {!ready && (
          <span className="muted" style={{ fontSize: "0.8125rem" }}>
            A token and an ad account are needed before real data will load.
          </span>
        )}
      </div>
    </main>
  );
}

/* --- Layout pieces -------------------------------------------------------- */

function Section({
  step,
  title,
  required = false,
  done = false,
  children,
}: {
  step: string;
  title: string;
  required?: boolean;
  done?: boolean;
  children: React.ReactNode;
}) {
  return (
    <section className="card" style={{ padding: "1.25rem", marginBottom: "1rem" }}>
      <div
        style={{
          display: "flex",
          alignItems: "center",
          gap: "0.6rem",
          marginBottom: "0.65rem",
        }}
      >
        {/* The tick is paired with text, so completion never rests on colour. */}
        <span
          aria-hidden="true"
          style={{
            width: 22,
            height: 22,
            borderRadius: "50%",
            display: "grid",
            placeItems: "center",
            fontSize: "0.75rem",
            fontWeight: 700,
            flexShrink: 0,
            background: done ? "var(--status-good)" : "var(--surface-sunken)",
            color: done ? "#ffffff" : "var(--text-secondary)",
            border: done ? "none" : "1px solid var(--border)",
          }}
        >
          {done ? "✓" : step}
        </span>
        <h2 style={{ margin: 0, fontSize: "1rem", fontWeight: 600 }}>{title}</h2>
        <span
          className="muted"
          style={{
            fontSize: "0.6875rem",
            textTransform: "uppercase",
            letterSpacing: "0.06em",
            fontWeight: 600,
          }}
        >
          {required ? "Required" : "Optional"}
          {done ? " · saved" : ""}
        </span>
      </div>
      {children}
    </section>
  );
}

const pageStyle: React.CSSProperties = {
  maxWidth: 720,
  margin: "0 auto",
  padding: "2rem 1.25rem 1rem",
};

const inputStyle: React.CSSProperties = {
  width: "100%",
  padding: "0.5rem 0.65rem",
  borderRadius: 8,
  border: "1px solid var(--border)",
  background: "var(--surface)",
  color: "var(--text-primary)",
  fontSize: "0.875rem",
  fontFamily: "inherit",
};

const labelStyle: React.CSSProperties = {
  display: "block",
  fontSize: "0.8125rem",
  color: "var(--text-secondary)",
  marginBottom: "0.3rem",
};

const helpStyle: React.CSSProperties = {
  margin: "0 0 0.7rem",
  fontSize: "0.8125rem",
  color: "var(--text-secondary)",
  lineHeight: 1.55,
};

const codeStyle: React.CSSProperties = {
  background: "var(--surface-sunken)",
  padding: "0.1rem 0.3rem",
  borderRadius: 4,
  fontSize: "0.8125rem",
  fontFamily: "ui-monospace, monospace",
};

const linkStyle: React.CSSProperties = {
  color: "var(--series-1)",
};
