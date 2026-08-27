import { readFileSync, writeFileSync, existsSync, statSync } from "node:fs";
import { join } from "node:path";

/**
 * Reads settings from `.env.local` on every request rather than relying only
 * on `process.env`.
 *
 * Next.js loads `.env.local` into `process.env` once at startup, so a value
 * saved through the setup page would not take effect until the server was
 * restarted. Reading the file directly (cached against its mtime, so this is
 * one `stat` per request in the steady state) means saving works immediately.
 *
 * The file wins over `process.env` because the file is what the setup page
 * edits; a real environment variable is only a fallback for values set some
 * other way.
 */

const ENV_PATH = join(process.cwd(), ".env.local");

export const CONFIG_KEYS = [
  "META_ACCESS_TOKEN",
  "META_AD_ACCOUNT_ID",
  "META_API_VERSION",
  "META_ATTRIBUTION_WINDOWS",
  "TARGET_ROAS",
  "TARGET_CPA",
  "SHOPIFY_STORE_DOMAIN",
  "SHOPIFY_ADMIN_TOKEN",
  "SHOPIFY_EXCLUDE_TAGS",
  "SHOPIFY_EXCLUDE_ABOVE",
  "SHOPIFY_EXCLUDE_B2B",
  "SHOPIFY_EXCLUDE_DRAFT_ORDERS",
  "ANTHROPIC_API_KEY",
] as const;

export type ConfigKey = (typeof CONFIG_KEYS)[number];

/** Keys whose values must never be sent back to the browser. */
export const SECRET_KEYS: ConfigKey[] = [
  "META_ACCESS_TOKEN",
  "SHOPIFY_ADMIN_TOKEN",
  "ANTHROPIC_API_KEY",
];

let cache: { mtimeMs: number; values: Record<string, string> } | null = null;

function parseEnv(contents: string): Record<string, string> {
  const out: Record<string, string> = {};
  for (const rawLine of contents.split(/\r?\n/)) {
    const line = rawLine.trim();
    if (!line || line.startsWith("#")) continue;

    const eq = line.indexOf("=");
    if (eq === -1) continue;

    const key = line.slice(0, eq).trim();
    let value = line.slice(eq + 1).trim();

    // Tolerate quoted values — people paste them that way out of habit.
    if (
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }
    if (value) out[key] = value;
  }
  return out;
}

function fileValues(): Record<string, string> {
  try {
    if (!existsSync(ENV_PATH)) return {};
    const { mtimeMs } = statSync(ENV_PATH);
    if (cache && cache.mtimeMs === mtimeMs) return cache.values;

    const values = parseEnv(readFileSync(ENV_PATH, "utf8"));
    cache = { mtimeMs, values };
    return values;
  } catch {
    // An unreadable .env.local should degrade to process.env, not crash.
    return {};
  }
}

export function getConfig(key: ConfigKey): string | undefined {
  const fromFile = fileValues()[key];
  if (fromFile) return fromFile;
  const fromEnv = process.env[key];
  return fromEnv || undefined;
}

/** True when the value is present and non-empty from either source. */
export function hasConfig(key: ConfigKey): boolean {
  return Boolean(getConfig(key));
}

/**
 * Writes the given keys into `.env.local`, updating lines in place so the
 * file's comments and ordering survive. An empty string clears a key rather
 * than writing a blank value, so "remove my Shopify token" works.
 */
export function saveConfig(updates: Partial<Record<ConfigKey, string>>): void {
  const existing = existsSync(ENV_PATH) ? readFileSync(ENV_PATH, "utf8") : "";
  const lines = existing.split(/\r?\n/);
  const pending = new Map<string, string>(Object.entries(updates));

  const rewritten = lines.map((line) => {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith("#")) return line;

    const eq = trimmed.indexOf("=");
    if (eq === -1) return line;

    const key = trimmed.slice(0, eq).trim();
    if (!pending.has(key)) return line;

    const value = pending.get(key)!;
    pending.delete(key);
    return `${key}=${value}`;
  });

  // Keys the file didn't already mention get appended.
  const added = [...pending.entries()].filter(([, v]) => v !== "");
  if (added.length) {
    if (rewritten.length && rewritten[rewritten.length - 1].trim() !== "") {
      rewritten.push("");
    }
    rewritten.push("# Added by the setup page");
    for (const [key, value] of added) rewritten.push(`${key}=${value}`);
  }

  writeFileSync(ENV_PATH, rewritten.join("\n"), "utf8");
  cache = null;
}

export function envFilePath(): string {
  return ENV_PATH;
}

/**
 * The setup page can write credentials to disk, so it is restricted to
 * requests that actually originated on this machine. `next dev` binds to every
 * interface, which means without this check anyone on the same network could
 * open the page and read or replace the stored tokens.
 */
export function isLocalRequest(request: Request): boolean {
  const host = request.headers.get("host") ?? "";
  const hostname = host.replace(/:\d+$/, "").replace(/^\[|\]$/g, "");
  return (
    hostname === "localhost" ||
    hostname === "127.0.0.1" ||
    hostname === "::1" ||
    hostname.endsWith(".localhost")
  );
}
