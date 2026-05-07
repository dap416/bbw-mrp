<?php

	// Template for includes/config.local.php
	// Copy this file to config.local.php and fill in real values.
	// config.local.php is gitignored — never commit real secrets.

	return [
		'db' => [
			'host' => 'localhost',
			'name' => 'bbw_raw_inv',
			'user' => 'bbwadmin',
			'pass' => 'CHANGE_ME',
		],
		'dev' => [
			'login_email'    => 'you@example.com',
			'login_password' => 'CHANGE_ME',
		],
	];
