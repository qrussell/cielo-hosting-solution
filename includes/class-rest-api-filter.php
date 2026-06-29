<?php
/**
 * REST API Filter Class
 * 
 * This class handles filtering post types from the WordPress REST API
 * 
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SkyHSHOSO_REST_API_Filter
 */
class SkyHSHOSO_REST_API_Filter {
    
    /**
     * Instance of this class.
     *
     * @var object
     */
    private static $instance = null;

    /**
     * Return an instance of this class.
     *
     * @return object A single instance of this class.
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    public function __construct() {
        // Add filters to modify post type registration
        add_filter( 'register_post_type_args', array( $this, 'remove_post_type_from_rest_api' ), 10, 2 );
        add_filter( 'register_taxonomy_args', array( $this, 'remove_taxonomy_from_rest_api' ), 10, 2 );
    }

    /**
     * Remove specified post types from REST API
     *
     * @param array  $args      Post type registration arguments.
     * @param string $post_type Post type name.
     * @return array Modified arguments
     */
    public function remove_post_type_from_rest_api( $args, $post_type ) {
        // List of post types to remove from REST API
        $post_types_to_remove = array( 'skyhshoso_server', 'skyhshoso_hosting', 'skyhshoso_domain' );
        
        if ( in_array( $post_type, $post_types_to_remove, true ) ) {
            // Disable REST API for this post type
            $args['show_in_rest'] = false;
        }
        
        return $args;
    }

    /**
     * Remove specified taxonomies from REST API
     *
     * @param array  $args     Taxonomy registration arguments.
     * @param string $taxonomy Taxonomy name.
     * @return array Modified arguments
     */
    public function remove_taxonomy_from_rest_api( $args, $taxonomy ) {
        // List of taxonomies to remove from REST API
        $taxonomies_to_remove = array();
        
        if ( in_array( $taxonomy, $taxonomies_to_remove, true ) ) {
            // Disable REST API for this taxonomy
            $args['show_in_rest'] = false;
        }
        
        return $args;
    }
}

/**
 * Returns the main instance of SkyHSHOSO_REST_API_Filter.
 *
 * @return SkyHSHOSO_REST_API_Filter
 */
function SkyHSHOSO_REST_API_Filter() {
    return SkyHSHOSO_REST_API_Filter::instance();
}

// Initialize the class
SkyHSHOSO_REST_API_Filter();
