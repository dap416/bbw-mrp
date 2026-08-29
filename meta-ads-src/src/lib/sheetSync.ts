import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { getConfig, type ConfigKey } from "./config";
import { CsvError, parsePlatformCsv } from "./csv";
import { upsertPlatformRows } from "./platformData";
import type { Platform } from "./platforms";

/**
 * Pulls a platform's figures from a published Google Sheet.
 *
 * The Sheet is filled by a Google Ads Script running inside the ad account
 * (see `scripts/google-ads-export.js`), which is why this exists at all: it
 * sidesteps the Google Ads API's Basic Access application entirely. The Sheet
 * is published as CSV, so fetching it needs no credentials — which also means
 * the URL is effectively public to anyone holding it, and is treated as a
 * secret in the config accordingly.
 *
 * Parsing is deliberately the same code path as a manual paste. A sheet and a
 * hand-pasted export are the same bytes in the same format, and having one
 * importer means a fix to either benefits both.
 */

/** Where each platform's published-CSV URL is stored. */
const URL_KEY: Partial<Record<Platform, ConfigKey>> = {
  google: "GOOGLE_SHEET_CSV_URL",
  microsoft: "MICROSOFT_SHEET_CSV_URL",
};

/**
 * Don't re-fetch more often than this when syncing opportunistically.
 *
 * Kept a little under the export script's hourly cadence. It was six hours
 * when that script ran daily, which was right then and wrong the moment the
 * script went hourly: the sheet would be current while the dashboard sat on a
 * copy most of a working day old, with nothing on screen to say so. The rule
 * is that this interval tracks whatever fills the sheet.
 */
const TTL_MS = 20 * 60 * 1000;

/** A slow or hanging sheet must not hold the dashboard open. */
const FETCH_TIMEOUT_MS = 10_000;

const STATE_PATH = join(process.cwd(), "sheet-sync.json");

interface SyncRecord {
  /** ISO timestamp of the last attempt, successful or not. */
  attemptedAt: string;
  /** ISO timestamp of the last attempt that stored rows. */
  succeededAt?: string;
  rows?: number;
  error?: string;
}

type SyncState = Partial<Record<Platform, SyncRecord>>;

export function readSyncState(): SyncState {
  try {
    if (!existsSync(STATE_PATH)) return {};
    const parsed = JSON.parse(readFileSync(STATE_PATH, "utf8"));
    return parsed && typeof parsed === "object" ? (parsed as SyncState) : {};
  } catch {
    return {};
  }
}

function writeSyncState(state: SyncState): void {
  try {
    writeFileSync(STATE_PATH, JSON.stringify(state, null, 2), "utf8");
  } catch {
    // Losing the timestamp costs an extra fetch next time, nothing more.
  }
}

export function sheetUrl(platform: Platform): string | undefined {
  const key = URL_KEY[platform];
  return key ? getConfig(key) : undefined;
}

export function isSheetConfigured(platform: Platform): boolean {
  return Boolean(sheetUrl(platform));
}

export interface SyncResult {
  ok: boolean;
  platform: Platform;
  added?: number;
  updated?: number;
  warnings?: string[];
  error?: string;
  /** True when the fetch was skipped because the last one was recent. */
  skipped?: boolean;
}

/**
 * Fetches, parses and stores. `force` bypasses the TTL, which is what the
 * "Sync now" button uses — an explicit request should never be answered with
 * a cached decision not to look.
 */
export async function syncPlatformFromSheet(
  platform: Platform,
  { force = false }: { force?: boolean } = {},
): Promise<SyncResult> {
  const url = sheetUrl(platform);
  if (!url) {
    return { ok: false, platform, error: "No sheet URL is configured." };
  }

  const state = readSyncState();
  const last = state[platform];

  if (!force && last?.attemptedAt) {
    const age = Date.now() - Date.parse(last.attemptedAt);
    // Failures are retried on the same schedule as successes rather than
    // hammering a broken URL on every page load.
    if (Number.isFinite(age) && age < TTL_MS) {
      return { ok: true, platform, skipped: true };
    }
  }

  /*
    Carry the previous success forward. A failed attempt overwrites when it
    happened, never what last worked — the UI's "figures are as of the last
    successful sync" line is only meaningful if that timestamp survives the
    failure that made it worth showing.
  */
  const record: SyncRecord = {
    ...last,
    attemptedAt: new Date().toISOString(),
  };

  try {
    const csv = await fetchCsv(url);
    const { rows, warnings } = parsePlatformCsv(csv, platform);
    const { added, updated } = upsertPlatformRows(rows);

    record.succeededAt = record.attemptedAt;
    record.rows = rows.length;
    delete record.error;
    writeSyncState({ ...state, [platform]: record });

    return { ok: true, platform, added, updated, warnings };
  } catch (err) {
    const message =
      err instanceof CsvError
        ? err.message
        : err instanceof Error
          ? err.message
          : "Could not fetch the sheet.";

    record.error = message;
    writeSyncState({ ...state, [platform]: record });

    return { ok: false, platform, error: message };
  }
}

async function fetchCsv(url: string): Promise<string> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);

  try {
    const res = await fetch(url, {
      cache: "no-store",
      redirect: "follow",
      signal: controller.signal,
    });

    if (!res.ok) {
      throw new Error(
        `The sheet URL returned ${res.status}. Check it is published to the web as CSV, not just shared.`,
      );
    }

    const text = await res.text();

    /*
      An unpublished or permission-gated sheet answers 200 with a sign-in page
      rather than an error, so the status code alone cannot be trusted. HTML
      where CSV was expected means exactly that, and saying so is far more
      useful than the parser's "no header row found".
    */
    const head = text.slice(0, 500).toLowerCase();
    if (head.includes("<!doctype html") || head.includes("<html")) {
      throw new Error(
        "That URL returned a web page, not CSV. In the Sheet use File > Share > Publish to web, pick the sheet, and choose Comma-separated values (.csv).",
      );
    }

    return text;
  } catch (err) {
    if (err instanceof Error && err.name === "AbortError") {
      throw new Error(
        `The sheet did not respond within ${FETCH_TIMEOUT_MS / 1000} seconds.`,
      );
    }
    throw err;
  } finally {
    clearTimeout(timer);
  }
}
