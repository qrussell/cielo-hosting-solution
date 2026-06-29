<?php

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Domain_Checker_Shortcode {
    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_shortcode( 'skyhshoso_domain_checker', array( $this, 'domain_checker_shortcode' ) );
        add_shortcode( 'skyhshoso_domain_transfer_checker', array( $this, 'domain_transfer_checker_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    public function enqueue_scripts() {
        // Enqueue custom CSS instead of Bootstrap
        wp_enqueue_style( 'skyhshoso-domain-checker', SKYHSHOSO_PLUGIN_URL . 'assets/css/domain-checker.css', array(), SKYHSHOSO_VERSION );
        wp_enqueue_script( 'wc-add-to-cart' );
        wp_enqueue_script( 'woocommerce' );
        wp_enqueue_script( 'skyhshoso-domain-checker', SKYHSHOSO_PLUGIN_URL . 'assets/js/domain-checker.js', array( 'jquery' ), SKYHSHOSO_VERSION, true );
        wp_localize_script( 'skyhshoso-domain-checker', 'skyhshoso_domain_checker_vars', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'home_url'     => home_url(),
            'checkout_url' => wc_get_checkout_url(),
            'nonce'        => wp_create_nonce( 'skyhshoso_check_skyhshoso_domain_nonce' ),
            'add_to_cart_nonce' => wp_create_nonce( 'skyhshoso_add_domain_to_cart_nonce' ),
            'i18n' => array(
                'enter_valid_domain' => __( 'Please enter a valid domain name.', 'skyhs-hosting-solution' ),
                'available' => __( 'Available', 'skyhs-hosting-solution' ),
                'not_available' => __( 'Not Available', 'skyhs-hosting-solution' ),
                'add_to_cart' => __( 'Add to Cart', 'skyhs-hosting-solution' ),
                'adding' => __( 'Adding...', 'skyhs-hosting-solution' ),
                'registration_price' => __( 'Registration Price', 'skyhs-hosting-solution' ),
                'renewal_price' => __( 'Renewal Price', 'skyhs-hosting-solution' ),
                'not_available_message' => __( 'This domain is not available for registration.', 'skyhs-hosting-solution' ),
                'error_occurred' => __( 'An error occurred. Please try again.', 'skyhs-hosting-solution' ),
                'load_more' => __( 'Load More Suggestions', 'skyhs-hosting-solution' ),
                'transfer_price' => __( 'Transfer Price', 'skyhs-hosting-solution' ),
                'renewal_price' => __( 'Renewal Price', 'skyhs-hosting-solution' ),
                'transferable' => __( 'Transferable', 'skyhs-hosting-solution' ),
                'not_transferable' => __( 'Not Transferable', 'skyhs-hosting-solution' ),
                'add_to_cart_transfer' => __( 'Add Transfer to Cart', 'skyhs-hosting-solution' ),
                'include_renewal' => __( 'Includes 1-year renewal', 'skyhs-hosting-solution' ),
                'auth_code_required' => __( 'Please enter the EPP authorization code.', 'skyhs-hosting-solution' ),
                'checking' => __( 'Checking...', 'skyhs-hosting-solution' ),
                'adding' => __( 'Adding...', 'skyhs-hosting-solution' ),
                'not_transferable_message' => __( 'This domain cannot be transferred.', 'skyhs-hosting-solution' ),
            ),
        ) );
    }

    public function domain_checker_shortcode() {
        if ( SkyHSHOSO_Settings::is_domain_registration_disabled() ) {
            return '<p>' . esc_html__( 'Domain registration is currently disabled.', 'skyhs-hosting-solution' ) . '</p>';
        }
        ob_start();
        ?>
        <div class="skyhshoso-domain-checker-wrapper">
            <div class="skyhshoso-domain-checker-container">
                <div class="skyhshoso-domain-checker-card">
                    <h1 class="skyhshoso-domain-checker-title"><?php esc_html_e( 'Domain Search', 'skyhs-hosting-solution' ); ?></h1>
                    <form id="domain-search-form" class="skyhshoso-domain-checker-form skyhshoso-domain-checker-mb-4">
                        <input type="text" id="newdomain" name="domain" required class="skyhshoso-domain-checker-input" placeholder="<?php esc_attr_e( 'Enter domain name', 'skyhs-hosting-solution' ); ?>">
                        <button type="submit" class="skyhshoso-domain-checker-button"><?php esc_html_e( 'Check Availability', 'skyhs-hosting-solution' ); ?></button>
                    </form>

                    <div id="search-results" class="skyhshoso-domain-checker-hidden">
                        <div id="main-result" class="skyhshoso-domain-checker-mb-4"></div>
                        <h2 class="skyhshoso-domain-checker-mb-3"><?php esc_html_e( 'Popular Domain Suggestions', 'skyhs-hosting-solution' ); ?></h2>
                        <div id="suggestions"></div>
                    </div>

                    <div id="loading" class="skyhshoso-domain-checker-text-center skyhshoso-domain-checker-hidden">
                        <div class="skyhshoso-domain-checker-spinner">
                            <span class="skyhshoso-domain-checker-sr-only"><?php esc_html_e( 'Loading...', 'skyhs-hosting-solution' ); ?></span>
                        </div>
                    </div>

                    <div id="load-more-container" class="skyhshoso-domain-checker-text-center skyhshoso-domain-checker-mt-4 skyhshoso-domain-checker-hidden">
                        <button id="load-more" class="skyhshoso-domain-checker-button secondary"><?php esc_html_e( 'Load More Suggestions', 'skyhs-hosting-solution' ); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function domain_transfer_checker_shortcode() {
        if ( SkyHSHOSO_Settings::is_domain_registration_disabled() ) {
            return '<p>' . esc_html__( 'Domain transfers are currently disabled.', 'skyhs-hosting-solution' ) . '</p>';
        }
        ob_start();
        ?>
        <div class="skyhshoso-domain-checker-wrapper">
            <div class="skyhshoso-domain-checker-container">
                <div class="skyhshoso-domain-checker-card">
                    <h1 class="skyhshoso-domain-checker-title"><?php esc_html_e( 'Transfer Domain', 'skyhs-hosting-solution' ); ?></h1>
                    <p class="skyhshoso-domain-checker-description"><?php esc_html_e( 'Enter the domain you want to transfer along with your EPP authorization code from your current registrar. The transfer includes a 1-year renewal.', 'skyhs-hosting-solution' ); ?></p>
                    <form id="domain-transfer-form" class="skyhshoso-domain-checker-form skyhshoso-domain-checker-mb-4">
                        <input type="text" id="transfer-domain" name="domain" required class="skyhshoso-domain-checker-input" placeholder="<?php esc_attr_e( 'Enter domain name (e.g. example.com)', 'skyhs-hosting-solution' ); ?>">
                        <input type="text" id="transfer-auth-code" name="auth_code" required class="skyhshoso-domain-checker-input" style="margin-top:10px;" placeholder="<?php esc_attr_e( 'EPP Authorization Code', 'skyhs-hosting-solution' ); ?>">
                        <button type="submit" class="skyhshoso-domain-checker-button" style="margin-top:16px;"><?php esc_html_e( 'Check Transfer Eligibility', 'skyhs-hosting-solution' ); ?></button>
                    </form>

                    <div id="transfer-results" class="skyhshoso-domain-checker-hidden"></div>

                    <div id="transfer-loading" class="skyhshoso-domain-checker-text-center skyhshoso-domain-checker-hidden">
                        <div class="skyhshoso-domain-checker-spinner">
                            <span class="skyhshoso-domain-checker-sr-only"><?php esc_html_e( 'Checking...', 'skyhs-hosting-solution' ); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

function SkyHSHOSO_Domain_Checker_Shortcode() {
    return SkyHSHOSO_Domain_Checker_Shortcode::instance();
}

SkyHSHOSO_Domain_Checker_Shortcode();