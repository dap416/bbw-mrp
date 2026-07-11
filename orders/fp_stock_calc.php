<?php
/**
 * Finished Product Stock Order — RETIRED.
 * Merged into the Packaging page (build.php): the Recommend panel, manual "add a
 * packaging order" control, the awaiting-packaging queue, and the build/finalize flow
 * all live there now (order → queue → package in one place). Kept as a redirect so old
 * links and bookmarks don't 404.
 */
require_once(__DIR__."/../includes/fns.php");
require_login();
header("Location: /build.php");
exit;
