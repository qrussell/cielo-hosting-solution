<?php
/**
 * SkyHS WP Site Manager
 *
 * Admin management page for WordPress site installations.
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_WP_Site_Manager {

    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    public function enqueue_scripts( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
        if ( false === strpos( $hook, 'skyhshoso-wp-sites' ) && 'skyhshoso-wp-sites' !== $page ) {
            return;
        }

        wp_enqueue_style(
            'skyhshoso-hosting-manager',
            SKYHSHOSO_PLUGIN_URL . 'assets/css/hosting-manager.css',
            array(),
            SKYHSHOSO_VERSION
        );
    }

    public function render_page() {
        // CHANGED: Default tab is now 'database' instead of 'fleet'
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'database';
        ?>
        <div class="wrap skyhshoso-hm-wrap">
            <h1><?php esc_html_e( 'WordPress Sites', 'skyhs-hosting-solution' ); ?></h1>
            <p><?php esc_html_e( 'Manage WordPress site installations across your servers.', 'skyhs-hosting-solution' ); ?></p>
            
            <h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
                <a href="?page=skyhshoso-wp-sites&tab=database" class="nav-tab <?php echo $active_tab === 'database' ? 'nav-tab-active' : ''; ?>">Database & Billing Records</a>
                <a href="?page=skyhshoso-wp-sites&tab=fleet" class="nav-tab <?php echo $active_tab === 'fleet' ? 'nav-tab-active' : ''; ?>">Live Fleet Dashboard</a>
            </h2>

            <div id="skyhshoso-wpm-notice" class="notice" style="display:none;"></div>

            <?php if ( $active_tab === 'fleet' ) : ?>
                <?php $this->render_fleet_dashboard(); ?>
            <?php else : ?>
                <?php $this->render_database_dashboard(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renders the Cross-Referenced Database & Billing Table
     */
    private function render_database_dashboard() {
        // 1. Fetch all managed hosting accounts to act as our "Billing Map"
        $hosting_posts = get_posts(array(
            'post_type'      => 'skyhshoso_hosting',
            'posts_per_page' => -1,
            'post_status'    => 'any'
        ));

        $managed_accounts = array();
        foreach ($hosting_posts as $hp) {
            $cpanel_user = get_post_meta($hp->ID, 'skyhshoso_hosting_username', true);
            if (empty($cpanel_user)) continue;

            $sub_id = get_post_meta($hp->ID, 'skyhshoso_subscription_id', true);
            $sub_status_label = '<span style="color:#d63638;">No Subscription Linked</span>';
            
            if ($sub_id && function_exists('skyhshoso_get_subscription')) {
                $sub = skyhshoso_get_subscription($sub_id);
                if ($sub) {
                    $status_color = ($sub->get_status() === 'active') ? 'green' : '#d63638';
                    $sub_status_label = '<strong>#'.$sub_id.'</strong> - <span style="color:'.$status_color.'; font-weight:600;">' . ucfirst($sub->get_status()) . '</span>';
                    
                    $prod_id = $sub->get_product_id();
                    if ($prod_id) {
                        $sub_status_label .= '<br><small style="color:#646970;">' . get_the_title($prod_id) . '</small>';
                    }
                }
            }

            // Map the cPanel username to its Subscription Data
            $managed_accounts[$cpanel_user] = array(
                'sub_label' => $sub_status_label,
                'edit_link' => admin_url('admin.php?page=skyhshoso-hosting&search_host=' . urlencode($cpanel_user))
            );
        }

        // 2. Fetch Servers for JS to query
        $servers = get_posts(['post_type' => 'skyhshoso_server', 'posts_per_page' => -1, 'order' => 'ASC', 'orderby' => 'title']);
        $server_data = [];
        foreach ($servers as $s) {
            $server_data[] = [
                'id' => $s->ID, 
                'name' => $s->post_title, 
                'type' => get_post_meta($s->ID, '_skyhshoso_server_type', true) ?: 'whm'
            ];
        }
        ?>
        <div id="skyhshoso-wpm-app">
            <div class="skyhshoso-hm-list-panel" style="background:#fff; border:1px solid #ccd0d4; padding:20px;">
                <div class="skyhshoso-hm-list-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h2 style="margin:0;">Database & Billing Records (Managed Sites Only)</h2>
                    <button type="button" id="refresh-db-table-btn" class="button button-primary">
                        <span class="dashicons dashicons-update-alt" style="line-height: 1.3; margin-top:3px;"></span> Refresh Table
                    </button>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Domain / Site URL</th>
                            <th>Account Name</th>
                            <th>Server</th>
                            <th>Subscription Type / Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="db-billing-tbody">
                        <tr><td colspan="5"><span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span> Cross-referencing local database with live servers...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var managedAccounts = <?php echo wp_json_encode($managed_accounts); ?>;
            var servers = <?php echo wp_json_encode($server_data); ?>;
            
            function loadDatabaseTable() {
                var tbody = $('#db-billing-tbody');
                tbody.html('<tr><td colspan="5"><span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span> Cross-referencing local database with live servers...</td></tr>');

                if (servers.length === 0) {
                    tbody.html('<tr><td colspan="5">No servers configured.</td></tr>');
                    return;
                }

                var allHtml = '';
                var pendingRequests = servers.length;

                servers.forEach(function(server) {
                    $.post(ajaxurl, { action: 'skyhshoso_scan_server_sites', server_id: server.id }, function(res) {
                        if (res.success && res.data.sites) {
                            res.data.sites.forEach(function(site) {
                                
                                // CRITICAL FILTER: Only show this site if its cPanel account is managed by our Billing system
                                if (managedAccounts[site.account]) {
                                    var accData = managedAccounts[site.account];
                                    var sType = server.type === 'hestiacp' ? 'HestiaCP' : 'cPanel';
                                    
                                    allHtml += '<tr>';
                                    allHtml += '<td><strong><a href="'+site.url+'" target="_blank" style="text-decoration:none;">'+site.domain+' <span class="dashicons dashicons-external" style="font-size:12px; line-height:1.5;"></span></a></strong></td>';
                                    allHtml += '<td><code>'+site.account+'</code><br><small style="color:#646970; text-transform:uppercase; font-size:10px;">'+sType+'</small></td>';
                                    allHtml += '<td>'+server.name+'</td>';
                                    allHtml += '<td>'+accData.sub_label+'</td>';
                                    allHtml += '<td><a href="'+accData.edit_link+'" class="button button-small">Manage Hosting Record</a></td>';
                                    allHtml += '</tr>';
                                }
                            });
                        }
                    }).always(function() {
                        pendingRequests--;
                        // When all servers have responded, print the final table
                        if (pendingRequests === 0) {
                            if (allHtml === '') {
                                tbody.html('<tr><td colspan="5">No managed WordPress sites found. (The plugin is tracking billing records, but no active WP sites exist on those specific cPanel accounts).</td></tr>');
                            } else {
                                tbody.html(allHtml);
                            }
                        }
                    });
                });
            }

            // Load on tab click
            loadDatabaseTable();

            // Refresh Button
            $('#refresh-db-table-btn').on('click', function() {
                loadDatabaseTable();
            });
        });
        </script>
        <?php
    }

    /**
     * Renders the Live Fleet Dashboard Tab
     */
    private function render_fleet_dashboard() {
        $servers = get_posts(['post_type' => 'skyhshoso_server', 'posts_per_page' => -1, 'order' => 'ASC', 'orderby' => 'title']);
        $server_data = [];
        foreach ($servers as $s) {
            $server_data[] = [
                'id' => $s->ID, 
                'name' => $s->post_title, 
                'type' => get_post_meta($s->ID, '_skyhshoso_server_type', true) ?: 'whm'
            ];
        }
        ?>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
            <p style="margin:0; font-size:14px; color:#50575e;">Live view of all WordPress installations across your server fleet, grouped by account.</p>
            <button type="button" id="skyhs-refresh-fleet" class="button button-primary">
                <span class="dashicons dashicons-update-alt" style="line-height: 1.3; margin-top:3px;"></span> Refresh Servers
            </button>
        </div>

        <div id="skyhs-fleet-dashboard"></div>

        <script>
        jQuery(document).ready(function($) {
            var servers = <?php echo json_encode($server_data); ?>;
            var container = $('#skyhs-fleet-dashboard');
            
            function loadFleet() {
                container.empty();
                if (servers.length === 0) {
                    container.html('<div class="notice notice-warning inline"><p>No servers configured. Add a server in the Server Manager first.</p></div>');
                    return;
                }
                
                servers.forEach(function(server) {
                    var sType = server.type === 'hestiacp' ? 'HestiaCP' : 'cPanel';
                    var html = '<div class="skyhs-fleet-server" id="fleet-server-'+server.id+'" style="border: 1px solid #ccd0d4; background: #fff; margin-bottom: 20px; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">';
                    html += '<div class="skyhs-fleet-server-header" style="background: #f6f7f7; padding: 15px 20px; border-bottom: 1px solid #ccd0d4; display: flex; justify-content: space-between; align-items: center;">';
                    html += '<h2 style="margin: 0; font-size: 16px; color: #1d2327;">' + server.name + '</h2>';
                    html += '<span style="font-size:11px; background:#e2e8f0; padding:3px 8px; border-radius:4px; font-weight:600; text-transform:uppercase;">' + sType + '</span>';
                    html += '</div>';
                    html += '<div class="skyhs-fleet-server-body" style="padding: 10px 20px 20px 20px;"><span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span> Scanning live server...</div>';
                    html += '</div>';
                    container.append(html);

                    $.post(ajaxurl, { action: 'skyhshoso_scan_server_sites', server_id: server.id }, function(res) {
                        var bodyDiv = $('#fleet-server-'+server.id+' .skyhs-fleet-server-body');
                        
                        if (!res.success) {
                            bodyDiv.html('<p style="color:#d63638;"><strong>Connection Error:</strong> ' + res.data.message + '</p>');
                            return;
                        }

                        if (res.data.sites.length === 0) {
                            bodyDiv.html('<p style="color:#646970;">No WordPress sites found on this server.</p>');
                            return;
                        }

                        var accounts = {};
                        res.data.sites.forEach(function(site) {
                            if (!accounts[site.account]) accounts[site.account] = { type: site.type, sites: [] };
                            accounts[site.account].sites.push(site);
                        });

                        var renderHtml = '';
                        for (var acc in accounts) {
                            renderHtml += '<div style="margin-top: 15px; border: 1px solid #e2e4e7; border-left: 4px solid #2271b1; border-radius: 3px; background: #fafafa;">';
                            renderHtml += '<div style="padding: 10px 15px; border-bottom: 1px solid #e2e4e7; background: #fff; display: flex; justify-content: space-between;">';
                            renderHtml += '<h3 style="margin: 0; font-size: 14px; color: #1d2327;">Account: <code>' + acc + '</code></h3>';
                            renderHtml += '<span style="font-size:11px; color:#646970; text-transform:uppercase;">' + accounts[acc].type + '</span>';
                            renderHtml += '</div>';
                            renderHtml += '<ul style="list-style: none; margin: 0; padding: 0;">';
                            
                            accounts[acc].sites.forEach(function(s) {
                                renderHtml += '<li style="padding: 10px 15px; border-bottom: 1px dashed #e2e4e7; display: flex; align-items: center; gap: 8px;">';
                                renderHtml += '<span class="dashicons dashicons-wordpress" style="color:#2271b1;"></span> ';
                                renderHtml += '<a href="'+s.url+'" target="_blank" style="text-decoration: none; font-weight: 600; font-size: 13px;">' + s.domain + '</a>';
                                renderHtml += '</li>';
                            });
                            
                            renderHtml += '</ul></div>';
                        }
                        bodyDiv.html(renderHtml);
                        
                    }).fail(function() {
                        $('#fleet-server-'+server.id+' .skyhs-fleet-server-body').html('<p style="color:#d63638;">Server Unreachable.</p>');
                    });
                });
            }
            
            loadFleet();
            
            $('#skyhs-refresh-fleet').on('click', function() {
                loadFleet();
            });
        });
        </script>
        <?php
    }
}

SkyHSHOSO_WP_Site_Manager::instance();