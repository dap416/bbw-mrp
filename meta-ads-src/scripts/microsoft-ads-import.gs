/**
 * Google Apps Script — turns Microsoft Advertising's emailed report into a
 * sheet the ads dashboard can read.
 *
 * WHY THIS EXISTS
 *
 * Google Ads lets a script run inside the ad account, which is how that
 * platform's export works with no API credentials at all. Microsoft has no
 * equivalent: its Reporting API needs an Entra app registration, a client
 * secret and an OAuth refresh token — a lot of credential handling for a
 * read-only report.
 *
 * Microsoft will, however, email a scheduled report. So: Microsoft mails the
 * CSV, this script reads it out of Gmail and writes it into the same
 * spreadsheet the Google export uses, and the dashboard reads that tab as
 * published CSV. Same shape as Google, no Microsoft credentials anywhere.
 *
 * INSTALLING IT
 *
 *   1. In Microsoft Advertising: Reporting > create a Campaign performance
 *      report, broken down by day, and schedule it Daily, delivered by email
 *      as CSV to the Gmail account that owns this spreadsheet.
 *   2. Open the spreadsheet > Extensions > Apps Script. Paste this file in.
 *   3. Set SPREADSHEET_ID below (it is in the sheet's own URL).
 *   4. Run `importMicrosoftReport` once and grant the Gmail + Sheets access it
 *      asks for. Check the execution log says how many rows it wrote.
 *   5. Triggers (clock icon) > add a time-driven trigger for
 *      `importMicrosoftReport`, every 4 hours or so. There is no point going
 *      faster than Microsoft sends the mail.
 *   6. In the sheet: File > Share > Publish to web > the "Microsoft Export"
 *      tab > Comma-separated values (.csv) > Publish. Put that URL in the
 *      dashboard's setup page as the Microsoft Sheet CSV URL.
 *
 * NOTES
 *
 *   - Rows are MERGED into whatever is already there, keyed by date and
 *     campaign, newest wins. Each email is only a snapshot of a rolling
 *     window, so merging is what accumulates history — unlike the Google
 *     script, which re-queries 90 days every run and can safely overwrite.
 *   - If no matching email is found, the sheet is left exactly as it is.
 *     Wiping good history because a mail was late would be far worse than
 *     showing figures that are a day stale, which the dashboard flags anyway.
 */

/** The spreadsheet to write into — the same one the Google export uses. */
var SPREADSHEET_ID = 'PASTE_YOUR_SPREADSHEET_ID_HERE';

/** Tab to write. Created automatically. Keep it distinct from Google's. */
var SHEET_NAME = 'Microsoft Export';

/**
 * Gmail search that finds the report. Widen it if the mail is not being
 * picked up: `from:` alone plus an attachment is usually enough.
 */
var GMAIL_QUERY = 'from:(microsoft.com) has:attachment newer_than:7d';

/** Never keep more than this many days of history in the tab. */
var MAX_HISTORY_DAYS = 120;

/** The header the dashboard's importer expects — identical to Google's tab. */
var HEADER = [
  'Day',
  'Campaign',
  'Cost',
  'Impressions',
  'Clicks',
  'Conversions',
  'Conv. value',
];

function importMicrosoftReport() {
  if (SPREADSHEET_ID.indexOf('PASTE_YOUR') === 0) {
    throw new Error('Set SPREADSHEET_ID at the top of this script first.');
  }

  var csv = findLatestReportCsv();
  if (!csv) {
    Logger.log('No matching report email found. Sheet left unchanged.');
    return;
  }

  var rows = parseReport(csv);
  Logger.log('Parsed ' + rows.length + ' rows from the report.');
  if (!rows.length) {
    Logger.log('Nothing usable in that attachment. Sheet left unchanged.');
    return;
  }

  var written = mergeIntoSheet(rows);
  Logger.log('Sheet now holds ' + written + ' rows.');
}

/* --- Getting the CSV out of Gmail ---------------------------------------- */

/**
 * The newest CSV attachment matching the query. Microsoft sends either a bare
 * .csv or a .zip containing one, depending on report size, so both are
 * handled rather than assuming the small case.
 */
function findLatestReportCsv() {
  var threads = GmailApp.search(GMAIL_QUERY, 0, 20);
  var newest = null;
  var newestDate = 0;

  for (var t = 0; t < threads.length; t++) {
    var messages = threads[t].getMessages();
    for (var m = 0; m < messages.length; m++) {
      var message = messages[m];
      var when = message.getDate().getTime();
      if (when <= newestDate) continue;

      var text = extractCsv(message);
      if (text) {
        newest = text;
        newestDate = when;
      }
    }
  }

  if (newest) Logger.log('Using report emailed ' + new Date(newestDate));
  return newest;
}

function extractCsv(message) {
  var attachments = message.getAttachments();

  for (var i = 0; i < attachments.length; i++) {
    var attachment = attachments[i];
    var name = (attachment.getName() || '').toLowerCase();

    if (name.slice(-4) === '.csv') {
      return attachment.getDataAsString();
    }

    if (name.slice(-4) === '.zip') {
      var files = Utilities.unzip(attachment.copyBlob());
      for (var f = 0; f < files.length; f++) {
        var inner = (files[f].getName() || '').toLowerCase();
        if (inner.slice(-4) === '.csv') return files[f].getDataAsString();
      }
    }
  }
  return null;
}

/* --- Parsing ------------------------------------------------------------- */

/**
 * Microsoft's exports carry several preamble lines above the real header —
 * report name, account, date range — so the header is found by looking for a
 * date column rather than assumed to be line one.
 */
function parseReport(text) {
  var lines = text.split(/\r?\n/);
  var headerIndex = -1;
  var header = null;

  for (var i = 0; i < lines.length && i < 30; i++) {
    var cells = splitCsvLine(lines[i]).map(normalizeHeader);
    if (indexOfAny(cells, ['date', 'gregorian date', 'day']) !== -1) {
      headerIndex = i;
      header = cells;
      break;
    }
  }

  if (headerIndex === -1) {
    Logger.log('Could not find a header row with a date column.');
    return [];
  }

  var col = {
    date: indexOfAny(header, ['date', 'gregorian date', 'day']),
    campaign: indexOfAny(header, ['campaign', 'campaign name']),
    spend: indexOfAny(header, ['spend', 'cost']),
    impressions: indexOfAny(header, ['impressions', 'impr']),
    clicks: indexOfAny(header, ['clicks']),
    conversions: indexOfAny(header, ['conversions', 'conv']),
    revenue: indexOfAny(header, ['revenue', 'conv value', 'conversion value']),
  };

  if (col.spend === -1) {
    Logger.log('No spend/cost column. Header was: ' + header.join(', '));
    return [];
  }

  var rows = [];
  for (var j = headerIndex + 1; j < lines.length; j++) {
    if (!lines[j].trim()) continue;

    var cells = splitCsvLine(lines[j]);
    // More cells than headers means an unquoted comma shifted the row; every
    // figure after it would land in the wrong column, so drop it.
    if (cells.length > header.length) continue;

    var date = normalizeDate(cells[col.date]);
    if (!date) continue; // Preamble and the export's own total row land here.

    rows.push([
      date,
      col.campaign === -1 || !cells[col.campaign]
        ? 'All campaigns'
        : cells[col.campaign],
      toNumber(cells[col.spend]),
      toNumber(cells[col.impressions]),
      toNumber(cells[col.clicks]),
      toNumber(cells[col.conversions]),
      toNumber(cells[col.revenue]),
    ]);
  }

  return rows;
}

function indexOfAny(cells, names) {
  for (var i = 0; i < cells.length; i++) {
    for (var n = 0; n < names.length; n++) {
      if (cells[i] === names[n]) return i;
    }
  }
  return -1;
}

function normalizeHeader(value) {
  return String(value || '')
    .toLowerCase()
    .replace(/[.()]/g, '')
    .replace(/[_/]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

/** Minimal RFC-4180 split — enough for quoted campaign names with commas. */
function splitCsvLine(line) {
  var out = [];
  var field = '';
  var quoted = false;

  for (var i = 0; i < line.length; i++) {
    var ch = line.charAt(i);
    if (quoted) {
      if (ch === '"') {
        if (line.charAt(i + 1) === '"') {
          field += '"';
          i++;
        } else {
          quoted = false;
        }
      } else {
        field += ch;
      }
    } else if (ch === '"') {
      quoted = true;
    } else if (ch === ',') {
      out.push(field);
      field = '';
    } else {
      field += ch;
    }
  }
  out.push(field);

  for (var k = 0; k < out.length; k++) out[k] = out[k].trim();
  return out;
}

/**
 * Accepts YYYY-MM-DD and M/D/YYYY. Anything else is refused rather than
 * guessed at — a misread date files spend under the wrong day silently.
 */
function normalizeDate(raw) {
  var value = String(raw || '').trim();
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value;

  var slash = value.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
  if (slash) {
    return (
      slash[3] + '-' + pad2(slash[1]) + '-' + pad2(slash[2])
    );
  }
  return null;
}

function pad2(n) {
  return String(n).length < 2 ? '0' + n : String(n);
}

/** Strips currency symbols, thousands separators and percent signs. */
function toNumber(raw) {
  if (raw === undefined || raw === null) return 0;
  var cleaned = String(raw).replace(/[^0-9.\-]/g, '');
  var value = Number(cleaned);
  return isNaN(value) ? 0 : Math.round(value * 100) / 100;
}

/* --- Writing ------------------------------------------------------------- */

/**
 * Merges by date + campaign, the incoming row winning. Each email is one
 * snapshot of a rolling window, so replacing the tab outright would throw
 * away every day the newest report no longer covers.
 */
function mergeIntoSheet(incoming) {
  var spreadsheet = SpreadsheetApp.openById(SPREADSHEET_ID);
  var sheet = spreadsheet.getSheetByName(SHEET_NAME);
  if (!sheet) sheet = spreadsheet.insertSheet(SHEET_NAME);

  var byKey = {};

  // Existing rows first, so the incoming ones overwrite on collision.
  var lastRow = sheet.getLastRow();
  if (lastRow > 1) {
    var existing = sheet.getRange(2, 1, lastRow - 1, HEADER.length).getValues();
    for (var i = 0; i < existing.length; i++) {
      var row = existing[i];
      if (!row[0]) continue;
      byKey[keyOf(row)] = row;
    }
  }

  for (var j = 0; j < incoming.length; j++) {
    byKey[keyOf(incoming[j])] = incoming[j];
  }

  var cutoff = daysAgo(MAX_HISTORY_DAYS);
  var merged = [];
  for (var k in byKey) {
    if (!byKey.hasOwnProperty(k)) continue;
    if (String(byKey[k][0]) >= cutoff) merged.push(byKey[k]);
  }

  merged.sort(function (a, b) {
    if (a[0] === b[0]) return String(a[1]) < String(b[1]) ? -1 : 1;
    return String(a[0]) < String(b[0]) ? -1 : 1;
  });

  sheet.clear();
  sheet.getRange(1, 1, 1, HEADER.length).setValues([HEADER]);
  if (merged.length) {
    sheet.getRange(2, 1, merged.length, HEADER.length).setValues(merged);
  }

  /*
    Plain number formats. Published-CSV output uses the DISPLAYED value, so a
    cell shown as "$1,240.50" would reach the dashboard as an unquoted comma
    and shift every column after it.
  */
  sheet.getRange(1, 1, sheet.getMaxRows(), 1).setNumberFormat('@');
  sheet.getRange(1, 3, sheet.getMaxRows(), 1).setNumberFormat('0.00');
  sheet.getRange(1, 4, sheet.getMaxRows(), 2).setNumberFormat('0');
  sheet.getRange(1, 6, sheet.getMaxRows(), 2).setNumberFormat('0.00');

  return merged.length;
}

function keyOf(row) {
  return String(row[0]) + '|' + String(row[1]).toLowerCase();
}

function daysAgo(days) {
  var d = new Date(new Date().getTime() - days * 24 * 60 * 60 * 1000);
  return Utilities.formatDate(d, Session.getScriptTimeZone(), 'yyyy-MM-dd');
}
