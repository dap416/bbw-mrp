<?php

	require_once(__DIR__."/includes/fns.php");

	// Already logged in — go to the dashboard
	if (isset($_SESSION['user_id'])) {
		header('Location: /home.php');
		exit;
	}

	$error = '';

	$host  = $_SERVER['HTTP_HOST'] ?? '';
	$isDev = in_array($host, ['localhost', '127.0.0.1'], true) || str_ends_with($host, '.local');
	$devEmail    = $isDev ? (string) app_config('dev.login_email')    : '';
	$devPassword = $isDev ? (string) app_config('dev.login_password') : '';

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {

		$email    = strtolower(trim($_POST['email'] ?? ''));
		$password = $_POST['password'] ?? '';
		$dbLink   = db_connect();

		$user = $dbLink->query("SELECT * FROM `users` WHERE `username` = '$email' AND `active` = 1")->fetch();

		if ($user && password_verify($password, $user['password'])) {

			$_SESSION['user_id']    = $user['id'];
			$_SESSION['user_name']  = $user['name'];
			$_SESSION['user_role']  = $user['role'];
			$_SESSION['user_email'] = strtolower(trim($user['username'] ?? ''));
			// Driven by permission_areas()/permission_flags() so a NEW area is picked up here
			// automatically. Anything the user has no column/value for lands at 0 = no access.
			$_SESSION['user_access'] = [];
			foreach (array_merge(array_keys(permission_areas()), array_keys(permission_flags())) as $col) {
				$_SESSION['user_access'][$col] = (int)($user[$col] ?? 0);
			}
			$_SESSION['user_menu_hidden'] = json_decode($user['menu_hidden'] ?? '[]', true) ?: [];

			header('Location: /home.php');
			exit;

		} else {
			$error = 'Invalid email or password.';
		}
	}

?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<title>BBW MRP — Login</title>
	<link rel="icon" type="image/png" sizes="32x32" href="/images/favicon-32.png" />
	<link rel="icon" type="image/png" sizes="16x16" href="/images/favicon-16.png" />
	<link rel="apple-touch-icon" sizes="180x180" href="/images/apple-touch-icon.png" />
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700&display=swap" />
	<link rel="stylesheet" href="/berry/assets/css/style.css" />
	<link rel="stylesheet" href="/css/css.css" />
</head>
<body style="background:#f4f5f7; display:flex; align-items:center; justify-content:center; min-height:100vh;">

<div style="width:100%; max-width:400px; padding:20px;">

	<div class="text-center mb-4">
		<img src="/images/logo.png" alt="BBW" style="max-height:80px;" />
	</div>

	<div class="card">
	<div class="card-body p-4">

		<h4 class="fw-bold mb-1">Sign In</h4>
		<p class="text-muted small mb-4">Blue Bird Waterfowl MRP</p>

		<?php if ($error) { ?>
		<div class="alert alert-danger py-2 small"><?php echo $error; ?></div>
		<?php } ?>

		<form method="POST" autocomplete="on">
			<div class="mb-3">
				<label class="form-label fw-semibold small text-muted">Email Address</label>
				<input type="email" name="email" class="form-control" placeholder="you@example.com" required autofocus
					autocomplete="username"
					value="<?php echo htmlspecialchars($_POST['email'] ?? $devEmail); ?>" />
			</div>
			<div class="mb-4">
				<label class="form-label fw-semibold small text-muted">Password</label>
				<input type="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password"
					value="<?php echo htmlspecialchars($devPassword); ?>" />
			</div>
			<button type="submit" class="btn btn-primary w-100">Sign In</button>
		</form>

	</div>
	</div>

</div>

</body>
</html>
