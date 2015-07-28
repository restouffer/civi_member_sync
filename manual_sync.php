<div id="icon-edit-pages" class="icon32"></div>
<div class="wrap">
	<h2 id="add-new-user">Manual Synchronize</h2>
	<?php if ( ! $_GET['action']) { ?>
	<table class="form-table">
		<td>
			<span>Manual Synchronization:</span> <br/>
			<?php $sync_confirm_url = get_bloginfo( 'url' ) . "/wp-admin/admin.php?&action=confirm&page=" . CIV_MEMB_SYNC_BASE . "manual_sync.php"; ?>
			<input class="button-primary" type="submit"
			       value="Synchronize CiviMember Membership Types to WordPress Roles now"
			       onclick="window.location.href='<?php echo $sync_confirm_url; ?>'"/>
		</td>
		</tr>
	</table>
</div>
<?php } ?>


<?php
if ( $_GET['action'] == 'confirm' ) {
	do_action( 'civi_member_sync_refresh' );
	?>

	<div id="message" class="updated below-h2">
		<span><p> CiviMember Memberships and WordPress Roles have been
				synchronized using available rules. Note: if no association
				rules exist then synchronization has not been
				completed.</p></span>
	</div>
<?php } ?>