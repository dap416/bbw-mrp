import { NextResponse } from "next/server";
import { isAuthorized } from "@/lib/auth";
import { testShopifyConnection } from "@/lib/shopify";

export const dynamic = "force-dynamic";

/**
 * Tests Shopify credentials for the setup page.
 *
 * Takes them in the body so a pasted Client ID and secret can be checked
 * before they are written to disk. Guards on its own rather than trusting the
 * middleware, exactly as the sibling setup route does — this one makes
 * outbound calls with whatever credentials it is handed.
 */
export async function POST(request: Request) {
  if (!(await isAuthorized(request))) {
    return NextResponse.json(
      { error: "Not authorised. This needs Edit access to Meta Ads in the MRP." },
      { status: 403 },
    );
  }

  let body: Record<string, unknown> = {};
  try {
    body = (await request.json()) as Record<string, unknown>;
  } catch {
    // An empty body means "test what is already saved".
  }

  /**
   * A masked value means the field was not edited in the form, so the stored
   * secret should be used rather than a string of dots.
   */
  const field = (key: string): string | undefined => {
    const raw = body[key];
    if (typeof raw !== "string") return undefined;
    const value = raw.trim();
    if (!value || value.includes("••")) return undefined;
    return value;
  };

  const result = await testShopifyConnection({
    domain: field("SHOPIFY_STORE_DOMAIN"),
    token: field("SHOPIFY_ADMIN_TOKEN"),
    clientId: field("SHOPIFY_CLIENT_ID"),
    clientSecret: field("SHOPIFY_CLIENT_SECRET"),
  });

  // 200 either way: a failed test is a result to render, not a broken request.
  return NextResponse.json(result);
}
