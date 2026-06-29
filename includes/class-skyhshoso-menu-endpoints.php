<?php
/**
 * SkyHS Custom Menu Endpoints
 *
 * @package Hosting_Solution
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Class SkyHSHOSO_Menu_Endpoints
 * Registers a custom meta box to add Hosting, Domain, and Dashboard links to WordPress navigation menus.
 */
class SkyHSHOSO_Menu_Endpoints {

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'admin_head-nav-menus.php', array( $this, 'register_menu_metabox' ) );
        add_filter( 'wp_setup_nav_menu_item', array( $this, 'setup_custom_menu_item' ) );
        add_filter( 'wp_nav_menu_objects', array( $this, 'filter_menu_items' ), 10, 2 );
    }

    /**
     * Registers the custom menu metabox.
     */
    public function register_menu_metabox() {
        add_meta_box(
            'skyhshoso-custom-menu-endpoints',
            __( 'SkyHS Endpoints', 'skyhs-hosting-solution' ),
            array( $this, 'render_menu_metabox' ),
            'nav-menus',
            'side',
            'default'
        );
    }

    /**
     * Renders the custom menu metabox content.
     * Mimics the structure of default WordPress menu item metaboxes (e.g., Pages, Posts).
     */
    public function render_menu_metabox() {
        global $nav_menu_selected_id;

        $dashboard_url = SkyHSHOSO_Settings::get_dashboard_url();

        $menu_items_to_display = array();
        $item_counter = -1; // Use negative IDs for new, unsaved menu items

        if ( $dashboard_url ) {
            $menu_items_to_display[] = array(
                'title'  => __( 'Dashboard', 'skyhs-hosting-solution' ),
                'url'    => esc_url( $dashboard_url ),
                'object' => 'skyhshoso_dashboard',
                'type'   => 'custom',
            );

            $menu_items_to_display[] = array(
                'title'  => __( 'New Hosting', 'skyhs-hosting-solution' ),
                'url'    => esc_url( add_query_arg( array( 'tab' => 'skyhshoso_hosting', 'new_hosting' => '1' ), $dashboard_url ) ),
                'object' => 'skyhshoso_hosting',
                'type'   => 'custom',
            );

            $menu_items_to_display[] = array(
                'title'  => __( 'New Domain', 'skyhs-hosting-solution' ),
                'url'    => esc_url( add_query_arg( array( 'tab' => 'domains', 'new_domain' => '1' ), $dashboard_url ) ),
                'object' => 'skyhshoso_domain',
                'type'   => 'custom',
            );

            // Add new "Hosting" endpoint
            $menu_items_to_display[] = array(
                'title'  => __( 'Hosting', 'skyhs-hosting-solution' ),
                'url'    => esc_url( add_query_arg( array( 'tab' => 'skyhshoso_hosting' ), $dashboard_url ) ),
                'object' => 'skyhshoso_hosting_tab',
                'type'   => 'custom',
                'classes' => 'skyhshoso-hide-logged-out',
            );

            // Add new "Domain" endpoint
            $menu_items_to_display[] = array(
                'title'  => __( 'Domain', 'skyhs-hosting-solution' ),
                'url'    => esc_url( add_query_arg( array( 'tab' => 'domains' ), $dashboard_url ) ),
                'object' => 'skyhshoso_domain_tab',
                'type'   => 'custom',
                'classes' => 'skyhshoso-hide-logged-out',
            );
        }

        ?>
        <div id="skyhshoso-endpoints-div" class="postbox">
            <div class="inside">
                <div id="posttype-skyhshoso-endpoints" class="posttypediv">
                    <div id="tabs-panel-skyhshoso-endpoints-all" class="tabs-panel tabs-panel-active">
                        <ul id="skyhshoso-endpoints-checklist" class="categorychecklist form-no-clear">
                            <?php foreach ( $menu_items_to_display as $item ) : ?>
                                <li>
                                    <label class="menu-item-title">
                                        <input type="checkbox" class="menu-item-checkbox" name="menu-item[<?php echo esc_attr( $item_counter ); ?>][menu-item-object-id]" value="<?php echo esc_attr( $item_counter ); ?>" /> <?php echo esc_html( $item['title'] ); ?>
                                    </label>
                                    <input type="hidden" class="menu-item-db-id" name="menu-item[<?php echo esc_attr( $item_counter ); ?>][menu-item-db-id]" value="0" />
                                    <input type="hidden" class="menu-item-object" name="menu-item[<?php echo esc_attr( $item_counter ); ?>][menu-item-object]" value="<?php echo esc_attr( $item['object'] ); ?>" />
                                    <input type="hidden" class="menu-item-parent-id" name="menu-item[<?php echo esc_attr( $item_counter ); ?>][menu-item-parent-id]" value="0" />
                                    <input type="hidden" class="menu-item-type" name="menu-item[<?php echo esc_attr( $item_counter ); ?>][menu-item-type]" value="<?php echo esc_attr( $item['type'] ); ?>" />
                                    <input type="hidden" class="menu-item-title" name="menu-item[<?php echo esc_attr( $item_counter ); ?>][menu-item-title]" value="<?php echo esc_attr( $item['title'] ); ?>" />
                                    <input type="hidden" class="menu-item-url" name="menu-item[<?php echo esc_attr( $item_counter ); ?>][menu-item-url]" value="<?php echo esc_attr( $item['url'] ); ?>" />
                                    <input type="hidden" class="menu-item-target" name="menu-item[<?php echo esc_attr( $item_counter ); ?>][menu-item-target]" value="" />
                                    <input type="hidden" class="menu-item-attr-title" name="menu-item[<?php echo esc_attr( $item_counter ); ?>][menu-item-attr-title]" value="" />
                                    <input type="hidden" class="menu-item-classes" name="menu-item[<?php echo esc_attr( $item_counter ); ?>][menu-item-classes]" value="<?php echo isset( $item['classes'] ) ? esc_attr( $item['classes'] ) : ''; ?>" />
                                    <input type="hidden" class="menu-item-xfn" name="menu-item[<?php echo esc_attr( $item_counter ); ?>][menu-item-xfn]" value="" />
                                </li>
                                <?php $item_counter--; ?>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <p class="button-controls">
                        <span class="list-controls">
                            <a href="<?php echo esc_url( add_query_arg( array( 'skyhshoso-endpoints-div' => 'all', 'selectall' => 1 ), remove_query_arg( array( 'action', 'customlink-tab', 'edit-menu-item', 'menu-item', 'page-tab', '_wpnonce' ) ) ) ); ?>#skyhshoso-endpoints-div" class="select-all"><?php esc_html_e( 'Select All', 'skyhs-hosting-solution' ); ?></a>
                        </span>
                        <span class="add-to-menu">
                            <input type="submit"<?php wp_nav_menu_disabled_check( $nav_menu_selected_id ); ?> class="button-secondary submit-add-to-menu right" value="<?php esc_attr_e( 'Add to Menu', 'skyhs-hosting-solution' ); ?>" name="add-menu-item" id="submit-skyhshoso-endpoints-div" />
                            <span class="spinner"></span>
                        </span>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Set up custom menu item properties for display in the menu editor.
     *
     * @param object $menu_item The menu item object.
     * @return object The modified menu item object.
     */
    public function setup_custom_menu_item( object $menu_item ): object {
        if ( 'skyhshoso_dashboard' === $menu_item->object || 'skyhshoso_hosting' === $menu_item->object || 'skyhshoso_domain' === $menu_item->object || 'skyhshoso_hosting_tab' === $menu_item->object || 'skyhshoso_domain_tab' === $menu_item->object ) {
            // For custom items, ensure type is 'custom' and type_label is set correctly.
            // WordPress will handle the URL, title etc. as they are passed via the form.
            $menu_item->type       = 'custom';
            $menu_item->type_label = __( 'SkyHS Endpoint', 'skyhs-hosting-solution' );
        }
        return $menu_item;
    }

    /**
     * Filters the nav menu items to selectively display items based on user login status.
     *
     * @param array    $sorted_menu_items The menu items, sorted by each menu item's menu order.
     * @param stdClass $args              An object containing wp_nav_menu() arguments.
     * @return array The filtered list of menu items.
     */
    public function filter_menu_items( $sorted_menu_items, $args ) {
        // If the user is not logged in, remove the 'Hosting' and 'Domain' tab links.
        if ( ! is_user_logged_in() ) {
            foreach ( $sorted_menu_items as $key => $menu_item ) {
                if ( isset( $menu_item->classes ) && in_array( 'skyhshoso-hide-logged-out', $menu_item->classes, true ) ) {
                    unset( $sorted_menu_items[ $key ] );
                }
            }
        }
        return $sorted_menu_items;
    }
}
