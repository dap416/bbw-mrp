/**
 * Verification of the single sign-on token minted by the MRP app.
 *
 * meta_gate.php checks the MRP session and the user's access to the Meta Ads
 * area, then sets a cookie of the form
 * `<expiry>.<user id>.<level>.<hmac of the first three fields>`. Nothing here
 * issues tokens; this only checks them.
 *
 * Deliberately dependency-free and built on Web Crypto rather than node:crypto,
 * so the same code runs in the middleware's edge runtime and in the Node
 * runtime the route handlers use. Both need it: middleware is the gate, and the
 * routes that write to disk check again for themselves rather than trusting
 * that the gate ran, because Next has shipped more than one advisory in which a
 * forged header makes middleware skip itself.
 *
 * This module must not import anything that touches node:fs — middleware would
 * fail to compile.
 */

export const SSO_COOKIE = "meta_sso";

function readCookie(request: Request, name: string): string | null {
  // Parsed off the raw header rather than NextRequest.cookies so that route
  // handlers, which receive a plain Request, can use this too.
  const header = request.headers.get("cookie");
  if (!header) return null;
  for (const part of header.split(";")) {
    const eq = part.indexOf("=");
    if (eq < 0) continue;
    if (part.slice(0, eq).trim() === name) return part.slice(eq + 1).trim();
  }
  return null;
}

function timingSafeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return diff === 0;
}

async function hmac(message: string, secret: string): Promise<string> {
  const key = await crypto.subtle.importKey(
    "raw",
    new TextEncoder().encode(secret),
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["sign"],
  );
  const sig = await crypto.subtle.sign("HMAC", key, new TextEncoder().encode(message));
  return Array.from(new Uint8Array(sig))
    .map((b) => b.toString(16).padStart(2, "0"))
    .join("");
}

/**
 * A deployed build, as opposed to `next dev` on someone's machine.
 *
 * Everything below keys off this rather than off whether a secret happens to be
 * configured. Keying off the secret would mean a production build that lost its
 * .env.local served an ad dashboard, with the tokens behind /api/setup, to the
 * open internet — the failure has to land on the safe side.
 */
export function isProduction(): boolean {
  return process.env.NODE_ENV === "production";
}

/**
 * The MRP permission level the token was signed with: 0 none, 1 view, 2 edit.
 *
 * Mirrors access_level() in the MRP app. View opens the dashboard; Edit is
 * additionally required for Setup and Adjustments, which can overwrite the
 * stored API tokens and restate revenue.
 */
export type AuthLevel = 0 | 1 | 2;

/**
 * The level this request proves, or 0 for anything unsigned, forged or expired.
 *
 * Payload is `<expiry>.<user id>.<level>`; the signature covers all three, so
 * the level cannot be edited without invalidating it.
 */
export async function ssoLevel(request: Request): Promise<AuthLevel> {
  const secret = process.env.META_SSO_SECRET;
  if (!secret) return 0;

  const token = readCookie(request, SSO_COOKIE);
  if (!token) return 0;

  const cut = token.lastIndexOf(".");
  if (cut < 0) return 0;
  const payload = token.slice(0, cut);

  const expected = await hmac(payload, secret);
  if (!timingSafeEqual(token.slice(cut + 1), expected)) return 0;

  const fields = payload.split(".");
  const expiry = Number(fields[0]);
  if (!Number.isFinite(expiry) || expiry * 1000 <= Date.now()) return 0;

  // A token minted before the level was added carries only expiry and user id.
  // Treat it as view: it is signed, so it is genuine, but it never proved edit.
  // These expire within hours of the change and are not re-issued.
  const level = Number(fields[2]);
  if (level === 2) return 2;
  return 1;
}

/** True when the request carries a signed, unexpired token from the gate. */
export async function hasValidSsoToken(request: Request): Promise<boolean> {
  return (await ssoLevel(request)) > 0;
}

/**
 * Whether the request came from this machine.
 *
 * `next dev` binds every interface, so in development this is what stops a
 * device on the same wifi reading or replacing the stored tokens. It means
 * nothing in production, where every request arrives from the Apache proxy on
 * loopback and would pass — which is why it is never consulted there.
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

/**
 * The check for endpoints that read or write credentials and stored data.
 *
 * These need Edit, not merely access: Setup can overwrite the Meta, Shopify and
 * Anthropic tokens, and Adjustments restates revenue. Someone granted View sees
 * the figures and nothing more.
 *
 * In development there is no MRP to mint a token, so the old same-machine rule
 * still stands in for it.
 */
export async function isAuthorized(request: Request): Promise<boolean> {
  if (isProduction()) return (await ssoLevel(request)) >= 2;
  return isLocalRequest(request) || (await ssoLevel(request)) >= 2;
}
