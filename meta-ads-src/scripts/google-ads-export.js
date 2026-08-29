/**
 * Google Ads Script — exports campaign performance by day into a Google Sheet.
 *
 * WHY THIS EXISTS
 *
 * The Google Ads API would need a developer token at Basic Access, which means
 * a formal application, a design document, a public business domain and a
 * multi-day review — all to read our own numbers. A Google Ads Script runs
 * inside the account itself, so it is already authorised: there is nothing to
 * apply for. It writes to a Sheet, the Sheet is published as CSV, and the ads
 * dashboard reads that CSV. Same data, no approvals.
 *
 * INSTALLING IT
 *
 *   1. Create a Google Sheet. Copy its URL into SHEET_URL below.
 *   2. In Google Ads: Tools > Bulk actions > Scripts > + New script.
 *   3. Paste this file in, click Authorise, then Preview to check it runs.
 *   4. Save, then set a schedule. HOURLY is the right choice: the dashboard
 *      opens on Today, and an export that runs once a day leaves Google
 *      reading empty next to Meta's live figures for most of the working day.
 *      Hourly costs nothing here — the query returns a few dozen rows.
 *   5. In the Sheet: File > Share > Publish to web > choose the "Ads Export"
 *      sheet, format Comma-separated values (.csv), Publish. Put that URL in
 *      the dashboard's setup page as the Google Sheet CSV URL.
 *
 * NOTES
 *
 *   - Runs against the account it is installed in. To run it across a manager
 *     account instead, see the MCC note at the bottom of this file.
 *   - It rewrites the whole sheet each run rather than appending, so a
 *     re-stated conversion figure corrects itself. LOOKBACK_DAYS therefore
 *     controls how much history the dashboard can see.
 *   - Conversions keep being attributed for days after the click, which is why
 *     the lookback re-exports old days instead of only writing the newest one.
 *   - Today's row is always partial and climbs through the day. The dashboard
 *     already flags any range running to today as still moving, so this is
 *     consistent with how Meta's own figures behave there.
 */

/** The Google Sheet to write into. Paste your own URL here. */
var SHEET_URL = 'PASTE_YOUR_GOOGLE_SHEET_URL_HERE';

/** Tab name inside that spreadsheet. Created automatically if missing. */
var SHEET_NAME = 'Ads Export';

/**
 * How many days back to export on every run. 90 covers the dashboard's longest
 * preset with room to spare; raising it costs only script runtime.
 */
var LOOKBACK_DAYS = 90;

function main() {
  if (SHEET_URL.indexOf('PASTE_YOUR') === 0) {
    throw new Error(
      'Set SHEET_URL at the top of this script to your Google Sheet URL first.',
    );
  }

  var range = dateRange(LOOKBACK_DAYS);
  Logger.log('Exporting ' + range.since + ' to ' + range.until);

  var rows = fetchRows(range);
  Logger.log('Fetched ' + rows.length + ' campaign-day rows.');

  writeSheet(rows);
  Logger.log('Done. Sheet updated.');
}

/**
 * Today backwards, in the account's own timezone — using the script runner's
 * timezone would file spend under the wrong day either side of midnight.
 *
 * Today is INCLUDED even though it is still accumulating. Excluding it looked
 * tidier but was wrong in practice: the dashboard opens on Today, so a
 * yesterday-only export left Google reading "no data" every single day, while
 * Meta showed live figures beside it. A partial number the dashboard already
 * labels as partial beats an absent one that looks like a decision not to
 * advertise.
 *
 * The corollary is that this script has to run more than once a day to be
 * worth anything on the Today view — see the schedule note in the header.
 */
function dateRange(lookbackDays) {
  var timezone = AdsApp.currentAccount().getTimeZone();
  var now = new Date();

  var since = new Date(now.getTime() - lookbackDays * 24 * 60 * 60 * 1000);

  return {
    since: Utilities.formatDate(since, timezone, 'yyyy-MM-dd'),
    until: Utilities.formatDate(now, timezone, 'yyyy-MM-dd'),
  };
}

function fetchRows(range) {
  var query =
    'SELECT campaign.name, segments.date, metrics.cost_micros, ' +
    'metrics.impressions, metrics.clicks, metrics.conversions, ' +
    'metrics.conversions_value ' +
    'FROM campaign ' +
    "WHERE segments.date BETWEEN '" + range.since + "' AND '" + range.until + "' " +
    'ORDER BY segments.date ASC';

  var iterator = AdsApp.search(query);
  var rows = [];

  while (iterator.hasNext()) {
    var row = iterator.next();
    var metrics = row.metrics || {};

    var impressions = Number(metrics.impressions || 0);
    var clicks = Number(metrics.clicks || 0);
    var conversions = Number(metrics.conversions || 0);
    var cost = Number(metrics.costMicros || 0) / 1000000;
    var value = Number(metrics.conversionsValue || 0);

    // A campaign that did not run that day contributes nothing and would only
    // pad the sheet with zero rows.
    if (!impressions && !clicks && !cost) continue;

    rows.push([
      row.segments.date,
      row.campaign.name,
      round2(cost),
      impressions,
      clicks,
      round2(conversions),
      round2(value),
    ]);
  }

  return rows;
}

function round2(value) {
  return Math.round(value * 100) / 100;
}

/**
 * Rewrites the tab from scratch.
 *
 * The header names are the ones the dashboard's importer recognises — it also
 * accepts Google's own export spellings, so these are chosen to match what a
 * manual export would produce. Changing them means changing the importer.
 */
function writeSheet(rows) {
  var spreadsheet = SpreadsheetApp.openByUrl(SHEET_URL);
  var sheet = spreadsheet.getSheetByName(SHEET_NAME);
  if (!sheet) sheet = spreadsheet.insertSheet(SHEET_NAME);

  sheet.clear();

  var header = [
    'Day',
    'Campaign',
    'Cost',
    'Impressions',
    'Clicks',
    'Conversions',
    'Conv. value',
  ];
  sheet.getRange(1, 1, 1, header.length).setValues([header]);

  if (rows.length) {
    sheet.getRange(2, 1, rows.length, header.length).setValues(rows);
  }

  /*
    Plain number formats, no thousands separators and no currency symbol.
    Published-CSV output uses the DISPLAYED value, so a cell formatted as
    "$1,240.50" would reach the dashboard as an unquoted comma and shift every
    column after it. The importer now refuses such rows rather than misreading
    them, which would show up as silently missing days.
  */
  sheet.getRange(1, 1, sheet.getMaxRows(), 1).setNumberFormat('@'); // date as text
  sheet.getRange(1, 3, sheet.getMaxRows(), 1).setNumberFormat('0.00');
  sheet.getRange(1, 4, sheet.getMaxRows(), 2).setNumberFormat('0');
  sheet.getRange(1, 6, sheet.getMaxRows(), 2).setNumberFormat('0.00');
}

/*
 * RUNNING IT ACROSS A MANAGER (MCC) ACCOUNT
 *
 * Install the script on the manager account instead, and wrap the work in an
 * account iterator. Each account needs its own sheet or its own tab, since the
 * dashboard treats one CSV as one platform:
 *
 *   function main() {
 *     var accounts = AdsManagerApp.accounts().withIds(['954-898-5988']).get();
 *     while (accounts.hasNext()) {
 *       AdsManagerApp.select(accounts.next());
 *       writeSheet(fetchRows(dateRange(LOOKBACK_DAYS)));
 *     }
 *   }
 *
 * For a single advertising account, installing directly on that account (as
 * above) is simpler and is what these instructions assume.
 */
