<?php

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Product_Fields {
    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Subscription & billing fields on the general product tab
        add_action( 'woocommerce_product_options_general_product_data', array( $this, 'add_subscription_fields' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_subscription_fields' ) );

        // Variation-level billing fields
        add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'add_variation_billing_fields' ), 10, 3 );
        add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_billing_fields' ), 10, 2 );

        // Enqueue toggle script for subscription fields
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_toggle_script' ) );

        // Fix products stuck on old subscription product types — convert to normal WC types.
        add_filter( 'woocommerce_product_type_query', array( $this, 'fix_legacy_product_types' ), 10, 2 );

        // Filter WooCommerce admin product list table to exclude hosting and wp site products
        add_action( 'pre_get_posts', array( $this, 'exclude_custom_products_from_woocommerce_admin' ) );
    }

    /**
     * Exclude hosting and WP site products from the WooCommerce default products list table in admin.
     */
    public function exclude_custom_products_from_woocommerce_admin( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        global $pagenow;
        if ( 'edit.php' === $pagenow && 'product' === $query->get( 'post_type' ) ) {
            $meta_query = $query->get( 'meta_query' );
            if ( ! is_array( $meta_query ) ) {
                $meta_query = array();
            }

            $meta_query[] = array(
                'relation' => 'OR',
                array(
                    'key'     => '_skyhshoso_product_type',
                    'compare' => 'NOT EXISTS',
                ),
                array(
                    'key'     => '_skyhshoso_product_type',
                    'value'   => array( 'skyhshoso_hosting', 'skyhshoso_wp_site' ),
                    'compare' => 'NOT IN',
                ),
            );

            $query->set( 'meta_query', $meta_query );
        }
    }

    /**
     * Fix products that were created as 'subscription' or 'variable-subscription'.
     * Without a subscription plugin those product types are unrecognized, which makes
     * WooCommerce unable to add them to cart. We transparently remap them.
     */
    public function fix_legacy_product_types( $type, $product_id ) {
        if ( $type === false ) {
            $terms = get_the_terms( $product_id, 'product_type' );
            if ( $terms && ! is_wp_error( $terms ) ) {
                $slugs = wp_list_pluck( $terms, 'slug' );
                if ( in_array( 'variable-subscription', $slugs, true ) ) {
                    // Reassign to 'variable' so WooCommerce handles it natively.
                    wp_set_object_terms( $product_id, 'variable', 'product_type' );
                    return 'variable';
                }
                if ( in_array( 'subscription', $slugs, true ) ) {
                    // Reassign to 'simple'.
                    wp_set_object_terms( $product_id, 'simple', 'product_type' );
                    return 'simple';
                }
            }
        }
        return $type;
    }

    /**
     * Enqueue toggle script for subscription billing fields on product edit page.
     */
    public function enqueue_toggle_script( $hook ) {
        $screen = get_current_screen();
        if ( ! $screen || 'product' !== $screen->post_type ) {
            return;
        }

        wp_add_inline_script( 'jquery', '
jQuery(function($) {
    function toggleBillingFields() {
        var isSub = $("#_skyhshoso_is_subscription").is(":checked");
        var isSimple = $("#product-type").val() === "simple";
        $(".skyhshoso-billing-fields-wrapper").toggle(isSub && isSimple);
        $(".skyhshoso-variation-billing-fields").toggle(isSub);
    }
    $("#_skyhshoso_is_subscription, #product-type").on("change", toggleBillingFields);
    $("body").on("woocommerce-product-type-change", toggleBillingFields);
    toggleBillingFields();
    $(document).on("woocommerce_variations_added woocommerce_variations_loaded", function() {
        $(".skyhshoso-variation-billing-fields").toggle($("#_skyhshoso_is_subscription").is(":checked"));
    });
});
' );
    }

    // -------------------------------------------------------------------------
    // Subscription & Billing fields (simple products)
    // -------------------------------------------------------------------------

    public function add_subscription_fields() {
        global $post;

        echo '<div class="options_group skyhshoso-subscription-group">';

        $is_subscription = get_post_meta( $post->ID, '_skyhshoso_is_subscription', true );
        woocommerce_wp_checkbox(array(
            'id'          => '_skyhshoso_is_subscription',
            'label'       => __('Enable Subscription', 'skyhs-hosting-solution'),
            'description' => __('Check this to make this product a recurring subscription.', 'skyhs-hosting-solution'),
            'value'       => $is_subscription ? 'yes' : 'no',
            'cbvalue'     => 'yes',
        ));

        echo '<div class="skyhshoso-billing-fields-wrapper show_if_simple" style="' . ( $is_subscription ? '' : 'display:none;' ) . '">';

        woocommerce_wp_select(array(
            'id'      => '_skyhshoso_billing_period',
            'label'   => __('Billing Period', 'skyhs-hosting-solution'),
            'options' => array(
                'month' => __('Month', 'skyhs-hosting-solution'),
                'year'  => __('Year', 'skyhs-hosting-solution'),
                'week'  => __('Week', 'skyhs-hosting-solution'),
                'day'   => __('Day', 'skyhs-hosting-solution'),
            ),
            'value'   => get_post_meta($post->ID, '_skyhshoso_billing_period', true) ?: 'month',
            'desc_tip' => true,
            'description' => __('How often the customer is billed.', 'skyhs-hosting-solution'),
        ));

        woocommerce_wp_text_input(array(
            'id'          => '_skyhshoso_billing_interval',
            'label'       => __('Billing Interval', 'skyhs-hosting-solution'),
            'placeholder' => '1',
            'type'        => 'number',
            'custom_attributes' => array('min' => '1', 'step' => '1'),
            'value'       => get_post_meta($post->ID, '_skyhshoso_billing_interval', true) ?: '1',
            'desc_tip'    => true,
            'description' => __('Bill every X billing periods (e.g. every 2 months).', 'skyhs-hosting-solution'),
        ));

        woocommerce_wp_text_input(array(
            'id'          => '_skyhshoso_trial_length',
            'label'       => __('Trial Length', 'skyhs-hosting-solution'),
            'placeholder' => '0',
            'type'        => 'number',
            'custom_attributes' => array('min' => '0', 'step' => '1'),
            'value'       => get_post_meta($post->ID, '_skyhshoso_trial_length', true) ?: '0',
            'desc_tip'    => true,
            'description' => __('Free trial length. 0 = no trial.', 'skyhs-hosting-solution'),
        ));

        woocommerce_wp_select(array(
            'id'      => '_skyhshoso_trial_period',
            'label'   => __('Trial Period', 'skyhs-hosting-solution'),
            'options' => array(
                'day'   => __('Day(s)', 'skyhs-hosting-solution'),
                'week'  => __('Week(s)', 'skyhs-hosting-solution'),
                'month' => __('Month(s)', 'skyhs-hosting-solution'),
            ),
            'value'   => get_post_meta($post->ID, '_skyhshoso_trial_period', true) ?: 'day',
        ));

        echo '</div>'; // .skyhshoso-billing-fields-wrapper
        echo '</div>'; // .options_group
    }

    public function save_subscription_fields( $post_id ) {
        if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
            return;
        }

        $is_sub = isset( $_POST['_skyhshoso_is_subscription'] ) && 'yes' === $_POST['_skyhshoso_is_subscription'] ? 'yes' : '';
        update_post_meta( $post_id, '_skyhshoso_is_subscription', $is_sub );

        $billing_period   = isset( $_POST['_skyhshoso_billing_period'] ) ? sanitize_text_field( wp_unslash( $_POST['_skyhshoso_billing_period'] ) ) : '';
        update_post_meta( $post_id, '_skyhshoso_billing_period', $billing_period );

        $billing_interval = isset( $_POST['_skyhshoso_billing_interval'] ) ? absint( wp_unslash( $_POST['_skyhshoso_billing_interval'] ) ) : 1;
        update_post_meta( $post_id, '_skyhshoso_billing_interval', max( 1, $billing_interval ) );

        $trial_length = isset( $_POST['_skyhshoso_trial_length'] ) ? absint( wp_unslash( $_POST['_skyhshoso_trial_length'] ) ) : 0;
        update_post_meta( $post_id, '_skyhshoso_trial_length', $trial_length );

        $trial_period = isset( $_POST['_skyhshoso_trial_period'] ) ? sanitize_text_field( wp_unslash( $_POST['_skyhshoso_trial_period'] ) ) : 'day';
        update_post_meta( $post_id, '_skyhshoso_trial_period', $trial_period );
    }

    // -------------------------------------------------------------------------
    // Variation-level billing fields
    // -------------------------------------------------------------------------

    public function add_variation_billing_fields( $loop, $variation_data, $variation ) {
        echo '<div class="skyhshoso-variation-billing-fields">';

        woocommerce_wp_select(array(
            'id'      => "_skyhshoso_billing_period{$loop}",
            'name'    => "_skyhshoso_billing_period[{$loop}]",
            'label'   => __('Billing Period', 'skyhs-hosting-solution'),
            'options' => array(
                'day'   => __('Day', 'skyhs-hosting-solution'),
                'week'  => __('Week', 'skyhs-hosting-solution'),
                'month' => __('Month', 'skyhs-hosting-solution'),
                'year'  => __('Year', 'skyhs-hosting-solution'),
            ),
            'value'   => get_post_meta($variation->ID, '_skyhshoso_billing_period', true) ?: 'month',
            'desc_tip' => true,
            'description' => __('How often the customer is billed.', 'skyhs-hosting-solution'),
        ));

        woocommerce_wp_text_input(array(
            'id'          => "_skyhshoso_billing_interval{$loop}",
            'name'        => "_skyhshoso_billing_interval[{$loop}]",
            'label'       => __('Billing Interval', 'skyhs-hosting-solution'),
            'placeholder' => '1',
            'type'        => 'number',
            'custom_attributes' => array('min' => '1', 'step' => '1'),
            'value'       => get_post_meta($variation->ID, '_skyhshoso_billing_interval', true) ?: '1',
            'desc_tip'    => true,
            'description' => __('Every X billing periods.', 'skyhs-hosting-solution'),
        ));

        woocommerce_wp_text_input(array(
            'id'          => "_skyhshoso_trial_length{$loop}",
            'name'        => "_skyhshoso_trial_length[{$loop}]",
            'label'       => __('Trial Length', 'skyhs-hosting-solution'),
            'placeholder' => '0',
            'type'        => 'number',
            'custom_attributes' => array('min' => '0', 'step' => '1'),
            'value'       => get_post_meta($variation->ID, '_skyhshoso_trial_length', true) ?: '0',
            'desc_tip'    => true,
            'description' => __('Free trial length. 0 = no trial.', 'skyhs-hosting-solution'),
        ));

        woocommerce_wp_select(array(
            'id'      => "_skyhshoso_trial_period{$loop}",
            'name'    => "_skyhshoso_trial_period[{$loop}]",
            'label'   => __('Trial Period', 'skyhs-hosting-solution'),
            'options' => array(
                'day'   => __('Day(s)', 'skyhs-hosting-solution'),
                'week'  => __('Week(s)', 'skyhs-hosting-solution'),
                'month' => __('Month(s)', 'skyhs-hosting-solution'),
            ),
            'value'   => get_post_meta($variation->ID, '_skyhshoso_trial_period', true) ?: 'day',
        ));

        echo '</div>';
    }

    public function save_variation_billing_fields( $variation_id, $loop ) {
        $nonce_verified = false;
        if ( isset( $_POST['woocommerce_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
            $nonce_verified = true;
        } elseif ( isset( $_POST['security'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'save-variations' ) ) {
            $nonce_verified = true;
        }

        if ( ! $nonce_verified ) {
            return;
        }

        $billing_period = isset( $_POST['_skyhshoso_billing_period'][$loop] ) ? sanitize_text_field( wp_unslash( $_POST['_skyhshoso_billing_period'][$loop] ) ) : '';
        update_post_meta( $variation_id, '_skyhshoso_billing_period', $billing_period );

        $billing_interval = isset( $_POST['_skyhshoso_billing_interval'][$loop] ) ? absint( wp_unslash( $_POST['_skyhshoso_billing_interval'][$loop] ) ) : 1;
        update_post_meta( $variation_id, '_skyhshoso_billing_interval', max( 1, $billing_interval ) );

        $trial_length = isset( $_POST['_skyhshoso_trial_length'][$loop] ) ? absint( wp_unslash( $_POST['_skyhshoso_trial_length'][$loop] ) ) : 0;
        update_post_meta( $variation_id, '_skyhshoso_trial_length', $trial_length );

        $trial_period = isset( $_POST['_skyhshoso_trial_period'][$loop] ) ? sanitize_text_field( wp_unslash( $_POST['_skyhshoso_trial_period'][$loop] ) ) : '';
        update_post_meta( $variation_id, '_skyhshoso_trial_period', $trial_period );
    }
}

function SkyHSHOSO_Product_Fields() {
    return SkyHSHOSO_Product_Fields::instance();
}

SkyHSHOSO_Product_Fields();
