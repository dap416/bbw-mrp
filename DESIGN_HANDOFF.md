# BBW MRP — Design/Front-End Handoff

**Purpose of this doc:** everything a Claude design agent needs to build new pages (specifically a new **dashboard**) that look and behave like the rest of the app. Read this before writing any markup, CSS, or chart code.

---

## 1. What the app is

**Blue Bird Waterfowl MRP** — an internal manufacturing/inventory (MRP) web app for a small manufacturer. Tracks parts, orders, inventory, packaging/build, manufacturers, plus business modules (tasks, call center, cash flow, research, QuickBooks/Shopify integrations, AI assistants). Users are staff with per-area permissions.

The existing dashboard is `home.php`. A "dashboard" here means a single page of KPI tiles + cards containing tables and Chart.js charts.

---

## 2. Tech stack

| Layer | Choice |
|---|---|
| Language | **PHP** (plain, no framework), server-rendered |
| DB access | **PDO / MySQL** via `db_connect()` returning a `PDO` (see §7) |
| CSS/UI kit | **Berry** — a Bootstrap 5 admin template (`/berry/assets/...`) |
| Grid/utilities | **Bootstrap 5** (comes with Berry) |
| JS | **jQuery 3.6** + vanilla; **Chart.js 4.4** for all charts |
| Icons | **Tabler Icons** (primary, `ti ti-*`), plus Feather, FontAwesome, Phosphor, Material available |
| Font | **Inter** (Google Fonts), weights 400/500/700 |

**There is no build step, no bundler, no Node, no React.** Pages are `.php` files at the web root that echo HTML. CSS is plain `.css` files plus page-local `<style>` blocks. JS is `<script>` tags (CDN + Berry assets).

---

## 3. Constraints / workflow (important)

- **No local database on this machine.** Do not try to run or test against a DB here. Write code; it's deployed by pushing to git (`origin/main` → live server).
- **Schema changes** follow the repo convention: an **idempotent `setup_*.php` installer** that runs `CREATE TABLE IF NOT EXISTS` / guarded `ALTER TABLE` (catches "duplicate" errors as already-applied), and/or a `*_ensure_tables($db)` helper in `includes/`. Never assume a migration tool — there is none (no Alembic; that's a different, unrelated Python project).
- Match the surrounding code's style: **tab indentation**, PHP short echo `<?php echo ... ?>`, terse inline styles are normal here.

---

## 4. Page anatomy (Berry layout)

Every authenticated page is wrapped by a shared header and footer:

```php
<?php
    require_once(__DIR__."/includes/fns.php");
    require_login();                          // redirects to login if not authed
    require_once(__DIR__."/includes/header.php");  // opens <html>…<body>, sidebar, top header, and:
                                              //   <div class="pc-container"><div class="pc-content">
    $db = db_connect();
    // … queries + markup …
    require_once(__DIR__."/includes/footer.php");   // closes pc-content/pc-container, loads Berry JS
```

`header.php` renders:
- **Left sidebar** `nav.pc-sidebar` — logo + grouped menu (`pc-caption` section labels: MRP / Business / Admin). Menu items are gated by `has_access()` / `menu_visible()` / role (see §8). Submenus are forced permanently open by JS in the footer.
- **Top header** `header.pc-header` — "BBW MRP" title, a row of admin-only `.header-stat` value tiles (On-Hand, On-Order, Not Paid, Billed-Not-Received, Book Value), and the signed-in user + Sign Out.
- Opens the content wrapper: `<div class="pc-container"><div class="pc-content"> … </div></div>`.

**Your page content goes between the header and footer includes**, inside `.pc-content`. Use Bootstrap rows/cols directly.

---

## 5. CSS layering — where styles live

1. **Berry theme** — `/berry/assets/css/style.css` + `style-preset.css` (loaded in header). Provides `.card`, `.pc-*` layout, Bootstrap, theme colors. Don't edit Berry files.
2. **Site overrides** — `/css/css.css` (loaded after Berry). Global site classes: `.header-stat*`, `.stat-card`, `.link`, `.manage-area`, category-row helpers. Add **truly global** styles here.
3. **Page-local `<style>` block** — the dominant pattern. Each page defines its own component classes inline at the top/bottom (e.g. `home.php` defines `.kpi-card`, `.dash-table`, status pills). **For a new dashboard, put its component CSS in a page-local `<style>` block**, matching `home.php`.
4. **Inline `style="…"`** — used liberally for one-off colors/borders, often driven by PHP conditionals (e.g. `style="border-left:4px solid <?php echo $x>0?'#dc2626':'#adb5bd'; ?>"`). This is idiomatic here; don't fight it.

---

## 6. Reusable component patterns (copy these)

### 6a. KPI tile strip
Row of tiles, responsive `col-6 col-md-4 col-xl-2`. Class + page-local CSS from `home.php`:

```html
<div class="row g-3 mb-4">
  <div class="col-6 col-md-4 col-xl-2">
    <div class="kpi-card h-100" style="background:#eef2ff;border-left:4px solid #4680ff;">
      <i class="ti ti-packages kpi-icon" style="color:#4680ff;"></i>
      <div class="kpi-label" style="color:#4680ff;">On-Hand Value</div>
      <div class="kpi-value" style="color:#1e3a8a;">$123,456</div>
      <div class="kpi-sub">842 parts tracked</div>
    </div>
  </div>
  <!-- …more tiles… -->
</div>
```

```css
.kpi-card   { border-radius:10px; padding:20px 22px; position:relative; overflow:hidden; }
.kpi-icon   { position:absolute; right:16px; top:16px; font-size:2rem; opacity:0.18; }
.kpi-label  { font-size:0.72rem; text-transform:uppercase; letter-spacing:.06em; font-weight:600; }
.kpi-value  { font-size:1.75rem; font-weight:700; line-height:1.15; margin:4px 0 2px; }
.kpi-sub    { font-size:0.75rem; opacity:.75; }
```
Pattern: pale tinted `background` + solid `border-left` accent in the same hue; darker text shade for the value. Wrap the tile in `<a>` when it links somewhere (add `cursor:pointer`).

### 6b. Content card
Berry `.card` + accent border, holding a table or chart:

```html
<div class="card mb-3" style="border-top:3px solid #4680ff;">
  <div class="card-body">
    <div class="panel-header">
      <h6 class="panel-title">Section title</h6>
      <!-- optional action on the right -->
    </div>
    <!-- table / chart -->
  </div>
</div>
```
`.panel-title` / `.section-title`: `font-size:0.72rem; text-transform:uppercase; letter-spacing:.06em; font-weight:700; color:#6c757d;`

### 6c. Dashboard table
```html
<table class="table dash-table">…</table>
```
```css
.dash-table th { font-size:0.7rem; text-transform:uppercase; letter-spacing:.05em; color:#6c757d; font-weight:600; background:#f8f9fa; white-space:nowrap; }
.dash-table td { font-size:0.82rem; vertical-align:middle; }
.dash-table    { margin-bottom:0; }
.scroll-table  { max-height:320px; overflow-y:auto; }   /* wrap the table to cap height */
```

### 6d. Status pills
```css
.status-idle    { background:#fee2e2; color:#b91c1c; }
.status-excess2 { background:#fff7ed; color:#c2410c; }
.status-excess1 { background:#fef3c7; color:#92400e; }
.status-oos     { background:#fee2e2; color:#b91c1c; }
.status-low     { background:#fef3c7; color:#92400e; }
/* all share: */ font-size:0.68rem; padding:2px 8px; border-radius:20px; font-weight:600; white-space:nowrap;
```

### 6e. Collapsible section
`home.php` uses native `<details class="dash-section"><summary>…</summary>…</details>` with a rotating `ti-chevron-right`. Reuse for optional/secondary sections.

---

## 7. Data / DB access from a page

```php
$db = db_connect();                    // PDO, FETCH_ASSOC default, exceptions on
$val = (float)$db->query("SELECT COALESCE(SUM(qoh*cost),0) AS v FROM parts")->fetch()['v'];
$rows = $db->query("SELECT * FROM tasks WHERE completed = 0")->fetchAll();
$db->prepare("DELETE FROM tasks WHERE id = ?")->execute([$id]);   // params for user input
```
Notes: session runs with `sql_mode=''` (legacy permissive; zero-dates like `'0000-00-00'` exist in data — handle them). Timezone is America/Los_Angeles. `db_connect()` returns `false` on failure.

Core tables include: `parts`, `orders`, `trans`, `build`, `products`, `manufacturers`, `vendors`, `warehouses`, `part_warehouse_qty`, `users`, plus feature tables (`tasks`, `call_tickets`, cashflow tables, etc.).

---

## 8. Access control helpers (in `includes/fns.php`)

Use these to gate pages and menu items — a dashboard should respect them:

- `require_login()` — top of every page.
- `has_access($area)` — user can see area (`orders`, `inventory`, `build`, `products`, `manufacturers`, `users`, `call_center`, `research`, …).
- `access_level($area)` — `0` none / `1` view / `2` write.
- `can_edit($area)` — write gate.
- `menu_visible($key)` — respects the per-user "Menu View" (masters can hide items per user).
- `$_SESSION['user_role']` — `'user' | 'admin' | 'master'`; admin/master bypass most gates. `is_owner()` for George.
- Add a new permission area by extending the canonical list in `fns.php` (see the comment there) — it drives login hydration, the users.php editor, and `save_access.php` together.

---

## 9. AJAX pattern (for interactive dashboards)

Endpoints live under `/ajax/<module>/<action>.php`, return JSON:

```php
<?php
    require_once(__DIR__."/../../includes/fns.php");
    require_login();
    header('Content-Type: application/json');
    $db = db_connect();
    // … validate $_POST, do work …
    echo json_encode(['ok' => true, /* … */]);
```
Client side uses jQuery (`$.post`, `$(document).on('click', …)`). Guard mutations with the access helpers server-side.

---

## 10. Chart.js conventions (from `home.php`)

Chart.js 4.4 is already loaded globally. Add a `<canvas id="…">` in a card, then instantiate in a page `<script>`. Shared defaults + examples:

```js
const fontDefaults = { font:{family:'Inter, sans-serif',size:11}, color:'#6c757d' };

// Bar
new Chart(el, {
  type:'bar',
  data:{ labels, datasets:[{ data, backgroundColor:'rgba(70,128,255,0.75)', borderColor:'#4680ff', borderWidth:1, borderRadius:4 }] },
  options:{ responsive:true, maintainAspectRatio:true,
    plugins:{ legend:{display:false}, tooltip:{callbacks:{label:ctx=>' '+ctx.parsed.y.toLocaleString()}} },
    scales:{ x:{ticks:fontDefaults,grid:{display:false}}, y:{ticks:{...fontDefaults},beginAtZero:true} } }
});

// Doughnut: cutout:'60%', borderWidth:2, borderColor:'#fff', hoverOffset:6
```
Feed data from PHP with `<?php echo json_encode($arr); ?>`. Cap chart height with an inline `style="max-height:180px;"` on the canvas.

---

## 11. Palette (extracted from the live UI)

| Role | Main | Dark text | Pale bg |
|---|---|---|---|
| Primary (blue) | `#4680ff` | `#1e3a8a` | `#eef2ff` |
| Success (green) | `#2ca87f` | `#065f46` | `#ecfdf5` / `#f0fdf4` |
| Warning (amber) | `#e58a00` | `#92400e` | `#fff7ed` |
| Danger (red) | `#dc2626` | `#991b1b` | `#fef2f2` |
| Teal | `#3ec9d6` | `#164e63` | — |
| Purple | `#a855f7` | `#4c1d95` | `#faf5ff` |
| Muted / labels | `#6c757d`, `#8c8c8c`, `#adb5bd` | ink `#1a1a2e` | neutral `#f8f9fa` |
| Borders | `#e0e0e0`, `#e9ecef`, `#f0f0f0` | | |

Chart series use the same hues at `0.75` alpha: `rgba(70,128,255,.75)`, `rgba(44,168,127,.75)`, `rgba(229,138,0,.75)`, `rgba(62,201,214,.75)`, `rgba(168,85,247,.75)`, `rgba(249,115,22,.75)`, `rgba(220,38,38,.75)`, `rgba(156,163,175,.75)`.

Convention: pale tinted background + solid same-hue accent border + darker same-hue value text. Green = good/covered, amber = caution, red = at-risk/overdue, gray = neutral/empty.

---

## 12. Reference files to read before building

- `includes/header.php` — layout, sidebar menu structure, top header stats.
- `includes/footer.php` — JS load order, submenu-open script.
- `includes/fns.php` — `db_connect()`, all access helpers, menu/permission canonical lists.
- `home.php` — the canonical dashboard: KPI strip, cards, dash-tables, status pills, `<details>` sections, and all five Chart.js charts. **This is the primary template to imitate.**
- `css/css.css` — global site overrides.
- `tasks.php` + `ajax/tasks/*.php` — clean example of a page + its AJAX CRUD endpoints.
- `setup_call_center.php` — the idempotent schema-installer pattern for any new tables.

---

_When in doubt, open `home.php` and match it._
