import { NextResponse } from "next/server";
import {
  findCandidates,
  readAdjustments,
  validateAdjustment,
  writeAdjustments,
} from "@/lib/adjustments";
import { isAuthorized } from "@/lib/auth";
import { getConfig } from "@/lib/config";
import { addDays } from "@/lib/dates";
import { buildDemoData } from "@/lib/demo";
import { MetaError, getAccountTotals, getEntityRows } from "@/lib/meta";
import type { Adjustment, Level } from "@/lib/types";

export const dynamic = "force-dynamic";

/**
 * Manages manual revenue deductions. Writes to disk, so it checks authorisation
 * itself for the same reason the setup endpoint does.
 */

async function guard(request: Request): Promise<NextResponse | null> {
  if (await isAuthorized(request)) return null;
  return NextResponse.json(
    { error: "Not authorised. Sign in to the MRP as the account owner." },
    { status: 403 },
  );
}

export async function GET(request: Request) {
  const blocked = await guard(request);
  if (blocked) return blocked;
  return NextResponse.json({ adjustments: readAdjustments() });
}

export async function POST(request: Request) {
  const blocked = await guard(request);
  if (blocked) return blocked;

  let body: Partial<Adjustment>;
  try {
    body = (await request.json()) as Partial<Adjustment>;
  } catch {
    return NextResponse.json({ error: "Invalid request body" }, { status: 400 });
  }

  const error = validateAdjustment(body);
  if (error) return NextResponse.json({ error }, { status: 400 });

  const adjustment: Adjustment = {
    id: `adj_${Date.now().toString(36)}_${Math.floor(Math.random() * 1e6).toString(36)}`,
    date: body.date!,
    amount: body.amount!,
    purchases: body.purchases ?? 1,
    level: body.level!,
    entityId: body.entityId,
    entityName: body.entityName,
    note: body.note?.slice(0, 300),
    createdAt: new Date().toISOString(),
  };

  const list = readAdjustments();
  list.push(adjustment);
  list.sort((a, b) => b.date.localeCompare(a.date));

  try {
    writeAdjustments(list);
  } catch (err) {
    return NextResponse.json(
      { error: err instanceof Error ? err.message : "Could not save" },
      { status: 500 },
    );
  }

  return NextResponse.json({ adjustment });
}

export async function DELETE(request: Request) {
  const blocked = await guard(request);
  if (blocked) return blocked;

  const id = new URL(request.url).searchParams.get("id");
  if (!id) {
    return NextResponse.json({ error: "Missing id" }, { status: 400 });
  }

  const list = readAdjustments();
  const next = list.filter((a) => a.id !== id);
  if (next.length === list.length) {
    return NextResponse.json({ error: "No such adjustment" }, { status: 404 });
  }

  try {
    writeAdjustments(next);
  } catch (err) {
    return NextResponse.json(
      { error: err instanceof Error ? err.message : "Could not save" },
      { status: 500 },
    );
  }
  return NextResponse.json({ removed: id });
}

/**
 * Suggests which entity a given order was attributed to.
 *
 * Deliberately scoped to the single day of the order rather than the visible
 * date range: over a month a large order hides inside a normal-looking
 * average, but on its own day it stands out unmistakably.
 */
export async function PUT(request: Request) {
  const blocked = await guard(request);
  if (blocked) return blocked;

  const accountId = getConfig("META_AD_ACCOUNT_ID");
  const connected = Boolean(accountId && getConfig("META_ACCESS_TOKEN"));

  let date: string;
  let amount: number;
  let level: Level;
  try {
    const body = (await request.json()) as {
      date?: string;
      amount?: number;
      level?: Level;
    };
    if (!body.date || !/^\d{4}-\d{2}-\d{2}$/.test(body.date)) {
      return NextResponse.json({ error: "Pick a valid date." }, { status: 400 });
    }
    if (typeof body.amount !== "number" || !(body.amount > 0)) {
      return NextResponse.json(
        { error: "Enter the order value." },
        { status: 400 },
      );
    }
    date = body.date;
    amount = body.amount;
    level = body.level === "campaign" || body.level === "adset" ? body.level : "ad";
  } catch {
    return NextResponse.json({ error: "Invalid request body" }, { status: 400 });
  }

  const day = { since: date, until: date };

  // Without credentials, rank against the sample account so the flow can be
  // tried end to end before Meta is connected.
  if (!connected) {
    const demo = buildDemoData("today", "previous_period");
    const rows =
      level === "campaign" ? demo.campaigns
      : level === "adset" ? demo.adsets
      : demo.ads;
    const normalAov =
      demo.totals.purchases > 0 ? demo.totals.revenue / demo.totals.purchases : null;

    return NextResponse.json({
      candidates: findCandidates(rows, amount, normalAov),
      dayTotals: {
        revenue: demo.totals.revenue,
        purchases: demo.totals.purchases,
        aov: normalAov,
      },
      demo: true,
      message:
        "Searching sample data — connect your Meta account on the setup page to search your real ads.",
    });
  }

  /*
   * "Normal" has to come from a baseline window ending the day before the
   * order, not from the order's own day. On a quiet day a single wholesale
   * order can be most of the revenue, which drags that day's average up to
   * meet itself — the comparison then says the anomaly looks perfectly normal,
   * which is exactly backwards.
   */
  const baseline = { since: addDays(date, -30), until: addDays(date, -1) };

  try {
    const [rows, dayTotals, baselineTotals] = await Promise.all([
      getEntityRows(accountId!, day, day, level),
      getAccountTotals(accountId!, day),
      getAccountTotals(accountId!, baseline),
    ]);

    const normalAov =
      baselineTotals.purchases > 0
        ? baselineTotals.revenue / baselineTotals.purchases
        : dayTotals.purchases > 0
          ? dayTotals.revenue / dayTotals.purchases
          : null;

    const candidates = findCandidates(rows, amount, normalAov);

    return NextResponse.json({
      candidates,
      dayTotals: {
        revenue: dayTotals.revenue,
        purchases: dayTotals.purchases,
        aov: dayTotals.purchases > 0 ? dayTotals.revenue / dayTotals.purchases : null,
      },
      baseline: {
        since: baseline.since,
        until: baseline.until,
        aov: normalAov,
        purchases: baselineTotals.purchases,
      },
      message: candidates.length
        ? undefined
        : `No ${level} recorded revenue of at least ${amount.toLocaleString()} on ${date}. Meta may not have attributed this order to any ad — in which case nothing needs deducting.`,
    });
  } catch (err) {
    if (err instanceof MetaError) {
      return NextResponse.json({ error: err.message }, { status: 200 });
    }
    return NextResponse.json(
      { error: err instanceof Error ? err.message : "Could not reach Meta." },
      { status: 200 },
    );
  }
}
