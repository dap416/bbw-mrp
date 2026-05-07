<?php

	require_once(__DIR__."/../../includes/fns.php");

	$dbLink = $mysqli = db_connect();

	extract($_POST);

	$compList = $dbLink->query("SELECT * FROM `build` WHERE `prodid` = '$prodid' ORDER BY `partid` ASC");

	?>

	<div class="bold" style="margin-bottom: 10px;">Component List:</div>

	<?php

	while($comp = $compList->fetch()) {
		
		extract($comp);
		
		$compInfo = $dbLink->query("SELECT * FROM `parts` WHERE `id` = '$partid'")->fetch();
		
		$compName = $compInfo['partno'].' - '.$compInfo['desc'];
		
		?>

		<div id="<?php echo $id; ?>Row" style="padding: 3px; border-bottom: dotted 1px #cccccc;">

			<div class="inline middle" style="width: 250px; margin-right: 20px;"><?php echo $compName; ?></div>
			<div class="inline middle link" action="remove" buildid="<?php echo $id; ?>" prodid="<?php echo $prodid; ?>">Remove</div>

		</div>
		
		<?php
	}

	?>

	<script>

		// REMOVE COMPONENT
		
		$("[action=remove]").click(function() {
			
			var buildId = $(this).attr('buildid');
			
			//alert('Attempting to delete prodid '+prodId+' and compid '+compId);
			
			$.post('/ajax/products/remove.php', { buildid: buildId }, function() {
				
				$("#"+buildId+"Row").slideUp(100);
				
			});
			
		});


	</script>