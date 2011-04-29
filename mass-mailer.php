<?php
/*
Plugin Name: Mass Email Sender
Plugin URI: http://premium.wpmudev.org/project/mass-email-sender
Description: Allows you to send emails to all users via defined mailing lists. Users also have the option to unsubscribe from the mailing list.
Author: Andrew Billits, Ulrich Sossou
Version: 1.6.6
Author URI: http://premium.wpmudev.org/project/
Text Domain: mass_mailer
Network: true
WDP ID: 7
*/

/*
Copyright 2007-2011 Incsub (http://incsub.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License (Version 2 - GPLv2) as published by
the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

if ( ! is_multisite() )
	exit( __( 'The Mass Email Sender plugin is only compatible with WordPress Multisite.', 'mass_mailer' ) );

/**
 * Plugin main class
 **/
class Mass_Mailer {

	/**
	 * Current version of the plugin
	 **/
	var $version = '1.6.6';

	/**
	 * PHP4 constructor
	 **/
	function Mass_Mailer() {
		__construct();
	}

	/**
	 * PHP5 constructor
	 **/
	function __construct() {
		global $wp_version;

		add_action( 'admin_init', array( &$this, 'upgrade' ) );

		add_action( 'admin_init', array( &$this, 'user_install' ) );

		// Add the super admin page
		if( version_compare( $wp_version, '3.0.9', '>' ) )
			add_action( 'network_admin_menu', array( &$this, 'network_admin_page' ) );
		else
			add_action( 'admin_menu', array( &$this, 'pre_3_1_network_admin_page' ) );

		add_action( 'wp_head', array( &$this, 'unsubscribe' ) );

		add_action( 'edit_user_profile', array( &$this, 'profile' ) );
		add_action( 'show_user_profile', array( &$this, 'profile' ) );
		add_filter( 'wp_redirect', array( &$this, 'profile_process' ), 1, 1);

		// load text domain
		if ( defined( 'WPMU_PLUGIN_DIR' ) && file_exists( WPMU_PLUGIN_DIR . '/mass-mailer.php' ) ) {
			load_muplugin_textdomain( 'mass_mailer', 'massmailerincludes/languages' );
		} else {
			load_plugin_textdomain( 'mass_mailer', false, dirname( plugin_basename( __FILE__ ) ) . '/massmailerincludes/languages' );
		}

	}

	function upgrade() {
		global $plugin_page;

		if( 'mass-mailer' !== $plugin_page )
			return;

		if ( get_site_option( 'mass_mailer_version' ) == '' )
			add_site_option( 'mass_mailer_version', '0.0.0' );

		if ( get_site_option( 'mass_mailer_version' ) !== $this->version ) {
			update_site_option( 'mass_mailer_version', $this->version );
			update_site_option( 'mass_mailer_installed', 'no' );
			$this->table_populate();
			update_site_option( 'mass_mailer_installed', 'yes' );
		}

		$this->install();
	}

	function install() {
		global $wpdb;

		if ( get_site_option( 'mass_mailer_installed' ) == '' )
			add_site_option( 'mass_mailer_installed', 'no' );

		if ( get_site_option( 'mass_mailer_installed' ) !== 'yes' ) {

			if( @is_file( ABSPATH . '/wp-admin/includes/upgrade.php' ) )
				include_once( ABSPATH . '/wp-admin/includes/upgrade.php' );
			else
				die( __( 'We have problem finding your \'/wp-admin/upgrade-functions.php\' and \'/wp-admin/includes/upgrade.php\'', 'mass_mailer' ) );

			$charset_collate = '';
			if( $wpdb->supports_collation() ) {
				if( !empty( $wpdb->charset ) ) {
					$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
				}
				if( !empty( $wpdb->collate ) ) {
					$charset_collate .= " COLLATE $wpdb->collate";
				}
			}

			$mass_mailer_table = "CREATE TABLE `{$wpdb->base_prefix}mass_mailer` (
				email_ID bigint(20) unsigned NOT NULL auto_increment,
				email_user_id VARCHAR(255),
				email_optout VARCHAR(255) NOT NULL default 'yes',
				email_status VARCHAR(255) NOT NULL default 'no',
				PRIMARY KEY  (email_ID)
			) $charset_collate;";

			maybe_create_table( "{$wpdb->base_prefix}mass_mailer", $mass_mailer_table );
			$this->table_populate();
			update_site_option( 'mass_mailer_installed', 'yes' );
		}
	}

	function table_populate() {
		global $wpdb;
		$query = "SELECT ID, user_login FROM {$wpdb->base_prefix}users";
		$users_list = $wpdb->get_results( $query, ARRAY_A );
		$this->processArrayUsersPopulate( $users_list );
	}

	function processArrayUsersPopulate( $arrayName ) {
		global $wpdb;
		foreach ( $arrayName as $arrayElement ) {
			$tmp_email_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->base_prefix}mass_mailer WHERE email_user_id = '" . $arrayElement['ID']. "'");
			if ( $tmp_email_count ) {
				$wpdb->query( "UPDATE {$wpdb->base_prefix}mass_mailer SET email_optout = '" . get_user_meta($arrayElement['ID'], 'recieve_admin_emails', true) . "' WHERE email_user_id = '" . $arrayElement['ID'] . "'" );
			} else {
				$tmp_email_count2 = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->base_prefix}usermeta WHERE user_id = '" . $arrayElement['ID'] . "' AND meta_key = 'recieve_admin_emails'");
				if ( $tmp_email_count2 ) {
					$wpdb->query( "INSERT INTO {$wpdb->base_prefix}mass_mailer (email_user_id, email_optout, email_status) VALUES ( '" . $arrayElement['ID'] . "', '" . get_user_meta($arrayElement['ID'], 'recieve_admin_emails', true) . "', 'yes' )" );
				} else {
					$wpdb->query( "INSERT INTO {$wpdb->base_prefix}mass_mailer (email_user_id, email_optout, email_status) VALUES ( '" . $arrayElement['ID'] . "', 'yes', 'yes' )" );
				}
			}
			//echo $arrayElement['ID'] . ': ' . var_export(get_user_meta($arrayElement['ID'], 'recieve_admin_emails', true),1) . '<hr>';
		}
	}

	function clear() {
		global $wpdb;
		$wpdb->query( "UPDATE {$wpdb->base_prefix}mass_mailer SET email_status = 'yes'" );
	}

	function reset() {
		global $wpdb, $current_user;
		$wpdb->query( "UPDATE {$wpdb->base_prefix}mass_mailer SET email_status = 'no'" );
	}

	function global_db_sync() {
		global $wpdb, $current_user;

		if ( ! $current_user )
			return;

		$email_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->base_prefix}mass_mailer WHERE email_user_id = %d", $current_user->ID ) );

		if ( $email_count ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->base_prefix}mass_mailer SET email_optout = %s WHERE email_user_id = %d", get_user_meta( $current_user->ID, 'recieve_admin_emails', true ), $current_user->ID ) );
		} else {
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->base_prefix}mass_mailer ( email_user_id, email_optout, email_status ) VALUES ( %d, 'yes', 'yes' )", $current_user->ID ) );
		}
	}

	function user_install() {
		global $current_user;

		if( get_site_option( 'mass_mailer_installed' ) == 'yes' && get_user_meta( $current_user->ID, 'mass_mailer_user_installed', true ) != '1' ) {
			$this->global_db_sync();
			update_user_meta( $current_user->ID, 'mass_mailer_user_installed', '1' );
		}
	}

	function processArrayUsers( $arrayName ) {
		global $wpdb;
		foreach ( $arrayName as $aid=>$arrayElement ) {
			$this->send_email( $arrayElement['email_user_id'] );
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->base_prefix}mass_mailer SET email_status = 'yes' WHERE email_user_id = %d", $arrayElement['email_user_id'] ) );
		}
	}

	function send_email( $tmp_user_id, $email = null ) {
		global $wpdb, $current_user, $user_ID, $current_site, $tmp_send_count;

		if ($tmp_user_id == 0) {
			$tmp_username = __( 'Test User', 'mass_mailer' );
			$tmp_user_email = $email;
		} else {
			$tmp_username =  $wpdb->get_var("SELECT user_login FROM ".$wpdb->users." WHERE ID = '" . $tmp_user_id . "'");
			$tmp_user_email =  $wpdb->get_var("SELECT user_email FROM ".$wpdb->users." WHERE ID = '" . $tmp_user_id . "'");
		}
		$message_content = get_site_option( "mass_mailer_message" );
		$message_content = str_replace( "SITE_NAME", $current_site->site_name, $message_content );
		$message_content = str_replace( "USERNAME", $tmp_username, $message_content );
		$message_content = str_replace( "\'", "'", $message_content );
		$tmp_unsubscribe_url = 'http://' . $current_site->domain . $current_site->path . '?eaction=unsubscribe&key=a013658e3af0acf26850d' . $tmp_user_id . '0387f7a60cd';
		$message_content = str_replace( "UNSUBSCRIBE_URL", $tmp_unsubscribe_url, $message_content );

		$admin_email = get_site_option( "mass_mailer_sender" );
		if( $admin_email == '' )
			$admin_email = 'support@' . $_SERVER[ 'SERVER_NAME' ];
		$message_headers = "MIME-Version: 1.0\n" . "From: " . get_site_option( "site_name" ) .  " <{$admin_email}>\n" . "Content-Type: text/plain; charset=\"" . get_option('blog_charset') . "\"\n";

		if( empty( $current_site->site_name ) )
			$current_site->site_name = __( 'Blog Provider', 'mass_mailer' );
		wp_mail( $tmp_user_email, get_site_option( "mass_mailer_subject" ), $message_content, $message_headers );
	}

	function network_admin_page() {
		add_submenu_page( 'settings.php', __( 'Send Email', 'mass_mailer' ), __( 'Send Email', 'mass_mailer' ), 'manage_network_options', 'mass-mailer', array( &$this, 'page_main_output' ) );
	}

	function pre_3_1_network_admin_page() {
		add_submenu_page( 'ms-admin.php', __( 'Send Email', 'mass_mailer' ), __( 'Send Email', 'mass_mailer' ), 'manage_network_options', 'mass-mailer', array( &$this, 'page_main_output' ) );
	}

	function profile_process( $location ) {
		global $user_ID;
		/*
		if ( !empty( $_GET['user_id'] ) ) {
			$uid = $_GET['user_id'];
		} else {
			$uid = $user_ID;
		}
		*/
		$uid = @$_POST['mass_mailer_uid'];
		$uid = (int)$uid ? (int)$uid : $user_ID;

		if ( !empty( $_POST['recieve_admin_emails'] ) ) {
			update_user_meta( $uid, 'recieve_admin_emails', $_POST['recieve_admin_emails'] );
		}

		return $location;
	}

	function profile() {
		global $user_types, $user_types_branding, $user_ID;
		$this->global_db_sync();
		if ( !empty( $_GET['user_id'] ) ) {
			$uid = $_GET['user_id'];
		} else {
			$uid = $user_ID;
		}

		$recieve_admin_emails = get_user_meta( $uid, 'recieve_admin_emails', true );
		?>
		<h3><?php _e('Receive admin emails', 'mass_mailer'); ?></h3>

		<table class="form-table">
		<tr>
			<th><label for="recieve_admin_emails"><?php _e('Receive admin emails', 'mass_mailer'); ?></label></th>
			<td>
				<input type="hidden" name="mass_mailer_uid" value="<?php echo $uid;?>" />
				<select name="recieve_admin_emails" id="recieve_admin_emails">
						<option value="yes"<?php if ( $recieve_admin_emails == 'yes' ) { echo ' selected="selected" '; } ?>><?php _e('Yes', 'mass_mailer'); ?></option>
						<option value="no"<?php if ( $recieve_admin_emails == 'no' ) { echo ' selected="selected" '; } ?>><?php _e('No', 'mass_mailer'); ?></option>
				</select>
			</td>

		</tr>
		</table>
		<?php
	}

	function unsubscribe() {
		global $wpdb, $current_site, $current_user;
		if( isset( $_GET['eaction'] ) && 'unsubscribe' == $_GET['eaction'] ) {
			$tmp_key = $_GET['key'];
			$tmp_key = str_replace( "a013658e3af0acf26850d", "", $tmp_key );
			$tmp_key = str_replace( "0387f7a60cd", "", $tmp_key );

			update_user_meta($tmp_key, 'recieve_admin_emails', 'no');
			$wpdb->query( "UPDATE {$wpdb->base_prefix}mass_mailer SET email_optout = '" . get_user_meta($tmp_key, 'recieve_admin_emails', true) . "' WHERE email_user_id = '" . $tmp_key . "'" );

			//send unsubscribed email
			$tmp_username =  $wpdb->get_var("SELECT user_login FROM ".$wpdb->users." WHERE ID = '" . $tmp_key . "'");
			$tmp_user_email =  $wpdb->get_var("SELECT user_email FROM ".$wpdb->users." WHERE ID = '" . $tmp_key . "'");

			$tmp_message_content = __( "Dear USERNAME,\n\nYou have been successfully unsubscribed from future emails.\n\nThanks!\n\n--The Team @ SITE_NAME\n\n-------------------------\n\nTo receive emails again, login and edit your profile to 'receive admin emails'.", 'mass_mailer' );

			$message_content = $tmp_message_content;
			$message_content = str_replace( "SITE_NAME", $current_site->site_name, $message_content );
			$message_content = str_replace( "USERNAME", $tmp_username, $message_content );
			$message_content = str_replace( "\'", "'", $message_content );

			$admin_email = get_site_option( "mass_mailer_sender" );
			if( $admin_email == '' )
				$admin_email = 'support@' . $_SERVER[ 'SERVER_NAME' ];
			$message_headers = "MIME-Version: 1.0\n" . "From: " . get_site_option( "site_name" ) .  " <{$admin_email}>\n" . "Content-Type: text/plain; charset=\"" . get_option('blog_charset') . "\"\n";
			$message = $notification_email;
			if( empty( $current_site->site_name ) )
				$current_site->site_name = __( 'Blog Provider', 'mass_mailer' );
			wp_mail( $tmp_user_email, __( 'Unsubscribed', 'mass_mailer' ), $message_content, $message_headers);
			wp_die(__(sprintf(
				"Hello %s, <br />You are now successfully <strong>unsubscribed</strong> from future admin emails from <em>%s</em>. <br />One last email has been sent to you to confirm this fact.",
				$tmp_username, $current_site->site_name
			)));
		}
	}

	function page_main_output() {
		global $wpdb, $current_user, $user_ID, $current_site, $tmp_send_count;

		if (get_site_option( 'mass_mailer_message' ) == '') {
			add_site_option( 'mass_mailer_message', 'empty' );
		}
		if (get_site_option( 'mass_mailer_subject' ) == '') {
			add_site_option( 'mass_mailer_subject', 'empty' );
		}
		if (get_site_option( 'mass_mailer_sender' ) == '') {
			add_site_option( 'mass_mailer_sender', 'empty' );
		}
		if (get_site_option( 'mass_mailer_number_sent' ) == '') {
			add_site_option( 'mass_mailer_number_sent', '0' );
		}

		if (isset($_GET['updated'])) {
			?><div id="message" class="updated fade"><p><?php echo urldecode( $_GET['updatedmsg'] ) ?></p></div><?php
		}
		echo '<div class="wrap">';

		$tmp_last_email_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->base_prefix}mass_mailer WHERE email_optout = 'yes' AND email_status = 'no'");
		$tmp_users_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
		$tmp_user_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->base_prefix}mass_mailer WHERE email_optout = 'no'");
		$tmp_user_count = $tmp_users_count - $tmp_user_count;

		if ( $tmp_user_count < 2500 ) {
			$action = isset( $_GET[ 'action' ] ) ? $_GET[ 'action' ] : '';
			switch( $action ) {
				//---------------------------------------------------//
				default:
					// Make super-sure we actually have some user data to walk through,
					// AND that this data is recent.
					$this->table_populate();
					?>
					<h2><?php _e('Send Email', 'mass_mailer') ?></h2>
					<p><?php echo sprintf(__("%s out of %s user(s) currently accepting emails.", 'mass_mailer'), $tmp_user_count, $tmp_users_count); ?></p>
					<?php
					if ($tmp_last_email_count != 0) {
						echo "<p>".sprintf(__("Your last email still needs to be sent to %s user(s). Click <a href='%s'>here</a> to finish sending the email.", 'mass_mailer'), $tmp_last_email_count, "?page=mass-mailer&action=loop")."</p>";
					}
					?>
					<form method="post" action="?page=mass-mailer&action=process">
					<table class="form-table">
					<tr valign="top">
					<th scope="row"><?php _e('Sender Email:', 'mass_mailer') ?></th>
					<td><input name="email_sender" type="text" id="from_email" style="width: 95%" value="<?php echo stripslashes( get_site_option('admin_email') ) ?>" size="45" />
					<br /><?php _e('The address that will appear in the "email from" field.', 'mass_mailer') ?></td>
					</tr>
				<tr valign="top">
					<th scope="row"><?php _e('Test Mail Recepient Email:', 'mass_mailer') ?></th>
					<td><input name="email_test_to" type="text" id="email_test_to" style="width: 95%" value="<?php echo stripslashes( get_site_option('admin_email') ) ?>" size="45" />
					<br /><?php _e("Test mail recepient's address, will be ingored when sending sending mails out.", 'mass_mailer') ?></td>
					</tr>
					<tr valign="top">
					<th scope="row"><?php _e('Subject:', 'mass_mailer') ?></th>
					<td><input name="email_subject" type="text" id="subject" style="width: 95%" value="" size="75" />
					<br /><?php _e('This cannot be left blank.', 'mass_mailer') ?></td>
					</tr>
					<tr valign="top">
					<th scope="row"><?php _e('Content:', 'mass_mailer') ?></th>
					<td><textarea name="email_content" id="email_content" rows='5' style="width: 95%"><?php _e( "Dear USERNAME,\n\nBlah Blah Blah\n\nThanks!\n\n--The Team @ SITE_NAME\n\n-------------------------\n\nTo unsubscribe from admin emails please visit this address: UNSUBSCRIBE_URL", 'mass_mailer' ) ?></textarea>
					<br /><?php _e('Plain text only. No HTML allowed.', 'mass_mailer') ?></td>
					</tr>
					</table>

					<p class="submit">
						<input type="submit" name="Submit" value="<?php _e('Send Email', 'mass_mailer') ?>" />
						<input type="reset" name="Reset" value="<?php _e('Reset', 'mass_mailer') ?>" />
						<input type="submit" name="Test" value="<?php _e('Send Test Mail', 'mass_mailer') ?>" />
					</p>
					</form>
				<?php
				break;
				//---------------------------------------------------//
				case "process":
					if (!($_POST['email_sender'] == '' || $_POST['email_subject'] == '' || $_POST['email_content'] == '')) {
						if (isset($_POST['Submit'])) {
							//reset email sent status
							$this->reset();
							//proceed to process
							?>
							<h2><?php _e('Send Email', 'mass_mailer') ?></h2>
							<p><?php _e('Preparing to send emails... This could take a while. Please be patient!', 'mass_mailer'); ?></p>
							<?php

							update_site_option( "mass_mailer_message", $_POST['email_content'] );
							update_site_option( "mass_mailer_subject", $_POST['email_subject'] );
							update_site_option( "mass_mailer_sender", $_POST['email_sender'] );
							update_site_option( "mass_mailer_test", $_POST['email_sender'] );
							update_site_option( "mass_mailer_number_sent", '0' );

							echo "
							<SCRIPT LANGUAGE='JavaScript'>
							window.location='?page=mass-mailer&action=loop';
							</script>
							";
							break;
						} else if (isset($_POST['Test'])) {
							update_site_option( "mass_mailer_message", $_POST['email_content'] );
							update_site_option( "mass_mailer_subject", $_POST['email_subject'] );
							update_site_option( "mass_mailer_sender", $_POST['email_sender'] );
							update_site_option( "mass_mailer_test", $_POST['email_sender'] );

							$this->send_email(0, $_POST['email_test_to']);
						}
					} else {
						$error = __('You must fill in ALL required fields.', 'mass_mailer');
					}
					?>
						<h2><?php _e('Send Email', 'mass_mailer') ?></h2>
						<form name="email_form" method="POST" action="?page=mass-mailer&action=process">
						<p><?php echo sprintf(__('Send an email to blog owners. %s out of %s user(s) currently accepting emails.', 'mass_mailer'), $tmp_user_count, $tmp_users_count); ?></p>
							<?php if ( !empty( $error ) ) { ?>
							<div class="error"><p><?php echo $error; ?></p></div>
							<?php } ?>
							<table class="form-table">
								<tr valign="top">
									<th scope="row"><?php _e('Sender Email:', 'mass_mailer'); ?></th>
									<td><input name="email_sender" type="text" id="from_email" style="width: 95%" value="<?php print $_POST['email_sender']; ?>" size="45" />
									<br />
									<?php _e('The address that will appear in the "email from" field.', 'mass_mailer'); ?></td>
								</tr>
								<tr valign="top">
									<th scope="row"><?php _e('Test Mail Recepient Email:', 'mass_mailer') ?></th>
									<td><input name="email_test_to" type="text" id="email_test_to" style="width: 95%" value="<?php print $_POST['email_test_to']; ?>" size="45" />
									<br />
									<?php _e("Test mail recepient's address, will be ingored when sending sending mails out.", 'mass_mailer') ?></td>
								</tr>
								<tr valign="top">
									<th scope="row"><?php _e('Subject:', 'mass_mailer'); ?></th>
									<td><input name="email_subject" type="text" id="subject" style="width: 95%" value="<?php print $_POST['email_subject']; ?>" size="75" />
									<br />
									<?php _e('This cannot be left blank.', 'mass_mailer'); ?></td>
								</tr>
								<tr valign="top">
									<th scope="row"><?php _e('Email Content:', 'mass_mailer'); ?></th>
									<td><textarea name="email_content" id="email_content" rows='5' cols='45' style="width: 95%"><?php print $_POST['email_content']; ?></textarea>
									<br />
									<?php _e('Plain text only. No HTML allowed.', 'mass_mailer'); ?></td>
								</tr>
							</table>
						<p class="submit">
							<input type="submit" name="Submit" value="<?php _e('Send Email', 'mass_mailer'); ?>" />
							<input type="reset" name="Reset" value="<?php _e('Reset', 'mass_mailer') ?>" />
							<input type="submit" name="Test" value="<?php _e('Send Test Mail', 'mass_mailer') ?>" />
						</p>
						</form>
					<?php
				break;
				//---------------------------------------------------//
				case "loop":
					if ( is_super_admin() ) {
						set_time_limit(0);
						$tmp_emails_left_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->base_prefix}mass_mailer WHERE email_optout<>'no' AND email_status = 'no'");
						if ($tmp_emails_left_count == 0){
							?>
							<h2><?php _e('Send Email', 'mass_mailer') ?></h2>
							<p><?php _e('Finished!', 'mass_mailer'); ?> </p>
							<?php
						} else {
							?>
							<h2><?php _e('Send Email', 'mass_mailer') ?></h2>
							<p><?php echo sprintf(__('Sending emails... Roughly %s left to send.', 'mass_mailer'), $tmp_emails_left_count); ?></p>
							<?php

							//------------------------------//
							$query = "SELECT email_user_id, email_optout FROM {$wpdb->base_prefix}mass_mailer WHERE email_optout<>'no' AND email_status = 'no' LIMIT 500";
							$users_list = $wpdb->get_results( $query, ARRAY_A );
							$tmp_count_array = count( $users_list );
							if ($tmp_count_array == 0) {
							} else {
							$this->processArrayUsers($users_list);
							}
							//------------------------------//

							echo "
							<SCRIPT LANGUAGE='JavaScript'>
							window.location.reload();
							</script>
							";
						}
					}
				break;
			}
		} else {
			echo '<p>' . __('This plugin is only for sites with less than 2,500 users', 'mass_mailer') . '</p>';
		}
		echo '</div>';
	}

}

$mass_mailer =& new Mass_Mailer();

/**
 * Show notification if WPMUDEV Update Notifications plugin is not installed
 **/
if ( !function_exists( 'wdp_un_check' ) ) {
	add_action( 'admin_notices', 'wdp_un_check', 5 );
	add_action( 'network_admin_notices', 'wdp_un_check', 5 );

	function wdp_un_check() {
		if ( !class_exists( 'WPMUDEV_Update_Notifications' ) && current_user_can( 'edit_users' ) )
			echo '<div class="error fade"><p>' . __('Please install the latest version of <a href="http://premium.wpmudev.org/project/update-notifications/" title="Download Now &raquo;">our free Update Notifications plugin</a> which helps you stay up-to-date with the most stable, secure versions of WPMU DEV themes and plugins. <a href="http://premium.wpmudev.org/wpmu-dev/update-notifications-plugin-information/">More information &raquo;</a>', 'wds') . '</a></p></div>';
	}
}
