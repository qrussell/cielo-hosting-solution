<?php
/**
 * Review Collector for SkyHS Hosting Solution
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SkyHSHOSO_Review_Collector
 */
class SkyHSHOSO_Review_Collector {

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'admin_notices', array( $this, 'maybe_display_review_notice' ) );
        add_action( 'wp_ajax_skyhshoso_dismiss_review_notice', array( $this, 'dismiss_review_notice' ) );
        add_filter( 'admin_footer_text', array( $this, 'admin_footer_text' ) );
    }

    /**
     * Show review request in admin footer on SkyHS pages
     */
    public function admin_footer_text( $footer_text ) {
        $screen = get_current_screen();

        if ( ! $screen ) {
            return $footer_text;
        }

        // List of SkyHS related screens
        $skyhs_screens = array(
            'toplevel_page_skyhshoso-dashboard',
            'skyhs_page_skyhshoso-servers',
            'skyhs_page_skyhshoso-products',
            'edit-skyhshoso_hosting',
            'skyhshoso_hosting',
            'edit-skyhshoso_domain',
            'skyhshoso_domain',
            'skyhs_page_skyhshoso-subscriptions',
            'skyhs_page_skyhshoso-domain-registration',
            'skyhs_page_skyhshoso-enom-settings',
            'skyhs_page_skyhshoso-settings',
            'skyhs_page_skyhshoso-import-export',
            'skyhs_page_skyhshoso-setup',
        );

        if ( in_array( $screen->id, $skyhs_screens, true ) ) {
            $review_url = 'https://wordpress.org/support/plugin/skyhs-hosting-solution/reviews/#new-post';
            $footer_text = sprintf(
                /* translators: %s: review link */
                __( 'If you like <strong>SkyHS Hosting Solution</strong> please leave us a %s review. Thank you!', 'skyhs-hosting-solution' ),
                '<a href="' . esc_url( $review_url ) . '" target="_blank">&#9733;&#9733;&#9733;&#9733;&#9733;</a>'
            );
        }

        return $footer_text;
    }

    /**
     * Maybe display the review notice
     */
    public function maybe_display_review_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Only show if setup is complete
        if ( ! get_option( 'skyhshoso_setup_completed', false ) ) {
            return;
        }

        // Only show if not dismissed
        if ( get_option( 'skyhshoso_review_notice_dismissed', false ) ) {
            return;
        }

        // Only show after a short delay (e.g., 2 days after setup)
        $setup_completed_time = get_option( 'skyhshoso_setup_completed_time', 0 );
        if ( ! $setup_completed_time ) {
            // If the time is not set, set it now (for users who already completed setup)
            $setup_completed_time = time();
            update_option( 'skyhshoso_setup_completed_time', $setup_completed_time );
        }

        // Wait 2 days before showing (172800 seconds)
        if ( time() < $setup_completed_time + ( 2 * DAY_IN_SECONDS ) ) {
            return;
        }

        $review_url = 'https://wordpress.org/support/plugin/skyhs-hosting-solution/reviews/#new-post';
        ?>
        <div id="skyhshoso-review-notice" class="notice notice-info is-dismissible" style="position: relative;">
            <p><strong><?php esc_html_e( 'Are you enjoying SkyHS Hosting Solution?', 'skyhs-hosting-solution' ); ?></strong></p>
            <p><?php esc_html_e( 'We hope the plugin is helping you manage your hosting business! If you have a moment, please consider leaving us a 5-star review on WordPress.org. It helps us a lot!', 'skyhs-hosting-solution' ); ?></p>
            <p>
                <a href="<?php echo esc_url( $review_url ); ?>" class="button button-primary" target="_blank"><?php esc_html_e( 'Leave a Review', 'skyhs-hosting-solution' ); ?></a>
                <button type="button" class="button skyhshoso-dismiss-review" style="margin-left: 10px;"><?php esc_html_e( 'Maybe later', 'skyhs-hosting-solution' ); ?></button>
            </p>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $(document).on('click', '#skyhshoso-review-notice .notice-dismiss, .skyhshoso-dismiss-review', function(e) {
                        e.preventDefault();
                        $('#skyhshoso-review-notice').fadeOut();
                        $.post(ajaxurl, {
                            action: 'skyhshoso_dismiss_review_notice',
                            nonce: '<?php echo esc_js( wp_create_nonce( 'skyhshoso_review_dismiss' ) ); ?>'
                        });
                    });
                });
            </script>
        </div>
        <?php
    }

    /**
     * Dismiss the review notice via AJAX
     */
    public function dismiss_review_notice() {
        check_ajax_referer( 'skyhshoso_review_dismiss', 'nonce' );
        update_option( 'skyhshoso_review_notice_dismissed', true );
        wp_send_json_success();
    }
}

new SkyHSHOSO_Review_Collector();
