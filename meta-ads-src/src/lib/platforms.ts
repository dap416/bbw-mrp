/**
 * The three ad platforms this dashboard balances.
 *
 * Meta arrives over its Graph API; Google and Microsoft do not yet, because
 * both require a developer token and an OAuth consent that have to be applied
 * for. Until those land, their figures come from the manual/CSV store in
 * `platformData.ts` — see `providers.ts` for where that seam sits. Nothing
 * outside those two files needs to know which platform is live and which is
 * hand-entered, which is the point: adding the real APIs later is a change to
 * one function per platform, not to the dashboard.
 */

export const PLATFORMS = ["meta", "google", "microsoft"] as const;

export type Platform = (typeof PLATFORMS)[number];

/** Includes the combined roll-up, which is a view rather than a platform. */
export type PlatformView = Platform | "all";

export function isPlatform(value: unknown): value is Platform {
  return typeof value === "string" && (PLATFORMS as readonly string[]).includes(value);
}

interface PlatformMeta {
  label: string;
  /** Short form for tight table columns. */
  short: string;
  /** Chart/series colour, so a platform reads the same everywhere. */
  color: string;
  /** How this platform's numbers get here. Drives the UI's honesty labels. */
  source: "api" | "manual";
}

export const PLATFORM_META: Record<Platform, PlatformMeta> = {
  meta: {
    label: "Meta Ads",
    short: "Meta",
    color: "#1877f2",
    source: "api",
  },
  google: {
    label: "Google Ads",
    short: "Google",
    color: "#34a853",
    source: "manual",
  },
  microsoft: {
    label: "Microsoft Ads",
    short: "Microsoft",
    color: "#f7630c",
    source: "manual",
  },
};

export function platformLabel(platform: Platform): string {
  return PLATFORM_META[platform].label;
}
