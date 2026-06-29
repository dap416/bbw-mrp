<?php

	require_once(__DIR__."/fns.php");

	$_db = db_connect();

	$_ohVal      = $_db->query("SELECT SUM(`qoh`*`cost`) AS `v` FROM `parts`")->fetch()['v'] ?? 0;
	$_ooVal      = $_db->query("SELECT SUM(`ordval` - (`recqty` / `qty` * `ordval`)) AS `v` FROM `orders` WHERE (`qty` - `recqty` > 0)")->fetch()['v'] ?? 0;
	$_notPaidVal = $_db->query("SELECT SUM(`ordval` - `paidamt`) AS `v` FROM `orders` WHERE `paidamt` < `ordval` AND `qty` > `recqty`")->fetch()['v'] ?? 0;
	$_BNR        = $_ooVal - $_notPaidVal;
	$_bookVal    = $_ohVal + $_BNR;

	?>

<!doctype html>
<html lang="en">
<head>

	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui" />
	<meta http-equiv="X-UA-Compatible" content="IE=edge" />
	<title>BBW MRP</title>

	<!-- [Favicon] -->
	<link rel="icon" type="image/png" sizes="32x32" href="/images/favicon-32.png" />
	<link rel="icon" type="image/png" sizes="16x16" href="/images/favicon-16.png" />
	<link rel="apple-touch-icon" sizes="180x180" href="/images/apple-touch-icon.png" />

	<!-- [Google Font] -->
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700&display=swap" id="main-font-link" />
	<!-- [Berry Icons] -->
	<link rel="stylesheet" href="/berry/assets/fonts/phosphor/duotone/style.css" />
	<link rel="stylesheet" href="/berry/assets/fonts/tabler-icons.min.css" />
	<link rel="stylesheet" href="/berry/assets/fonts/feather.css" />
	<link rel="stylesheet" href="/berry/assets/fonts/fontawesome.css" />
	<link rel="stylesheet" href="/berry/assets/fonts/material.css" />
	<!-- [Berry CSS] -->
	<link rel="stylesheet" href="/berry/assets/css/style.css" id="main-style-link" />
	<link rel="stylesheet" href="/berry/assets/css/style-preset.css" />
	<!-- [Site CSS] -->
	<link rel="stylesheet" href="/css/css.css">

	<!-- JQUERY -->
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
	<!-- Chart.js -->
	<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

</head>

<body data-pc-preset="preset-1" data-pc-sidebar-theme="light" data-pc-sidebar-caption="true" data-pc-direction="ltr" data-pc-theme="light">

<!-- [ Pre-loader ] start -->
<div class="loader-bg">
  <div class="loader-track">
    <div class="loader-fill"></div>
  </div>
</div>
<!-- [ Pre-loader ] end -->

<!-- [ Sidebar ] start -->
<nav class="pc-sidebar">
  <div class="navbar-wrapper">
    <div class="m-header">
      <a href="/home.php" class="b-brand text-primary">
        <img src="/images/logo.png" alt="BBW" style="max-height: 70px;" />
      </a>
    </div>
    <div class="navbar-content">
      <ul class="pc-navbar">

        <li class="pc-item">
          <a href="/home.php" class="pc-link">
            <span class="pc-micon"><i class="ti ti-layout-dashboard"></i></span>
            <span class="pc-mtext">Dashboard</span>
          </a>
        </li>

        <?php if (has_access('orders')) { ?>
        <li class="pc-item pc-hasmenu active">
          <a href="/orders.php" class="pc-link">
            <span class="pc-micon"><i class="ti ti-shopping-cart"></i></span>
            <span class="pc-mtext">Orders</span>
            <span class="pc-arrow"><i data-feather="chevron-right"></i></span>
          </a>
          <ul class="pc-submenu">
            <li class="pc-item"><a class="pc-link" href="/orders.php">Open Orders</a></li>
            <li class="pc-item"><a class="pc-link" href="/orders/archived.php">Archived Orders</a></li>
            <li class="pc-item"><a class="pc-link" href="/orders/stock_calc.php">Raw Materials<br><small>Stock Order</small></a></li>
            <li class="pc-item"><a class="pc-link" href="/orders/fp_stock_calc.php">Finished Product<br><small>Stock Order</small></a></li>
          </ul>
        </li>
        <?php } ?>

        <?php if (has_access('inventory')) { ?>
        <li class="pc-item">
          <a href="/index.php" class="pc-link">
            <span class="pc-micon"><i class="ti ti-package"></i></span>
            <span class="pc-mtext">Inventory</span>
          </a>
        </li>
        <?php } ?>

        <?php if (has_access('build')) { ?>
        <li class="pc-item">
          <a href="/build.php" class="pc-link">
            <span class="pc-micon"><i class="ti ti-box"></i></span>
            <span class="pc-mtext">Packaging</span>
          </a>
        </li>
        <?php } ?>

        <?php if (has_access('products')) { ?>
        <li class="pc-item">
          <a href="/products.php" class="pc-link">
            <span class="pc-micon"><i class="ti ti-tag"></i></span>
            <span class="pc-mtext">Products</span>
          </a>
        </li>
        <?php } ?>

        <?php if (has_access('research')) { ?>
        <li class="pc-item">
          <a href="/research.php" class="pc-link">
            <span class="pc-micon"><i class="ti ti-flask"></i></span>
            <span class="pc-mtext">Research</span>
          </a>
        </li>
        <?php } ?>

        <?php if (has_access('build')) { ?>
        <li class="pc-item">
          <a href="/physical_inventory.php" class="pc-link">
            <span class="pc-micon"><i class="ti ti-clipboard-list"></i></span>
            <span class="pc-mtext">Physical Inventory</span>
          </a>
        </li>
        <?php } ?>

        <?php if (has_access('manufacturers')) { ?>
        <li class="pc-item">
          <a href="/manufacturers.php" class="pc-link">
            <span class="pc-micon"><i class="ti ti-building-factory"></i></span>
            <span class="pc-mtext">Manufacturers</span>
          </a>
        </li>
        <?php } ?>

        <?php if (has_access('users')) { ?>
        <li class="pc-item">
          <a href="/warehouses.php" class="pc-link">
            <span class="pc-micon"><i class="ti ti-building-warehouse"></i></span>
            <span class="pc-mtext">Warehouses</span>
          </a>
        </li>
        <li class="pc-item">
          <a href="/users.php" class="pc-link">
            <span class="pc-micon"><i class="ti ti-users"></i></span>
            <span class="pc-mtext">Users</span>
          </a>
        </li>
        <?php } ?>

        <?php if (in_array($_SESSION['user_role'] ?? '', ['admin', 'master'], true)) { ?>
        <li class="pc-item">
          <a href="/integrations.php" class="pc-link">
            <span class="pc-micon"><i class="ti ti-plug-connected"></i></span>
            <span class="pc-mtext">Integrations</span>
          </a>
        </li>
        <?php } ?>

      </ul>
    </div>
  </div>
</nav>
<!-- [ Sidebar ] end -->

<!-- [ Header ] start -->
<header class="pc-header">
  <div class="header-wrapper">
    <div class="me-auto pc-mob-drp d-md-none">
      <ul class="list-unstyled">
        <li class="pc-h-item header-mobile-collapse">
          <a href="#" class="pc-head-link head-link-secondary ms-0" id="sidebar-hide">
            <i class="ti ti-menu-2"></i>
          </a>
        </li>
        <li class="pc-h-item pc-sidebar-popup">
          <a href="#" class="pc-head-link head-link-secondary ms-0" id="mobile-collapse">
            <i class="ti ti-menu-2"></i>
          </a>
        </li>
      </ul>
    </div>
    <div class="d-none d-md-flex align-items-center ps-3">
      <span style="font-size:1.35rem;font-weight:700;letter-spacing:0.02em;color:#1a1a2e;">BBW MRP</span>
    </div>
    <div class="ms-auto d-flex align-items-center gap-3 pe-3">
      <?php if (in_array($_SESSION['user_role'] ?? '', ['admin', 'master'])) { ?>
      <div class="header-stat">
        <div class="header-stat-label">On-Hand</div>
        <div class="header-stat-value">$<?php echo number_format($_ohVal,2); ?></div>
      </div>
      <div class="header-stat">
        <div class="header-stat-label">On-Order</div>
        <div class="header-stat-value">$<?php echo number_format($_ooVal,2); ?></div>
      </div>
      <div class="header-stat">
        <div class="header-stat-label">Not Paid</div>
        <div class="header-stat-value">$<?php echo number_format($_notPaidVal,2); ?></div>
      </div>
      <div class="header-stat">
        <div class="header-stat-label">Billed, Not Received</div>
        <div class="header-stat-value">$<?php echo number_format($_BNR,2); ?></div>
      </div>
      <div class="header-stat header-stat-highlight">
        <div class="header-stat-label">Book Value</div>
        <div class="header-stat-value">$<?php echo number_format($_bookVal,2); ?></div>
      </div>
      <?php } ?>
      <div style="border-left:1px solid #e0e0e0; padding-left:16px; line-height:1.2;">
        <div class="small text-muted"><?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></div>
        <a href="/logout.php" class="small text-danger" style="text-decoration:none;">Sign Out</a>
      </div>
    </div>
  </div>
</header>
<!-- [ Header ] end -->

<!-- [ Main Content ] start -->
<div class="pc-container">
  <div class="pc-content">
