<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	require_can(can_edit('products'), 'You do not have permission to edit products/parts.');

	$dbLink = $mysqli = db_connect();

	extract($_POST);

	$mfrid = isset($mfrid) ? (int)$mfrid : 0;
	$add = $dbLink->query("INSERT INTO `parts` (`mfgpartno`,`partno`,`desc`,`imoq`,`cost`,`supplier`,`manufacturer`) VALUES ('$mfg','$partno','$desc','$imoq','$cost','$supp','$mfrid')");

