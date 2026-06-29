<?php
/**
 * DNS Editor functionality
 *
 * @package SkyHSHOSO
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function skyhshoso_enom_dns_editor($domain_name) {
    $enom_integration = SkyHSHOSO_Enom_Integration();
    $ns_info = $enom_integration->skyhshoso_enom_api_request('GetDNS', $domain_name);

    $current_nameservers = isset($ns_info->dns) ? (array)$ns_info->dns : [];
   

    $dns_records = $enom_integration->skyhshoso_enom_api_request('GetHosts', $domain_name);


    // Enqueue the CSS
    wp_enqueue_style('skyhshoso-dns-editor-css', plugin_dir_url(dirname(__FILE__)) . 'assets/css/dns-editor.css', array(), SKYHSHOSO_VERSION);
    
    // Enqueue the JS
    wp_enqueue_script('skyhshoso-dns-editor-js', plugin_dir_url(dirname(__FILE__)) . 'assets/js/dns-editor.js', array('jquery'), SKYHSHOSO_VERSION, true);

    // Localize the script
    wp_localize_script('skyhshoso-dns-editor-js', 'skyhshoso_dns_editor_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('skyhshoso_dns_editor_nonce'),
        'domain_name' => $domain_name,
        'current_nameservers' => array_values($current_nameservers), // Pass current nameservers to JS
        'i18n' => array(
            'nameservers_updated' => __('Nameservers updated successfully', 'skyhs-hosting-solution'),
            'error_updating_nameservers' => __('Error updating nameservers:', 'skyhs-hosting-solution'),
            'confirm_delete' => __('Are you sure you want to delete this record?', 'skyhs-hosting-solution'),
            'dns_record_updated' => __('DNS record updated successfully', 'skyhs-hosting-solution'),
            'error_updating_dns' => __('Error updating DNS record:', 'skyhs-hosting-solution'),
            'error_occurred' => __('An error occurred while updating the DNS record', 'skyhs-hosting-solution'),
        ),
    ));
   
    ?>
   
        <div class="skyhshoso-dns-editor-card">
            
            <div id="skyhshoso-dns-editor-notification" class="skyhshoso-dns-editor-hidden"></div>
            
            <div class="skyhshoso-dns-editor-mb-4">
                <h2 class="skyhshoso-dns-editor-subtitle"><?php esc_html_e( 'Current Nameservers', 'skyhs-hosting-solution' ); ?></h2>
                <ul id="current-nameservers" class="skyhshoso-dns-editor-nameservers">
                    <?php foreach ($current_nameservers as $ns): ?>
                        <li><?php echo esc_html($ns); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button id="edit-nameservers" class="skyhshoso-dns-editor-btn skyhshoso-dns-editor-btn-secondary"><?php esc_html_e( 'Edit Nameservers', 'skyhs-hosting-solution' ); ?></button>
            </div>

            <div id="nameserver-form" class="skyhshoso-dns-editor-form skyhshoso-dns-editor-hidden">
                <form id="update-nameservers-form">
                    <?php

                    for ($i = 0; $i < 4; $i++):
                        $current_ns = isset($current_nameservers[$i]) ? (string)$current_nameservers[$i] : '';

                    ?>
                        <div class="skyhshoso-dns-editor-form-group">
                            <label for="nameserver-<?php echo absint( $i + 1 ); ?>"><?php 
                            /* translators: %d: nameserver number */
                            printf( esc_html__( 'Nameserver %d', 'skyhs-hosting-solution' ), absint( $i + 1 ) ); ?></label>
                            <input type="text" id="nameserver-<?php echo absint( $i + 1 ); ?>" name="nameservers[]" class="skyhshoso-dns-editor-form-control" value="<?php echo esc_attr($current_ns); ?>">
                        </div>
                    <?php endfor; ?>
                    <div class="skyhshoso-dns-editor-form-actions">
                        <button type="submit" class="skyhshoso-dns-editor-btn skyhshoso-dns-editor-btn-primary"><?php esc_html_e('Update Nameservers', 'skyhs-hosting-solution'); ?></button>
                        <button type="button" id="cancel-nameservers" class="skyhshoso-dns-editor-btn skyhshoso-dns-editor-btn-secondary"><?php esc_html_e('Cancel', 'skyhs-hosting-solution'); ?></button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="skyhshoso-dns-editor-card">
            <h2 class="skyhshoso-dns-editor-subtitle"><?php esc_html_e( 'DNS Records', 'skyhs-hosting-solution' ); ?></h2>
            <table class="skyhshoso-dns-editor-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Type', 'skyhs-hosting-solution' ); ?></th>
                        <th><?php esc_html_e( 'Name', 'skyhs-hosting-solution' ); ?></th>
                        <th><?php esc_html_e( 'Value', 'skyhs-hosting-solution' ); ?></th>
                        <th><?php esc_html_e( 'MX Pref', 'skyhs-hosting-solution' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'skyhs-hosting-solution' ); ?></th>
                    </tr>
                </thead>
                <tbody id="dns-records">
                    <?php
                    if (isset($dns_records->host)) {
                        foreach ($dns_records->host as $record) {
                            $record_type = isset($record->type) ? (string)$record->type : '';
                            $record_name = isset($record->name) ? (string)$record->name : '';
                            $record_address = isset($record->address) ? (string)$record->address : '';
                            $record_mxpref = isset($record->mxpref) ? (string)$record->mxpref : '';

                            echo '<tr>';
                            echo '<td>' . esc_html($record_type) . '</td>';
                            echo '<td>' . esc_html($record_name) . '</td>';
                            echo '<td>' . esc_html($record_address) . '</td>';
                            echo '<td>' . ($record_type === 'MX' ? esc_html($record_mxpref) : '') . '</td>';
                            echo '<td class="skyhshoso-dns-editor-actions">';
                            echo '<button class="skyhshoso-dns-editor-btn skyhshoso-dns-editor-btn-secondary edit-record" data-type="' . esc_attr($record_type) . '" data-name="' . esc_attr($record_name) . '" data-address="' . esc_attr($record_address) . '" data-mxpref="' . esc_attr($record_mxpref) . '">' . esc_html__( 'Edit', 'skyhs-hosting-solution' ) . '</button>';
                            echo '<button class="skyhshoso-dns-editor-btn skyhshoso-dns-editor-btn-danger delete-record" data-type="' . esc_attr($record_type) . '" data-name="' . esc_attr($record_name) . '" data-address="' . esc_attr($record_address) . '">' . esc_html__( 'Delete', 'skyhs-hosting-solution' ) . '</button>';
                            echo '</td>';
                            echo '</tr>';
                        }
                    }
                    ?>
                </tbody>
            </table>

            <button id="add-record" class="skyhshoso-dns-editor-btn skyhshoso-dns-editor-btn-primary"><?php esc_html_e( 'Add New Record', 'skyhs-hosting-solution' ); ?></button>

            <div id="record-form" class="skyhshoso-dns-editor-form skyhshoso-dns-editor-hidden">
                <form id="dns-record-form">
                    <input type="hidden" id="form-mode" value="add">
                    <input type="hidden" id="old-host-name" value="">
                    <input type="hidden" id="old-address" value="">
                    
                    <div class="skyhshoso-dns-editor-form-group">
                        <label for="record-type"><?php esc_html_e( 'Record Type', 'skyhs-hosting-solution' ); ?></label>
                        <select id="record-type" class="skyhshoso-dns-editor-form-control">
                            <option value="A"><?php esc_html_e( 'A', 'skyhs-hosting-solution' ); ?></option>
                            <option value="AAAA"><?php esc_html_e( 'AAAA', 'skyhs-hosting-solution' ); ?></option>
                            <option value="CNAME"><?php esc_html_e( 'CNAME', 'skyhs-hosting-solution' ); ?></option>
                            <option value="MX"><?php esc_html_e( 'MX', 'skyhs-hosting-solution' ); ?></option>
                            <option value="TXT"><?php esc_html_e( 'TXT', 'skyhs-hosting-solution' ); ?></option>
                            <option value="SRV"><?php esc_html_e( 'SRV', 'skyhs-hosting-solution' ); ?></option>
                            <option value="CAA"><?php esc_html_e( 'CAA', 'skyhs-hosting-solution' ); ?></option>
                        </select>
                    </div>
                    
                    <div class="skyhshoso-dns-editor-form-group">
                        <label for="host-name"><?php esc_html_e( 'Hostname', 'skyhs-hosting-solution' ); ?></label>
                        <input type="text" id="host-name" placeholder="<?php esc_attr_e( 'Hostname', 'skyhs-hosting-solution' ); ?>" class="skyhshoso-dns-editor-form-control">
                    </div>
                    
                    <div class="skyhshoso-dns-editor-form-group">
                        <label for="address"><?php esc_html_e( 'Value', 'skyhs-hosting-solution' ); ?></label>
                        <input type="text" id="address" placeholder="<?php esc_attr_e( 'Value', 'skyhs-hosting-solution' ); ?>" class="skyhshoso-dns-editor-form-control">
                    </div>
                    
                    <div class="skyhshoso-dns-editor-form-group" id="mx-pref-group" style="display: none;">
                        <label for="mx-pref"><?php esc_html_e( 'MX Preference', 'skyhs-hosting-solution' ); ?></label>
                        <input type="number" id="mx-pref" placeholder="<?php esc_attr_e( 'MX Preference', 'skyhs-hosting-solution' ); ?>" class="skyhshoso-dns-editor-form-control">
                    </div>
                    
                    <div class="skyhshoso-dns-editor-form-actions">
                        <button type="submit" class="skyhshoso-dns-editor-btn skyhshoso-dns-editor-btn-primary"><?php esc_html_e( 'Save Record', 'skyhs-hosting-solution' ); ?></button>
                        <button type="button" id="cancel-record" class="skyhshoso-dns-editor-btn skyhshoso-dns-editor-btn-secondary"><?php esc_html_e( 'Cancel', 'skyhs-hosting-solution' ); ?></button>
                    </div>
                </form>
            </div>
        </div>
    

    <?php
}
