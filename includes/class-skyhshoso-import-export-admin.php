<?php
/**
 * SkyHS Import/Export Admin Page
 *
 * Renders admin UI for exporting and importing SkyHS entity data.
 * Uses onboarding wizard CSS classes for consistent look.
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Import_Export_Admin {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_skyhshoso_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_skyhshoso_import', array( $this, 'handle_import' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Enqueue wizard CSS for consistent styling with onboarding page.
	 *
	 * @param string $hook
	 */
	public function enqueue_styles( $hook ) {
		if ( false === strpos( $hook, 'skyhshoso-import-export' )
			&& false === strpos( $hook, 'skyhshoso-settings' )
			&& false === strpos( $hook, 'skyhshoso-enom-settings' )
		) {
			return;
		}
		wp_enqueue_style(
			'skyhshoso-wizard-css',
			SKYHSHOSO_PLUGIN_URL . 'assets/css/admin-wizard.css',
			array(),
			SKYHSHOSO_VERSION
		);
	}

	public function render_page() {
		$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'export'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="skyhshoso-wizard-wrap">
			<div class="skyhshoso-wizard-header">
				<h1><?php esc_html_e( 'Import / Export', 'skyhs-hosting-solution' ); ?></h1>
				<p><?php esc_html_e( 'Transfer your SkyHS data between sites using stable UUID-based JSON files.', 'skyhs-hosting-solution' ); ?></p>
			</div>

			<div class="skyhshoso-wizard-nav">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=skyhshoso-import-export&tab=export' ) ); ?>" class="skyhshoso-wizard-step-link <?php echo 'export' === $current_tab ? 'active' : ''; ?>" style="text-decoration:none;"><?php esc_html_e( 'Export', 'skyhs-hosting-solution' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=skyhshoso-import-export&tab=import' ) ); ?>" class="skyhshoso-wizard-step-link <?php echo 'import' === $current_tab ? 'active' : ''; ?>" style="text-decoration:none;"><?php esc_html_e( 'Import', 'skyhs-hosting-solution' ); ?></a>
			</div>

			<div class="skyhshoso-wizard-content">
				<?php
				if ( 'import' === $current_tab ) {
					$this->render_import_tab();
				} else {
					$this->render_export_tab();
				}
				?>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Export Tab
	// -------------------------------------------------------------------------

	private function render_export_tab() {
		?>
		<div class="skyhshoso-wizard-step active">
			<h2><?php esc_html_e( 'Export Data', 'skyhs-hosting-solution' ); ?></h2>
			<p><?php esc_html_e( 'Export all SkyHS data as JSON. All internal IDs are replaced with UUIDs so the file can be imported into another site without conflicts.', 'skyhs-hosting-solution' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'skyhshoso_export', 'skyhshoso_export_nonce' ); ?>
				<input type="hidden" name="action" value="skyhshoso_export">

				<div class="skyhshoso-wizard-form-group">
					<label><?php esc_html_e( 'Entity Types to Export', 'skyhs-hosting-solution' ); ?></label>
					<label style="font-weight:400;margin:5px 0;"><input type="checkbox" name="export_types[]" value="users" checked> <?php esc_html_e( 'Users (shop customers / account holders)', 'skyhs-hosting-solution' ); ?></label><br>
					<label style="font-weight:400;margin:5px 0;"><input type="checkbox" name="export_types[]" value="servers" checked> <?php esc_html_e( 'Servers', 'skyhs-hosting-solution' ); ?></label><br>
					<label style="font-weight:400;margin:5px 0;"><input type="checkbox" name="export_types[]" value="products" checked> <?php esc_html_e( 'Products (Hosting / Domain)', 'skyhs-hosting-solution' ); ?></label><br>
					<label style="font-weight:400;margin:5px 0;"><input type="checkbox" name="export_types[]" value="orders" checked> <?php esc_html_e( 'Orders (subscription-related)', 'skyhs-hosting-solution' ); ?></label><br>
					<label style="font-weight:400;margin:5px 0;"><input type="checkbox" name="export_types[]" value="subscriptions" checked> <?php esc_html_e( 'Subscriptions', 'skyhs-hosting-solution' ); ?></label><br>
					<label style="font-weight:400;margin:5px 0;"><input type="checkbox" name="export_types[]" value="hosting" checked> <?php esc_html_e( 'Hosting Accounts', 'skyhs-hosting-solution' ); ?></label><br>
					<label style="font-weight:400;margin:5px 0;"><input type="checkbox" name="export_types[]" value="domains" checked> <?php esc_html_e( 'Domains', 'skyhs-hosting-solution' ); ?></label><br>
					<label style="font-weight:400;margin:5px 0;"><input type="checkbox" name="export_types[]" value="wp_sites" checked> <?php esc_html_e( 'WordPress Sites', 'skyhs-hosting-solution' ); ?></label><br>
					<label style="font-weight:400;margin:5px 0;"><input type="checkbox" name="export_types[]" value="settings" checked> <?php esc_html_e( 'Plugin Settings & Credentials (General, eNom, Customize)', 'skyhs-hosting-solution' ); ?></label>
				</div>


				<div class="skyhshoso-wizard-actions">
					<div></div>
					<div>
						<button type="submit" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-primary"><?php esc_html_e( 'Generate Export', 'skyhs-hosting-solution' ); ?></button>
					</div>
				</div>
			</form>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Import Tab
	// -------------------------------------------------------------------------

	private function render_import_tab() {
		$import_results = get_transient( 'skyhshoso_import_results' );
		delete_transient( 'skyhshoso_import_results' );
		?>
		<div class="skyhshoso-wizard-step active">
			<h2><?php esc_html_e( 'Import Data', 'skyhs-hosting-solution' ); ?></h2>
			<p><?php esc_html_e( 'Upload a previously exported JSON file. Records matching existing UUIDs are skipped. Import order: Users → Servers → Products → Orders → Subscriptions → Hosting → Domains.', 'skyhs-hosting-solution' ); ?></p>

			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'skyhshoso_import', 'skyhshoso_import_nonce' ); ?>
				<input type="hidden" name="action" value="skyhshoso_import">
				<input type="hidden" name="MAX_FILE_SIZE" value="104857600">

				<div class="skyhshoso-wizard-form-group">
					<label><?php esc_html_e( 'Select JSON File', 'skyhs-hosting-solution' ); ?></label>
					<input type="file" name="import_file" accept=".json" required style="padding:8px 0;">
				</div>

				<div class="skyhshoso-wizard-actions">
					<div></div>
					<div>
						<button type="submit" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-primary"><?php esc_html_e( 'Upload and Import', 'skyhs-hosting-solution' ); ?></button>
					</div>
				</div>
			</form>

			<?php
			if ( $import_results && is_array( $import_results ) ) {
				$this->render_import_results( $import_results );
			}
			?>
		</div>
		<?php
	}

	private function render_import_results( $results ) {
		if ( isset( $results['error'] ) ) {
			echo '<div class="skyhshoso-wizard-notice error" style="display:block;">' . esc_html( $results['error'] ) . '</div>';
			return;
		}

		$totals = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0 );
		$labels = array(
			'users'         => __( 'Users', 'skyhs-hosting-solution' ),
			'servers'       => __( 'Servers', 'skyhs-hosting-solution' ),
			'products'      => __( 'Products', 'skyhs-hosting-solution' ),
			'orders'        => __( 'Orders', 'skyhs-hosting-solution' ),
			'subscriptions' => __( 'Subscriptions', 'skyhs-hosting-solution' ),
			'hosting'       => __( 'Hosting', 'skyhs-hosting-solution' ),
			'domains'       => __( 'Domains', 'skyhs-hosting-solution' ),
			'wp_sites'      => __( 'WordPress Sites', 'skyhs-hosting-solution' ),
			'settings'      => __( 'Plugin Settings', 'skyhs-hosting-solution' ),
		);

		$has_errors = false;
		?>
		<div style="margin-top:30px;padding-top:20px;border-top:1px solid #dcdcde;">
			<h3><?php esc_html_e( 'Import Results', 'skyhs-hosting-solution' ); ?></h3>
			<table class="widefat striped" style="max-width:700px;">
				<thead><tr><th><?php esc_html_e( 'Entity', 'skyhs-hosting-solution' ); ?></th><th><?php esc_html_e( 'Created', 'skyhs-hosting-solution' ); ?></th><th><?php esc_html_e( 'Updated', 'skyhs-hosting-solution' ); ?></th><th><?php esc_html_e( 'Skipped', 'skyhs-hosting-solution' ); ?></th><th><?php esc_html_e( 'Errors', 'skyhs-hosting-solution' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $labels as $key => $label ) : ?>
					<?php
					$r = isset( $results[ $key ] ) ? $results[ $key ] : array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() );
					$totals['created'] += $r['created'];
					$totals['updated'] += $r['updated'];
					$totals['skipped'] += $r['skipped'];
					$ec = count( $r['errors'] );
					$totals['errors'] += $ec;
					if ( $ec > 0 ) $has_errors = true;
					?>
					<tr><td><?php echo esc_html( $label ); ?></td><td><?php echo intval( $r['created'] ); ?></td><td><?php echo intval( $r['updated'] ); ?></td><td><?php echo intval( $r['skipped'] ); ?></td><td><?php echo intval( $ec ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
				<tfoot><tr><th><?php esc_html_e( 'Total', 'skyhs-hosting-solution' ); ?></th><th><?php echo intval( $totals['created'] ); ?></th><th><?php echo intval( $totals['updated'] ); ?></th><th><?php echo intval( $totals['skipped'] ); ?></th><th><?php echo intval( $totals['errors'] ); ?></th></tr></tfoot>
			</table>

			<?php if ( $has_errors ) : ?>
				<h4><?php esc_html_e( 'Error Details', 'skyhs-hosting-solution' ); ?></h4>
				<ul style="max-height:300px;overflow-y:auto;background:#fcf0f1;border:1px solid #d63638;border-radius:4px;padding:10px 20px;">
				<?php foreach ( $labels as $key => $label ) : ?>
					<?php foreach ( ( $results[ $key ]['errors'] ?? array() ) as $err ) : ?>
						<li style="color:#d63638;">[<?php echo esc_html( $label ); ?>] <?php echo esc_html( $err ); ?></li>
					<?php endforeach; ?>
				<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Action Handlers
	// -------------------------------------------------------------------------

	public function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'skyhs-hosting-solution' ) );
		}
		check_admin_referer( 'skyhshoso_export', 'skyhshoso_export_nonce' );

		try {
			// Auto-backfill any records missing UUIDs before export.
			SkyHSHOSO_UUID::backfill_batch( 500 );

			$types  = isset( $_POST['export_types'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['export_types'] ) ) : array();
			$export = SkyHSHOSO_Export::export_all( $types );


			$export = SkyHSHOSO_Export::replace_ids_with_uuids( $export );
			$json   = SkyHSHOSO_Export::generate_json( $export );
			SkyHSHOSO_Export::download_file( $json );

		} catch ( Throwable $e ) {
			set_transient( 'skyhshoso_import_results', array( 'error' => 'Export failed: ' . $e->getMessage() ), 60 );
			wp_safe_redirect( admin_url( 'admin.php?page=skyhshoso-import-export&tab=export' ) );
			exit;
		}
	}

	public function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'skyhs-hosting-solution' ) );
		}
		check_admin_referer( 'skyhshoso_import', 'skyhshoso_import_nonce' );

		if ( empty( $_FILES['import_file'] ) ) {
			set_transient( 'skyhshoso_import_results', array( 'error' => 'No file uploaded.' ), 60 );
			$this->redirect_import();
		}

		$file = $_FILES['import_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			set_transient( 'skyhshoso_import_results', array( 'error' => 'File upload error: ' . $file['error'] ), 60 );
			$this->redirect_import();
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'json' !== $ext ) {
			set_transient( 'skyhshoso_import_results', array( 'error' => 'Only .json files are supported.' ), 60 );
			$this->redirect_import();
		}

		try {
			$importer = new SkyHSHOSO_Import();
			$results  = $importer->import_from_file( $file['tmp_name'] );
			set_transient( 'skyhshoso_import_results', $results, 120 );
		} catch ( Throwable $e ) {
			set_transient( 'skyhshoso_import_results', array( 'error' => 'Import failed: ' . $e->getMessage() ), 60 );
		}

		$this->redirect_import();
	}

	private function redirect_import() {
		wp_safe_redirect( admin_url( 'admin.php?page=skyhshoso-import-export&tab=import' ) );
		exit;
	}
}
