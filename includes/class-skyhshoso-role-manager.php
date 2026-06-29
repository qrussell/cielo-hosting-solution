<?php
/**
 * Role Manager for SkyHS Hosting Solution
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SkyHSHOSO_Role_Manager
 *
 * Provides a matrix UI (features × roles) in Skyhs Settings
 * to assign/revoke plugin capabilities on existing WordPress roles.
 */
class SkyHSHOSO_Role_Manager {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_filter( 'skyhshoso_settings_tabs', array( $this, 'add_tab' ) );
		add_action( 'skyhshoso_settings_tab_role_manager', array( $this, 'render_tab' ) );
		add_action( 'admin_post_skyhshoso_save_role_manager', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		$this->enforce_admin_caps();
	}

	/**
	 * Get all SkyHS features and their mapped capabilities.
	 *
	 * @return array
	 */
	public static function get_features() {
		return array(
			'dashboard'                => array(
				'label' => __( 'Dashboard', 'skyhs-hosting-solution' ),
				'cap'   => 'skyhshoso_view_dashboard',
			),
			'servers'                  => array(
				'label' => __( 'Servers', 'skyhs-hosting-solution' ),
				'cap'   => 'skyhshoso_manage_servers',
			),
			'products'                 => array(
				'label' => __( 'Products', 'skyhs-hosting-solution' ),
				'cap'   => 'skyhshoso_manage_products',
			),
			'hosting'                  => array(
				'label' => __( 'Hosting', 'skyhs-hosting-solution' ),
				'cap'   => 'skyhshoso_manage_hosting',
			),
			'domains'                  => array(
				'label' => __( 'Domains', 'skyhs-hosting-solution' ),
				'cap'   => 'skyhshoso_manage_domains',
			),
			'subscriptions'            => array(
				'label' => __( 'Subscriptions', 'skyhs-hosting-solution' ),
				'cap'   => 'skyhshoso_manage_subscriptions',
			),
			'enom_manager'             => array(
				'label' => __( 'Enom Manager', 'skyhs-hosting-solution' ),
				'cap'   => 'skyhshoso_manage_enom_manager',
			),
			'enom_settings'            => array(
				'label' => __( 'Enom Settings', 'skyhs-hosting-solution' ),
				'cap'   => 'skyhshoso_manage_enom_settings',
			),
			'settings'                 => array(
				'label' => __( 'Skyhs Settings', 'skyhs-hosting-solution' ),
				'cap'   => 'skyhshoso_manage_settings',
			),
			'import_export'            => array(
				'label' => __( 'Import/Export', 'skyhs-hosting-solution' ),
				'cap'   => 'skyhshoso_manage_import_export',
			),
			'backups'                  => array(
				'label' => __( 'Backups', 'skyhs-hosting-solution' ),
				'cap'   => 'skyhshoso_manage_backups',
			),
			'switch_subscription'      => array(
				'label' => __( 'Switch Subscription', 'skyhs-hosting-solution' ),
				'cap'   => 'switch_shop_subscription',
			),
			'edit_subscription_status' => array(
				'label' => __( 'Edit Subscription Status', 'skyhs-hosting-solution' ),
				'cap'   => 'edit_shop_subscription_status',
			),
			'email_campaigns'          => array(
				'label' => __( 'Email Campaigns', 'skyhs-hosting-solution' ),
				'cap'   => 'skyhshoso_manage_email_campaigns',
			),
		);
	}

	/**
	 * Hard-grant all SkyHS capabilities to Administrator.
	 * Called on every page load so admin can never lose them.
	 */
	private function enforce_admin_caps() {
		$admin = get_role( 'administrator' );
		if ( ! $admin ) {
			return;
		}

		$caps = wp_list_pluck( self::get_features(), 'cap' );

		foreach ( $caps as $cap ) {
			$admin->add_cap( $cap );
		}

		$user = wp_get_current_user();
		if ( $user->exists() && in_array( 'administrator', (array) $user->roles, true ) ) {
			foreach ( $caps as $cap ) {
				$user->add_cap( $cap );
			}
		}
	}

	/**
	 * Add Role Manager tab to Skyhs Settings.
	 *
	 * @param array $tabs
	 * @return array
	 */
	public function add_tab( $tabs ) {
		$tabs['role_manager'] = __( 'Role Manager', 'skyhs-hosting-solution' );
		return $tabs;
	}

	/**
	 * Enqueue script for select-all behavior.
	 */
	public function enqueue_scripts( $hook ) {
		if ( 'skyhshoso-dashboard_page_skyhshoso-settings' !== $hook ) {
			return;
		}

		$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';
		if ( 'role_manager' !== $current_tab ) {
			return;
		}

		wp_add_inline_script(
			'jquery',
			'jQuery(function($){
				$(".skyhshoso-select-all-column").on("change",function(){
					var idx = $(this).closest("td").index();
					var checked = $(this).prop("checked");
					$("tbody tr").not(".skyhshoso-role-admin").each(function(){
						$(this).find("td").eq(idx).find("input[type=checkbox]").prop("checked",checked);
					});
				});
			});'
		);
	}

	/**
	 * Render the Role Manager tab.
	 */
	public function render_tab() {
		$features = self::get_features();
		$roles    = wp_roles()->role_names;

		if ( isset( $_GET['role-manager-saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Role capabilities updated successfully.', 'skyhs-hosting-solution' ) . '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'skyhshoso_role_manager', 'skyhshoso_role_manager_nonce' ); ?>
			<input type="hidden" name="action" value="skyhshoso_save_role_manager">

			<div class="skyhshoso-wizard-form-group">
				<label><?php esc_html_e( 'Role Capabilities', 'skyhs-hosting-solution' ); ?></label>
				<p style="font-size:12px;color:#646970;margin:0 0 16px 0;">
					<?php esc_html_e( 'Assign capabilities to roles. Check a box to grant the role access to that feature. Uncheck to revoke.', 'skyhs-hosting-solution' ); ?>
					<br><strong><?php esc_html_e( 'Note:', 'skyhs-hosting-solution' ); ?></strong>
					<?php esc_html_e( 'Administrator always has all capabilities and cannot be modified.', 'skyhs-hosting-solution' ); ?>
				</p>

				<div style="overflow-x: auto;">
					<table class="wp-list-table widefat fixed striped skyhshoso-role-matrix" style="min-width: 900px;">
						<thead>
							<tr>
								<th style="width: 180px;"><?php esc_html_e( 'Role', 'skyhs-hosting-solution' ); ?></th>
								<?php foreach ( $features as $key => $feature ) : ?>
									<th style="text-align: center; min-width: 70px;">
										<?php echo esc_html( $feature['label'] ); ?>
									</th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $roles as $role_name => $role_label ) :
								$role = get_role( $role_name );
								if ( ! $role ) {
									continue;
								}
								$is_admin = 'administrator' === $role_name;
								$row_class = $is_admin ? 'skyhshoso-role-admin' : '';
								?>
								<tr class="<?php echo esc_attr( $row_class ); ?>">
									<td>
										<strong><?php echo esc_html( translate_user_role( $role_label ) ); ?></strong>
										<code style="font-size:10px;color:#8c8f94;display:block;"><?php echo esc_html( $role_name ); ?></code>
										<?php if ( $is_admin ) : ?>
											<span style="font-size:10px;color:#d63638;">&#128274; <?php esc_html_e( 'Locked', 'skyhs-hosting-solution' ); ?></span>
										<?php endif; ?>
									</td>
									<?php foreach ( $features as $key => $feature ) :
										$checked = $role->has_cap( $feature['cap'] ) ? 'checked' : '';
										$disabled = $is_admin ? 'disabled' : '';
										?>
										<td style="text-align: center;">
											<input type="checkbox"
												   name="skyhshoso_caps[<?php echo esc_attr( $role_name ); ?>][]"
												   value="<?php echo esc_attr( $feature['cap'] ); ?>"
													<?php echo $checked; ?>
													<?php echo $disabled; ?>>
										</td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
						<tfoot>
							<tr>
								<td>
									<strong><?php esc_html_e( 'Select All', 'skyhs-hosting-solution' ); ?></strong>
								</td>
								<?php foreach ( $features as $key => $feature ) : ?>
									<td style="text-align: center;">
										<input type="checkbox" class="skyhshoso-select-all-column">
									</td>
								<?php endforeach; ?>
							</tr>
						</tfoot>
					</table>
				</div>
			</div>

			<div class="skyhshoso-wizard-actions">
				<div></div>
				<div>
					<button type="submit" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-primary">
						<?php esc_html_e( 'Save Capabilities', 'skyhs-hosting-solution' ); ?>
					</button>
				</div>
			</div>
		</form>
		<?php
	}

	/**
	 * Save role capabilities from the matrix form.
	 */
	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'skyhs-hosting-solution' ) );
		}

		check_admin_referer( 'skyhshoso_role_manager', 'skyhshoso_role_manager_nonce' );

		$features = self::get_features();
		$all_caps = wp_list_pluck( $features, 'cap' );
		$roles    = wp_roles()->role_names;

		$submitted = isset( $_POST['skyhshoso_caps'] ) ? (array) $_POST['skyhshoso_caps'] : array();

		foreach ( $roles as $role_name => $role_label ) {
			if ( 'administrator' === $role_name ) {
				continue;
			}

			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}

			$selected_caps = isset( $submitted[ $role_name ] ) ? array_map( 'sanitize_text_field', (array) $submitted[ $role_name ] ) : array();

			foreach ( $all_caps as $cap ) {
				if ( in_array( $cap, $selected_caps, true ) ) {
					$role->add_cap( $cap );
				} else {
					$role->remove_cap( $cap );
				}
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => 'skyhshoso-settings',
					'tab'               => 'role_manager',
					'role-manager-saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}

new SkyHSHOSO_Role_Manager();
