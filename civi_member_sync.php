<?php
/*
	Plugin Name: Tadpole CiviMember Role Synchronize
	Depends: CiviCRM
	Plugin URI: https://tadpole.cc
	Description: Plugin to syncronize members in CiviCRM with WordPress
	Author: Jag Kandasamy and Tadpole Collective
	Version: 4.5
	Author URI: https://tadpole.cc

	Based on CiviMember Role Synchronize by Jag Kandasamy of http://www.orangecreative.net.  This has been
	altered to use WP $wpdb class.

	*/

global $tadms_db_version;
$tadms_db_version = "4.5";
define( 'CIV_MEMB_SYNC_DIR', dirname( __FILE__ ) );
define( 'CIV_MEMB_SYNC_URL', plugin_dir_url( __FILE__ ) );
define( 'CIV_MEMB_SYNC_PBASE', plugin_basename( __FILE__ ) );
define( 'CIV_MEMB_SYNC_BASE', str_replace( basename( __FILE__ ), "", plugin_basename( __FILE__ ) ) );

function tadms_install() {
	global $wpdb;
	global $tadms_db_version;
	$tadms_db_version = "4.5";

	$table_name      = $wpdb->prefix . "civi_member_sync";
	$charset_collate = '';

	if ( ! empty( $wpdb->charset ) ) {
		$charset_collate = "DEFAULT CHARACTER SET {$wpdb->charset}";
	}

	if ( ! empty( $wpdb->collate ) ) {
		$charset_collate .= " COLLATE {$wpdb->collate}";
	}

	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `wp_role` varchar(255) NOT NULL,
          `civi_mem_type` int(11) NOT NULL,
          `current_rule` varchar(255) NOT NULL,
          `expiry_rule` varchar(255) NOT NULL,
          `expire_wp_role` varchar(255) NOT NULL,
           PRIMARY KEY (`id`),         
           UNIQUE KEY `civi_mem_type` (`civi_mem_type`)
           )$charset_collate;";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql );
	add_option( "tadms_db_version", $tadms_db_version );

	if ( ! wp_next_scheduled( 'civi_member_sync_refresh' ) ) {
		wp_schedule_event( time(), 'daily', 'civi_member_sync_refresh' );
	}
}
register_activation_hook( __FILE__, 'tadms_install' );

/** function to schedule manual sync daily **/

function civi_member_sync_daily() {
	$users = get_users();

	foreach ( $users as $user ) {
		member_check($user->ID, $user->roles);
	}
}
add_action( 'civi_member_sync_refresh', 'civi_member_sync_daily' );

/** function to check user's membership record while login and logout **/
function civi_member_sync_check($username, $user) {
	global $current_user;

	if ( ! $user)
	{
		$user = $current_user;
	}

	if ($user->data)
	{
		$user = $user->data;
	}

	member_check( $user->ID, $user->roles );
}

add_action( 'wp_login', 'civi_member_sync_check', 10, 2 );
add_action( 'wp_logout', 'civi_member_sync_check' );

/** function to check membership record and assign wordpress role based on the membership status
 * input params
 * #Wordpress UserID and
 * #User Role **/
function member_check( $userID, $userRoles ) {

	if ( in_array('administrator', $userRoles) ) {
		return;
	}

	civicrm_wp_initialize();

	try {
		$contactID = civicrm_api3('UFMatch', 'getvalue', array(
			'sequential' => 1,
			'return' => 'contact_id',
			'uf_id' => $userID
		));
	}
	catch (CiviCRM_API3_Exception $ex) {
		return;
	}

	$memberships = civicrm_api3('Membership', 'get', array(
		'sequential' => 1,
		'contact_id' => $contactID
	));

	if ($memberships['count'] == 0) {
		return;
	}

	foreach ($memberships['values'] as $membership) {
		$syncRules = role_sync_rules($membership['membership_type_id']);
		if ( ! $syncRules) {
			continue;
		}

		$activeStatuses = unserialize($syncRules->current_rule);
		$expiredStatuses = unserialize($syncRules->expiry_rule);

		if (in_array($membership['status_id'], $activeStatuses)) {
			$newRole = strtolower($syncRules->wp_role);
			if ($newRole == $current_user_role) {
				continue;
			}

			$user = new WP_User($userID);
			$user->set_role($newRole);
		}
		elseif (in_array($membership['status_id'], $expiredStatuses)) {
			$newRole = strtolower($syncRules->expire_wp_role);

			if ($newRole) {
				$user = new WP_User($userID);
				$user->set_role($newRole);
			}
		}
	}
}

$civicrm_member_to_role_dict = array();
function role_sync_rules($membership_type_id) {
	global $wpdb, $civicrm_member_to_role_dict;

	if ( ! array_key_exists($membership_type_id, $civicrm_member_to_role_dict)) {
		$tbl = $wpdb->prefix . 'civi_member_sync';
		$civicrm_member_to_role_dict[$membership_type_id] = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $tbl WHERE `civi_mem_type` = %d",
				$membership_type_id
			)
		);
	}

	return $civicrm_member_to_role_dict[$membership_type_id];
}

/** function to set setings page for the plugin in menu **/
function setup_civi_member_sync_check_menu() {
	add_submenu_page( 'CiviMember Role Sync', 'CiviMember Role Sync', 'List of Rules', 'add_users', CIV_MEMB_SYNC_BASE . 'settings.php' );
	add_submenu_page( 'CiviMember Role Manual Sync', 'CiviMember Role Manual Sync', 'List of Rules', 'add_users', CIV_MEMB_SYNC_BASE . 'manual_sync.php' );
	add_options_page( 'CiviMember Role Sync', 'CiviMember Role Sync', 'manage_options', CIV_MEMB_SYNC_BASE . 'list.php' );
}

add_action( "admin_menu", "setup_civi_member_sync_check_menu" );
add_action( 'admin_init', 'my_plugin_admin_init' );

//create the function called by your new action
function my_plugin_admin_init() {
	wp_enqueue_script( 'jquery' );
	wp_enqueue_script( 'jquery-form' );
}

function plugin_add_settings_link( $links ) {
	$settings_link = '<a href="admin.php?page=' . CIV_MEMB_SYNC_BASE . 'list.php">Settings</a>';
	array_push( $links, $settings_link );

	return $links;
}

$plugin = plugin_basename( __FILE__ );
add_filter( "plugin_action_links_$plugin", 'plugin_add_settings_link' );
?>