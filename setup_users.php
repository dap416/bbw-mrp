<?php

	require_once(__DIR__."/includes/fns.php");
	$dbLink = db_connect();

	$dbLink->query("
		CREATE TABLE IF NOT EXISTS `users` (
			`id`               INT AUTO_INCREMENT PRIMARY KEY,
			`name`             VARCHAR(100) NOT NULL,
			`username`         VARCHAR(100) NOT NULL UNIQUE,
			`password`         VARCHAR(255) NOT NULL,
			`role`             ENUM('user','admin','master') NOT NULL DEFAULT 'user',
			`access_orders`    TINYINT(1) NOT NULL DEFAULT 0,
			`access_inventory` TINYINT(1) NOT NULL DEFAULT 0,
			`access_products`  TINYINT(1) NOT NULL DEFAULT 0,
			`access_build`     TINYINT(1) NOT NULL DEFAULT 0,
			`active`           TINYINT(1) NOT NULL DEFAULT 1,
			`created`          DATETIME DEFAULT CURRENT_TIMESTAMP
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
	");

	echo 'Users table created successfully. <a href="/setup_users.php?delete=1">Click here to delete this file.</a>';

	if (isset($_GET['delete'])) {
		unlink(__FILE__);
		echo ' File deleted.';
	}
