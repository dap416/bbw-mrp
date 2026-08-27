import { NextResponse } from "next/server";
import { MetaError, listAdAccounts } from "@/lib/meta";

export const dynamic = "force-dynamic";

/**
 * Lists the ad accounts the configured token can read. This exists so the
 * first-run answer to "what do I put in META_AD_ACCOUNT_ID" is one page load
 * rather than a trip through Business Manager.
 */
export async function GET() {
  if (!process.env.META_ACCESS_TOKEN) {
    return NextResponse.json(
      { error: "META_ACCESS_TOKEN is not set." },
      { status: 400 },
    );
  }

  try {
    const accounts = await listAdAccounts();
    return NextResponse.json({
      accounts,
      configured: process.env.META_AD_ACCOUNT_ID ?? null,
      hint: accounts.length
        ? "Copy the `id` of the account you want into META_AD_ACCOUNT_ID in .env.local."
        : "This token can't see any ad accounts. Check it was issued by a System User with ads_read and that the account is assigned to that System User.",
    });
  } catch (err) {
    if (err instanceof MetaError) {
      return NextResponse.json(
        { error: err.message, code: err.code },
        { status: err.isAuthError ? 401 : 502 },
      );
    }
    return NextResponse.json(
      { error: err instanceof Error ? err.message : "Unexpected error" },
      { status: 500 },
    );
  }
}
