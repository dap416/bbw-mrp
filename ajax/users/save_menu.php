<?php
/**
 * Save a user's "Menu View" — which sidebar items are hidden for them.
 * Input: record (user id), hidden = JSON array of menu keys to hide.
 * Master only. Menu View is a display preference only; it never changes
 * a user's actual page permissions.
 */
require_once(__DIR__."/../../includes/fns.php");
require_login();
header('Content-Type: application/json');

if (($_SESSION['user_role'] ?? '') !== 'master') {
	http_response_code(403);
	echo json_encode(['ok' => false, 'error' => 'Only master admins can change menu views.']);
	exit;
}

$db = db_connect();
if (!$db) { echo json_encode(['ok' => false, 'error' => 'Database connection failed.']); exit; }

$record = (int)($_POST['record'] ?? 0);
if ($record <= 0) { echo json_encode(['ok' => false, 'error' => 'Missing user id.']); exit; }

// Self-heal: ensure the column exists even if setup_menu_view.php never ran.
try { $db->exec("ALTER TABLE `users` ADD COLUMN `menu_hidden` TEXT DEFAULT NULL"); }
catch (Throwable $e) { /* duplicate column = already there, fine */ }

// Keep only recognised menu keys, de-duplicated.
$valid   = array_keys(menu_items());
$request = json_decode($_POST['hidden'] ?? '[]', true);
$hidden  = [];
if (is_array($request)) {
	foreach ($request as $k) {
		if (in_array($k, $valid, true) && !in_array($k, $hidden, true)) $hidden[] = $k;
	}
}

try {
	$stmt = $db->prepare("UPDATE `users` SET `menu_hidden` = ? WHERE `id` = ?");
	$stmt->execute([json_encode(array_values($hidden)), $record]);
} catch (Throwable $e) {
	echo json_encode(['ok' => false, 'error' => 'Save failed: ' . $e->getMessage()]);
	exit;
}

$nameRow = $db->prepare("SELECT `name` FROM `users` WHERE `id` = ?");
$nameRow->execute([$record]);
$name = $nameRow->fetchColumn() ?: 'user';

// If the master just edited their OWN menu, apply to the live session now.
$self = $record === (int)($_SESSION['user_id'] ?? 0);
if ($self) { $_SESSION['user_menu_hidden'] = $hidden; }

echo json_encode(['ok' => true, 'name' => $name, 'hidden' => $hidden, 'self' => $self]);
