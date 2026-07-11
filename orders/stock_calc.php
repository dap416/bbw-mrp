<?php
/**
 * Raw Materials Stock Order — RETIRED.
 * This page was merged into the Research page (Season Readiness → Raw Materials to
 * Order + the "All raw materials — stock reference" table, which carries over BSL,
 * 6/12-month build demand, and the Omit editor). Kept as a redirect so old links,
 * bookmarks, and the bsl_calc.php direct-call target don't 404.
 */
require_once(__DIR__."/../includes/fns.php");
require_login();
header("Location: /research.php");
exit;
