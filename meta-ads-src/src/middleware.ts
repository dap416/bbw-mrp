import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";

/**
 * Single sign-on with MRP.
 *
 * This app has no user table and no login of its own, but it exposes ad spend,
 * store revenue and — through /api/setup — the Meta and Shopify tokens
 * themselves. Behind Apache it shares an origin with MRP, so MRP does the
 * authenticating: meta_gate.php checks the PHP session and is_owner(), then
 * issues the signed cookie this verifies.
 *
 * A PHP session cookie is meaningless here (there is no PHP to hand it to),
 * hence the separate signed token. It carries only an expiry and a user id, so
 * a stolen one grants nothing beyond the window it was minted for.
 */

const COOKIE = "meta_sso";
const GATE = "/meta_gate.php";

function unauthorized(request: NextRequest) {
  // A relative Location on purpose. Behind the proxy this app's own idea of its
  // origin is the loopback address it binds to, so NextResponse.redirect() —
  // which requires an absolute URL — sends the browser to localhost:3100. The
  // browser resolves a relative Location against the address it actually asked
  // for, which is the only one that is correct here.
  const next = request.nextUrl.pathname + request.nextUrl.search;
  const location = `${GATE}?next=${encodeURIComponent(next)}`;
  return new NextResponse(null, { status: 307, headers: { Location: location } });
}

function timingSafeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return diff === 0;
}

async function hmac(message: string, secret: string): Promise<string> {
  // Node's crypto is unavailable in the middleware runtime; Web Crypto is.
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

export async function middleware(request: NextRequest) {
  const secret = process.env.META_SSO_SECRET;

  // No secret configured means the signature cannot be checked, so nothing can
  // be trusted. Fail closed: an unauthenticated ad dashboard on a public host
  // is worse than one that is temporarily unreachable.
  if (!secret) return unauthorized(request);

  const token = request.cookies.get(COOKIE)?.value;
  if (!token) return unauthorized(request);

  // <expiry seconds>.<user id>.<hex hmac of the first two fields>
  const cut = token.lastIndexOf(".");
  if (cut < 0) return unauthorized(request);
  const payload = token.slice(0, cut);
  const signature = token.slice(cut + 1);

  const expected = await hmac(payload, secret);
  if (!timingSafeEqual(signature, expected)) return unauthorized(request);

  const expiry = Number(payload.split(".")[0]);
  if (!Number.isFinite(expiry) || expiry * 1000 < Date.now()) return unauthorized(request);

  return NextResponse.next();
}

export const config = {
  // Everything is gated, including the API routes — they are what actually
  // return the data. Next's own static chunks under /_next are exempt: they
  // hold no data, and a redirect there breaks the page for a signed-in user.
  // "/" is listed separately: Next compiles the group in the pattern below as a
  // required path segment, so it covers /setup and /api/* but never the root —
  // which would leave the dashboard itself the one ungated page.
  matcher: ["/", "/((?!_next/static|_next/image|favicon.ico).*)"],
};
