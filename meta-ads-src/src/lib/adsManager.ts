/**
 * Deep links into Meta's own Ads Manager.
 *
 * The point of the drill-down is to end somewhere useful: once you have found
 * the ad that is losing money here, the next thing you want is that ad open in
 * the place you can change it. Ads Manager accepts a preselected entity id and
 * opens filtered to it, which saves hunting through a campaign tree by name.
 */

import type { EntityRow, Level } from "./types";

const BASE = "https://adsmanager.facebook.com/adsmanager/manage";

/** The tab that shows rows at this level. */
const TAB: Record<Exclude<Level, "account">, string> = {
  campaign: "campaigns",
  adset: "adsets",
  ad: "ads",
};

const SELECTOR: Record<Exclude<Level, "account">, string> = {
  campaign: "selected_campaign_ids",
  adset: "selected_adset_ids",
  ad: "selected_ad_ids",
};

/**
 * Null when we cannot build an honest link — the demo account has no real id,
 * and a link that 404s is worse than no link.
 */
export function adsManagerUrl(row: EntityRow, accountId: string): string | null {
  if (row.level === "account") return null;
  const act = accountId.replace(/^act_/, "");
  if (!/^\d+$/.test(act)) return null;

  const params = new URLSearchParams({ act });
  // Preselecting the parents as well is what makes Ads Manager open with the
  // tree already expanded around the row, rather than at the account root.
  if (row.campaignId) params.set("selected_campaign_ids", row.campaignId);
  if (row.adsetId) params.set("selected_adset_ids", row.adsetId);
  params.set(SELECTOR[row.level], row.id);

  return `${BASE}/${TAB[row.level]}?${params.toString()}`;
}
