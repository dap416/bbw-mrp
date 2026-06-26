<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_login();

	$role = $_SESSION['user_role'] ?? '';
	if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo 'denied'; exit; }

	try {
		$db = db_connect();
		$db->exec("CREATE TABLE IF NOT EXISTS planning_events (
			id INT AUTO_INCREMENT PRIMARY KEY,
			type VARCHAR(20) NOT NULL DEFAULT 'po',
			name VARCHAR(255) NOT NULL,
			event_date DATE DEFAULT NULL,
			end_date DATE DEFAULT NULL,
			repeats TINYINT(1) NOT NULL DEFAULT 0,
			details TEXT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
		) ENGINE=InnoDB");

		$id      = (int)($_POST['id'] ?? 0);
		$type    = ($_POST['type'] ?? 'po') === 'tradeshow' ? 'tradeshow' : 'po';
		$name    = trim($_POST['name'] ?? '');
		$date    = trim($_POST['event_date'] ?? '') ?: null;
		$endDate = trim($_POST['end_date'] ?? '') ?: null;
		$repeats = !empty($_POST['repeats']) ? 1 : 0;
		$details = trim($_POST['details'] ?? '');

		if ($name === '') { echo 'Name is required'; http_response_code(400); exit; }

		if ($id > 0) {
			$stmt = $db->prepare("UPDATE planning_events SET type=?, name=?, event_date=?, end_date=?, repeats=?, details=? WHERE id=?");
			$stmt->execute([$type, $name, $date, $endDate, $repeats, $details, $id]);
		} else {
			$stmt = $db->prepare("INSERT INTO planning_events (type, name, event_date, end_date, repeats, details) VALUES (?,?,?,?,?,?)");
			$stmt->execute([$type, $name, $date, $endDate, $repeats, $details]);
		}
		echo 'ok';
	} catch (Throwable $e) {
		http_response_code(500);
		echo 'Save failed: ' . $e->getMessage();
	}
