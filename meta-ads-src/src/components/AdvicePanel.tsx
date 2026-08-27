"use client";

import { api } from "@/lib/basePath";
import { useState } from "react";
import type { Advice, DashboardData } from "@/lib/types";

const PRIORITY: Record<
  Advice["actions"][number]["priority"],
  { label: string; color: string }
> = {
  now: { label: "Do now", color: "var(--status-critical)" },
  this_week: { label: "This week", color: "var(--status-warning)" },
  monitor: { label: "Monitor", color: "var(--text-muted)" },
};

export function AdvicePanel({ data }: { data: DashboardData }) {
  const [advice, setAdvice] = useState<Advice | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function generate() {
    setLoading(true);
    setError(null);
    try {
      const res = await fetch(api("/api/advice"), {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(data),
      });
      const body = await res.json();
      if (!res.ok) {
        setError(body.hint ? `${body.error} ${body.hint}` : body.error);
        return;
      }
      setAdvice(body.advice as Advice);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Request failed");
    } finally {
      setLoading(false);
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
            Written analysis
          </h2>
          <p className="muted" style={{ margin: 0, fontSize: "0.8125rem" }}>
            Claude reads the findings above and says what to do first.
          </p>
        </div>
        <button
          className="control control-primary no-print"
          onClick={generate}
          disabled={loading}
        >
          {loading ? "Analysing…" : advice ? "Re-analyse" : "Analyse this period"}
        </button>
      </div>

      {error && (
        <p
          style={{
            margin: "1rem 0 0",
            fontSize: "0.875rem",
            color: "var(--delta-bad)",
            lineHeight: 1.5,
          }}
        >
          {error}
        </p>
      )}

      {advice && (
        <div style={{ marginTop: "1.1rem" }}>
          <p
            style={{
              margin: "0 0 1.1rem",
              fontSize: "0.9375rem",
              lineHeight: 1.6,
              color: "var(--text-primary)",
            }}
          >
            {advice.summary}
          </p>

          {advice.actions.length > 0 && (
            <ol
              style={{
                listStyle: "none",
                margin: 0,
                padding: 0,
                display: "grid",
                gap: "0.9rem",
              }}
            >
              {advice.actions.map((action, i) => {
                const p = PRIORITY[action.priority] ?? PRIORITY.monitor;
                return (
                  <li
                    key={i}
                    style={{
                      background: "var(--surface-sunken)",
                      borderRadius: 8,
                      padding: "0.8rem 0.9rem",
                    }}
                  >
                    <div
                      style={{
                        display: "flex",
                        alignItems: "baseline",
                        gap: "0.6rem",
                        flexWrap: "wrap",
                      }}
                    >
                      <span
                        style={{
                          fontSize: "0.6875rem",
                          fontWeight: 700,
                          textTransform: "uppercase",
                          letterSpacing: "0.06em",
                          color: p.color,
                        }}
                      >
                        {p.label}
                      </span>
                      <span
                        style={{
                          fontWeight: 600,
                          fontSize: "0.9375rem",
                          flexBasis: "100%",
                          color: "var(--text-primary)",
                        }}
                      >
                        {action.title}
                      </span>
                    </div>
                    <p
                      className="secondary"
                      style={{ margin: "0.35rem 0 0", fontSize: "0.875rem", lineHeight: 1.55 }}
                    >
                      {action.reasoning}
                    </p>
                    {action.targets.length > 0 && (
                      <div
                        style={{
                          display: "flex",
                          flexWrap: "wrap",
                          gap: "0.35rem",
                          marginTop: "0.5rem",
                        }}
                      >
                        {action.targets.map((t) => (
                          <span
                            key={t}
                            style={{
                              background: "var(--surface)",
                              border: "1px solid var(--border)",
                              borderRadius: 6,
                              padding: "0.15rem 0.45rem",
                              fontSize: "0.75rem",
                              color: "var(--text-secondary)",
                            }}
                          >
                            {t}
                          </span>
                        ))}
                      </div>
                    )}
                  </li>
                );
              })}
            </ol>
          )}

          {advice.caveats.length > 0 && (
            <div style={{ marginTop: "1.1rem" }}>
              <h3
                className="muted"
                style={{
                  margin: "0 0 0.4rem",
                  fontSize: "0.6875rem",
                  fontWeight: 700,
                  textTransform: "uppercase",
                  letterSpacing: "0.06em",
                }}
              >
                Worth knowing before you act
              </h3>
              <ul
                className="secondary"
                style={{
                  margin: 0,
                  paddingLeft: "1.1rem",
                  fontSize: "0.875rem",
                  lineHeight: 1.55,
                  display: "grid",
                  gap: "0.3rem",
                }}
              >
                {advice.caveats.map((c, i) => (
                  <li key={i}>{c}</li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
