"use client";

import { PLATFORMS, PLATFORM_META, type PlatformView } from "@/lib/platforms";

/**
 * The view switcher: All platforms, then one tab per platform.
 *
 * A tab carries its platform's colour as an underline rather than as text or
 * background colour, so the association survives greyscale and forced-colors
 * mode — the selected tab is also the only one with full-strength text and
 * carries aria-selected for anything not reading the styling at all.
 */
export function PlatformTabs({
  view,
  onChange,
  /** Spend per platform for the period, shown under each tab as context. */
  subtitles,
}: {
  view: PlatformView;
  onChange: (view: PlatformView) => void;
  subtitles?: Partial<Record<PlatformView, string>>;
}) {
  const tabs: { value: PlatformView; label: string; color: string }[] = [
    { value: "all", label: "All platforms", color: "var(--text-primary)" },
    ...PLATFORMS.map((p) => ({
      value: p as PlatformView,
      label: PLATFORM_META[p].label,
      color: PLATFORM_META[p].color,
    })),
  ];

  return (
    <div
      role="tablist"
      aria-label="Ad platform"
      className="no-print"
      style={{
        display: "flex",
        gap: "0.25rem",
        borderBottom: "1px solid var(--border)",
        overflowX: "auto",
      }}
    >
      {tabs.map((tab) => {
        const selected = tab.value === view;
        return (
          <button
            key={tab.value}
            role="tab"
            aria-selected={selected}
            onClick={() => onChange(tab.value)}
            style={{
              appearance: "none",
              background: "none",
              border: "none",
              borderBottom: `2px solid ${selected ? tab.color : "transparent"}`,
              padding: "0.6rem 0.9rem 0.5rem",
              cursor: "pointer",
              font: "inherit",
              fontSize: "0.875rem",
              fontWeight: selected ? 600 : 400,
              color: selected ? "var(--text-primary)" : "var(--text-secondary)",
              whiteSpace: "nowrap",
              display: "grid",
              gap: "0.15rem",
              justifyItems: "start",
              marginBottom: -1,
            }}
          >
            <span>{tab.label}</span>
            {subtitles?.[tab.value] && (
              <span
                className="muted"
                style={{ fontSize: "0.75rem", fontWeight: 400 }}
              >
                {subtitles[tab.value]}
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
}
