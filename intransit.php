<?php

	require_once(__DIR__."/includes/header.php");

	$dbLink = $mysqli = db_connect();

	$inTransit = $dbLink->query("SELECT SUM(`buildqty`) as `buildqty`, MAX(`builddate`) as `builddate`, `prodid` FROM `intransit` WHERE `recdate` = '0000-00-00 00:00:00' AND `buildqty` > '0' GROUP BY `prodid` ORDER BY `prodid` ASC");

	?>

	<div class="inline middle" style="padding-top: 20px; padding-bottom: 20px;"><h2>Product Ready to Ship (In Transit)</h2></div>

	<div class="inline middle" style="padding-top: 20px; padding-bottom: 20px; margin-left: 200px;">
		<input type="button" id="markrec" style="font-size: 20px;" value="Mark as Received" />
	</div>

	<div class="chartHeadRow" style="padding: 10px;">
		<div class="chartHead" style="width: 350px;">Product</div>
		<div class="chartHead" style="width: 100px;">QTY</div>
		<div class="chartHead" style="width: 150px;">Last Package Date</div>
	</div>

	<?php

	while($line = $inTransit->fetch()) {
		
		$prodId = $line['prodid'];
		$qty = $line['buildqty'];
		$buildDate = date("m/d/y",strtotime($line['builddate']));
			
		$prodInfo = $dbLink->query("SELECT * FROM `products` WHERE `id` = '$prodId'")->fetch();
		$prodName = $prodInfo['name'];
		
	?>

	<div class="chartRow" style="padding: 10px;">
		<div class="chartCell" style="width: 350px;"><?php echo $prodName; ?></div>
		<div class="chartCell" style="width: 100px;"><?php echo $qty; ?></div>
		<div class="chartCell" style="width: 150px;"><?php echo $buildDate; ?></div>
	</div>

	<?php } ?>

	<script>

		$("#markrec").click(function() {
			
			$.post('/ajax/rec_intransit.php', {  }, function() {
				location.reload();
			});
			
		});


	</script>