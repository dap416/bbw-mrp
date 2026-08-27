/**
 * Next's basePath rewrites links and asset URLs, but leaves fetch() alone — a
 * bare fetch("/api/insights") from the browser would go to the host root, which
 * in production is the MRP app rather than this one. Every client-side request
 * goes through here instead.
 */
const BASE = process.env.NEXT_PUBLIC_BASE_PATH || "";

export function api(path: string): string {
  return `${BASE}${path}`;
}
