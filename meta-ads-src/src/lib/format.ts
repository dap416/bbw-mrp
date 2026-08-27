/** Display formatting. Kept in one place so tables and charts agree. */

export function money(
  value: number | null | undefined,
  currency: string,
  opts: { compact?: boolean } = {},
): string {
  if (value === null || value === undefined || !Number.isFinite(value)) return "—";
  try {
    return new Intl.NumberFormat("en-US", {
      style: "currency",
      currency,
      notation: opts.compact && Math.abs(value) >= 10_000 ? "compact" : "standard",
      maximumFractionDigits: Math.abs(value) >= 1000 ? 0 : 2,
      minimumFractionDigits: Math.abs(value) >= 1000 ? 0 : 2,
    }).format(value);
  } catch {
    return `${value.toFixed(2)} ${currency}`;
  }
}

export function count(value: number | null | undefined): string {
  if (value === null || value === undefined || !Number.isFinite(value)) return "—";
  return new Intl.NumberFormat("en-US", { maximumFractionDigits: 0 }).format(value);
}

/** Ratios stored as fractions (0.0123) rendered as percentages (1.23%). */
export function percent(value: number | null | undefined, digits = 2): string {
  if (value === null || value === undefined || !Number.isFinite(value)) return "—";
  return `${(value * 100).toFixed(digits)}%`;
}

export function multiple(value: number | null | undefined): string {
  if (value === null || value === undefined || !Number.isFinite(value)) return "—";
  return `${value.toFixed(2)}x`;
}

export function decimal(value: number | null | undefined, digits = 2): string {
  if (value === null || value === undefined || !Number.isFinite(value)) return "—";
  return value.toFixed(digits);
}

export interface Delta {
  /** Fractional change, e.g. 0.12 for +12%. null when there's no baseline. */
  change: number | null;
  /** Formatted for display, including sign. */
  label: string;
  /** Whether this movement is good, bad, or neutral for the business. */
  tone: "good" | "bad" | "neutral";
}

/**
 * @param lowerIsBetter true for cost metrics (CPA, CPC, CPM) where a
 *   decrease is the good outcome.
 */
export function delta(
  current: number | null | undefined,
  previous: number | null | undefined,
  lowerIsBetter = false,
): Delta {
  if (
    current === null || current === undefined || !Number.isFinite(current) ||
    previous === null || previous === undefined || !Number.isFinite(previous) ||
    previous === 0
  ) {
    return { change: null, label: "—", tone: "neutral" };
  }

  const change = (current - previous) / previous;
  const pct = change * 100;
  const label = `${change > 0 ? "+" : ""}${pct.toFixed(pct >= 100 || pct <= -100 ? 0 : 1)}%`;

  // Movements under half a percent are noise, not news.
  if (Math.abs(change) < 0.005) return { change, label, tone: "neutral" };

  const improved = lowerIsBetter ? change < 0 : change > 0;
  return { change, label, tone: improved ? "good" : "bad" };
}

/** Short weekday+day label for chart axes, e.g. "Mon 4". */
export function shortDate(key: string): string {
  const [y, m, d] = key.split("-").map(Number);
  const date = new Date(Date.UTC(y, m - 1, d));
  return new Intl.DateTimeFormat("en-US", {
    timeZone: "UTC",
    month: "short",
    day: "numeric",
  }).format(date);
}

export function longDate(key: string): string {
  const [y, m, d] = key.split("-").map(Number);
  const date = new Date(Date.UTC(y, m - 1, d));
  return new Intl.DateTimeFormat("en-US", {
    timeZone: "UTC",
    weekday: "short",
    month: "short",
    day: "numeric",
  }).format(date);
}
