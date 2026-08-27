import { NextResponse } from "next/server";
import {
  CONFIG_KEYS,
  SECRET_KEYS,
  envFilePath,
  getConfig,
  saveConfig,
  type ConfigKey,
} from "@/lib/config";
import { isAuthorized } from "@/lib/auth";
import { MetaError, listAdAccounts } from "@/lib/meta";

export const dynamic = "force-dynamic";

/**
 * Reads and writes .env.local for the setup page.
 *
 * This endpoint can persist credentials to disk, so it checks authorisation
 * itself rather than relying on the middleware having run. In production that
 * means a valid token from meta_gate.php, which MRP issues only to the
 * signed-in owner; in development, where there is no MRP, it still means a
 * request from this machine.
 */

async function guard(request: Request): Promise<NextResponse | null> {
  if (await isAuthorized(request)) return null;
  return NextResponse.json(
    { error: "Not authorised. This needs Edit access to Meta Ads in the MRP." },
    { status: 403 },
  );
}

/** Enough of a secret to recognise, not enough to use. */
function mask(value: string): string {
  if (value.length <= 8) return "••••••";
  return `${value.slice(0, 4)}••••${value.slice(-4)}`;
}

export async function GET(request: Request) {
  const blocked = await guard(request);
  if (blocked) return blocked;

  const values: Record<string, string> = {};
  const present: Record<string, boolean> = {};

  for (const key of CONFIG_KEYS) {
    const value = getConfig(key);
    present[key] = Boolean(value);
    if (!value) continue;
    // Secrets are only ever described, never returned.
    values[key] = SECRET_KEYS.includes(key) ? mask(value) : value;
  }

  return NextResponse.json({ values, present, path: envFilePath() });
}

export async function POST(request: Request) {
  const blocked = await guard(request);
  if (blocked) return blocked;

  let body: Record<string, unknown>;
  try {
    body = (await request.json()) as Record<string, unknown>;
  } catch {
    return NextResponse.json({ error: "Invalid request body" }, { status: 400 });
  }

  const updates: Partial<Record<ConfigKey, string>> = {};
  for (const key of CONFIG_KEYS) {
    const raw = body[key];
    if (typeof raw !== "string") continue;

    const value = raw.trim();
    // A masked value means the field was left untouched in the form, so the
    // stored secret must be preserved rather than overwritten with dots.
    if (value.includes("••")) continue;
    updates[key] = value;
  }

  if (!Object.keys(updates).length) {
    return NextResponse.json({ error: "Nothing to save" }, { status: 400 });
  }

  // Catch the most common paste mistakes before they become confusing
  // "invalid token" errors later.
  const accountId = updates.META_AD_ACCOUNT_ID;
  if (accountId && !/^act_\d+$/.test(accountId)) {
    return NextResponse.json(
      {
        error: `Ad account ID should look like act_123456789012345 — got "${accountId}".`,
      },
      { status: 400 },
    );
  }
  const roas = updates.TARGET_ROAS;
  if (roas && !(Number(roas) > 0)) {
    return NextResponse.json(
      { error: "Target ROAS must be a positive number, e.g. 2.5" },
      { status: 400 },
    );
  }

  try {
    saveConfig(updates);
  } catch (err) {
    return NextResponse.json(
      {
        error: `Could not write ${envFilePath()}: ${
          err instanceof Error ? err.message : "unknown error"
        }`,
      },
      { status: 500 },
    );
  }

  return NextResponse.json({ saved: true });
}

/**
 * Validates a token by using it. Accepts a token in the body so it can be
 * checked before being written to disk, and returns the ad accounts it can
 * see so the setup page can offer them as a list instead of asking the user
 * to find an ID themselves.
 */
export async function PUT(request: Request) {
  const blocked = await guard(request);
  if (blocked) return blocked;

  let token: string | undefined;
  try {
    const body = (await request.json()) as { token?: unknown };
    if (typeof body.token === "string" && body.token.trim()) {
      token = body.token.trim();
    }
  } catch {
    // Fall through to the stored token.
  }

  // A masked value means the field wasn't edited, so fall through to the
  // stored token — that also makes "Test" re-check what is already saved.
  const override = token && !token.includes("••") ? token : undefined;

  try {
    if (!override && !getConfig("META_ACCESS_TOKEN")) {
      return NextResponse.json(
        { error: "Paste an access token first." },
        { status: 400 },
      );
    }

    const accounts = await listAdAccounts(override);
    return NextResponse.json({
      ok: true,
      accounts,
      message: accounts.length
        ? `Token works. It can see ${accounts.length} ad account${accounts.length === 1 ? "" : "s"}.`
        : "Token works, but it cannot see any ad accounts. Check the account is assigned to this system user.",
    });
  } catch (err) {
    if (err instanceof MetaError) {
      return NextResponse.json(
        {
          error: err.message,
          hint: err.isAuthError
            ? "The token is invalid, expired, or missing the ads_read permission."
            : undefined,
        },
        { status: 200 },
      );
    }
    return NextResponse.json(
      { error: err instanceof Error ? err.message : "Could not reach Meta." },
      { status: 200 },
    );
  }
}
