/**
 * The dashboard is served from https://mrp.bbwmanager.com/meta in production —
 * Apache reverse-proxies that path to this app — but from the root in local
 * development. basePath therefore comes from the environment rather than being
 * hardcoded, so the same source runs in both places.
 *
 * Note that basePath rewrites <Link> hrefs and asset URLs but NOT fetch() calls;
 * client code must route its own requests through the helper in lib/basePath.ts.
 */
const basePath = process.env.NEXT_PUBLIC_BASE_PATH || "";

/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  basePath,
  // Behind a proxy the app never sees its public origin; this keeps redirects
  // and asset URLs on /meta instead of falling back to the bare host.
  assetPrefix: basePath || undefined,
};

export default nextConfig;
