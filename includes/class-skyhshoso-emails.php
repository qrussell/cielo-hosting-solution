<?php
/**
 * SkyHS Email Notifications
 *
 * Sends transactional emails for hosting lifecycle events:
 * provisioning, suspension, termination notice, and termination.
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Emails {

	/**
	 * Send provisioning email after cPanel account created.
	 *
	 * @param int    $hosting_id  Hosting post ID.
	 * @param string $whm_username cPanel username.
	 * @return bool
	 */
	public static function send_provisioning( $hosting_id, $whm_username ) {
		if ( ! self::email_type_enabled( 'provisioning' ) ) {
			return true;
		}

		$post = get_post( $hosting_id );
		if ( ! $post ) {
			return false;
		}

		// Prevent duplicate sends.
		if ( get_post_meta( $hosting_id, '_skyhshoso_provisioning_email_sent', true ) ) {
			return true;
		}

		$user = get_user_by( 'id', $post->post_author );
		if ( ! $user || empty( $user->user_email ) ) {
			return false;
		}

		$password   = get_post_meta( $hosting_id, '_skyhshoso_hosting_temp_password', true );
		$server_id  = get_post_meta( $hosting_id, 'skyhshoso_server_id', true );
		$server_name = $server_id ? get_the_title( $server_id ) : '';
		$server_ip  = $server_id ? get_post_meta( $server_id, '_skyhshoso_server_ip', true ) : '';
		$whm_host   = $server_id ? get_post_meta( $server_id, '_skyhshoso_whm_host', true ) : '';
		$domain     = get_post_meta( $hosting_id, 'skyhshoso_hosting_domain', true );
		$plan       = get_post_meta( $hosting_id, 'skyhshoso_hosting_plan', true );
		$server_ns = $server_id ? get_post_meta( $server_id, '_skyhshoso_server_nameservers', true ) : array();
		$nameservers = is_array( $server_ns ) && ! empty( array_filter( $server_ns ) ) ? $server_ns : get_option( 'skyhshoso_enom_default_nameservers', array() );

		// Generate cPanel URL.
		$cpanel_url = self::generate_cpanel_url( $server_id, $whm_username );

		$site_name  = get_bloginfo( 'name' );
		$subject    = sprintf( __( '[%s] Hosting account setup details', 'skyhs-hosting-solution' ), $site_name );

		$ns_list = '';
		if ( ! empty( $nameservers ) ) {
			$filtered = array_filter( $nameservers );
			if ( ! empty( $filtered ) ) {
				$ns_list = '<p><strong>' . esc_html__( 'Nameservers:', 'skyhs-hosting-solution' ) . '</strong></p><ul>';
				foreach ( $filtered as $ns ) {
					$ns_list .= '<li>' . esc_html( $ns ) . '</li>';
				}
				$ns_list .= '</ul>';
			}
		}

		$message = '
		<html>
		<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;padding:20px;color:#1d2327;">
			<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
				<div style="background:#2271b1;color:#fff;padding:30px;text-align:center;">
					<h1 style="margin:0;font-size:22px;">' . esc_html__( 'Hosting Account Details', 'skyhs-hosting-solution' ) . '</h1>
				</div>
				<div style="padding:30px;">
					<p>' . sprintf( esc_html__( 'Your hosting account setup on %s is complete. Below are the details for your new service:', 'skyhs-hosting-solution' ), esc_html( $site_name ) ) . '</p>
					<table style="width:100%;border-collapse:collapse;margin:20px 0;">
						<tr><td style="padding:8px 0;font-weight:600;width:140px;">' . esc_html__( 'Domain:', 'skyhs-hosting-solution' ) . '</td><td style="padding:8px 0;">' . esc_html( $domain ?: '—' ) . '</td></tr>
						<tr><td style="padding:8px 0;font-weight:600;">' . esc_html__( 'Plan:', 'skyhs-hosting-solution' ) . '</td><td style="padding:8px 0;">' . esc_html( $plan ?: '—' ) . '</td></tr>
						<tr><td style="padding:8px 0;font-weight:600;">' . esc_html__( 'Server:', 'skyhs-hosting-solution' ) . '</td><td style="padding:8px 0;">' . esc_html( $server_name ?: '—' ) . '</td></tr>
						<tr><td style="padding:8px 0;font-weight:600;">' . esc_html__( 'Server IP:', 'skyhs-hosting-solution' ) . '</td><td style="padding:8px 0;"><code>' . esc_html( $server_ip ?: '—' ) . '</code></td></tr>
						<tr><td style="padding:8px 0;font-weight:600;">' . esc_html__( 'cPanel URL:', 'skyhs-hosting-solution' ) . '</td><td style="padding:8px 0;"><a href="' . esc_url( $cpanel_url ) . '">' . esc_url( $cpanel_url ) . '</a></td></tr>
						<tr><td style="padding:8px 0;font-weight:600;">' . esc_html__( 'Username:', 'skyhs-hosting-solution' ) . '</td><td style="padding:8px 0;">' . esc_html( $whm_username ) . '</td></tr>';

		if ( $password ) {
			$message .= '<tr><td style="padding:8px 0;font-weight:600;">' . esc_html__( 'Password:', 'skyhs-hosting-solution' ) . '</td><td style="padding:8px 0;"><code>' . esc_html( $password ) . '</code></td></tr>';
		}

		$ip_block = '';
		if ( ! empty( $server_ip ) ) {
			$ip_block = '<p style="font-size:13px;color:#646970;">' . esc_html__( 'Point your domain\'s A record to', 'skyhs-hosting-solution' ) . ' <code>' . esc_html( $server_ip ) . '</code> ' . esc_html__( 'if you prefer not to use nameservers.', 'skyhs-hosting-solution' ) . '</p>';
		}

		$message .= '	</table>
					<p style="font-size:13px;color:#646970;">' . esc_html__( 'Please save your login details and change your password after first login.', 'skyhs-hosting-solution' ) . '</p>' .
					$ns_list .
					$ip_block . '
				</div>
			</div>
		</body>
		</html>';

		$sent = self::send( $user->user_email, $subject, $message );

		if ( class_exists( 'SkyHSHOSO_Activity_Log' ) ) {
			SkyHSHOSO_Activity_Log::log( 'email', sprintf( 'Provisioning email %s to %s for hosting #%d.', $sent ? 'sent' : 'FAILED', $user->user_email, $hosting_id ), $sent ? 'success' : 'error', 0, 0, $user->ID );
		}

		if ( $sent ) {
			update_post_meta( $hosting_id, '_skyhshoso_provisioning_email_sent', true );
			delete_post_meta( $hosting_id, '_skyhshoso_hosting_temp_password' );
		}

		return $sent;
	}

	/**
	 * Send WordPress site provisioning email.
	 *
	 * @param int    $wp_site_id WP Site post ID.
	 * @param string $admin_url  WordPress admin URL.
	 * @param string $admin_user WP admin username.
	 * @param string $admin_pass WP admin password.
	 * @return bool
	 */
	public static function send_wp_provisioning( $wp_site_id, $admin_url, $admin_user, $admin_pass ) {
		if ( ! self::email_type_enabled( 'provisioning' ) ) {
			return true;
		}

		$post = get_post( $wp_site_id );
		if ( ! $post ) {
			return false;
		}

		if ( get_post_meta( $wp_site_id, '_skyhshoso_wp_provisioning_email_sent', true ) ) {
			return true;
		}

		$user = get_user_by( 'id', $post->post_author );
		if ( ! $user || empty( $user->user_email ) ) {
			return false;
		}

		$site_name  = get_bloginfo( 'name' );
		$subject    = sprintf( __( '[%s] WordPress site setup details', 'skyhs-hosting-solution' ), $site_name );
		$site_url   = get_post_meta( $wp_site_id, '_skyhshoso_wp_site_url', true );
		$domain     = get_post_meta( $wp_site_id, 'skyhshoso_wp_domain', true );
		$hosting_id = get_post_meta( $wp_site_id, '_skyhshoso_hosting_product_id', true );
		$plan_name  = $hosting_id ? get_the_title( $hosting_id ) : get_the_title( $wp_site_id );

		$server_id   = get_post_meta( $wp_site_id, 'skyhshoso_server_id', true );
		$server_ip   = $server_id ? get_post_meta( $server_id, '_skyhshoso_server_ip', true ) : '';
		$server_ns   = $server_id ? get_post_meta( $server_id, '_skyhshoso_server_nameservers', true ) : array();
		$nameservers = is_array( $server_ns ) && ! empty( array_filter( $server_ns ) ) ? $server_ns : get_option( 'skyhshoso_enom_default_nameservers', array() );

		$ns_list = '';
		if ( ! empty( $nameservers ) ) {
			$filtered = array_filter( $nameservers );
			if ( ! empty( $filtered ) ) {
				$ns_list = '<p><strong>' . esc_html__( 'Nameservers:', 'skyhs-hosting-solution' ) . '</strong></p><ul>';
				foreach ( $filtered as $ns ) {
					$ns_list .= '<li>' . esc_html( $ns ) . '</li>';
				}
				$ns_list .= '</ul>';
			}
		}

		$ip_block = '';
		if ( ! empty( $server_ip ) ) {
			$ip_block = '<p style="font-size:13px;color:#646970;">' . esc_html__( 'Point your domain\'s A record to', 'skyhs-hosting-solution' ) . ' <code>' . esc_html( $server_ip ) . '</code> ' . esc_html__( 'if you prefer not to use nameservers.', 'skyhs-hosting-solution' ) . '</p>';
		}

		$message = '
		<html>
		<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;padding:20px;color:#1d2327;">
			<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
				<div style="background:#2271b1;color:#fff;padding:30px;text-align:center;">
					<h1 style="margin:0;font-size:22px;">' . esc_html__( 'WordPress Site Details', 'skyhs-hosting-solution' ) . '</h1>
				</div>
				<div style="padding:30px;">
					<p>' . sprintf( esc_html__( 'Your WordPress site setup on %s is complete. Below are your configuration details:', 'skyhs-hosting-solution' ), esc_html( $site_name ) ) . '</p>
					<table style="width:100%;border-collapse:collapse;margin:20px 0;">
						<tr><td style="padding:8px 0;font-weight:600;width:140px;">' . esc_html__( 'Plan:', 'skyhs-hosting-solution' ) . '</td><td style="padding:8px 0;">' . esc_html( $plan_name ) . '</td></tr>
						<tr><td style="padding:8px 0;font-weight:600;">' . esc_html__( 'Domain:', 'skyhs-hosting-solution' ) . '</td><td style="padding:8px 0;"><a href="' . esc_url( $site_url ) . '">' . esc_html( $domain ) . '</a></td></tr>';

		if ( $server_ip ) {
			$message .= '<tr><td style="padding:8px 0;font-weight:600;">' . esc_html__( 'Server IP:', 'skyhs-hosting-solution' ) . '</td><td style="padding:8px 0;"><code>' . esc_html( $server_ip ) . '</code></td></tr>';
		}

		$message .= '
						<tr><td style="padding:8px 0;font-weight:600;">' . esc_html__( 'Admin URL:', 'skyhs-hosting-solution' ) . '</td><td style="padding:8px 0;"><a href="' . esc_url( $admin_url ) . '">' . esc_url( $admin_url ) . '</a></td></tr>
						<tr><td style="padding:8px 0;font-weight:600;">' . esc_html__( 'Username:', 'skyhs-hosting-solution' ) . '</td><td style="padding:8px 0;">' . esc_html( $admin_user ) . '</td></tr>
						<tr><td style="padding:8px 0;font-weight:600;">' . esc_html__( 'Password:', 'skyhs-hosting-solution' ) . '</td><td style="padding:8px 0;"><code>' . esc_html( $admin_pass ) . '</code></td></tr>
					</table>
					<p style="font-size:13px;color:#646970;">' . esc_html__( 'Please save your login details and change your password after first login.', 'skyhs-hosting-solution' ) . '</p>' .
					$ns_list .
					$ip_block . '
				</div>
			</div>
		</body>
		</html>';

		$sent = self::send( $user->user_email, $subject, $message );

		if ( class_exists( 'SkyHSHOSO_Activity_Log' ) ) {
			SkyHSHOSO_Activity_Log::log( 'email', sprintf( 'WP provisioning email %s to %s for WP site #%d.', $sent ? 'sent' : 'FAILED', $user->user_email, $wp_site_id ), $sent ? 'success' : 'error', 0, 0, $user->ID );
		}

		if ( $sent ) {
			update_post_meta( $wp_site_id, '_skyhshoso_wp_provisioning_email_sent', true );
		}

		return $sent;
	}

	/**
	 * Send suspension notice when subscription goes on-hold.
	 *
	 * @param SkyHSHOSO_Subscription $subscription
	 * @return bool
	 */
	public static function send_suspension( $subscription ) {
		$user = get_user_by( 'id', $subscription->get_customer_id() );
		if ( ! $user || empty( $user->user_email ) ) {
			return false;
		}

		$sub_id  = $subscription->get_id();
		if ( get_post_meta( $sub_id, '_skyhshoso_suspension_email_sent', true ) ) {
			return true;
		}

		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf( __( '[%s] Update required: Hosting service status', 'skyhs-hosting-solution' ), $site_name );

		$message = '
		<html>
		<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;padding:20px;color:#1d2327;">
			<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
				<div style="background:#646970;color:#fff;padding:30px;text-align:center;">
					<h1 style="margin:0;font-size:22px;">' . esc_html__( 'Billing Update Notice', 'skyhs-hosting-solution' ) . '</h1>
				</div>
				<div style="padding:30px;">
					<p>' . esc_html__( 'Your hosting subscription is currently inactive due to a renewal billing issue.', 'skyhs-hosting-solution' ) . '</p>
					<p>' . esc_html__( 'To resume your hosting service, please update your billing details and process the renewal.', 'skyhs-hosting-solution' ) . '</p>
					<p style="font-size:13px;color:#646970;">' . esc_html__( 'Inactive accounts are scheduled for automatic retirement after a grace period of 30 days.', 'skyhs-hosting-solution' ) . '</p>
				</div>
			</div>
		</body>
		</html>';

		$sent = self::send( $user->user_email, $subject, $message );

		if ( class_exists( 'SkyHSHOSO_Activity_Log' ) ) {
			SkyHSHOSO_Activity_Log::log( 'email', sprintf( 'Suspension email %s to %s for subscription #%d.', $sent ? 'sent' : 'FAILED', $user->user_email, $sub_id ), $sent ? 'success' : 'error', $sub_id, 0, $user->ID );
		}

		if ( $sent ) {
			update_post_meta( $sub_id, '_skyhshoso_suspension_email_sent', true );
		}

		return $sent;
	}

	/**
	 * Send termination notice — 30-day warning when subscription cancelled.
	 *
	 * @param SkyHSHOSO_Subscription $subscription
	 * @return bool
	 */
	public static function send_termination_notice( $subscription ) {
		$user = get_user_by( 'id', $subscription->get_customer_id() );
		if ( ! $user || empty( $user->user_email ) ) {
			return false;
		}

		$sub_id = $subscription->get_id();
		if ( get_post_meta( $sub_id, '_skyhshoso_termination_notice_sent', true ) ) {
			return true;
		}

		$end_date   = $subscription->get_date( 'end' ) ?: __( 'soon', 'skyhs-hosting-solution' );
		$site_name  = get_bloginfo( 'name' );
		$subject    = sprintf( __( '[%s] Action required: Hosting subscription status', 'skyhs-hosting-solution' ), $site_name );

		$message = '
		<html>
		<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;padding:20px;color:#1d2327;">
			<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
				<div style="background:#646970;color:#fff;padding:30px;text-align:center;">
					<h1 style="margin:0;font-size:22px;">' . esc_html__( 'Subscription Expiry Advisory', 'skyhs-hosting-solution' ) . '</h1>
				</div>
				<div style="padding:30px;">
					<p>' . esc_html__( 'Your hosting subscription has been marked for cancellation.', 'skyhs-hosting-solution' ) . '</p>
					<p>' . sprintf( esc_html__( 'Your hosting service and associated data are scheduled to be retired on or after %s.', 'skyhs-hosting-solution' ), esc_html( $end_date ) ) . '</p>
					<p>' . esc_html__( 'Please retrieve any needed backup data from your panel before this date.', 'skyhs-hosting-solution' ) . '</p>
					<p style="font-size:13px;color:#646970;">' . esc_html__( 'If you wish to renew or reactivate your subscription, please reach out to our team.', 'skyhs-hosting-solution' ) . '</p>
				</div>
			</div>
		</body>
		</html>';

		$sent = self::send( $user->user_email, $subject, $message );

		if ( class_exists( 'SkyHSHOSO_Activity_Log' ) ) {
			SkyHSHOSO_Activity_Log::log( 'email', sprintf( 'Termination notice email %s to %s for subscription #%d.', $sent ? 'sent' : 'FAILED', $user->user_email, $sub_id ), $sent ? 'success' : 'error', $sub_id, 0, $user->ID );
		}

		if ( $sent ) {
			update_post_meta( $sub_id, '_skyhshoso_termination_notice_sent', true );
		}

		return $sent;
	}

	/**
	 * Send termination email when WHM account removed.
	 *
	 * @param SkyHSHOSO_Subscription $subscription
	 * @return bool
	 */
	public static function send_terminated( $subscription ) {
		$user = get_user_by( 'id', $subscription->get_customer_id() );
		if ( ! $user || empty( $user->user_email ) ) {
			return false;
		}

		$sub_id = $subscription->get_id();
		if ( get_post_meta( $sub_id, '_skyhshoso_terminated_email_sent', true ) ) {
			return true;
		}

		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf( __( '[%s] Hosting account retired', 'skyhs-hosting-solution' ), $site_name );

		$message = '
		<html>
		<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;padding:20px;color:#1d2327;">
			<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
				<div style="background:#646970;color:#fff;padding:30px;text-align:center;">
					<h1 style="margin:0;font-size:22px;">' . esc_html__( 'Hosting Service Retired', 'skyhs-hosting-solution' ) . '</h1>
				</div>
				<div style="padding:30px;">
					<p>' . esc_html__( 'Your hosting service has been retired.', 'skyhs-hosting-solution' ) . '</p>
					<p>' . esc_html__( 'All files and databases associated with this hosting account have been removed from the server.', 'skyhs-hosting-solution' ) . '</p>
					<p style="font-size:13px;color:#646970;">' . esc_html__( 'If you have any questions or require assistance, please contact support.', 'skyhs-hosting-solution' ) . '</p>
				</div>
			</div>
		</body>
		</html>';

		$sent = self::send( $user->user_email, $subject, $message );

		if ( class_exists( 'SkyHSHOSO_Activity_Log' ) ) {
			SkyHSHOSO_Activity_Log::log( 'email', sprintf( 'Terminated email %s to %s for subscription #%d.', $sent ? 'sent' : 'FAILED', $user->user_email, $sub_id ), $sent ? 'success' : 'error', $sub_id, 0, $user->ID );
		}

		if ( $sent ) {
			update_post_meta( $sub_id, '_skyhshoso_terminated_email_sent', true );
		}
		return $sent;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------


	/**
	 * Render an email template with variable substitution.
	 *
	 * Checks for a custom template in settings. Falls back to empty string
	 * (caller uses hardcoded default).
	 *
	 * @param string $type  provisioning|suspension|termination_notice|terminated|reminder|deletion_warning
	 * @param string $part  subject|body
	 * @param array  $data  Associative array of {{var}} => value.
	 * @return string  Rendered template or empty string if no custom template.
	 */
	public static function render_template( $type, $part, array $data = array() ) {
		if ( ! class_exists( 'SkyHSHOSO_Settings' ) ) {
			return '';
		}

		$options = get_option( 'skyhshoso_settings_group', array() );
		$key     = "email_{$part}_{$type}";
		$template = isset( $options[ $key ] ) ? trim( $options[ $key ] ) : '';

		if ( empty( $template ) ) {
			return '';
		}

		// Replace {{var}} placeholders with data values.
		$search = array();
		$replace = array();
		foreach ( $data as $var => $value ) {
			$search[]  = '{{' . $var . '}}';
			$replace[] = $value;
		}

		return str_replace( $search, $replace, $template );
	}

	/**
	 * Send renewal reminder email.
	 *
	 * @param array $data  Template data with display_name, renewal_date, amount.
	 * @return bool
	 */
	public static function send_reminder( $data ) {
		if ( ! self::email_type_enabled( 'reminder' ) ) {
			return true;
		}

		$user = get_user_by( 'id', isset( $data['user_id'] ) ? (int) $data['user_id'] : 0 );
		if ( ! $user || empty( $user->user_email ) ) {
			return false;
		}

		$site_name  = get_bloginfo( 'name' );
		$default_subject = sprintf(
			/* translators: %s: site name */
			__( 'Upcoming renewal notice — %s', 'skyhs-hosting-solution' ),
			$site_name
		);

		$default_message = sprintf(
			/* translators: 1: display name 2: renewal date 3: amount */
			__( "Hi %1\$s,\n\nThis is a friendly reminder that your subscription renews on %2\$s for %3\$s.\n\nIf you have any questions, please contact us.\n\nThank you!", 'skyhs-hosting-solution' ),
			$data['display_name'] ?? $user->display_name,
			$data['renewal_date'] ?? '',
			wp_strip_all_tags( $data['amount'] ?? '' )
		);

		// Try custom template.
		$subject = self::render_template( 'reminder', 'subject', $data );
		$body    = self::render_template( 'reminder', 'body', $data );

		if ( empty( $subject ) ) {
			$subject = $default_subject;
		}
		if ( empty( $body ) ) {
			// Convert plain-text default to professional HTML.
			$body = '<html>
			<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;padding:20px;color:#1d2327;">
				<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
					<div style="background:#2271b1;color:#fff;padding:30px;text-align:center;">
						<h1 style="margin:0;font-size:22px;">' . esc_html__( 'Subscription Renewal Reminder', 'skyhs-hosting-solution' ) . '</h1>
					</div>
					<div style="padding:30px;">
						<p>' . sprintf( esc_html__( 'Hi %s,', 'skyhs-hosting-solution' ), esc_html( $data['display_name'] ?? $user->display_name ) ) . '</p>
						<p>' . sprintf( esc_html__( 'This is a friendly reminder that your subscription renews on %1$s for %2$s.', 'skyhs-hosting-solution' ), esc_html( $data['renewal_date'] ?? '' ), esc_html( wp_strip_all_tags( $data['amount'] ?? '' ) ) ) . '</p>
						<p style="font-size:13px;color:#646970;">' . esc_html__( 'If you have any questions or require assistance, please contact us.', 'skyhs-hosting-solution' ) . '</p>
					</div>
				</div>
			</body>
			</html>';
		}

		$sent = self::send( $user->user_email, $subject, $body );

		if ( class_exists( 'SkyHSHOSO_Activity_Log' ) ) {
			SkyHSHOSO_Activity_Log::log( 'email', sprintf( 'Reminder email %s to %s (renewal: %s).', $sent ? 'sent' : 'FAILED', $user->user_email, $data['renewal_date'] ?? '' ), $sent ? 'success' : 'error', $data['subscription_id'] ?? 0, 0, $user->ID );
		}

		return $sent;
	}

	/**
	 * Send final deletion warning email before hosting is terminated.
	 *
	 * @param SkyHSHOSO_Subscription $subscription
	 * @param int                    $days_left
	 * @return bool
	 */
	public static function send_deletion_warning( $subscription, $days_left ) {
		if ( ! self::email_type_enabled( 'deletion_warning' ) ) {
			return true;
		}

		$user = get_user_by( 'id', $subscription->get_customer_id() );
		if ( ! $user || empty( $user->user_email ) ) {
			return false;
		}

		$sub_id = $subscription->get_id();
		if ( SkyHSHOSO_Subscription_DB::get_meta( $sub_id, '_skyhshoso_deletion_warning_sent', true ) ) {
			return true;
		}

		$terminate_after = SkyHSHOSO_Subscription_DB::get_meta( $sub_id, '_skyhshoso_terminate_after', true );
		$termination_date = $terminate_after ? date_i18n( get_option( 'date_format' ), strtotime( $terminate_after ) ) : __( 'soon', 'skyhs-hosting-solution' );

		$site_name = get_bloginfo( 'name' );
		$default_subject = sprintf(
			/* translators: %s: site name */
			__( 'Important advisory: Hosting data retention status — %s', 'skyhs-hosting-solution' ),
			$site_name
		);

		$default_message = sprintf(
			/* translators: 1: display name 2: days left 3: termination date */
			__( "Hi %1\$s,\n\nThis is a notice regarding your inactive hosting service. The data retention period is ending. To retain your service and data, please review your subscription status.\n\nFiles and database backups are scheduled to be removed in %2\$d days on %3\$s.\n\nIf you have any questions, please contact support.\n\nThank you!", 'skyhs-hosting-solution' ),
			$user->display_name,
			$days_left,
			$termination_date
		);

		$data = array(
			'site_name'        => $site_name,
			'display_name'     => $user->display_name,
			'termination_date' => $termination_date,
			'days_left'        => $days_left,
		);

		// Try custom template.
		$subject = self::render_template( 'deletion_warning', 'subject', $data );
		$body    = self::render_template( 'deletion_warning', 'body', $data );

		if ( empty( $subject ) ) {
			$subject = $default_subject;
		}
		if ( empty( $body ) ) {
			// Convert plain-text default to HTML.
			$body = '<html>
			<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;padding:20px;color:#1d2327;">
				<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
					<div style="background:#646970;color:#fff;padding:30px;text-align:center;">
						<h1 style="margin:0;font-size:22px;">' . esc_html__( 'Data Retention Advisory', 'skyhs-hosting-solution' ) . '</h1>
					</div>
					<div style="padding:30px;">
						<p>' . sprintf( esc_html__( 'Hi %s,', 'skyhs-hosting-solution' ), esc_html( $user->display_name ) ) . '</p>
						<p>' . sprintf( esc_html__( 'This is a notice regarding your inactive hosting service. The data retention period is ending. Files and database backups are scheduled to be removed in %1$s days on %2$s.', 'skyhs-hosting-solution' ), esc_html( $days_left ), esc_html( $termination_date ) ) . '</p>
						<p><strong>' . esc_html__( 'To retain your service and data, please review and renew your subscription.', 'skyhs-hosting-solution' ) . '</strong></p>
						<p style="font-size:13px;color:#646970;">' . esc_html__( 'If you have any questions, please contact support.', 'skyhs-hosting-solution' ) . '</p>
					</div>
				</div>
			</body>
			</html>';
		}

		$sent = self::send( $user->user_email, $subject, $body );

		if ( class_exists( 'SkyHSHOSO_Activity_Log' ) ) {
			SkyHSHOSO_Activity_Log::log( 'email', sprintf( 'Deletion warning email %s to %s for subscription #%d.', $sent ? 'sent' : 'FAILED', $user->user_email, $sub_id ), $sent ? 'success' : 'error', $sub_id, 0, $user->ID );
		}

		if ( $sent ) {
			SkyHSHOSO_Subscription_DB::update_meta( $sub_id, '_skyhshoso_deletion_warning_sent', gmdate( 'Y-m-d H:i:s' ) );
		}

		return $sent;
	}

	// -------------------------------------------------------------------------
	// Default template getters
	// -------------------------------------------------------------------------

	/**
	 * Get default subject for an email type.
	 *
	 * @param string $type  provisioning|suspension|termination_notice|terminated|reminder|deletion_warning
	 * @return string
	 */
	public static function get_default_subject( $type ) {
		$site_name = '{{site_name}}';
		switch ( $type ) {
			case 'provisioning':
				return sprintf( __( '[%s] Hosting account setup details', 'skyhs-hosting-solution' ), $site_name );
			case 'suspension':
				return sprintf( __( '[%s] Update required: Hosting service status', 'skyhs-hosting-solution' ), $site_name );
			case 'termination_notice':
				return sprintf( __( '[%s] Action required: Hosting subscription status', 'skyhs-hosting-solution' ), $site_name );
			case 'terminated':
				return sprintf( __( '[%s] Hosting account retired', 'skyhs-hosting-solution' ), $site_name );
			case 'reminder':
				return sprintf( __( 'Upcoming renewal notice — %s', 'skyhs-hosting-solution' ), $site_name );
			case 'deletion_warning':
				return sprintf( __( 'Important advisory: Hosting data retention status — %s', 'skyhs-hosting-solution' ), $site_name );
		}
		return '';
	}

	/**
	 * Get default HTML body for an email type.
	 *
	 * Returns the full HTML template with {{var}} placeholders.
	 *
	 * @param string $type  provisioning|suspension|termination_notice|terminated|reminder|deletion_warning
	 * @return string
	 */
	public static function get_default_body( $type ) {
		switch ( $type ) {
			case 'provisioning':
				return '<html>
			<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;padding:20px;color:#1d2327;">
				<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
					<div style="background:#2271b1;color:#fff;padding:30px;text-align:center;">
						<h1 style="margin:0;font-size:22px;">' . esc_html__( 'Hosting Account Details', 'skyhs-hosting-solution' ) . '</h1>
					</div>
					<div style="padding:30px;">
						<p>' . sprintf( esc_html__( 'Your hosting account setup on %s is complete. Below are the details for your new service:', 'skyhs-hosting-solution' ), '{{site_name}}' ) . '</p>
						<table style="width:100%;border-collapse:collapse;margin:20px 0;">
							<tr><td style="padding:8px 0;font-weight:600;width:140px;">' . esc_html__( 'Domain:', 'skyhs-hosting-solution' ) . '</td><td style="padding:8px 0;">{{domain}}</td></tr>
							<tr><td style="padding:8px 0;font-weight:600;">' . esc_html__( 'Plan:', 'skyhs-hosting-solution' ) . '</td><td style="padding:8px 0;">{{plan}}</td></tr>
							<tr><td style="padding:8px 0;font-weight:600;">' . esc_html__( 'Server:', 'skyhs-hosting-solution' ) . '</td><td style="padding:8px 0;">{{server_name}}</td></tr>
							<tr><td style="padding:8px 0;font-weight:600;">' . esc_html__( 'Server IP:', 'skyhs-hosting-solution' ) . '</td><td style="padding:8px 0;"><code>{{server_ip}}</code></td></tr>
							<tr><td style="padding:8px 0;font-weight:600;">' . esc_html__( 'cPanel URL:', 'skyhs-hosting-solution' ) . '</td><td style="padding:8px 0;">{{cpanel_url}}</td></tr>
							<tr><td style="padding:8px 0;font-weight:600;">' . esc_html__( 'Username:', 'skyhs-hosting-solution' ) . '</td><td style="padding:8px 0;">{{username}}</td></tr>
							<tr><td style="padding:8px 0;font-weight:600;">' . esc_html__( 'Password:', 'skyhs-hosting-solution' ) . '</td><td style="padding:8px 0;"><code>{{password}}</code></td></tr>
						</table>
						<p style="font-size:13px;color:#646970;">' . esc_html__( 'Please save your login details and change your password after first login.', 'skyhs-hosting-solution' ) . '</p>
						<p><strong>' . esc_html__( 'Nameservers:', 'skyhs-hosting-solution' ) . '</strong></p>
						<p>{{nameservers}}</p>
					</div>
				</div>
			</body>
			</html>';

			case 'suspension':
				return '<html>
			<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;padding:20px;color:#1d2327;">
				<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
					<div style="background:#646970;color:#fff;padding:30px;text-align:center;">
						<h1 style="margin:0;font-size:22px;">' . esc_html__( 'Billing Update Notice', 'skyhs-hosting-solution' ) . '</h1>
					</div>
					<div style="padding:30px;">
						<p>' . esc_html__( 'Your hosting subscription is currently inactive due to a renewal billing issue.', 'skyhs-hosting-solution' ) . '</p>
						<p>' . esc_html__( 'To resume your hosting service, please update your billing details and process the renewal.', 'skyhs-hosting-solution' ) . '</p>
						<p style="font-size:13px;color:#646970;">' . esc_html__( 'Inactive accounts are scheduled for automatic retirement after a grace period of', 'skyhs-hosting-solution' ) . ' {{grace_period}} ' . esc_html__( 'days.', 'skyhs-hosting-solution' ) . '</p>
					</div>
				</div>
			</body>
			</html>';

			case 'termination_notice':
				return '<html>
			<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;padding:20px;color:#1d2327;">
				<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
					<div style="background:#646970;color:#fff;padding:30px;text-align:center;">
						<h1 style="margin:0;font-size:22px;">' . esc_html__( 'Subscription Expiry Advisory', 'skyhs-hosting-solution' ) . '</h1>
					</div>
					<div style="padding:30px;">
						<p>' . esc_html__( 'Your hosting subscription has been marked for cancellation.', 'skyhs-hosting-solution' ) . '</p>
						<p>' . esc_html__( 'Your hosting service and associated data are scheduled to be retired on or after', 'skyhs-hosting-solution' ) . ' {{end_date}}.</p>
						<p>' . esc_html__( 'Please retrieve any needed backup data from your panel before this date.', 'skyhs-hosting-solution' ) . '</p>
						<p style="font-size:13px;color:#646970;">' . esc_html__( 'If you wish to renew or reactivate your subscription, please reach out to our team.', 'skyhs-hosting-solution' ) . '</p>
					</div>
				</div>
			</body>
			</html>';

			case 'terminated':
				return '<html>
			<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;padding:20px;color:#1d2327;">
				<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
					<div style="background:#646970;color:#fff;padding:30px;text-align:center;">
						<h1 style="margin:0;font-size:22px;">' . esc_html__( 'Hosting Service Retired', 'skyhs-hosting-solution' ) . '</h1>
					</div>
					<div style="padding:30px;">
						<p>' . esc_html__( 'Your hosting service has been retired.', 'skyhs-hosting-solution' ) . '</p>
						<p>' . esc_html__( 'All files and databases associated with this hosting account have been removed from the server.', 'skyhs-hosting-solution' ) . '</p>
						<p style="font-size:13px;color:#646970;">' . esc_html__( 'If you have any questions or require assistance, please contact support.', 'skyhs-hosting-solution' ) . '</p>
					</div>
				</div>
			</body>
			</html>';

			case 'reminder':
				return '<html>
			<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;padding:20px;color:#1d2327;">
				<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
					<div style="background:#2271b1;color:#fff;padding:30px;text-align:center;">
						<h1 style="margin:0;font-size:22px;">' . esc_html__( 'Subscription Renewal Reminder', 'skyhs-hosting-solution' ) . '</h1>
					</div>
					<div style="padding:30px;">
						<p>' . esc_html__( 'Hi {{display_name}},', 'skyhs-hosting-solution' ) . '</p>
						<p>' . sprintf( esc_html__( 'This is a friendly reminder that your subscription renews on %1$s for %2$s.', 'skyhs-hosting-solution' ), '{{renewal_date}}', '{{amount}}' ) . '</p>
						<p style="font-size:13px;color:#646970;">' . esc_html__( 'If you have any questions or require assistance, please contact us.', 'skyhs-hosting-solution' ) . '</p>
					</div>
				</div>
			</body>
			</html>';

			case 'deletion_warning':
				return '<html>
			<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;padding:20px;color:#1d2327;">
				<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
					<div style="background:#646970;color:#fff;padding:30px;text-align:center;">
						<h1 style="margin:0;font-size:22px;">' . esc_html__( 'Data Retention Advisory', 'skyhs-hosting-solution' ) . '</h1>
					</div>
					<div style="padding:30px;">
						<p>' . esc_html__( 'Hi {{display_name}},', 'skyhs-hosting-solution' ) . '</p>
						<p>' . sprintf( esc_html__( 'This is a notice regarding your inactive hosting service. The data retention period is ending. Files and database backups are scheduled to be removed in %s days on %s.', 'skyhs-hosting-solution' ), '{{days_left}}', '{{termination_date}}' ) . '</p>
						<p><strong>' . esc_html__( 'To retain your service and data, please review and renew your subscription.', 'skyhs-hosting-solution' ) . '</strong></p>
						<p style="font-size:13px;color:#646970;">' . esc_html__( 'If you have any questions, please contact support.', 'skyhs-hosting-solution' ) . '</p>
					</div>
				</div>
			</body>
			</html>';

		}
		return '';
	}

	/**
	 * Check if a specific email type is enabled.
	 *
	 * @param string $type  provisioning|suspension|termination_notice|terminated|reminder|deletion_warning
	 * @return bool
	 */
	private static function email_type_enabled( $type ) {
		return class_exists( 'SkyHSHOSO_Settings' ) && SkyHSHOSO_Settings::is_email_enabled( $type );
	}

	/**
	 * Send an HTML email.
	 *
	 * @param string $to      Recipient email.
	 * @param string $subject Subject line.
	 * @param string $message HTML body.
	 * @return bool
	 */
	private static function send( $to, $subject, $message ) {
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);
		return wp_mail( $to, $subject, $message, $headers );
	}

	/**
	 * Generate a one-time cPanel login URL.
	 *
	 * @param int    $server_id    Server post ID.
	 * @param string $whm_username cPanel username.
	 * @return string
	 */
	private static function generate_cpanel_url( $server_id, $whm_username ) {
		if ( ! $server_id || ! $whm_username ) {
			return '';
		}

		$api_user = get_post_meta( $server_id, '_skyhshoso_whm_user_id', true );
		$api_token = get_post_meta( $server_id, '_skyhshoso_whm_token', true );
		$whm_host  = get_post_meta( $server_id, '_skyhshoso_whm_host', true );

		if ( empty( $api_user ) || empty( $api_token ) || empty( $whm_host ) ) {
			return '';
		}

		$query = http_build_query( array(
			'api.version' => 1,
			'user'        => $whm_username,
			'service'     => 'cpaneld',
		) );

		$clean_host = preg_replace( '#^https?://#i', '', trim( $whm_host ) );
		$clean_host = rtrim( $clean_host, '/' );
		$url        = "https://{$clean_host}:2087/json-api/create_user_session?{$query}";

		$response = wp_remote_get( $url, array(
			'headers'  => array( 'Authorization' => "WHM {$api_user}:{$api_token}" ),
			'sslverify' => true,
			'timeout'   => 15,
		) );

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return isset( $body['data']['url'] ) ? $body['data']['url'] : '';
	}
}
