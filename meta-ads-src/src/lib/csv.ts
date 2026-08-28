import type { Platform } from "./platforms";
import type { PlatformRow } from "./platformData";

/**
 * Parses a pasted Google Ads or Microsoft Advertising report export.
 *
 * Both platforms let you download a campaign-by-day report as CSV, and both
 * name their columns differently ("Cost" vs "Spend", "Conversions" vs "Conv.",
 * "Conv. value" vs "Revenue"). Rather than demand one exact format, this maps
 * a range of known headers onto our five counters and tells the user plainly
 * which columns it could not find — a rejected paste with a specific reason is
 * far more useful than a silent zero.
 */

export interface CsvResult {
  rows: PlatformRow[];
  /** Non-fatal notes: skipped lines, columns that defaulted to zero. */
  warnings: string[];
}

export class CsvError extends Error {}

/** Header aliases, lowercased and stripped of punctuation before matching. */
const ALIASES: Record<keyof Omit<PlatformRow, "platform">, string[]> = {
  date: ["date", "day"],
  campaign: ["campaign", "campaign name"],
  spend: ["spend", "cost", "amount spent", "amount spent usd"],
  impressions: ["impressions", "impr", "impr "],
  clicks: ["clicks", "link clicks"],
  purchases: [
    "purchases",
    "conversions",
    "conv",
    "all conv",
    "all conversions",
  ],
  revenue: [
    "revenue",
    "conv value",
    "conversion value",
    "conv value",
    "all conv value",
    "total conv value",
    "revenue usd",
  ],
};

function normalizeHeader(value: string): string {
  return value
    .toLowerCase()
    .replace(/[.()]/g, "")
    .replace(/[_/]/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

/**
 * A minimal RFC-4180 split: enough for quoted fields containing commas, which
 * campaign names routinely do. Not a general CSV library, and does not need
 * to be — these files come out of two known exporters.
 */
function splitLine(line: string): string[] {
  const out: string[] = [];
  let field = "";
  let quoted = false;

  for (let i = 0; i < line.length; i++) {
    const ch = line[i];
    if (quoted) {
      if (ch === '"') {
        if (line[i + 1] === '"') {
          field += '"';
          i++;
        } else {
          quoted = false;
        }
      } else {
        field += ch;
      }
    } else if (ch === '"') {
      quoted = true;
    } else if (ch === ",") {
      out.push(field);
      field = "";
    } else {
      field += ch;
    }
  }
  out.push(field);
  return out.map((f) => f.trim());
}

/** Strips currency symbols, thousands separators and stray percent signs. */
function toNumber(raw: string | undefined): number {
  if (!raw) return 0;
  const cleaned = raw.replace(/[^0-9.\-]/g, "");
  const value = Number(cleaned);
  return Number.isFinite(value) ? value : 0;
}

/**
 * Accepts YYYY-MM-DD (both exporters' default) and the M/D/YYYY that a trip
 * through Excel tends to produce. Anything else is refused rather than
 * guessed at — a misread date silently files spend under the wrong day.
 */
function toDate(raw: string | undefined): string | null {
  if (!raw) return null;
  const value = raw.trim();

  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value;

  const slash = value.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
  if (slash) {
    const [, m, d, y] = slash;
    return `${y}-${m.padStart(2, "0")}-${d.padStart(2, "0")}`;
  }
  return null;
}

export function parsePlatformCsv(text: string, platform: Platform): CsvResult {
  const lines = text
    .split(/\r?\n/)
    .map((l) => l.trim())
    .filter(Boolean);

  if (lines.length < 2) {
    throw new CsvError(
      "That looks empty. Paste the whole export, including its header row.",
    );
  }

  // Google's exports carry a title line or two above the real header. Find the
  // first line that actually names a date column instead of assuming line 1.
  const headerIndex = lines.findIndex((line) => {
    const cells = splitLine(line).map(normalizeHeader);
    return cells.some((c) => ALIASES.date.includes(c));
  });
  if (headerIndex === -1) {
    throw new CsvError(
      "Could not find a header row with a Date column. Export the report by day, with columns for campaign, cost, and conversions.",
    );
  }

  const header = splitLine(lines[headerIndex]).map(normalizeHeader);
  const columnFor = (field: keyof typeof ALIASES): number =>
    header.findIndex((h) => ALIASES[field].includes(h));

  const dateCol = columnFor("date");
  const campaignCol = columnFor("campaign");
  const spendCol = columnFor("spend");

  if (spendCol === -1) {
    throw new CsvError(
      `No cost column found. Expected one of: ${ALIASES.spend.join(", ")}. Found: ${header.filter(Boolean).join(", ")}.`,
    );
  }

  const warnings: string[] = [];
  if (campaignCol === -1) {
    warnings.push(
      "No campaign column, so every row was filed under “All campaigns”. Totals are still correct.",
    );
  }
  for (const field of ["impressions", "clicks", "purchases", "revenue"] as const) {
    if (columnFor(field) === -1) {
      warnings.push(`No ${field} column found — those are recorded as zero.`);
    }
  }

  const impressionsCol = columnFor("impressions");
  const clicksCol = columnFor("clicks");
  const purchasesCol = columnFor("purchases");
  const revenueCol = columnFor("revenue");

  const rows: PlatformRow[] = [];
  let skipped = 0;
  let malformed = 0;

  for (const line of lines.slice(headerIndex + 1)) {
    const cells = splitLine(line);

    /*
      More cells than headers means a field contained an unquoted comma —
      a thousands separator in a cost that the exporter failed to quote is
      the usual cause. Every column past that point is shifted, so the row
      would import a cost of "1" and file the real figures under the wrong
      metrics entirely. That is far worse than dropping the row, and unlike
      a wrong number it is invisible once saved.
    */
    if (cells.length > header.length) {
      malformed++;
      continue;
    }

    const date = toDate(cells[dateCol]);
    if (!date) {
      // Export footers ("Total", "—") land here, which is why this is a skip
      // rather than an error.
      skipped++;
      continue;
    }

    rows.push({
      platform,
      date,
      campaign:
        campaignCol === -1 || !cells[campaignCol]
          ? "All campaigns"
          : cells[campaignCol],
      spend: toNumber(cells[spendCol]),
      impressions: toNumber(cells[impressionsCol]),
      clicks: toNumber(cells[clicksCol]),
      purchases: toNumber(cells[purchasesCol]),
      revenue: toNumber(cells[revenueCol]),
    });
  }

  if (!rows.length) {
    throw new CsvError(
      "Found the header but no dated rows underneath it. Check the report is broken down by day.",
    );
  }
  if (skipped) {
    warnings.push(
      skipped === 1
        ? "1 line without a readable date was skipped (usually the export's total row)."
        : `${skipped} lines without a readable date were skipped (usually the export's total row).`,
    );
  }
  if (malformed) {
    warnings.push(
      `${malformed} line${malformed === 1 ? " had" : "s had"} more columns than the header — usually an unquoted comma inside a cost or campaign name. ${malformed === 1 ? "It was" : "They were"} skipped rather than imported into the wrong columns. Re-export the report, or add quotes around those fields.`,
    );
  }

  return { rows, warnings };
}
