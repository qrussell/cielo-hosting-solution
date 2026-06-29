<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class SkyHSHOSO_Account_Collaborator {
    public function __construct() {
        // Remove from WooCommerce account menu but keep the endpoint for AJAX operations
        // add_filter('woocommerce_account_menu_items', array($this, 'add_collaborator_tab'), 30);
        // add_action('woocommerce_account_collaborator_endpoint', array($this, 'collaborator_content'));
        add_action('wp_ajax_skyhshoso_invite_user', array($this, 'ajax_invite_user'));
        add_action('wp_ajax_skyhshoso_remove_invite', array($this, 'ajax_remove_invite'));
        add_action('wp_ajax_skyhshoso_get_collaborator_data', array($this, 'ajax_get_collaborator_data'));
    }



    public function ajax_invite_user() {
        // Verify nonce for security
        check_ajax_referer( 'skyhshoso-collaborator-nonce', 'nonce' );
        
        // Check if user is logged in
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in to perform this action.', 'skyhs-hosting-solution' ) ) );
            wp_die();
        }

        $inviter_id = get_current_user_id();
        $invitee_email = isset( $_POST['invitee_email'] ) ? sanitize_email( wp_unslash( $_POST['invitee_email'] ) ) : '';

        if (!is_email($invitee_email)) {
            wp_send_json_error(array('message' => __('Invalid email address.', 'skyhs-hosting-solution')));
            return;
        }

        $invitee_id = email_exists($invitee_email);

        $inviter_meta = get_user_meta($inviter_id, 'skyhshoso_invited_users', true);
        $inviter_meta = is_array($inviter_meta) ? $inviter_meta : array();

        $invitee_meta = $invitee_id ? get_user_meta($invitee_id, 'skyhshoso_invited_by', true) : array();
        $invitee_meta = is_array($invitee_meta) ? $invitee_meta : array();

        if ($invitee_id && (in_array($invitee_id, $inviter_meta) || in_array($inviter_id, $invitee_meta))) {
            wp_send_json_error(array('message' => __('User is already invited or has invited you.', 'skyhs-hosting-solution')));
            return;
        }

        if (!$invitee_id) {
            $user_id = wc_create_new_customer($invitee_email);

            if (is_wp_error($user_id)) {
                wp_send_json_error(array('message' => $user_id->get_error_message()));
                return;
            }

            $invitee_id = $user_id;
        }

        $inviter_meta[] = $invitee_id;
        update_user_meta($inviter_id, 'skyhshoso_invited_users', $inviter_meta);

        $invitee_meta[] = $inviter_id;
        update_user_meta($invitee_id, 'skyhshoso_invited_by', $invitee_meta);

        wp_send_json_success(array(
            'message' => __('User invited successfully!', 'skyhs-hosting-solution')
        ));
    }

    public function ajax_remove_invite() {
        // Verify nonce for security
        check_ajax_referer( 'skyhshoso-collaborator-nonce', 'nonce' );
        
        // Check if user is logged in
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in to perform this action.', 'skyhs-hosting-solution' ) ) );
            wp_die();
        }

        $current_user_id = get_current_user_id();
        $remove_user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

        $invited_users = get_user_meta($current_user_id, 'skyhshoso_invited_users', true);
        $invited_users = is_array($invited_users) ? $invited_users : array();

        $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true);
        $invited_by = is_array($invited_by) ? $invited_by : array();

        if (in_array($remove_user_id, $invited_users)) {
            $invited_users = array_diff($invited_users, array($remove_user_id));
            update_user_meta($current_user_id, 'skyhshoso_invited_users', $invited_users);
        }

        if (in_array($remove_user_id, $invited_by)) {
            $invited_by = array_diff($invited_by, array($remove_user_id));
            update_user_meta($current_user_id, 'skyhshoso_invited_by', $invited_by);
        }

        $removed_user_invited_by = get_user_meta($remove_user_id, 'skyhshoso_invited_by', true);
        $removed_user_invited_by = is_array($removed_user_invited_by) ? $removed_user_invited_by : array();
        $removed_user_invited_by = array_diff($removed_user_invited_by, array($current_user_id));
        update_user_meta($remove_user_id, 'skyhshoso_invited_by', $removed_user_invited_by);

        $removed_user_invited_users = get_user_meta($remove_user_id, 'skyhshoso_invited_users', true);
        $removed_user_invited_users = is_array($removed_user_invited_users) ? $removed_user_invited_users : array();
        $removed_user_invited_users = array_diff($removed_user_invited_users, array($current_user_id));
        update_user_meta($remove_user_id, 'skyhshoso_invited_users', $removed_user_invited_users);

        wp_send_json_success(array(
            'message' => __('Invite removed successfully.', 'skyhs-hosting-solution')
        ));
    }

    public function ajax_get_collaborator_data() {
        // Verify nonce for security
        check_ajax_referer( 'skyhshoso-collaborator-nonce', 'nonce' );
        
        // Check if user is logged in
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => esc_html__( 'You must be logged in to perform this action.', 'skyhs-hosting-solution' ) ) );
            wp_die();
        }

        $current_user_id = get_current_user_id();
        
        // Get users invited by current user
        $invited_users = get_user_meta($current_user_id, 'skyhshoso_invited_users', true);
        $invited_users = is_array($invited_users) ? $invited_users : array();
        
        $invited_users_data = array();
        foreach ($invited_users as $user_id) {
            $user_info = get_userdata($user_id);
            if ($user_info) {
                $invited_users_data[] = array(
                    'id' => $user_id,
                    'email' => $user_info->user_email
                );
            }
        }
        
        // Get users who invited the current user
        $invited_by = get_user_meta($current_user_id, 'skyhshoso_invited_by', true);
        $invited_by = is_array($invited_by) ? $invited_by : array();
        
        $invited_by_data = array();
        foreach ($invited_by as $user_id) {
            $user_info = get_userdata($user_id);
            if ($user_info) {
                $invited_by_data[] = array(
                    'id' => $user_id,
                    'email' => $user_info->user_email
                );
            }
        }
        
        wp_send_json_success(array(
            'skyhshoso_invited_users' => $invited_users_data,
            'skyhshoso_invited_by' => $invited_by_data
        ));
    }


}

new SkyHSHOSO_Account_Collaborator();
