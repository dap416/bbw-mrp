import { NextResponse } from "next/server";
import { isAuthorized } from "@/lib/auth";
import { CsvError, parsePlatformCsv } from "@/lib/csv";
import {
  deletePlatformRows,
  platformDataFilePath,
  readPlatformRows,
  upsertPlatformRows,
  type PlatformRow,
} from "@/lib/platformData";
import { isPlatform, type Platform } from "@/lib/platforms";

export const dynamic = "force-dynamic";

/**
 * Reads and writes the hand-entered Google and Microsoft figures.
 *
 * Like the setup route, this writes to disk, so it checks authorisation for
 * itself rather than trusting that the middleware ran. Meta is refused
 * outright: those figures come from the API, and letting them be overwritten
 * by hand would quietly break the one platform whose numbers are trustworthy.
 */

async function guard(request: Request): Promise<NextResponse | null> {
  if (await isAuthorized(request)) return null;
  return NextResponse.json(
    { error: "Not authorised. This needs Edit access to Meta Ads in the MRP." },
    { status: 403 },
  );
}

/** Manual entry is for the platforms without an API connection. */
function manualPlatform(value: unknown): Platform | null {
  if (!isPlatform(value) || value === "meta") return null;
  return value;
}

export async function GET(request: Request) {
  const blocked = await guard(request);
  if (blocked) return blocked;

  const url = new URL(request.url);
  const platform = url.searchParams.get("platform");
  const rows = readPlatformRows();

  const filtered = platform ? rows.filter((r) => r.platform === platform) : rows;

  // A per-platform summary is what the UI actually needs to say "Google:
  // 84 days, 3 Jun to 25 Aug" without shipping every row to the browser.
  const summary: Record<string, { rows: number; since: string; until: string; spend: number }> = {};
  for (const row of rows) {
    const s = summary[row.platform] ?? {
      rows: 0,
      since: row.date,
      until: row.date,
      spend: 0,
    };
    s.rows++;
    s.spend += row.spend;
    if (row.date < s.since) s.since = row.date;
    if (row.date > s.until) s.until = row.date;
    summary[row.platform] = s;
  }

  return NextResponse.json({
    rows: filtered,
    summary,
    path: platformDataFilePath(),
  });
}

/**
 * Accepts either a pasted CSV export or explicit rows. The CSV path is the
 * one people will use — both platforms export a campaign-by-day report — and
 * the row path exists for correcting a single day without re-exporting.
 */
export async function POST(request: Request) {
  const blocked = await guard(request);
  if (blocked) return blocked;

  let body: { platform?: unknown; csv?: unknown; rows?: unknown };
  try {
    body = (await request.json()) as typeof body;
  } catch {
    return NextResponse.json({ error: "Invalid request body" }, { status: 400 });
  }

  const platform = manualPlatform(body.platform);
  if (!platform) {
    return NextResponse.json(
      {
        error:
          "Choose Google Ads or Microsoft Ads. Meta figures come from its API and cannot be entered by hand.",
      },
      { status: 400 },
    );
  }

  let rows: PlatformRow[];
  let warnings: string[] = [];

  if (typeof body.csv === "string" && body.csv.trim()) {
    try {
      const parsed = parsePlatformCsv(body.csv, platform);
      rows = parsed.rows;
      warnings = parsed.warnings;
    } catch (err) {
      return NextResponse.json(
        {
          error:
            err instanceof CsvError
              ? err.message
              : "Could not read that CSV.",
        },
        { status: 400 },
      );
    }
  } else if (Array.isArray(body.rows)) {
    const validated = validateRows(body.rows, platform);
    if ("error" in validated) {
      return NextResponse.json({ error: validated.error }, { status: 400 });
    }
    rows = validated.rows;
  } else {
    return NextResponse.json(
      { error: "Paste a CSV export, or send explicit rows." },
      { status: 400 },
    );
  }

  const { added, updated } = upsertPlatformRows(rows);

  return NextResponse.json({
    saved: true,
    added,
    updated,
    warnings,
    message: `${added} row${added === 1 ? "" : "s"} added, ${updated} updated.`,
  });
}

/**
 * Clears a platform's rows over a date range, for re-importing a period that
 * was imported wrong. Scoped to one platform and one range on purpose —
 * there is no "delete everything" here.
 */
export async function DELETE(request: Request) {
  const blocked = await guard(request);
  if (blocked) return blocked;

  const url = new URL(request.url);
  const platform = manualPlatform(url.searchParams.get("platform"));
  const since = url.searchParams.get("since");
  const until = url.searchParams.get("until");

  if (!platform) {
    return NextResponse.json(
      { error: "Choose Google Ads or Microsoft Ads." },
      { status: 400 },
    );
  }
  if (!isDate(since) || !isDate(until)) {
    return NextResponse.json(
      { error: "A since and until date are both required, as YYYY-MM-DD." },
      { status: 400 },
    );
  }

  const removed = deletePlatformRows(platform, { since, until });
  return NextResponse.json({
    removed,
    message: `${removed} row${removed === 1 ? "" : "s"} removed.`,
  });
}

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

function isDate(value: string | null): value is string {
  return typeof value === "string" && DATE_RE.test(value);
}

function validateRows(
  input: unknown[],
  platform: Platform,
): { rows: PlatformRow[] } | { error: string } {
  const rows: PlatformRow[] = [];

  for (const [index, raw] of input.entries()) {
    if (typeof raw !== "object" || raw === null) {
      return { error: `Row ${index + 1} is not an object.` };
    }
    const r = raw as Record<string, unknown>;

    if (typeof r.date !== "string" || !DATE_RE.test(r.date)) {
      return { error: `Row ${index + 1} needs a date as YYYY-MM-DD.` };
    }

    const num = (key: string): number => {
      const value = Number(r[key] ?? 0);
      return Number.isFinite(value) && value >= 0 ? value : 0;
    };

    rows.push({
      platform,
      date: r.date,
      campaign:
        typeof r.campaign === "string" && r.campaign.trim()
          ? r.campaign.trim()
          : "All campaigns",
      spend: num("spend"),
      impressions: num("impressions"),
      clicks: num("clicks"),
      purchases: num("purchases"),
      revenue: num("revenue"),
    });
  }

  if (!rows.length) return { error: "No rows to save." };
  return { rows };
}
