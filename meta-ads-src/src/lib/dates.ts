import type { DateRange, Preset } from "./types";

/**
 * All date math here is deliberately string-based on YYYY-MM-DD in the ad
 * account's own timezone. Meta reports in account-local days, so using JS
 * Date objects with the server's timezone would silently shift every
 * boundary by a few hours and make "yesterday" wrong.
 */

const DAY_MS = 86_400_000;

function toKey(d: Date): string {
  return d.toISOString().slice(0, 10);
}

/** Parses YYYY-MM-DD into a UTC-midnight Date (safe for pure day arithmetic). */
export function parseKey(key: string): Date {
  const [y, m, d] = key.split("-").map(Number);
  return new Date(Date.UTC(y, m - 1, d));
}

export function addDays(key: string, days: number): string {
  return toKey(new Date(parseKey(key).getTime() + days * DAY_MS));
}

/** Inclusive day count: same day => 1. */
export function dayCount(range: DateRange): number {
  return (
    Math.round(
      (parseKey(range.until).getTime() - parseKey(range.since).getTime()) /
        DAY_MS,
    ) + 1
  );
}

/** "Today" in an IANA timezone, as YYYY-MM-DD. */
export function todayIn(timezone: string): string {
  try {
    // en-CA formats as YYYY-MM-DD, which is exactly what we want.
    return new Intl.DateTimeFormat("en-CA", {
      timeZone: timezone,
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
    }).format(new Date());
  } catch {
    // Unknown timezone string from Meta — fall back to UTC rather than crash.
    return toKey(new Date());
  }
}

export function rangeFromPreset(preset: Preset, timezone: string): DateRange {
  const today = todayIn(timezone);
  const yesterday = addDays(today, -1);

  switch (preset) {
    case "today":
      return { since: today, until: today };
    case "yesterday":
      return { since: yesterday, until: yesterday };
    case "last_7d":
      return { since: addDays(yesterday, -6), until: yesterday };
    case "last_14d":
      return { since: addDays(yesterday, -13), until: yesterday };
    case "last_28d":
      return { since: addDays(yesterday, -27), until: yesterday };
    case "last_30d":
      return { since: addDays(yesterday, -29), until: yesterday };
    case "last_90d":
      return { since: addDays(yesterday, -89), until: yesterday };
    case "this_month": {
      const d = parseKey(today);
      return { since: `${toKey(d).slice(0, 7)}-01`, until: today };
    }
    case "last_month": {
      const d = parseKey(today);
      const firstOfThis = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), 1));
      const lastOfPrev = new Date(firstOfThis.getTime() - DAY_MS);
      const firstOfPrev = new Date(
        Date.UTC(lastOfPrev.getUTCFullYear(), lastOfPrev.getUTCMonth(), 1),
      );
      return { since: toKey(firstOfPrev), until: toKey(lastOfPrev) };
    }
  }
}

export type CompareMode = "previous_period" | "previous_year";

/**
 * The comparison window. "previous_period" is the immediately preceding
 * block of the same length — the right default, because it holds day-count
 * constant so the numbers are actually comparable.
 */
export function comparisonRange(
  range: DateRange,
  mode: CompareMode,
): { range: DateRange; label: string } {
  const days = dayCount(range);

  if (mode === "previous_year") {
    return {
      range: {
        since: addDays(range.since, -364),
        until: addDays(range.until, -364),
      },
      // -364 rather than -365 keeps weekday alignment, which matters more
      // than calendar-date alignment for ad performance.
      label: "same period last year",
    };
  }

  return {
    range: {
      since: addDays(range.since, -days),
      until: addDays(range.since, -1),
    },
    label: `previous ${days} day${days === 1 ? "" : "s"}`,
  };
}

export const PRESET_LABELS: Record<Preset, string> = {
  today: "Today",
  yesterday: "Yesterday",
  last_7d: "Last 7 days",
  last_14d: "Last 14 days",
  last_28d: "Last 28 days",
  last_30d: "Last 30 days",
  last_90d: "Last 90 days",
  this_month: "This month",
  last_month: "Last month",
};
