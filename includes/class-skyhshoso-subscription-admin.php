<?php
defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Subscription_Admin {

    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'admin_post_skyhshoso_update_subscription', array( $this, 'handle_update' ) );
        add_action( 'admin_post_skyhshoso_delete_subscription', array( $this, 'handle_delete' ) );
        add_action( 'wp_ajax_skyhshoso_get_subscriptions', array( $this, 'ajax_get_subscriptions' ) );
        add_action( 'wp_ajax_skyhshoso_update_subscription_ajax', array( $this, 'ajax_update_subscription' ) );
        add_action( 'wp_ajax_skyhshoso_delete_subscription_ajax', array( $this, 'ajax_delete_subscription' ) );
        add_action( 'wp_ajax_skyhshoso_edit_subscription_ajax', array( $this, 'ajax_edit_subscription' ) );
        add_action( 'wp_ajax_skyhshoso_get_subscription_details', array( $this, 'ajax_get_subscription_details' ) );
        add_action( 'admin_footer', array( $this, 'maybe_render_edit_modal' ) );
    }

    public function enqueue_scripts( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $allowed_pages = array(
            'skyhshoso-subscriptions',
            'skyhshoso-hosting',
            'skyhshoso-wp-sites',
            'skyhshoso-domains'
        );

        $is_allowed = false;
        foreach ( $allowed_pages as $ap ) {
            if ( false !== strpos( $hook, $ap ) || $page === $ap ) {
                $is_allowed = true;
                break;
            }
        }

        if ( ! $is_allowed ) {
            return;
        }

        wp_enqueue_style(
            'skyhshoso-hosting-manager',
            SKYHSHOSO_PLUGIN_URL . 'assets/css/hosting-manager.css',
            array(),
            SKYHSHOSO_VERSION
        );

        if ( function_exists( 'WC' ) ) {
            wp_enqueue_script( 'wc-enhanced-select' );
            wp_enqueue_style( 'woocommerce_admin_styles' );
        }

        // Enqueue the common modal script
        wp_enqueue_script(
            'skyhshoso-subscription-modal',
            SKYHSHOSO_PLUGIN_URL . 'assets/js/subscription-modal.js',
            array( 'jquery', 'wc-enhanced-select' ),
            SKYHSHOSO_VERSION,
            true
        );

        $statuses = array(
            'active'         => __( 'Active', 'skyhs-hosting-solution' ),
            'pending-cancel' => __( 'Pending Cancel', 'skyhs-hosting-solution' ),
            'on-hold'        => __( 'On Hold', 'skyhs-hosting-solution' ),
            'cancelled'      => __( 'Cancelled', 'skyhs-hosting-solution' ),
            'expired'        => __( 'Expired', 'skyhs-hosting-solution' ),
            'pending'        => __( 'Pending', 'skyhs-hosting-solution' ),
        );

        $periods = array(
            'day'   => __( 'Day', 'skyhs-hosting-solution' ),
            'week'  => __( 'Week', 'skyhs-hosting-solution' ),
            'month' => __( 'Month', 'skyhs-hosting-solution' ),
            'year'  => __( 'Year', 'skyhs-hosting-solution' ),
        );

        wp_localize_script(
            'skyhshoso-subscription-modal',
            'skyhshoso_sm',
            array(
                'ajax_url'              => admin_url( 'admin-ajax.php' ),
                'nonce_get_details'     => wp_create_nonce( 'skyhshoso_get_subscription_details' ),
                'nonce_edit'            => wp_create_nonce( 'skyhshoso_edit_subscription_ajax' ),
                'nonce_search_products' => wp_create_nonce( 'search-products' ),
                'statuses'              => $statuses,
                'periods'               => $periods,
                'strings'               => array(
                    'saving'         => __( 'Updating subscription...', 'skyhs-hosting-solution' ),
                    'saved'          => __( 'Subscription updated successfully!', 'skyhs-hosting-solution' ),
                    'error'          => __( 'An error occurred.', 'skyhs-hosting-solution' ),
                    'edit_subscription' => __( 'Edit Subscription', 'skyhs-hosting-solution' ),
                    'cancel'         => __( 'Cancel', 'skyhs-hosting-solution' ),
                    'update'         => __( 'Update', 'skyhs-hosting-solution' ),
                    'search_products'=> __( 'Search for a product...', 'skyhs-hosting-solution' ),
                ),
            )
        );

        // Enqueue subscriptions page admin script only on subscriptions screen
        if ( false !== strpos( $hook, 'skyhshoso-subscriptions' ) || 'skyhshoso-subscriptions' === $page ) {
            wp_enqueue_script(
                'skyhshoso-subscriptions-admin',
                SKYHSHOSO_PLUGIN_URL . 'assets/js/subscriptions-admin.js',
                array( 'jquery', 'wc-enhanced-select', 'skyhshoso-subscription-modal' ),
                SKYHSHOSO_VERSION,
                true
            );

            wp_localize_script(
                'skyhshoso-subscriptions-admin',
                'skyhshoso_sa',
                array(
                    'ajax_url'     => admin_url( 'admin-ajax.php' ),
                    'nonce_get'    => wp_create_nonce( 'skyhshoso_get_subscriptions' ),
                    'nonce_update' => wp_create_nonce( 'skyhshoso_update_subscription_ajax' ),
                    'nonce_delete' => wp_create_nonce( 'skyhshoso_delete_subscription_ajax' ),
                    'nonce_search_products' => wp_create_nonce( 'search-products' ),
                    'statuses'     => array(
                        ''               => __( 'All Statuses', 'skyhs-hosting-solution' ),
                        'active'         => __( 'Active', 'skyhs-hosting-solution' ),
                        'pending-cancel' => __( 'Pending Cancel', 'skyhs-hosting-solution' ),
                        'on-hold'        => __( 'On Hold', 'skyhs-hosting-solution' ),
                        'cancelled'      => __( 'Cancelled', 'skyhs-hosting-solution' ),
                        'expired'        => __( 'Expired', 'skyhs-hosting-solution' ),
                        'pending'        => __( 'Pending', 'skyhs-hosting-solution' ),
                    ),
                    'periods'      => $periods,
                    'strings'      => array(
                        'deleting'       => __( 'Deleting subscription...', 'skyhs-hosting-solution' ),
                        'deleted'        => __( 'Subscription deleted successfully.', 'skyhs-hosting-solution' ),
                        'error'          => __( 'An error occurred.', 'skyhs-hosting-solution' ),
                        'confirm_delete' => __( 'Are you sure you want to permanently delete this subscription?', 'skyhs-hosting-solution' ),
                        'no_results'     => __( 'No subscriptions found matching the criteria.', 'skyhs-hosting-solution' ),
                        'every'          => __( 'Every', 'skyhs-hosting-solution' ),
                        'update'         => __( 'Update', 'skyhs-hosting-solution' ),
                    ),
                )
            );
        }
    }

    public function render_page() {
        ?>
        <div class="wrap skyhshoso-hm-wrap">
            <h1><?php esc_html_e( 'Subscriptions', 'skyhs-hosting-solution' ); ?></h1>
            <p><?php esc_html_e( 'View and manage all billing subscriptions. Update subscription statuses, track payment schedules, and manage customer billing cycles from a single admin screen.', 'skyhs-hosting-solution' ); ?></p>

            <div id="skyhshoso-hm-notice" class="notice" style="display:none;"></div>

            <div id="skyhshoso-hm-app">
                <div class="skyhshoso-hm-list-panel">
                    <div class="skyhshoso-hm-list-header" style="display:flex; flex-direction:column; gap:16px; align-items:stretch;">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <h2 style="margin:0;"><?php esc_html_e( 'All Subscriptions', 'skyhs-hosting-solution' ); ?></h2>
                        </div>
                        <div class="skyhshoso-hm-controls" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                            <input type="text" id="sa-search-input" class="hm-control-input" placeholder="<?php esc_attr_e( 'Search by customer name, email, or ID...', 'skyhs-hosting-solution' ); ?>" style="flex:1.5; min-width:200px;" />
                            <div style="flex:1; min-width:200px; max-width:300px;">
                                <select id="sa-product-filter" class="hm-control-select wc-product-search" style="width:100%;" data-placeholder="<?php esc_attr_e( 'Filter by product...', 'skyhs-hosting-solution' ); ?>">
                                    <option value=""></option>
                                </select>
                            </div>
                             <select id="sa-status-filter" class="hm-control-select" style="min-width:180px; flex:1;">
                                <option value=""><?php esc_html_e( 'All Statuses', 'skyhs-hosting-solution' ); ?></option>
                                <option value="active"><?php esc_html_e( 'Active', 'skyhs-hosting-solution' ); ?></option>
                                <option value="pending-cancel"><?php esc_html_e( 'Pending Cancel', 'skyhs-hosting-solution' ); ?></option>
                                <option value="on-hold"><?php esc_html_e( 'On Hold', 'skyhs-hosting-solution' ); ?></option>
                                <option value="cancelled"><?php esc_html_e( 'Cancelled', 'skyhs-hosting-solution' ); ?></option>
                                <option value="expired"><?php esc_html_e( 'Expired', 'skyhs-hosting-solution' ); ?></option>
                                <option value="pending"><?php esc_html_e( 'Pending', 'skyhs-hosting-solution' ); ?></option>
                             </select>
                            <select id="sa-next-payment-filter" class="hm-control-select" style="min-width:200px;">
                                <option value=""><?php esc_html_e( 'All Next Payment Dates', 'skyhs-hosting-solution' ); ?></option>
                                <option value="upcoming_7"><?php esc_html_e( 'Due within 7 days', 'skyhs-hosting-solution' ); ?></option>
                                <option value="upcoming_30"><?php esc_html_e( 'Due within 30 days', 'skyhs-hosting-solution' ); ?></option>
                                <option value="upcoming_90"><?php esc_html_e( 'Due within 90 days', 'skyhs-hosting-solution' ); ?></option>
                                <option value="overdue"><?php esc_html_e( 'Overdue (past due date)', 'skyhs-hosting-solution' ); ?></option>
                                <option value="no_date"><?php esc_html_e( 'No next payment date', 'skyhs-hosting-solution' ); ?></option>
                            </select>
                            <select id="sa-sort-by" class="hm-control-select" style="min-width:180px;">
                                <option value="created_at_desc"><?php esc_html_e( 'Sort: Newest First', 'skyhs-hosting-solution' ); ?></option>
                                <option value="created_at_asc"><?php esc_html_e( 'Sort: Oldest First', 'skyhs-hosting-solution' ); ?></option>
                                <option value="product_name_asc"><?php esc_html_e( 'Sort: Product (A-Z)', 'skyhs-hosting-solution' ); ?></option>
                                <option value="product_name_desc"><?php esc_html_e( 'Sort: Product (Z-A)', 'skyhs-hosting-solution' ); ?></option>
                                <option value="customer_name_asc"><?php esc_html_e( 'Sort: Customer Name (A-Z)', 'skyhs-hosting-solution' ); ?></option>
                                <option value="customer_name_desc"><?php esc_html_e( 'Sort: Customer Name (Z-A)', 'skyhs-hosting-solution' ); ?></option>
                                <option value="next_payment_asc"><?php esc_html_e( 'Sort: Next Payment (Soonest)', 'skyhs-hosting-solution' ); ?></option>
                                <option value="next_payment_desc"><?php esc_html_e( 'Sort: Next Payment (Latest)', 'skyhs-hosting-solution' ); ?></option>
                            </select>
                        </div>
                    </div>

                    <div id="skyhshoso-hm-container">
                    </div>

                    <div class="skyhshoso-hm-pagination" style="display:flex;justify-content:space-between;align-items:center;margin-top:15px;padding-top:15px;border-top:1px solid #eee;">
                        <button type="button" id="sa-prev-page" class="button" disabled>&laquo; <?php esc_html_e( 'Previous', 'skyhs-hosting-solution' ); ?></button>
                        <span id="sa-page-info"><?php esc_html_e( 'Page 1 of 1', 'skyhs-hosting-solution' ); ?></span>
                        <button type="button" id="sa-next-page" class="button" disabled><?php esc_html_e( 'Next', 'skyhs-hosting-solution' ); ?> &raquo;</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function format_subscription_data( $subscription ) {
        $user     = get_userdata( $subscription->get_customer_id() );
        $username = $user ? $user->display_name : '#' . $subscription->get_customer_id();
        $user_email = $user ? $user->user_email : '';
        $next     = $subscription->get_date( 'next_payment' );
        $order_id = $subscription->get_order_id();

        $invoices = array();
        $related_orders = $subscription->get_related_orders();
        if ( ! empty( $related_orders ) ) {
            krsort( $related_orders );
            foreach ( $related_orders as $o_id => $order ) {
                $invoices[] = array(
                    'id'               => $o_id,
                    'date'             => $order->get_date_created() ? date_i18n( get_option( 'date_format' ), $order->get_date_created()->getTimestamp() ) : '',
                    'date_raw'         => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
                    'amount'           => (float) $order->get_total(),
                    'amount_formatted' => wp_strip_all_tags( wc_price( $order->get_total() ) ),
                    'status'           => $order->get_status(),
                    'status_label'     => wc_get_order_status_name( $order->get_status() ),
                    'url'              => class_exists( 'SkyHSHOSO_Invoice' ) ? SkyHSHOSO_Invoice::get_invoice_url( $order ) : '',
                );
            }
        }

        $product_id = $subscription->get_product_id();
        $product_name = '';
        if ( $product_id ) {
            $product = wc_get_product( $product_id );
            $product_name = $product ? $product->get_name() : '';
        }

        return array(
            'id'                    => $subscription->get_id(),
            'customer_id'           => $subscription->get_customer_id(),
            'customer_name'         => $username,
            'customer_email'        => $user_email,
            'status'                => $subscription->get_status(),
            'status_label'          => skyhshoso_get_subscription_status_name( $subscription->get_status() ),
            'billing_period'        => $subscription->get_billing_period(),
            'billing_interval'      => $subscription->get_billing_interval(),
            'amount'                => (float) $subscription->get_total(),
            'amount_formatted'      => wp_strip_all_tags( wc_price( $subscription->get_total() ) ),
            'next_payment'          => $next ? date_i18n( get_option( 'date_format' ), strtotime( $next ) ) : '',
            'next_payment_raw'      => $next,
            'next_payment_ymd'      => $next ? gmdate( 'Y-m-d', strtotime( $next ) ) : '',
            'start_date'            => $subscription->get_date( 'start' ) ? date_i18n( get_option( 'date_format' ), strtotime( $subscription->get_date( 'start' ) ) ) : '',
            'start_date_raw'        => $subscription->get_date( 'start' ),
            'start_date_ymd'        => $subscription->get_date( 'start' ) ? gmdate( 'Y-m-d', strtotime( $subscription->get_date( 'start' ) ) ) : '',
            'end_date'              => $subscription->get_date( 'end' ) ? date_i18n( get_option( 'date_format' ), strtotime( $subscription->get_date( 'end' ) ) ) : '',
            'end_date_raw'          => $subscription->get_date( 'end' ),
            'end_date_ymd'          => $subscription->get_date( 'end' ) ? gmdate( 'Y-m-d', strtotime( $subscription->get_date( 'end' ) ) ) : '',
            'payment_method'        => $subscription->get_payment_method() ?: '',
            'order_id'              => $order_id,
            'has_parent_order'      => $order_id > 0,
            'order_edit_url'        => $order_id ? skyhshoso_get_edit_post_link( $order_id ) : '',
            'product_id'            => $product_id,
            'product_name'          => $product_name,
            'invoices'              => $invoices,
        );
    }

    public function ajax_get_subscriptions() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_get_subscriptions' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }

        global $wpdb;
        $table_subs  = $wpdb->prefix . 'skyhshoso_subscriptions';
        $table_users = $wpdb->users;

        $search          = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $status_filter   = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
        $next_payment_filter = isset( $_POST['next_payment'] ) ? sanitize_text_field( wp_unslash( $_POST['next_payment'] ) ) : '';
        $orderby         = isset( $_POST['orderby'] ) ? sanitize_text_field( wp_unslash( $_POST['orderby'] ) ) : 'created_at_desc';
        $product_filter  = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;

        $where = array( '1=1' );

        if ( ! empty( $status_filter ) ) {
            $where[] = $wpdb->prepare( 's.status = %s', $status_filter );
        }

        if ( $product_filter > 0 ) {
            $where[] = $wpdb->prepare( 's.product_id = %d', $product_filter );
        }

        if ( ! empty( $next_payment_filter ) ) {
            $now = current_time( 'mysql' );
            switch ( $next_payment_filter ) {
                case 'upcoming_7':
                    $where[] = $wpdb->prepare( 's.next_payment_date IS NOT NULL AND s.next_payment_date > %s AND s.next_payment_date <= %s', $now, date( 'Y-m-d H:i:s', strtotime( '+7 days' ) ) );
                    break;
                case 'upcoming_30':
                    $where[] = $wpdb->prepare( 's.next_payment_date IS NOT NULL AND s.next_payment_date > %s AND s.next_payment_date <= %s', $now, date( 'Y-m-d H:i:s', strtotime( '+30 days' ) ) );
                    break;
                case 'upcoming_90':
                    $where[] = $wpdb->prepare( 's.next_payment_date IS NOT NULL AND s.next_payment_date > %s AND s.next_payment_date <= %s', $now, date( 'Y-m-d H:i:s', strtotime( '+90 days' ) ) );
                    break;
                case 'overdue':
                    $where[] = $wpdb->prepare( 's.next_payment_date IS NOT NULL AND s.next_payment_date < %s', $now );
                    break;
                case 'no_date':
                    $where[] = 's.next_payment_date IS NULL';
                    break;
            }
        }

        if ( ! empty( $search ) ) {
            $search_like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = $wpdb->prepare(
                '(s.id LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s OR u.user_login LIKE %s)',
                $search_like, $search_like, $search_like, $search_like
            );
        }

        $where_sql = implode( ' AND ', $where );
        $join_sql  = " LEFT JOIN {$table_users} u ON s.user_id = u.ID ";
        $join_sql .= " LEFT JOIN {$wpdb->posts} p ON s.product_id = p.ID ";

        $order_clause = 's.created_at DESC';
        switch ( $orderby ) {
            case 'created_at_asc':
                $order_clause = 's.created_at ASC';
                break;
            case 'product_name_asc':
                $order_clause = 'p.post_title ASC';
                break;
            case 'product_name_desc':
                $order_clause = 'p.post_title DESC';
                break;
            case 'customer_name_asc':
                $order_clause = 'u.display_name ASC';
                break;
            case 'customer_name_desc':
                $order_clause = 'u.display_name DESC';
                break;
            case 'next_payment_asc':
                $order_clause = 's.next_payment_date ASC';
                break;
            case 'next_payment_desc':
                $order_clause = 's.next_payment_date DESC';
                break;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $total_records = $wpdb->get_var( "SELECT COUNT(DISTINCT s.id) FROM {$table_subs} s {$join_sql} WHERE {$where_sql}" );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        $limit = isset( $_POST['limit'] ) ? intval( $_POST['limit'] ) : 10;
        $page  = isset( $_POST['paged'] ) ? intval( $_POST['paged'] ) : 1;
        if ( $page < 1 ) {
            $page = 1;
        }
        $offset      = ( $page - 1 ) * $limit;
        $total_pages = max( 1, ceil( $total_records / $limit ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $query_sql = "SELECT s.* FROM {$table_subs} s {$join_sql} WHERE {$where_sql} ORDER BY {$order_clause} LIMIT %d OFFSET %d";
        $results   = $wpdb->get_results( $wpdb->prepare( $query_sql, $limit, $offset ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        $subscription_list = array();
        foreach ( $results as $row ) {
            $sub = new SkyHSHOSO_Subscription( $row );
            $subscription_list[] = $this->format_subscription_data( $sub );
        }

        wp_send_json_success( array(
            'subscriptions' => $subscription_list,
            'total_records' => intval( $total_records ),
            'total_pages'   => intval( $total_pages ),
            'current_page'  => intval( $page ),
        ) );
    }

    public function ajax_update_subscription() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_update_subscription_ajax' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
        }

        $sub_id     = isset( $_POST['subscription_id'] ) ? intval( $_POST['subscription_id'] ) : 0;
        $new_status = isset( $_POST['new_status'] ) ? sanitize_text_field( wp_unslash( $_POST['new_status'] ) ) : '';

        if ( ! $sub_id || ! $new_status ) {
            wp_send_json_error( array( 'message' => __( 'Invalid request.', 'skyhs-hosting-solution' ) ) );
        }

        $subscription = skyhshoso_get_subscription( $sub_id );
        if ( ! $subscription ) {
            wp_send_json_error( array( 'message' => __( 'Subscription not found.', 'skyhs-hosting-solution' ) ) );
        }

        $subscription->update_status( $new_status );

        wp_send_json_success( array(
            'message' => __( 'Subscription updated successfully.', 'skyhs-hosting-solution' ),
        ) );
    }

    public function ajax_delete_subscription() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_delete_subscription_ajax' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
        }

        $sub_id = isset( $_POST['subscription_id'] ) ? absint( wp_unslash( $_POST['subscription_id'] ) ) : 0;
        if ( ! $sub_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid subscription ID.', 'skyhs-hosting-solution' ) ) );
        }

        SkyHSHOSO_Subscription_DB::delete( $sub_id );

        wp_send_json_success( array(
            'message' => __( 'Subscription deleted permanently.', 'skyhs-hosting-solution' ),
        ) );
    }

    public function handle_update() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'skyhs-hosting-solution' ) );
        }

        $sub_id = isset( $_POST['subscription_id'] ) ? absint( wp_unslash( $_POST['subscription_id'] ) ) : 0;

        if ( ! isset( $_POST['_skyhshoso_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_skyhshoso_nonce'] ), 'skyhshoso_update_subscription_' . $sub_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'skyhs-hosting-solution' ) );
        }

        $new_status   = isset( $_POST['new_status'] ) ? sanitize_text_field( wp_unslash( $_POST['new_status'] ) ) : '';
        $redirect_url = isset( $_POST['_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['_redirect'] ) ) : admin_url( 'admin.php?page=skyhshoso-subscriptions&updated=1' );

        if ( $sub_id && $new_status ) {
            $subscription = skyhshoso_get_subscription( $sub_id );
            if ( $subscription ) {
                $subscription->update_status( $new_status );
            }
        }

        wp_safe_redirect( $redirect_url );
        exit;
    }

    public function ajax_edit_subscription() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_edit_subscription_ajax' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
        }

        $sub_id = isset( $_POST['subscription_id'] ) ? intval( $_POST['subscription_id'] ) : 0;
        if ( ! $sub_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid subscription ID.', 'skyhs-hosting-solution' ) ) );
        }

        $subscription = skyhshoso_get_subscription( $sub_id );
        if ( ! $subscription ) {
            wp_send_json_error( array( 'message' => __( 'Subscription not found.', 'skyhs-hosting-solution' ) ) );
        }

        $updates = array();

        if ( isset( $_POST['product_id'] ) ) {
            $product_id = intval( $_POST['product_id'] );
            if ( $product_id !== $subscription->get_product_id() ) {
                $product = wc_get_product( $product_id );
                if ( $product ) {
                    $updates['product_id'] = $product_id;
                    $updates['amount'] = (float) $product->get_price();
                }
            }
        }

        if ( isset( $_POST['amount'] ) ) {
            $amount = (float) $_POST['amount'];
            if ( $amount !== $subscription->get_total() ) {
                $updates['amount'] = $amount;
            }
        }

        if ( isset( $_POST['billing_period'] ) ) {
            $period = sanitize_text_field( wp_unslash( $_POST['billing_period'] ) );
            if ( $period !== $subscription->get_billing_period() ) {
                $updates['billing_period'] = $period;
            }
        }

        if ( isset( $_POST['billing_interval'] ) ) {
            $interval = max( 1, intval( $_POST['billing_interval'] ) );
            if ( $interval !== $subscription->get_billing_interval() ) {
                $updates['billing_interval'] = $interval;
            }
        }

        if ( isset( $_POST['status'] ) ) {
            $new_status = sanitize_text_field( wp_unslash( $_POST['status'] ) );
            if ( $new_status !== $subscription->get_status() ) {
                $subscription->update_status( $new_status );
            }
        }

        $next_payment = isset( $_POST['next_payment_date'] ) ? sanitize_text_field( wp_unslash( $_POST['next_payment_date'] ) ) : '';
        if ( '' !== $next_payment ) {
            $next_payment_dt = gmdate( 'Y-m-d H:i:s', strtotime( $next_payment ) );
            $current_next = $subscription->get_date( 'next_payment' );
            if ( $next_payment_dt !== $current_next ) {
                $updates['next_payment_date'] = $next_payment_dt;
            }
        } elseif ( isset( $_POST['next_payment_date'] ) && '' === $_POST['next_payment_date'] ) {
            $updates['next_payment_date'] = null;
        }

        $end_date = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
        if ( '' !== $end_date ) {
            $end_date_dt = gmdate( 'Y-m-d H:i:s', strtotime( $end_date ) );
            $current_end = $subscription->get_date( 'end' );
            if ( $end_date_dt !== $current_end ) {
                $updates['end_date'] = $end_date_dt;
            }
        } elseif ( isset( $_POST['end_date'] ) && '' === $_POST['end_date'] ) {
            $updates['end_date'] = null;
        }

        if ( ! empty( $updates ) ) {
            SkyHSHOSO_Subscription_DB::update( $sub_id, $updates );
        }

        do_action( 'skyhshoso_subscription_updated', $subscription );

        wp_send_json_success( array(
            'message' => __( 'Subscription updated successfully.', 'skyhs-hosting-solution' ),
        ) );
    }

    public function handle_delete() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'skyhs-hosting-solution' ) );
        }

        $sub_id = isset( $_POST['subscription_id'] ) ? absint( wp_unslash( $_POST['subscription_id'] ) ) : 0;

        if ( ! isset( $_POST['_skyhshoso_del_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_skyhshoso_del_nonce'] ), 'skyhshoso_delete_subscription_' . $sub_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'skyhs-hosting-solution' ) );
        }

        $redirect_url = isset( $_POST['_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['_redirect'] ) ) : admin_url( 'admin.php?page=skyhshoso-subscriptions&updated=1' );

        if ( $sub_id ) {
            SkyHSHOSO_Subscription_DB::delete( $sub_id );
        }

        wp_safe_redirect( $redirect_url );
        exit;
    }

    public function ajax_get_subscription_details() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'skyhshoso_get_subscription_details' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'skyhs-hosting-solution' ) ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
        }

        $sub_id = isset( $_POST['subscription_id'] ) ? intval( $_POST['subscription_id'] ) : 0;
        if ( ! $sub_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid subscription ID.', 'skyhs-hosting-solution' ) ) );
        }

        $subscription = skyhshoso_get_subscription( $sub_id );
        if ( ! $subscription ) {
            wp_send_json_error( array( 'message' => __( 'Subscription not found.', 'skyhs-hosting-solution' ) ) );
        }

        wp_send_json_success( $this->format_subscription_data( $subscription ) );
    }

    public function maybe_render_edit_modal() {
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
        $allowed_pages = array(
            'skyhshoso-subscriptions',
            'skyhshoso-hosting',
            'skyhshoso-wp-sites',
            'skyhshoso-domains'
        );
        if ( in_array( $page, $allowed_pages, true ) ) {
            $statuses = array(
                'active'         => __( 'Active', 'skyhs-hosting-solution' ),
                'pending-cancel' => __( 'Pending Cancel', 'skyhs-hosting-solution' ),
                'on-hold'        => __( 'On Hold', 'skyhs-hosting-solution' ),
                'cancelled'      => __( 'Cancelled', 'skyhs-hosting-solution' ),
                'expired'        => __( 'Expired', 'skyhs-hosting-solution' ),
                'pending'        => __( 'Pending', 'skyhs-hosting-solution' ),
            );
            ?>
            <div id="skyhshoso-edit-modal" class="skyhshoso-modal" style="display:none; z-index: 999999;">
                <div class="skyhshoso-modal-backdrop"></div>
                <div class="skyhshoso-modal-content">
                    <div class="skyhshoso-modal-header">
                        <h3><?php esc_html_e( 'Edit Subscription', 'skyhs-hosting-solution' ); ?> #<span id="sem-sub-id"></span></h3>
                        <button type="button" id="sem-close-btn" class="skyhshoso-modal-close">&times;</button>
                    </div>
                    <div class="skyhshoso-modal-body">
                        <form id="sem-edit-form">
                            <input type="hidden" name="subscription_id" id="sem-sub-id-input" value="" />

                            <div class="skyhshoso-hm-section">
                                <h3><?php esc_html_e( 'Product & Pricing', 'skyhs-hosting-solution' ); ?></h3>
                                <div class="skyhshoso-hm-row">
                                    <div class="skyhshoso-hm-field">
                                        <label for="sem-product-search"><?php esc_html_e( 'Product', 'skyhs-hosting-solution' ); ?></label>
                                        <select name="product_id" id="sem-product-search" class="hm-input wc-product-search" style="width:100%;" data-placeholder="<?php esc_attr_e( 'Search for a product...', 'skyhs-hosting-solution' ); ?>"></select>
                                        <p class="hm-field-desc"><?php esc_html_e( 'Search and select the associated WooCommerce product.', 'skyhs-hosting-solution' ); ?></p>
                                    </div>
                                </div>
                                <div class="skyhshoso-hm-row skyhshoso-hm-row-cols-3">
                                    <div class="skyhshoso-hm-field">
                                        <label for="sem-amount"><?php esc_html_e( 'Amount', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                        <input type="number" name="amount" id="sem-amount" class="hm-input" step="0.01" min="0" required />
                                    </div>
                                    <div class="skyhshoso-hm-field">
                                        <label for="sem-billing-period"><?php esc_html_e( 'Billing Period', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                        <select name="billing_period" id="sem-billing-period" class="hm-input" required>
                                            <option value="day"><?php esc_html_e( 'Day', 'skyhs-hosting-solution' ); ?></option>
                                            <option value="week"><?php esc_html_e( 'Week', 'skyhs-hosting-solution' ); ?></option>
                                            <option value="month"><?php esc_html_e( 'Month', 'skyhs-hosting-solution' ); ?></option>
                                            <option value="year"><?php esc_html_e( 'Year', 'skyhs-hosting-solution' ); ?></option>
                                        </select>
                                    </div>
                                    <div class="skyhshoso-hm-field">
                                        <label for="sem-billing-interval"><?php esc_html_e( 'Billing Interval', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
                                        <input type="number" name="billing_interval" id="sem-billing-interval" class="hm-input" min="1" step="1" required />
                                    </div>
                                </div>
                            </div>

                            <div class="skyhshoso-hm-section">
                                <h3><?php esc_html_e( 'Dates & Status', 'skyhs-hosting-solution' ); ?></h3>
                                <div class="skyhshoso-hm-row skyhshoso-hm-row-cols-3">
                                    <div class="skyhshoso-hm-field">
                                        <label for="sem-status"><?php esc_html_e( 'Status', 'skyhs-hosting-solution' ); ?></label>
                                        <select name="status" id="sem-status" class="hm-input">
                                            <?php foreach ( $statuses as $val => $label ) : ?>
                                                <option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="skyhshoso-hm-field">
                                        <label for="sem-next-payment"><?php esc_html_e( 'Next Payment Date', 'skyhs-hosting-solution' ); ?></label>
                                        <input type="date" name="next_payment_date" id="sem-next-payment" class="hm-input" />
                                        <p class="hm-field-desc"><?php esc_html_e( 'Leave empty to clear.', 'skyhs-hosting-solution' ); ?></p>
                                    </div>
                                    <div class="skyhshoso-hm-field">
                                        <label for="sem-end-date"><?php esc_html_e( 'End Date', 'skyhs-hosting-solution' ); ?></label>
                                        <input type="date" name="end_date" id="sem-end-date" class="hm-input" />
                                        <p class="hm-field-desc"><?php esc_html_e( 'Leave empty for no end date.', 'skyhs-hosting-solution' ); ?></p>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="skyhshoso-modal-footer">
                        <button type="button" id="sem-cancel-btn" class="button"><?php esc_html_e( 'Cancel', 'skyhs-hosting-solution' ); ?></button>
                        <button type="button" id="sem-save-btn" class="button button-primary"><?php esc_html_e( 'Update', 'skyhs-hosting-solution' ); ?></button>
                    </div>
                </div>
            </div>
            <?php
        }
    }
}

SkyHSHOSO_Subscription_Admin::instance();
