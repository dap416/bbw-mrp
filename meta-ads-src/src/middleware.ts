import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";
import { hasValidSsoToken, isProduction } from "@/lib/auth";

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

const GATE = "/meta_gate.php";

function unauthorized(request: NextRequest) {
  // Behind the proxy the app's own idea of its origin is the loopback address
  // it binds to, so deriving the gate URL from the request sends the browser to
  // localhost:3100. A relative Location would resolve correctly, but middleware
  // rejects one as an invalid URL — hence the public origin, configured rather
  // than inferred. Falling back to the request origin keeps local dev working,
  // where the two are the same anyway.
  const origin = process.env.META_PUBLIC_ORIGIN || request.nextUrl.origin;
  const url = new URL(GATE, origin);
  url.searchParams.set("next", request.nextUrl.pathname + request.nextUrl.search);
  return NextResponse.redirect(url, 307);
}

export async function middleware(request: NextRequest) {
  if (await hasValidSsoToken(request)) return NextResponse.next();

  // Development runs standalone: there is no MRP to sign a token, and the gate
  // this would redirect to does not exist, so requiring one would only make the
  // app unopenable. The check is on the environment, not on whether a secret is
  // configured — a deployed build that lost its .env.local must fail closed
  // rather than serve the dashboard to anyone who asks.
  if (!isProduction() && !process.env.META_SSO_SECRET) return NextResponse.next();

  return unauthorized(request);
}

export const config = {
  // Everything is gated, including the API routes — they are what actually
  // return the data. Next's own static chunks under /_next are exempt: they
  // hold no data, and a redirect there breaks the page for a signed-in user.
  //
  // "/" is listed separately: Next compiles the group in the pattern below as a
  // required path segment, so it covers /setup and /api/* but never the root —
  // which would leave the dashboard itself the one ungated page.
  matcher: ["/", "/((?!_next/static|_next/image|favicon.ico).*)"],
};
