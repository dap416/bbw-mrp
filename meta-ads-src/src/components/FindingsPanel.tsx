"use client";

import type { Finding, Severity } from "@/lib/types";

/**
 * The deterministic findings. Severity is carried by an icon and a written
 * label as well as colour, so it survives colour-blindness, greyscale print,
 * and forced-colors mode.
 */

const SEVERITY: Record<
  Severity,
  { color: string; icon: string; label: string }
> = {
  critical: { color: "var(--status-critical)", icon: "●", label: "Critical" },
  warning: { color: "var(--status-warning)", icon: "▲", label: "Warning" },
  opportunity: { color: "var(--status-good)", icon: "▲", label: "Opportunity" },
  info: { color: "var(--text-muted)", icon: "■", label: "Context" },
};

export function FindingsPanel({ findings }: { findings: Finding[] }) {
  if (!findings.length) {
    return (
      <div className="card" style={{ padding: "1.25rem" }}>
        <h2 style={headingStyle}>What the numbers say</h2>
        <p className="secondary" style={{ margin: 0 }}>
          Nothing crossed a threshold this period. That usually means the
          account is stable rather than that there is nothing to improve.
        </p>
      </div>
    );
  }

  return (
    <div className="card" style={{ padding: "1.25rem" }}>
      <h2 style={headingStyle}>What the numbers say</h2>
      <p className="muted" style={{ margin: "0 0 1rem", fontSize: "0.8125rem" }}>
        Rule-based checks against your targets. Ordered by money at stake.
      </p>

      <ul style={{ listStyle: "none", margin: 0, padding: 0, display: "grid", gap: "0.9rem" }}>
        {findings.map((f) => {
          const s = SEVERITY[f.severity];
          return (
            <li
              key={f.id}
              style={{
                borderLeft: `3px solid ${s.color}`,
                paddingLeft: "0.85rem",
              }}
            >
              <div
                style={{
                  display: "flex",
                  alignItems: "baseline",
                  gap: "0.5rem",
                  flexWrap: "wrap",
                }}
              >
                <span
                  aria-hidden="true"
                  style={{ color: s.color, fontSize: "0.7rem", lineHeight: 1 }}
                >
                  {s.icon}
                </span>
                <span
                  className="muted"
                  style={{
                    fontSize: "0.6875rem",
                    textTransform: "uppercase",
                    letterSpacing: "0.06em",
                    fontWeight: 600,
                  }}
                >
                  {s.label}
                </span>
                <span
                  style={{
                    fontWeight: 600,
                    fontSize: "0.9375rem",
                    color: "var(--text-primary)",
                    flexBasis: "100%",
                  }}
                >
                  {f.title}
                </span>
              </div>

              <p
                className="secondary"
                style={{ margin: "0.35rem 0 0", fontSize: "0.875rem", lineHeight: 1.5 }}
              >
                {f.detail}
              </p>
              <p
                style={{
                  margin: "0.4rem 0 0",
                  fontSize: "0.875rem",
                  lineHeight: 1.5,
                  color: "var(--text-primary)",
                }}
              >
                <strong style={{ fontWeight: 600 }}>Do this: </strong>
                {f.action}
              </p>

              {f.entities.length > 0 && (
                <div
                  style={{
                    display: "flex",
                    flexWrap: "wrap",
                    gap: "0.35rem",
                    marginTop: "0.5rem",
                  }}
                >
                  {f.entities.slice(0, 8).map((e) => (
                    <span
                      key={e.id}
                      title={`${e.level}: ${e.name}`}
                      style={{
                        background: "var(--surface-sunken)",
                        border: "1px solid var(--border)",
                        borderRadius: 6,
                        padding: "0.15rem 0.45rem",
                        fontSize: "0.75rem",
                        color: "var(--text-secondary)",
                        maxWidth: "100%",
                        overflow: "hidden",
                        textOverflow: "ellipsis",
                        whiteSpace: "nowrap",
                      }}
                    >
                      {e.name}
                    </span>
                  ))}
                  {f.entities.length > 8 && (
                    <span className="muted" style={{ fontSize: "0.75rem", alignSelf: "center" }}>
                      +{f.entities.length - 8} more
                    </span>
                  )}
                </div>
              )}
            </li>
          );
        })}
      </ul>
    </div>
  );
}

const headingStyle: React.CSSProperties = {
  margin: "0 0 0.25rem",
  fontSize: "1rem",
  fontWeight: 600,
};
