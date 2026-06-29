<?php
/**
 * SkyHS Email Campaign Admin
 *
 * Admin page for managing email campaigns: list, add, edit, delete,
 * duplicate, and toggle active status. AJAX-driven UI.
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Email_Campaign_Admin {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		add_action( 'wp_ajax_skyhshoso_save_campaign', array( $this, 'ajax_save_campaign' ) );
		add_action( 'wp_ajax_skyhshoso_delete_campaign', array( $this, 'ajax_delete_campaign' ) );
		add_action( 'wp_ajax_skyhshoso_toggle_campaign', array( $this, 'ajax_toggle_campaign' ) );
		add_action( 'wp_ajax_skyhshoso_duplicate_campaign', array( $this, 'ajax_duplicate_campaign' ) );
		add_action( 'wp_ajax_skyhshoso_get_campaign', array( $this, 'ajax_get_campaign' ) );
		add_action( 'wp_ajax_skyhshoso_send_campaign_test', array( $this, 'ajax_send_campaign_test' ) );
		add_action( 'wp_ajax_skyhshoso_campaign_product_search', array( $this, 'ajax_product_search' ) );
		add_action( 'wp_ajax_skyhshoso_campaign_category_search', array( $this, 'ajax_category_search' ) );
		add_action( 'wp_ajax_skyhshoso_campaign_recipient_count', array( $this, 'ajax_recipient_count' ) );
		add_action( 'wp_ajax_skyhshoso_campaign_preview', array( $this, 'ajax_campaign_preview' ) );
		add_action( 'wp_ajax_skyhshoso_send_to_existing', array( $this, 'ajax_send_to_existing' ) );
	}

	public function enqueue_scripts( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		if ( 'skyhshoso-email-campaigns' !== $page ) {
			return;
		}

		wp_enqueue_style(
			'skyhshoso-admin-wizard',
			SKYHSHOSO_PLUGIN_URL . 'assets/css/admin-wizard.css',
			array(),
			SKYHSHOSO_VERSION
		);

		wp_enqueue_style(
			'skyhshoso-hosting-manager',
			SKYHSHOSO_PLUGIN_URL . 'assets/css/hosting-manager.css',
			array(),
			SKYHSHOSO_VERSION
		);

		wp_enqueue_style(
			'skyhshoso-email-campaign-admin',
			SKYHSHOSO_PLUGIN_URL . 'assets/css/email-campaign-admin.css',
			array( 'skyhshoso-admin-wizard', 'skyhshoso-hosting-manager' ),
			SKYHSHOSO_VERSION
		);

		wp_enqueue_script(
			'skyhshoso-email-campaign-admin',
			SKYHSHOSO_PLUGIN_URL . 'assets/js/email-campaign-admin.js',
			array( 'jquery' ),
			SKYHSHOSO_VERSION,
			true
		);

		$campaigns = SkyHSHOSO_Email_Campaign_DB::get_all_campaigns();
		$campaigns_data = array();
		foreach ( $campaigns as $c ) {
			$campaigns_data[] = array(
				'id'           => (int) $c->id,
				'name'         => $c->name,
				'subject'      => $c->subject,
				'body'         => $c->body,
				'target_type'  => $c->target_type,
				'target_ids'   => $c->target_ids ? json_decode( $c->target_ids, true ) : array(),
				'trigger_type' => $c->trigger_type,
				'delay_value'  => (int) $c->delay_value,
				'delay_unit'   => $c->delay_unit,
				'is_active'    => (int) $c->is_active,
				'created_at'   => $c->created_at,
				'updated_at'   => $c->updated_at,
			);
		}

		$categories = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
		) );
		$cats_data = array();
		foreach ( $categories as $cat ) {
			$cats_data[] = array(
				'id'   => (int) $cat->term_id,
				'name' => $cat->name,
			);
		}

		wp_localize_script(
			'skyhshoso-email-campaign-admin',
			'skyhshoso_eca',
			array(
				'ajax_url'              => admin_url( 'admin-ajax.php' ),
				'nonce'                 => wp_create_nonce( 'skyhshoso_campaign_nonce' ),
				'campaigns'             => $campaigns_data,
				'categories'            => $cats_data,
				'strings'               => array(
					'confirm_delete'     => __( 'Are you sure you want to delete this campaign? Any pending queue entries will also be removed.', 'skyhs-hosting-solution' ),
					'saved'             => __( 'Campaign saved successfully.', 'skyhs-hosting-solution' ),
					'error_save'        => __( 'Error saving campaign.', 'skyhs-hosting-solution' ),
					'deleted'           => __( 'Campaign deleted.', 'skyhs-hosting-solution' ),
					'error_delete'      => __( 'Error deleting campaign.', 'skyhs-hosting-solution' ),
					'fill_required'     => __( 'Please fill in the campaign name and subject.', 'skyhs-hosting-solution' ),
					'test_sent'         => __( 'Test email sent to your email address.', 'skyhs-hosting-solution' ),
					'test_error'        => __( 'Failed to send test email.', 'skyhs-hosting-solution' ),
					'search_placeholder'=> __( 'Search products by name...', 'skyhs-hosting-solution' ),
					'search_cat_placeholder' => __( 'Search categories by name...', 'skyhs-hosting-solution' ),
				),
			)
		);
	}

	public function render_page() {
		?>
		<div class="wrap skyhshoso-hm-wrap">
			<h1><?php esc_html_e( 'Email Campaigns', 'skyhs-hosting-solution' ); ?></h1>
			<p><?php esc_html_e( 'Create automated email campaigns that send to customers based on products purchased, product categories, or specific customers you select.', 'skyhs-hosting-solution' ); ?></p>

			<div id="skyhshoso-hm-notice" class="notice" style="display:none;"></div>

			<div id="skyhshoso-ec-app">

				<div class="skyhshoso-hm-list-panel">
					<div class="skyhshoso-hm-list-header">
						<h2><?php esc_html_e( 'Campaigns', 'skyhs-hosting-solution' ); ?></h2>
						<button type="button" id="ec-btn-add" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-primary" style="background:#3b82f6;border-color:#2563eb;">
							<?php esc_html_e( '+ Add New Campaign', 'skyhs-hosting-solution' ); ?>
						</button>
					</div>
					<div id="ec-table-wrap" style="overflow-x:auto;">
						<table class="skyhshoso-hm-table" id="ec-table">
							<thead>
								<tr>
									<th style="min-width:150px;"><?php esc_html_e( 'Name', 'skyhs-hosting-solution' ); ?></th>
									<th><?php esc_html_e( 'Targeting', 'skyhs-hosting-solution' ); ?></th>
									<th><?php esc_html_e( 'Trigger', 'skyhs-hosting-solution' ); ?></th>
									<th style="min-width:80px;"><?php esc_html_e( 'Active', 'skyhs-hosting-solution' ); ?></th>
									<th style="min-width:80px;"><?php esc_html_e( 'Created', 'skyhs-hosting-solution' ); ?></th>
									<th style="min-width:250px;"><?php esc_html_e( 'Actions', 'skyhs-hosting-solution' ); ?></th>
								</tr>
							</thead>
							<tbody id="ec-tbody">
								<tr id="ec-empty-row">
									<td colspan="6" style="text-align:center;padding:40px;color:#6b7280;">
										<?php esc_html_e( 'No campaigns yet. Click "Add New Campaign" to create one.', 'skyhs-hosting-solution' ); ?>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>

				<div class="skyhshoso-hm-form-panel" id="ec-form-panel" style="display:none;">
					<div class="skyhshoso-hm-form-header">
						<h2 id="ec-form-title"><?php esc_html_e( 'Add New Campaign', 'skyhs-hosting-solution' ); ?></h2>
					</div>

					<form id="ec-form" class="skyhshoso-hm-form">
						<input type="hidden" id="ec_campaign_id" name="campaign_id" value="0" />

						<div class="skyhshoso-hm-section">
							<h3><?php esc_html_e( 'Campaign Details', 'skyhs-hosting-solution' ); ?></h3>

							<div class="skyhshoso-hm-row">
								<div class="skyhshoso-hm-field">
									<label for="ec_name"><?php esc_html_e( 'Campaign Name', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
									<input type="text" id="ec_name" name="name" class="hm-input" placeholder="<?php esc_attr_e( 'e.g. Welcome Email for New Customers', 'skyhs-hosting-solution' ); ?>" />
								</div>
							</div>

							<div class="skyhshoso-hm-row">
								<div class="skyhshoso-hm-field">
									<label for="ec_subject"><?php esc_html_e( 'Email Subject', 'skyhs-hosting-solution' ); ?> <span class="req">*</span></label>
									<input type="text" id="ec_subject" name="subject" class="hm-input" placeholder="<?php esc_attr_e( 'Thank you for your purchase!', 'skyhs-hosting-solution' ); ?>" />
								</div>
							</div>

							<div class="skyhshoso-hm-row">
								<div class="skyhshoso-hm-field">
									<label for="ec_body"><?php esc_html_e( 'Email Body (HTML)', 'skyhs-hosting-solution' ); ?></label>
									<textarea id="ec_body" name="body" class="hm-input" rows="10" style="font-family:monospace;font-size:13px;min-height:180px;resize:vertical;" placeholder="<?php esc_attr_e( '<p>Hi {{customer_name}},</p><p>Thank you for purchasing {{product_name}}.</p><p>Order #{{order_id}} — {{order_total}}</p>', 'skyhs-hosting-solution' ); ?>"></textarea>
									<p class="hm-field-desc">
										<?php esc_html_e( 'Available placeholders:', 'skyhs-hosting-solution' ); ?>
										<code>{{customer_name}}</code> <code>{{customer_first_name}}</code> <code>{{customer_last_name}}</code>
										<code>{{customer_email}}</code> <code>{{product_name}}</code> <code>{{variation_name}}</code>
										<code>{{product_quantity}}</code> <code>{{order_id}}</code> <code>{{order_date}}</code>
										<code>{{order_total}}</code> <code>{{billing_address}}</code> <code>{{site_name}}</code> <code>{{site_url}}</code>
									</p>
								</div>
							</div>
						</div>

						<div class="skyhshoso-hm-section">
							<h3><?php esc_html_e( 'Targeting', 'skyhs-hosting-solution' ); ?></h3>
							<p class="hm-field-desc" style="margin-bottom:16px;"><?php esc_html_e( 'Who should receive this email? Choose one targeting method.', 'skyhs-hosting-solution' ); ?></p>

							<div class="skyhshoso-hm-row">
								<div class="skyhshoso-hm-field">
									<label><?php esc_html_e( 'Target Type', 'skyhs-hosting-solution' ); ?></label>
									<select id="ec_target_type" name="target_type" class="hm-input" style="max-width:300px;">
										<option value="products"><?php esc_html_e( 'Specific Products', 'skyhs-hosting-solution' ); ?></option>
										<option value="categories"><?php esc_html_e( 'Product Categories', 'skyhs-hosting-solution' ); ?></option>
										<option value="manual"><?php esc_html_e( 'Manual Customers', 'skyhs-hosting-solution' ); ?></option>
									</select>
								</div>
							</div>

							<div id="ec-target-products" class="ec-target-group">
								<div class="skyhshoso-hm-row">
									<div class="skyhshoso-hm-field">
										<label><?php esc_html_e( 'Products', 'skyhs-hosting-solution' ); ?></label>
										<div style="position:relative;">
											<input type="text" id="ec_product_search" class="hm-input" style="max-width:400px;" placeholder="<?php esc_attr_e( 'Search products by name...', 'skyhs-hosting-solution' ); ?>" />
											<div id="ec_product_results" class="ec-search-results" style="display:none;"></div>
										</div>
										<div id="ec_selected_products" class="ec-chips"></div>
										<textarea id="ec_target_ids" style="display:none;"></textarea>
										<p class="hm-field-desc"><?php esc_html_e( 'All variations of a selected parent product are automatically included.', 'skyhs-hosting-solution' ); ?></p>
									</div>
								</div>
							</div>

							<div id="ec-target-categories" class="ec-target-group" style="display:none;">
								<div class="skyhshoso-hm-row">
									<div class="skyhshoso-hm-field">
										<label><?php esc_html_e( 'Categories', 'skyhs-hosting-solution' ); ?></label>
										<div style="position:relative;">
											<input type="text" id="ec_cat_search" class="hm-input" style="max-width:400px;" placeholder="<?php esc_attr_e( 'Search categories by name...', 'skyhs-hosting-solution' ); ?>" />
											<div id="ec_cat_results" class="ec-search-results" style="display:none;"></div>
										</div>
										<div id="ec_selected_cats" class="ec-chips"></div>
										<p class="hm-field-desc"><?php esc_html_e( 'Customers who purchased any product in the selected categories will receive this email.', 'skyhs-hosting-solution' ); ?></p>
									</div>
								</div>
							</div>

							<div id="ec-target-manual" class="ec-target-group" style="display:none;">
								<div class="skyhshoso-hm-row">
									<div class="skyhshoso-hm-field">
										<label><?php esc_html_e( 'User IDs', 'skyhs-hosting-solution' ); ?></label>
										<textarea id="ec_manual_user_ids" class="hm-input" rows="3" style="max-width:400px;font-family:monospace;font-size:13px;resize:vertical;" placeholder="<?php esc_attr_e( 'Comma-separated user IDs, e.g.: 1, 23, 45', 'skyhs-hosting-solution' ); ?>"></textarea>
										<p class="hm-field-desc"><?php esc_html_e( 'Enter comma-separated WordPress user IDs. These customers will receive the email on their next qualifying order.', 'skyhs-hosting-solution' ); ?></p>
									</div>
								</div>
							</div>
						</div>

						<div class="skyhshoso-hm-section">
							<h3><?php esc_html_e( 'Send Timing', 'skyhs-hosting-solution' ); ?></h3>

							<div class="skyhshoso-hm-row">
								<div class="skyhshoso-hm-field" style="max-width:300px;">
									<label><?php esc_html_e( 'Trigger', 'skyhs-hosting-solution' ); ?></label>
									<select id="ec_trigger_type" name="trigger_type" class="hm-input">
										<option value="scheduled"><?php esc_html_e( 'Scheduled (send after a delay)', 'skyhs-hosting-solution' ); ?></option>
										<option value="immediate"><?php esc_html_e( 'Immediate (send as soon as order completes)', 'skyhs-hosting-solution' ); ?></option>
									</select>
								</div>
							</div>

							<div id="ec-delay-row" class="skyhshoso-hm-row-cols-2" style="max-width:400px;">
								<div class="skyhshoso-hm-field">
									<label for="ec_delay_value"><?php esc_html_e( 'Delay', 'skyhs-hosting-solution' ); ?></label>
									<input type="number" id="ec_delay_value" name="delay_value" class="hm-input" value="3" min="0" step="1" />
								</div>
								<div class="skyhshoso-hm-field">
									<label for="ec_delay_unit"><?php esc_html_e( 'Unit', 'skyhs-hosting-solution' ); ?></label>
									<select id="ec_delay_unit" name="delay_unit" class="hm-input">
										<option value="hours"><?php esc_html_e( 'Hours', 'skyhs-hosting-solution' ); ?></option>
										<option value="days"><?php esc_html_e( 'Days', 'skyhs-hosting-solution' ); ?></option>
									</select>
								</div>
							</div>

							<div class="skyhshoso-hm-row ec-active-row">
								<div class="skyhshoso-hm-field">
									<label style="font-weight:400;display:flex;align-items:center;gap:8px;">
										<input type="checkbox" id="ec_is_active" name="is_active" value="1" />
										<?php esc_html_e( 'Active — campaign will send emails to matching customers', 'skyhs-hosting-solution' ); ?>
									</label>
								</div>
							</div>
						</div>

						<div class="skyhshoso-hm-actions">
							<div class="skyhshoso-hm-actions-left">
								<button type="button" id="ec-btn-cancel" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-secondary" style="border-color:#d1d5db;color:#374151;">
									<?php esc_html_e( 'Cancel', 'skyhs-hosting-solution' ); ?>
								</button>
							</div>
							<div class="skyhshoso-hm-actions-right">
								<button type="button" id="ec-btn-preview" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-secondary" style="border-color:#d1d5db;color:#374151;">
									<?php esc_html_e( 'Preview', 'skyhs-hosting-solution' ); ?>
								</button>
								<button type="button" id="ec-btn-test" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-secondary" style="border-color:#d1d5db;color:#374151;">
									<?php esc_html_e( 'Send Test', 'skyhs-hosting-solution' ); ?>
								</button>
								<button type="submit" id="ec-btn-save" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-primary" style="background:#3b82f6;border-color:#2563eb;">
									<?php esc_html_e( 'Save Campaign', 'skyhs-hosting-solution' ); ?>
								</button>
							</div>
						</div>
					</form>
				</div>

				<!-- Preview modal -->
				<div id="ec-preview-modal" class="skyhshoso-modal" style="display:none;">
					<div class="skyhshoso-modal-backdrop"></div>
					<div class="skyhshoso-modal-content" style="width:740px;">
						<div class="skyhshoso-modal-header">
							<h3><?php esc_html_e( 'Email Preview', 'skyhs-hosting-solution' ); ?></h3>
							<button type="button" class="skyhshoso-modal-close" id="ec-preview-close">&times;</button>
						</div>
						<div class="skyhshoso-modal-body" style="padding:0;">
							<div id="ec-preview-subject" style="padding:16px 24px;background:#f9fafb;border-bottom:1px solid #e5e7eb;font-size:14px;font-weight:600;color:#374151;"></div>
							<iframe id="ec-preview-iframe" style="width:100%;height:60vh;border:none;display:block;"></iframe>
						</div>
					</div>
				</div>
				<!-- /Preview modal -->

				<!-- Send Now confirmation modal -->
				<div id="ec-sendnow-modal" class="skyhshoso-modal" style="display:none;">
					<div class="skyhshoso-modal-backdrop"></div>
					<div class="skyhshoso-modal-content" style="width:480px;">
						<div class="skyhshoso-modal-header">
							<h3><?php esc_html_e( 'Send to Existing Customers', 'skyhs-hosting-solution' ); ?></h3>
							<button type="button" class="skyhshoso-modal-close" id="ec-sendnow-close">&times;</button>
						</div>
						<div class="skyhshoso-modal-body">
							<p id="ec-sendnow-count" style="font-size:24px;font-weight:700;color:#111827;text-align:center;margin:20px 0 8px 0;"></p>
							<p style="text-align:center;color:#6b7280;margin:0 0 24px 0;"><?php esc_html_e( 'existing customers will receive this email on the next cron run (~5 minutes).', 'skyhs-hosting-solution' ); ?></p>
							<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;margin-bottom:20px;">
								<p style="margin:0;font-size:13px;color:#92400e;"><strong><?php esc_html_e( 'Note:', 'skyhs-hosting-solution' ); ?></strong> <?php esc_html_e( 'This will email ALL matching customers for this campaign — including those who purchased long ago. Only customers who have not already received this campaign will be queued.', 'skyhs-hosting-solution' ); ?></p>
							</div>
						</div>
						<div class="skyhshoso-modal-footer" style="display:flex;justify-content:flex-end;gap:8px;padding:16px 24px;border-top:1px solid #e5e7eb;background:#f9fafb;">
							<button type="button" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-secondary ec-sendnow-cancel" style="border-color:#d1d5db;color:#374151;"><?php esc_html_e( 'Cancel', 'skyhs-hosting-solution' ); ?></button>
							<button type="button" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-primary" id="ec-sendnow-confirm" style="background:#3b82f6;border-color:#2563eb;"><?php esc_html_e( 'Confirm & Send', 'skyhs-hosting-solution' ); ?></button>
						</div>
					</div>
				</div>
				<!-- /Send Now modal -->

			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX: save campaign
	// -------------------------------------------------------------------------

	public function ajax_save_campaign() {
		if ( ! current_user_can( 'skyhshoso_manage_email_campaigns' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
		}

		check_ajax_referer( 'skyhshoso_campaign_nonce', 'nonce' );

		$id      = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$body    = isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '';

		if ( empty( $name ) || empty( $subject ) ) {
			wp_send_json_error( array( 'message' => __( 'Name and subject are required.', 'skyhs-hosting-solution' ) ) );
		}

		$target_type  = isset( $_POST['target_type'] ) ? sanitize_text_field( wp_unslash( $_POST['target_type'] ) ) : 'products';
		$trigger_type = isset( $_POST['trigger_type'] ) ? sanitize_text_field( wp_unslash( $_POST['trigger_type'] ) ) : 'scheduled';
		$delay_value  = isset( $_POST['delay_value'] ) ? absint( $_POST['delay_value'] ) : 0;
		$delay_unit   = isset( $_POST['delay_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['delay_unit'] ) ) : 'days';
		$is_active    = isset( $_POST['is_active'] ) && $_POST['is_active'] ? 1 : 0;

		$target_ids = array();
		switch ( $target_type ) {
			case 'products':
				$raw = isset( $_POST['target_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['target_ids'] ) ) : '';
				if ( ! empty( $raw ) ) {
					foreach ( explode( ',', $raw ) as $p ) {
						$pid = absint( trim( $p ) );
						if ( $pid > 0 ) {
							$target_ids[] = $pid;
						}
					}
				}
				break;
			case 'categories':
				$raw = isset( $_POST['category_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['category_ids'] ) ) : '';
				if ( ! empty( $raw ) ) {
					foreach ( explode( ',', $raw ) as $p ) {
						$cid = absint( trim( $p ) );
						if ( $cid > 0 ) {
							$target_ids[] = $cid;
						}
					}
				}
				break;
			case 'manual':
				$raw = isset( $_POST['manual_user_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['manual_user_ids'] ) ) : '';
				if ( ! empty( $raw ) ) {
					foreach ( explode( ',', $raw ) as $p ) {
						$uid = absint( trim( $p ) );
						if ( $uid > 0 ) {
							$target_ids[] = $uid;
						}
					}
				}
				break;
		}

		$data = array(
			'name'         => $name,
			'subject'      => $subject,
			'body'         => $body,
			'target_type'  => $target_type,
			'target_ids'   => wp_json_encode( $target_ids ),
			'trigger_type' => $trigger_type,
			'delay_value'  => $delay_value,
			'delay_unit'   => 'immediate' === $trigger_type ? 'days' : $delay_unit,
			'is_active'    => $is_active,
		);

		if ( $id > 0 ) {
			$result = SkyHSHOSO_Email_Campaign_DB::update_campaign( $id, $data );
		} else {
			$id     = SkyHSHOSO_Email_Campaign_DB::insert_campaign( $data );
			$result = (bool) $id;
		}

		if ( $result ) {
			$campaign = SkyHSHOSO_Email_Campaign_DB::get_campaign( $id );
			wp_send_json_success( array(
				'message'  => __( 'Campaign saved successfully.', 'skyhs-hosting-solution' ),
				'campaign' => array(
					'id'           => (int) $campaign->id,
					'name'         => $campaign->name,
					'subject'      => $campaign->subject,
					'body'         => $campaign->body,
					'target_type'  => $campaign->target_type,
					'target_ids'   => $campaign->target_ids ? json_decode( $campaign->target_ids, true ) : array(),
					'trigger_type' => $campaign->trigger_type,
					'delay_value'  => (int) $campaign->delay_value,
					'delay_unit'   => $campaign->delay_unit,
					'is_active'    => (int) $campaign->is_active,
					'created_at'   => $campaign->created_at,
					'updated_at'   => $campaign->updated_at,
				),
			) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to save campaign.', 'skyhs-hosting-solution' ) ) );
		}
	}

	// -------------------------------------------------------------------------
	// AJAX: delete
	// -------------------------------------------------------------------------

	public function ajax_delete_campaign() {
		if ( ! current_user_can( 'skyhshoso_manage_email_campaigns' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		check_ajax_referer( 'skyhshoso_campaign_nonce', 'nonce' );
		$id = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
		if ( ! $id ) { wp_send_json_error(); }
		SkyHSHOSO_Email_Campaign_DB::delete_queue_by_campaign( $id );
		SkyHSHOSO_Email_Campaign_DB::delete_campaign( $id );
		wp_send_json_success();
	}

	public function ajax_toggle_campaign() {
		if ( ! current_user_can( 'skyhshoso_manage_email_campaigns' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		check_ajax_referer( 'skyhshoso_campaign_nonce', 'nonce' );
		$id = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
		if ( ! $id ) { wp_send_json_error(); }
		SkyHSHOSO_Email_Campaign_DB::toggle_campaign( $id );
		$c = SkyHSHOSO_Email_Campaign_DB::get_campaign( $id );
		wp_send_json_success( array( 'is_active' => (int) $c->is_active ) );
	}

	public function ajax_duplicate_campaign() {
		if ( ! current_user_can( 'skyhshoso_manage_email_campaigns' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		check_ajax_referer( 'skyhshoso_campaign_nonce', 'nonce' );
		$id = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
		if ( ! $id ) { wp_send_json_error(); }
		$new_id  = SkyHSHOSO_Email_Campaign_DB::duplicate_campaign( $id );
		$c       = SkyHSHOSO_Email_Campaign_DB::get_campaign( $new_id );
		wp_send_json_success( array(
			'campaign' => array(
				'id'           => (int) $c->id,
				'name'         => $c->name,
				'subject'      => $c->subject,
				'body'         => $c->body,
				'target_type'  => $c->target_type,
				'target_ids'   => $c->target_ids ? json_decode( $c->target_ids, true ) : array(),
				'trigger_type' => $c->trigger_type,
				'delay_value'  => (int) $c->delay_value,
				'delay_unit'   => $c->delay_unit,
				'is_active'    => (int) $c->is_active,
				'created_at'   => $c->created_at,
				'updated_at'   => $c->updated_at,
			),
		) );
	}

	public function ajax_get_campaign() {
		if ( ! current_user_can( 'skyhshoso_manage_email_campaigns' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		check_ajax_referer( 'skyhshoso_campaign_nonce', 'nonce' );
		$id = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
		if ( ! $id ) { wp_send_json_error(); }
		$c = SkyHSHOSO_Email_Campaign_DB::get_campaign( $id );
		if ( ! $c ) { wp_send_json_error(); }
		wp_send_json_success( array(
			'campaign' => array(
				'id'           => (int) $c->id,
				'name'         => $c->name,
				'subject'      => $c->subject,
				'body'         => $c->body,
				'target_type'  => $c->target_type,
				'target_ids'   => $c->target_ids ? json_decode( $c->target_ids, true ) : array(),
				'trigger_type' => $c->trigger_type,
				'delay_value'  => (int) $c->delay_value,
				'delay_unit'   => $c->delay_unit,
				'is_active'    => (int) $c->is_active,
				'created_at'   => $c->created_at,
				'updated_at'   => $c->updated_at,
			),
		) );
	}

	// -------------------------------------------------------------------------
	// AJAX: send test email
	// -------------------------------------------------------------------------

	public function ajax_send_campaign_test() {
		if ( ! current_user_can( 'skyhshoso_manage_email_campaigns' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		check_ajax_referer( 'skyhshoso_campaign_nonce', 'nonce' );

		$user    = wp_get_current_user();
		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$body    = isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '';

		if ( empty( $subject ) ) {
			wp_send_json_error( array( 'message' => __( 'Subject is required.', 'skyhs-hosting-solution' ) ) );
		}

		$placeholders = array(
			'{{customer_name}}'       => $user->display_name,
			'{{customer_first_name}}' => $user->first_name ?: $user->display_name,
			'{{customer_last_name}}'  => $user->last_name ?: '',
			'{{customer_email}}'      => $user->user_email,
			'{{product_name}}'        => __( 'Sample Product', 'skyhs-hosting-solution' ),
			'{{variation_name}}'      => __( 'Sample Variation', 'skyhs-hosting-solution' ),
			'{{product_quantity}}'    => '1',
			'{{order_id}}'            => '#999',
			'{{order_date}}'          => date_i18n( get_option( 'date_format' ) ),
			'{{order_total}}'         => wc_price( 29.99 ),
			'{{billing_address}}'     => __( "123 Sample St\nCity, State 12345", 'skyhs-hosting-solution' ),
			'{{site_name}}'           => get_bloginfo( 'name' ),
			'{{site_url}}'            => home_url(),
		);

		$subject = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $subject );
		$body    = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $body );

		if ( false === strpos( $body, '<html' ) && false === strpos( $body, '<body' ) ) {
			$body = '<html><body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;padding:20px;color:#1d2327;"><div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);"><div style="padding:30px;">' . nl2br( $body ) . '</div></div></body></html>';
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		add_filter( 'skyhshoso_force_send_test', '__return_true' );
		$sent = wp_mail( $user->user_email, $subject, $body, $headers );
		remove_filter( 'skyhshoso_force_send_test', '__return_true' );

		if ( $sent ) {
			wp_send_json_success( array( 'message' => sprintf( __( 'Test email sent to %s.', 'skyhs-hosting-solution' ), $user->user_email ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to send test email.', 'skyhs-hosting-solution' ) ) );
		}
	}

	// -------------------------------------------------------------------------
	// AJAX: product search (all published products)
	// -------------------------------------------------------------------------

	public function ajax_product_search() {
		if ( ! current_user_can( 'skyhshoso_manage_email_campaigns' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		check_ajax_referer( 'skyhshoso_campaign_nonce', 'nonce' );

		global $wpdb;

		$search  = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$exclude = isset( $_POST['exclude'] ) ? array_map( 'absint', (array) $_POST['exclude'] ) : array();

		$sql     = "SELECT ID, post_title, post_type, post_parent FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status = 'publish'";
		$params  = array();

		if ( ! empty( $exclude ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $exclude ), '%d' ) );
			$sql .= " AND ID NOT IN ({$placeholders})";
			$params = array_merge( $params, $exclude );
		}

		if ( ! empty( $search ) ) {
			$sql     .= " AND post_title LIKE %s";
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$sql        .= " ORDER BY post_title ASC LIMIT 25";
		$full_query  = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$rows    = $wpdb->get_results( $full_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$results = array();
		$seen    = array();

		foreach ( $rows as $row ) {
			$pid = (int) $row->ID;

			if ( 'product_variation' === $row->post_type ) {
				$parent_id = (int) $row->post_parent;
				if ( isset( $seen[ $parent_id ] ) ) {
					continue;
				}
				$seen[ $parent_id ] = true;
				$pid = $parent_id;
			} elseif ( isset( $seen[ $pid ] ) ) {
				continue;
			}
			$seen[ $pid ] = true;

			$product = wc_get_product( $pid );
			if ( ! $product ) {
				continue;
			}

			$results[] = array(
				'id'    => $pid,
				'name'  => $product->get_name() . ( $product->is_type( 'variable' ) ? ' (' . __( 'Variable', 'skyhs-hosting-solution' ) . ')' : '' ),
				'price' => wp_strip_all_tags( $product->get_price_html() ),
				'type'  => $product->get_type(),
			);
		}

		wp_send_json_success( $results );
	}

	// -------------------------------------------------------------------------
	// AJAX: category search
	// -------------------------------------------------------------------------

	public function ajax_category_search() {
		if ( ! current_user_can( 'skyhshoso_manage_email_campaigns' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		check_ajax_referer( 'skyhshoso_campaign_nonce', 'nonce' );

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$exclude = isset( $_POST['exclude'] ) ? array_map( 'absint', (array) $_POST['exclude'] ) : array();

		$args = array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'name__like' => $search,
			'exclude'    => $exclude,
			'number'     => 20,
		);

		if ( empty( $search ) ) {
			$args['orderby'] = 'name';
			$args['order']   = 'ASC';
		}

		$terms   = get_terms( $args );
		$results = array();

		foreach ( $terms as $term ) {
			$results[] = array(
				'id'   => (int) $term->term_id,
				'name' => $term->name,
				'count' => (int) $term->count,
			);
		}

		wp_send_json_success( $results );
	}

	// -------------------------------------------------------------------------
	// AJAX: estimate recipient count
	// -------------------------------------------------------------------------

	public function ajax_recipient_count() {
		if ( ! current_user_can( 'skyhshoso_manage_email_campaigns' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		check_ajax_referer( 'skyhshoso_campaign_nonce', 'nonce' );

		$campaign_id = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
		if ( ! $campaign_id ) {
			wp_send_json_error();
		}

		$campaign = SkyHSHOSO_Email_Campaign_DB::get_campaign( $campaign_id );
		if ( ! $campaign ) {
			wp_send_json_error();
		}

		$count = $this->estimate_recipients( $campaign );

		wp_send_json_success( array(
			'count'    => $count,
			'label'    => sprintf( _n( '~%d potential recipient', '~%d potential recipients', $count, 'skyhs-hosting-solution' ), $count ),
		) );
	}

	// -------------------------------------------------------------------------
	// AJAX: send campaign to all existing matching customers right now
	// -------------------------------------------------------------------------

	public function ajax_send_to_existing() {
		if ( ! current_user_can( 'skyhshoso_manage_email_campaigns' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'skyhs-hosting-solution' ) ) );
		}
		check_ajax_referer( 'skyhshoso_campaign_nonce', 'nonce' );

		$campaign_id = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
		if ( ! $campaign_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid campaign.', 'skyhs-hosting-solution' ) ) );
		}

		$campaign = SkyHSHOSO_Email_Campaign_DB::get_campaign( $campaign_id );
		if ( ! $campaign ) {
			wp_send_json_error( array( 'message' => __( 'Campaign not found.', 'skyhs-hosting-solution' ) ) );
		}

		$target_ids = $campaign->target_ids ? json_decode( $campaign->target_ids, true ) : array();
		if ( empty( $target_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'Campaign has no targets.', 'skyhs-hosting-solution' ) ) );
		}

		$target_ids = array_map( 'absint', $target_ids );
		$now        = gmdate( 'Y-m-d H:i:s' );
		$queued     = 0;

		$matching = $this->find_matching_orders( $campaign->target_type, $target_ids );

		$seen_users = array();
		foreach ( $matching as $row ) {
			$user_id = (int) $row['user_id'];

			if ( ! $user_id ) {
				continue;
			}

			if ( isset( $seen_users[ $user_id ] ) ) {
				continue;
			}
			$seen_users[ $user_id ] = true;

			if ( SkyHSHOSO_Email_Campaign_DB::queue_entry_exists_for_user( $campaign_id, $user_id ) ) {
				continue;
			}

			SkyHSHOSO_Email_Campaign_DB::insert_queue( array(
				'campaign_id'  => $campaign_id,
				'order_id'     => (int) $row['order_id'],
				'user_id'      => $user_id,
				'status'       => 'pending',
				'scheduled_at' => $now,
			) );
			$queued++;
		}

		if ( class_exists( 'SkyHSHOSO_Activity_Log' ) ) {
			SkyHSHOSO_Activity_Log::log(
				'email_campaign',
				sprintf( 'Manual send: %d existing order(s) queued for campaign "%s".', $queued, $campaign->name ),
				$queued > 0 ? 'success' : 'info',
				0,
				0,
				0
			);
		}

		wp_send_json_success( array(
			'queued'  => $queued,
			'message' => sprintf(
				_n( '%d existing customer queued. It will be sent on the next cron run.', '%d existing customers queued. They will be sent on the next cron run.', $queued, 'skyhs-hosting-solution' ),
				$queued
			),
		) );
	}

	/**
	 * Find matching orders for a campaign using wc_get_orders (HPOS-compatible).
	 *
	 * @param string $target_type products|categories|manual
	 * @param int[]  $target_ids
	 * @return array[] Array of [order_id, user_id]
	 */
	private function find_matching_orders( $target_type, $target_ids ) {
		$results = array();
		$page    = 1;

		$order_statuses = array( 'completed', 'processing' );

		do {
			$orders = wc_get_orders( array(
				'status' => $order_statuses,
				'limit'  => 100,
				'paged'  => $page,
				'return' => 'ids',
				'type'   => 'shop_order',
			) );

			if ( empty( $orders ) ) {
				break;
			}

			foreach ( $orders as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					continue;
				}

				$user_id = $order->get_customer_id();
				$match   = false;

				switch ( $target_type ) {
					case 'products':
						foreach ( $order->get_items() as $item ) {
							if ( in_array( $item->get_product_id(), $target_ids, true ) ) {
								$match = true;
								break;
							}
						}
						break;

					case 'categories':
						foreach ( $order->get_items() as $item ) {
							$product = $item->get_product();
							if ( ! $product ) {
								continue;
							}
							$product_id  = $item->get_product_id();
							$cat_ids     = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
							if ( ! empty( array_intersect( $cat_ids, $target_ids ) ) ) {
								$match = true;
								break;
							}
						}
						break;

					case 'manual':
						if ( in_array( $user_id, $target_ids, true ) ) {
							$match = true;
						}
						break;
				}

				if ( $match ) {
					$results[] = array(
						'order_id' => $order_id,
						'user_id'  => $user_id,
					);
				}
			}

			$page++;
		} while ( count( $orders ) === 100 );

		return $results;
	}

	private function estimate_recipients( $campaign ) {
		$target_type = $campaign->target_type;
		$target_ids  = $campaign->target_ids ? json_decode( $campaign->target_ids, true ) : array();

		if ( empty( $target_ids ) ) {
			return 0;
		}

		$target_ids = array_map( 'absint', $target_ids );

		if ( 'manual' === $target_type ) {
			return count( $target_ids );
		}

		$matching = $this->find_matching_orders( $target_type, $target_ids );
		$users    = array();
		foreach ( $matching as $row ) {
			$users[ (int) $row['user_id'] ] = true;
		}
		return count( $users );
	}

	// -------------------------------------------------------------------------
	// AJAX: preview email
	// -------------------------------------------------------------------------

	public function ajax_campaign_preview() {
		if ( ! current_user_can( 'skyhshoso_manage_email_campaigns' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		check_ajax_referer( 'skyhshoso_campaign_nonce', 'nonce' );

		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$body    = isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '';

		$user = wp_get_current_user();

		$placeholders = array(
			'{{customer_name}}'       => 'John Doe',
			'{{customer_first_name}}' => 'John',
			'{{customer_last_name}}'  => 'Doe',
			'{{customer_email}}'      => 'customer@example.com',
			'{{product_name}}'        => 'Shared Hosting - Basic',
			'{{variation_name}}'      => 'Shared Hosting - Basic (Monthly)',
			'{{product_quantity}}'    => '1',
			'{{order_id}}'            => '#1234',
			'{{order_date}}'          => date_i18n( get_option( 'date_format' ) ),
			'{{order_total}}'         => wc_price( 29.99 ),
			'{{billing_address}}'     => "123 Main St\nSpringfield, IL 62701",
			'{{site_name}}'           => get_bloginfo( 'name' ),
			'{{site_url}}'            => home_url(),
		);

		$subject_preview = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $subject );
		$body_preview    = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $body );

		if ( false === strpos( $body_preview, '<html' ) && false === strpos( $body_preview, '<body' ) ) {
			$body_preview = '<html><body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;padding:20px;color:#1d2327;"><div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);"><div style="padding:30px;">' . nl2br( $body_preview ) . '</div></div></body></html>';
		}

		wp_send_json_success( array(
			'subject' => $subject_preview,
			'body'    => $body_preview,
		) );
	}
}
