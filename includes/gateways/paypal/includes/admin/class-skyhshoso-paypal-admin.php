<?php
/**
 * SkyHS PayPal Administration Class.
 *
 * Hooks into WooCommerce's core PayPal class to display fields and notices relating to subscriptions.
 *
 * @package    SkyHS Hosting Solution
 * @subpackage Gateways/PayPal
 * @category   Class
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SkyHSHOSO_PayPal_Admin {

	public static function init() {
		add_action( 'woocommerce_settings_start', __CLASS__ . '::add_form_fields', 100 );
		add_action( 'woocommerce_api_wc_gateway_paypal', __CLASS__ . '::add_form_fields', 100 );
		add_action( 'admin_init', __CLASS__ . '::maybe_check_account' );
		add_action( 'admin_notices', __CLASS__ . '::maybe_show_admin_notices' );
		add_action( 'woocommerce_admin_order_data_after_billing_address', __CLASS__ . '::profile_link' );
		add_action( 'load-woocommerce_page_wc-settings', __CLASS__ . '::maybe_update_credentials_error_flag', 9 );
		add_action( 'woocommerce_settings_api_form_fields_paypal', array( __CLASS__, 'add_enable_for_subscriptions_setting' ) );
	}

	public static function add_form_fields() {
		foreach ( WC()->payment_gateways->payment_gateways as $key => $gateway ) {
			if ( WC()->payment_gateways->payment_gateways[ $key ]->id !== 'paypal' ) {
				continue;
			}
			WC()->payment_gateways->payment_gateways[ $key ]->form_fields['receiver_email']['desc_tip'] = false;
			WC()->payment_gateways->payment_gateways[ $key ]->form_fields['receiver_email']['description'] .= ' </p><p class="description">' . sprintf(
				__( 'It is %1$sstrongly recommended you do not change the Receiver Email address%2$s if you have active subscriptions with PayPal. Doing so can break existing subscriptions.', 'skyhs-hosting-solution' ),
				'<strong>', '</strong>'
			) . '</p>';
		}
	}

	public static function maybe_check_account() {
		$skyhshoso_paypal = isset( $_GET['skyhshoso_paypal'] ) ? sanitize_key( wp_unslash( $_GET['skyhshoso_paypal'] ) ) : '';
		$wpnonce          = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( 'check_reference_transaction_support' === $skyhshoso_paypal && wp_verify_nonce( $wpnonce, __CLASS__ ) ) {
			$redirect_url = remove_query_arg( array( 'skyhshoso_paypal', '_wpnonce' ) );
			if ( SkyHSHOSO_PayPal::are_reference_transactions_enabled( 'bypass_cache' ) ) {
				$redirect_url = add_query_arg( array( 'skyhshoso_paypal' => 'rt_enabled' ), $redirect_url );
			} else {
				$redirect_url = add_query_arg( array( 'skyhshoso_paypal' => 'rt_not_enabled' ), $redirect_url );
			}
			wp_safe_redirect( $redirect_url );
		}
	}

	public static function maybe_show_admin_notices() {
		self::maybe_disable_invalid_profile_notice();

		$valid_paypal_currency = in_array( get_woocommerce_currency(), apply_filters( 'woocommerce_paypal_supported_currencies', array( 'AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 'CHF', 'TWD', 'THB', 'GBP', 'RMB' ) ) );
		$is_paypal_enabled = 'yes' === SkyHSHOSO_PayPal::get_option( 'enabled' );

		if ( ! $is_paypal_enabled || ! $valid_paypal_currency || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$payment_gateway_tab_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=paypal' );
		$notices = array();

		if ( ! SkyHSHOSO_PayPal::are_credentials_set() ) {
			if ( 'yes' === SkyHSHOSO_PayPal::get_option( 'enabled_for_subscriptions' ) ) {
				$notices[] = array(
					'type' => 'warning',
					'text' => sprintf(
						__( 'PayPal is inactive for subscription transactions. Please %1$sset up the PayPal IPN%2$s and %3$senter your API credentials%4$s to enable PayPal for Subscriptions.', 'skyhs-hosting-solution' ),
						'<a href="https://docs.woocommerce.com/document/subscriptions/store-manager-guide/#ipn-setup" target="_blank">',
						'</a>',
						'<a href="' . esc_url( $payment_gateway_tab_url ) . '">',
						'</a>'
					),
				);
			}
		} elseif ( 'woocommerce_page_wc-settings' === get_current_screen()->base && isset( $_GET['tab'] ) && in_array( $_GET['tab'], array( 'subscriptions', 'checkout' ) ) && ! SkyHSHOSO_PayPal::are_reference_transactions_enabled() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'yes' === SkyHSHOSO_PayPal::get_option( 'enabled_for_subscriptions' ) ) {
				$notice_type = 'warning';
				$notice_text = __( '%1$sPayPal Reference Transactions are not enabled on your account%2$s, some subscription management features are not enabled. Please contact PayPal and request they %3$senable PayPal Reference Transactions%4$s on your account. %5$sCheck PayPal Account%6$s  %3$sLearn more %7$s', 'skyhs-hosting-solution' );
			} else {
				$notice_type = 'info';
				$notice_text = __( '%1$sPayPal Reference Transactions are not enabled on your account%2$s. If you wish to use PayPal Reference Transactions with Subscriptions, please contact PayPal and request they %3$senable PayPal Reference Transactions%4$s on your account. %5$sCheck PayPal Account%6$s  %3$sLearn more %7$s', 'skyhs-hosting-solution' );
			}

			$notices[] = array(
				'type' => $notice_type,
				'text' => sprintf( $notice_text,
					'<strong>', '</strong>',
					'<a href="https://docs.woocommerce.com/document/subscriptions/faq/paypal-reference-transactions/" target="_blank">', '</a>',
					'</p><p><a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( 'skyhshoso_paypal', 'check_reference_transaction_support' ), __CLASS__ ) ) . '">', '</a>',
					'&raquo;</a>'
				),
			);
		}

		if ( isset( $_GET['skyhshoso_paypal'] ) && 'rt_enabled' === $_GET['skyhshoso_paypal'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$notices[] = array(
				'type' => 'confirmation',
				'text' => sprintf( __( '%1$sPayPal Reference Transactions are enabled on your account%2$s. All subscription management features are now enabled.', 'skyhs-hosting-solution' ), '<strong>', '</strong>' ),
			);
		}

		if ( false !== get_option( 'skyhshoso_paypal_credentials_error' ) ) {
			$notices[] = array(
				'type' => 'error',
				'text' => sprintf( __( 'There is a problem with PayPal. Your API credentials may be incorrect. Please update your %1$sAPI credentials%2$s. %3$sLearn more%4$s.', 'skyhs-hosting-solution' ),
					'<a href="' . esc_url( $payment_gateway_tab_url ) . '">', '</a>',
					'<a href="https://docs.woocommerce.com/document/subscriptions-canceled-suspended-paypal/#section-2" target="_blank">', '</a>'
				),
			);
		}

		if ( 'yes' === get_option( 'skyhshoso_paypal_invalid_profile_id' ) ) {
			$notices[] = array(
				'type' => 'error',
				'text' => sprintf( __( 'There is a problem with PayPal. Your PayPal account is issuing out-of-date subscription IDs. %1$sLearn more%2$s. %3$sDismiss%4$s.', 'skyhs-hosting-solution' ),
					'<a href="https://docs.woocommerce.com/document/subscriptions-canceled-suspended-paypal/#section-3" target="_blank">', '</a>',
					'<a href="' . esc_url( add_query_arg( 'skyhshoso_disable_paypal_invalid_profile_id_notice', 'true' ) ) . '">', '</a>'
				),
			);
		}

		$last_ipn_error        = get_option( 'skyhshoso_fatal_error_handling_ipn', '' );
		$failed_ipn_log_handle = 'skyhshoso-ipn-failures';

		if ( ! empty( $last_ipn_error ) && ( false === get_option( 'skyhshoso_fatal_error_handling_ipn_ignored', false ) || isset( $_GET['skyhshoso_reveal_your_ipn_secrets'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$log_file_url = admin_url( sprintf( 'admin.php?page=wc-status&tab=logs&log_file=%s-%s-log', $failed_ipn_log_handle, sanitize_file_name( wp_hash( $failed_ipn_log_handle ) ) ) );
			$dismiss_url  = wp_nonce_url( add_query_arg( 'skyhshoso_ipn_error_notice', 'ignore' ), 'skyhshoso_ipn_error_notice', '_skyhshoso_nonce' );
			?>
			<div class="notice notice-error">
				<p><?php echo wp_kses_post( sprintf( __( 'A fatal error has occurred while processing a recent subscription payment with PayPal. Please open a new ticket at <a href="%s" target="_blank">WooCommerce Support</a> immediately.', 'skyhs-hosting-solution' ), 'https://woocommerce.com/my-account/marketplace-ticket-form/' ) ); ?></p>
				<p><?php echo wp_kses_post( sprintf( __( 'Last recorded error: %s', 'skyhs-hosting-solution' ), '<code>' . esc_html( $last_ipn_error ) . '</code>' ) ); ?></p>
				<p><?php echo wp_kses_post( sprintf( __( 'View the %s log file.', 'skyhs-hosting-solution' ), '<code>' . esc_html( $failed_ipn_log_handle ) . '</code>' ) ); ?>
				<a href="<?php echo esc_url( $log_file_url ); ?>"><?php echo esc_html__( 'View logs', 'skyhs-hosting-solution' ); ?></a></p>
				<p><a class="button" href="<?php echo esc_url( $dismiss_url ); ?>"><?php echo esc_html__( 'Ignore this error (not recommended)', 'skyhs-hosting-solution' ); ?></a></p>
			</div>
			<?php
		}

		foreach ( $notices as $notice_args ) {
			$notice_args = wp_parse_args( $notice_args, array( 'type' => 'error', 'text' => '' ) );
			$css_class   = 'notice notice-error';
			switch ( $notice_args['type'] ) {
				case 'warning':
					$css_class = 'notice notice-warning';
					break;
				case 'info':
					$css_class = 'notice notice-info';
					break;
				case 'confirmation':
					$css_class = 'notice notice-success';
					break;
			}
			printf( '<div class="%s"><p>%s</p></div>', esc_attr( $css_class ), wp_kses_post( $notice_args['text'] ) );
		}
	}

	protected static function maybe_disable_invalid_profile_notice() {
		if ( isset( $_GET['skyhshoso_disable_paypal_invalid_profile_id_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			update_option( 'skyhshoso_paypal_invalid_profile_id', 'disabled' );
		}
		if ( isset( $_GET['skyhshoso_ipn_error_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			update_option( 'skyhshoso_fatal_error_handling_ipn_ignored', true );
		}
	}

	public static function maybe_update_credentials_error_flag() {
		$wpnonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! empty( $wpnonce ) && wp_verify_nonce( $wpnonce, 'woocommerce-settings' ) && ( isset( $_POST['woocommerce_paypal_api_username'] ) || isset( $_POST['woocommerce_paypal_api_password'] ) || isset( $_POST['woocommerce_paypal_api_signature'] ) ) ) {
			$credentials_updated = false;
			if ( isset( $_POST['woocommerce_paypal_api_username'] ) && SkyHSHOSO_PayPal::get_option( 'api_username' ) !== $_POST['woocommerce_paypal_api_username'] ) {
				$credentials_updated = true;
			} elseif ( isset( $_POST['woocommerce_paypal_api_password'] ) && SkyHSHOSO_PayPal::get_option( 'api_password' ) !== $_POST['woocommerce_paypal_api_password'] ) {
				$credentials_updated = true;
			} elseif ( isset( $_POST['woocommerce_paypal_api_signature'] ) && SkyHSHOSO_PayPal::get_option( 'api_signature' ) !== $_POST['woocommerce_paypal_api_signature'] ) {
				$credentials_updated = true;
			}
			if ( $credentials_updated ) {
				delete_option( 'skyhshoso_paypal_credentials_error' );
			}
		}
		do_action( 'skyhshoso_paypal_admin_update_credentials' );
	}

	public static function profile_link( $subscription ) {
		if ( ! $subscription instanceof SkyHSHOSO_Subscription || $subscription->is_manual() || 'paypal' !== $subscription->get_payment_method() ) {
			return;
		}

		$paypal_profile_id = skyhshoso_get_paypal_id( $subscription->get_id() );

		if ( empty( $paypal_profile_id ) ) {
			return;
		}

		$url    = '';
		$domain = SkyHSHOSO_PayPal::get_option( 'testmode' ) === 'yes' ? 'sandbox.paypal' : 'paypal';

		if ( false === skyhshoso_is_paypal_profile_a( $paypal_profile_id, 'billing_agreement' ) ) {
			$url = "https://www.{$domain}.com/?cmd=_profile-recurring-payments&encrypted_profile_id={$paypal_profile_id}";
		} elseif ( skyhshoso_is_paypal_profile_a( $paypal_profile_id, 'billing_agreement' ) ) {
			$url = "https://www.{$domain}.com/?cmd=_profile-merchant-pull&encrypted_profile_id={$paypal_profile_id}&mp_id={$paypal_profile_id}&return_to=merchant&flag_flow=merchant";
		}

		echo '<div class="address">';
		echo '<p class="paypal_subscription_info"><strong>' . esc_html__( 'PayPal Subscription ID:', 'skyhs-hosting-solution' ) . '</strong>';
		if ( ! empty( $url ) ) {
			echo '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $paypal_profile_id ) . '</a>';
		} else {
			echo esc_html( $paypal_profile_id );
		}
		echo '</p></div>';
	}

	public static function add_enable_for_subscriptions_setting( $settings ) {
		if ( SkyHSHOSO_PayPal::are_reference_transactions_enabled() ) {
			return $settings;
		}

		$setting = array(
			'type'    => 'checkbox',
			'label'   => __( 'Enable PayPal Standard for Subscriptions', 'skyhs-hosting-solution' ),
			'default' => 'no',
		);

		if ( 'no' === SkyHSHOSO_PayPal::get_option( 'enabled_for_subscriptions' ) ) {
			$setting['description'] = sprintf(
				__( "Before enabling PayPal Standard for Subscriptions, please note, when using PayPal Standard, customers are locked into using PayPal Standard for the life of their subscription, and PayPal Standard has a number of limitations. Please read the guide on %1\$swhy we don't recommend PayPal Standard%2\$s for Subscriptions before choosing to enable this option.", 'skyhs-hosting-solution' ),
				'<a href="https://docs.woocommerce.com/document/subscriptions/payment-gateways/#paypal-limitations">', '</a>'
			);
		}

		$settings = skyhshoso_array_insert_after( 'enabled', $settings, 'enabled_for_subscriptions', $setting );
		return $settings;
	}
}
