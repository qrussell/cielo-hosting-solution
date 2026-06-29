<?php
/**
 * Custom Post Types for Hosting Solution
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SkyHSHOSO_Post_Types
 */
class SkyHSHOSO_Post_Types {

    /**
     * Init hooks.
     */
    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_post_types' ), 5 );
    }

    /**
     * Register custom post types.
     */
    public static function register_post_types() {
        if ( ! is_blog_installed() ) {
            return;
        }

        self::register_hosting_post_type();
        self::register_server_post_type();
        self::register_domain_post_type();
        self::register_wp_site_post_type();
    }


    /**
     * Register hosting post type.
     */
    private static function register_hosting_post_type() {
        register_post_type( 'skyhshoso_hosting',
            array(
                'labels'              => array(
                    'name'               => __( 'Hostings', 'skyhs-hosting-solution' ),
                    'singular_name'      => __( 'Hosting', 'skyhs-hosting-solution' ),
                ),
                'public'              => false,
                'show_ui'             => false,
                'show_in_menu'        => false,
                'show_in_rest'        => false,
                'query_var'           => false,
                'rewrite'             => false,
                'capability_type'     => 'post',
                'hierarchical'        => false,
                'supports'            => array( 'title' ),
            )
        );
    }

    /**
     * Register server post type.
     */
    private static function register_server_post_type() {
        register_post_type( 'skyhshoso_server',
            array(
                'labels'              => array(
                    'name'               => __( 'Servers', 'skyhs-hosting-solution' ),
                    'singular_name'      => __( 'Server', 'skyhs-hosting-solution' ),
                ),
                'public'              => false,
                'show_ui'             => false,
                'show_in_menu'        => false,
                'show_in_rest'        => false,
                'query_var'           => false,
                'rewrite'             => false,
                'capability_type'     => 'post',
                'hierarchical'        => false,
                'supports'            => array( 'title' ),
            )
        );
    }

    /**
     * Register domain post type.
     */
private static function register_domain_post_type() {
        register_post_type( 'skyhshoso_domain',
            array(
                'labels'              => array(
                    'name'               => __( 'Domains', 'skyhs-hosting-solution' ),
                    'singular_name'      => __( 'Domain', 'skyhs-hosting-solution' ),
                    'add_new'            => __( 'Add New', 'skyhs-hosting-solution' ),
                    'add_new_item'       => __( 'Add New Domain', 'skyhs-hosting-solution' ),
                    'edit_item'          => __( 'Edit Domain', 'skyhs-hosting-solution' ),
                    'new_item'           => __( 'New Domain', 'skyhs-hosting-solution' ),
                    'view_item'          => __( 'View Domain', 'skyhs-hosting-solution' ),
                    'search_items'       => __( 'Search Domains', 'skyhs-hosting-solution' ),
                    'not_found'          => __( 'No domains found', 'skyhs-hosting-solution' ),
                    'not_found_in_trash' => __( 'No domains found in trash', 'skyhs-hosting-solution' ),
                ),
                'public'              => false,
                'show_ui'             => false,
                'show_in_menu'        => false,
                'show_in_rest'        => false,
                'query_var'           => false,
                'rewrite'             => false,
                'capability_type'     => 'post',
                'hierarchical'        => false,
                'menu_position'       => null,
                'supports'            => array( 'title' ),
            )
        );
    }

    /**
     * Register WordPress site post type.
     */
    private static function register_wp_site_post_type() {
        register_post_type( 'skyhshoso_wp_site',
            array(
                'labels'              => array(
                    'name'               => __( 'WP Sites', 'skyhs-hosting-solution' ),
                    'singular_name'      => __( 'WP Site', 'skyhs-hosting-solution' ),
                ),
                'public'              => false,
                'show_ui'             => false,
                'show_in_menu'        => false,
                'show_in_rest'        => false,
                'query_var'           => false,
                'rewrite'             => false,
                'capability_type'     => 'post',
                'hierarchical'        => false,
                'supports'            => array( 'title' ),
            )
        );
    }

}
