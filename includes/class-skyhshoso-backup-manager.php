<?php
/**
 * Backup Manager for SkyHS Hosting Solution
 *
 * Handles automatic scheduled backups, manual backups, secure backup storage,
 * and one-click restores.
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Backup_Manager {

	/**
	 * Initialize the backup manager hooks.
	 */
	public static function init() {
		// Hook WP-Cron auto backup event
		add_action( 'skyhshoso_run_auto_backup', array( __CLASS__, 'run_auto_backup' ) );

		// Register custom cron schedules
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedules' ) );

		// Hook admin POST action handlers
		add_action( 'admin_post_skyhshoso_save_backup_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_skyhshoso_create_backup', array( __CLASS__, 'handle_create_backup' ) );
		add_action( 'admin_post_skyhshoso_delete_backup', array( __CLASS__, 'handle_delete_backup' ) );
		add_action( 'admin_post_skyhshoso_download_backup', array( __CLASS__, 'handle_download_backup' ) );
		add_action( 'admin_post_skyhshoso_import_backup', array( __CLASS__, 'handle_import_backup' ) );

		// Register settings
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );

		// Enqueue scripts/styles
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
	}

	/**
	 * Register custom schedules for weekly and monthly crons.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function register_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['skyhshoso_weekly'] ) ) {
			$schedules['skyhshoso_weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'skyhs-hosting-solution' ),
			);
		}
		if ( ! isset( $schedules['skyhshoso_monthly'] ) ) {
			$schedules['skyhshoso_monthly'] = array(
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( 'Once Monthly', 'skyhs-hosting-solution' ),
			);
		}
		return $schedules;
	}

	/**
	 * Register backup manager settings.
	 */
	public static function register_settings() {
		register_setting( 'skyhshoso_backup_settings', 'skyhshoso_backup_enabled' );
		register_setting( 'skyhshoso_backup_settings', 'skyhshoso_backup_frequency' );
		register_setting( 'skyhshoso_backup_settings', 'skyhshoso_backup_email_enabled' );
		register_setting( 'skyhshoso_backup_settings', 'skyhshoso_backup_email_address' );
	}

	/**
	 * Enqueue administration page styles and scripts.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public static function enqueue_admin_assets( $hook ) {
		if ( false === strpos( $hook, 'skyhshoso-backups' ) ) {
			return;
		}

		wp_enqueue_style(
			'skyhshoso-wizard-css',
			SKYHSHOSO_PLUGIN_URL . 'assets/css/admin-wizard.css',
			array(),
			SKYHSHOSO_VERSION
		);

		wp_add_inline_script(
			'jquery',
			'jQuery(document).ready(function($) {
				function toggleEmailField() {
					if ($("#skyhshoso_backup_email_enabled").is(":checked")) {
						$("#skyhshoso_email_field_group").slideDown();
					} else {
						$("#skyhshoso_email_field_group").slideUp();
					}
				}
				$("#skyhshoso_backup_email_enabled").on("change", toggleEmailField);
				toggleEmailField();

				$(".skyhshoso-confirm-delete").on("click", function(e) {
					if (!confirm("' . esc_js( __( 'Are you sure you want to permanently delete this backup file?', 'skyhs-hosting-solution' ) ) . '")) {
						e.preventDefault();
					}
				});

				$(".skyhshoso-confirm-restore").on("click", function(e) {
					if (!confirm("' . esc_js( __( 'WARNING: Restoring this backup will overwrite existing plugin data. Are you sure you want to proceed?', 'skyhs-hosting-solution' ) ) . '")) {
						e.preventDefault();
					}
				});
			});'
		);
	}

	/**
	 * Callback triggered by WP-Cron to run the scheduled auto-backup.
	 */
	public static function run_auto_backup() {
		if ( 'yes' !== get_option( 'skyhshoso_backup_enabled', 'no' ) ) {
			return;
		}
		self::create_local_backup( false );
	}

	/**
	 * Create and write a backup JSON file to the secure local uploads directory.
	 *
	 * @param bool $is_manual Whether the backup was triggered manually by an admin.
	 * @return string|WP_Error The created filename on success, or WP_Error on failure.
	 */
	public static function create_local_backup( $is_manual = false ) {
		try {
			// Backfill any records that might be missing UUIDs.
			if ( class_exists( 'SkyHSHOSO_UUID' ) ) {
				SkyHSHOSO_UUID::backfill_batch( 500 );
			}

			// Generate the complete raw export data.
			$types = array( 'users', 'servers', 'products', 'orders', 'subscriptions', 'hosting', 'domains', 'wp_sites', 'settings' );
			$export = SkyHSHOSO_Export::export_all( $types );


			// Prepare file representation.
			$export = SkyHSHOSO_Export::replace_ids_with_uuids( $export );
			$json = SkyHSHOSO_Export::generate_json( $export );

			// Determine secure directory paths.
			$upload_dir = wp_upload_dir();
			if ( ! empty( $upload_dir['error'] ) ) {
				return new WP_Error( 'upload_dir_error', $upload_dir['error'] );
			}

			$backup_dir = $upload_dir['basedir'] . '/skyhshoso-backups';

			// Ensure folder is created.
			if ( ! file_exists( $backup_dir ) ) {
				wp_mkdir_p( $backup_dir );
			}

			// Secure folder with .htaccess and empty index.html.
			if ( ! file_exists( $backup_dir . '/.htaccess' ) ) {
				file_put_contents( $backup_dir . '/.htaccess', "Deny from all" );
			}
			if ( ! file_exists( $backup_dir . '/index.html' ) ) {
				file_put_contents( $backup_dir . '/index.html', "" );
			}

			// Generate unique name using 16-character random hex string.
			$random_suffix = bin2hex( random_bytes( 8 ) );
			$filename = sprintf( 'skyhs-backup-%s-%s.json', gmdate( 'Y-m-d-His' ), $random_suffix );
			$filepath = $backup_dir . '/' . $filename;

			// Write to file.
			$result = file_put_contents( $filepath, $json );
			if ( false === $result ) {
				throw new Exception( __( 'Failed to write backup file contents.', 'skyhs-hosting-solution' ) );
			}

			$log_message = sprintf(
				$is_manual ? __( 'Manual backup created: %s', 'skyhs-hosting-solution' ) : __( 'Automatic backup created: %s', 'skyhs-hosting-solution' ),
				$filename
			);
			SkyHSHOSO_Activity_Log::log( 'backup', $log_message, 'success' );

			// Process optional email attachments.
			$email_enabled = get_option( 'skyhshoso_backup_email_enabled', 'no' );
			$email_address = get_option( 'skyhshoso_backup_email_address', '' );
			if ( 'yes' === $email_enabled && is_email( $email_address ) ) {
				$subject = sprintf( __( '[SkyHS] Backup Generated - %s', 'skyhs-hosting-solution' ), gmdate( 'Y-m-d' ) );
				$body = sprintf(
					__( "Hello,\n\nAn automated backup of your SkyHS data has been successfully generated.\n\nFile Name: %s\nFile Size: %s\nGenerated At: %s UTC\n\nPlease find the JSON backup attached to this email.\n\nBest Regards,\nSkyHS", 'skyhs-hosting-solution' ),
					$filename,
					size_format( strlen( $json ) ),
					gmdate( 'Y-m-d H:i:s' )
				);
				$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
				$attachments = array( $filepath );
				
				$mail_sent = wp_mail( $email_address, $subject, $body, $headers, $attachments );
				if ( ! $mail_sent ) {
					SkyHSHOSO_Activity_Log::log( 'backup', sprintf( __( 'Failed sending backup email to %s.', 'skyhs-hosting-solution' ), $email_address ), 'warning' );
				}
			}

			return $filename;

		} catch ( Exception $e ) {
			$err_msg = __( 'Backup execution error: ', 'skyhs-hosting-solution' ) . $e->getMessage();
			SkyHSHOSO_Activity_Log::log( 'backup', $err_msg, 'error' );
			return new WP_Error( 'backup_failed', $err_msg );
		}
	}

	/**
	 * Fetch lists of existing backup files sorted by creation date.
	 *
	 * @return array Existing backups detail array.
	 */
	public static function get_backup_files() {
		$upload_dir = wp_upload_dir();
		$backup_dir = $upload_dir['basedir'] . '/skyhshoso-backups';

		if ( ! file_exists( $backup_dir ) ) {
			return array();
		}

		$files = glob( $backup_dir . '/skyhs-backup-*.json' );
		if ( ! $files ) {
			return array();
		}

		$backups = array();
		foreach ( $files as $file ) {
			$filename = basename( $file );
			$date_str = '';
			
			// Extract date from filename format: skyhs-backup-YYYY-MM-DD-HHMMSS-[hex].json
			if ( preg_match( '/skyhs-backup-(\d{4}-\d{2}-\d{2}-\d{6})/', $filename, $matches ) ) {
				$parsed_time = DateTime::createFromFormat( 'Y-m-d-His', $matches[1], new DateTimeZone( 'UTC' ) );
				if ( $parsed_time ) {
					$parsed_time->setTimezone( wp_timezone() );
					$date_str = $parsed_time->format( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
				}
			}

			if ( ! $date_str ) {
				$date_str = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), filemtime( $file ) );
			}

			$backups[] = array(
				'filename'     => $filename,
				'size'         => size_format( filesize( $file ) ),
				'date_created' => $date_str,
				'timestamp'    => filemtime( $file ),
			);
		}

		// Sort: Newest backups first
		usort( $backups, function( $a, $b ) {
			return $b['timestamp'] - $a['timestamp'];
		} );

		return $backups;
	}

	/**
	 * Handle settings saving from post form.
	 */
	public static function handle_save_settings() {
		if ( ! current_user_can( 'skyhshoso_manage_backups' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage backups.', 'skyhs-hosting-solution' ) );
		}
		check_admin_referer( 'skyhshoso_save_backup_settings', 'skyhshoso_backup_nonce' );

		$enabled       = isset( $_POST['skyhshoso_backup_enabled'] ) ? 'yes' : 'no';
		$frequency     = isset( $_POST['skyhshoso_backup_frequency'] ) ? sanitize_text_field( wp_unslash( $_POST['skyhshoso_backup_frequency'] ) ) : 'daily';
		$email_enabled = isset( $_POST['skyhshoso_backup_email_enabled'] ) ? 'yes' : 'no';
		$email_address = isset( $_POST['skyhshoso_backup_email_address'] ) ? sanitize_email( wp_unslash( $_POST['skyhshoso_backup_email_address'] ) ) : '';

		update_option( 'skyhshoso_backup_enabled', $enabled );
		update_option( 'skyhshoso_backup_frequency', $frequency );
		update_option( 'skyhshoso_backup_email_enabled', $email_enabled );
		update_option( 'skyhshoso_backup_email_address', $email_address );

		self::reschedule_backup_cron();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'skyhshoso-backups',
					'settings-saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Trigger manual backup creation.
	 */
	public static function handle_create_backup() {
		if ( ! current_user_can( 'skyhshoso_manage_backups' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage backups.', 'skyhs-hosting-solution' ) );
		}
		check_admin_referer( 'skyhshoso_create_backup', 'skyhshoso_backup_nonce' );

		$result = self::create_local_backup( true );

		$args = array( 'page' => 'skyhshoso-backups' );
		if ( is_wp_error( $result ) ) {
			$args['backup-created'] = '0';
			set_transient( 'skyhshoso_backup_error', $result->get_error_message(), 60 );
		} else {
			$args['backup-created'] = '1';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Safely delete a backup file.
	 */
	public static function handle_delete_backup() {
		if ( ! current_user_can( 'skyhshoso_manage_backups' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage backups.', 'skyhs-hosting-solution' ) );
		}
		check_admin_referer( 'skyhshoso_delete_backup', 'skyhshoso_backup_nonce' );

		$filename = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		if ( ! preg_match( '/^skyhs-backup-\d{4}-\d{2}-\d{2}-\d{6}-[a-f0-9]+\.json$/i', $filename ) ) {
			wp_die( esc_html__( 'Invalid backup filename format.', 'skyhs-hosting-solution' ) );
		}

		$upload_dir = wp_upload_dir();
		$backup_dir = $upload_dir['basedir'] . '/skyhshoso-backups';
		$filepath = $backup_dir . '/' . $filename;

		if ( file_exists( $filepath ) ) {
			unlink( $filepath );
			SkyHSHOSO_Activity_Log::log( 'backup', sprintf( __( 'Backup file %s deleted.', 'skyhs-hosting-solution' ), $filename ), 'info' );
			wp_safe_redirect( add_query_arg( array( 'page' => 'skyhshoso-backups', 'backup-deleted' => '1' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'skyhshoso-backups', 'backup-deleted' => '0' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Stream backup file download to admin browser.
	 */
	public static function handle_download_backup() {
		if ( ! current_user_can( 'skyhshoso_manage_backups' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage backups.', 'skyhs-hosting-solution' ) );
		}
		check_admin_referer( 'skyhshoso_download_backup', 'skyhshoso_backup_nonce' );

		$filename = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		if ( ! preg_match( '/^skyhs-backup-\d{4}-\d{2}-\d{2}-\d{6}-[a-f0-9]+\.json$/i', $filename ) ) {
			wp_die( esc_html__( 'Invalid backup filename format.', 'skyhs-hosting-solution' ) );
		}

		$upload_dir = wp_upload_dir();
		$backup_dir = $upload_dir['basedir'] . '/skyhshoso-backups';
		$filepath = $backup_dir . '/' . $filename;

		if ( ! file_exists( $filepath ) ) {
			wp_die( esc_html__( 'Backup file not found.', 'skyhs-hosting-solution' ) );
		}

		// Stream download
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="' . esc_attr( $filename ) . '"' );
		header( 'Content-Length: ' . filesize( $filepath ) );
		readfile( $filepath );
		exit;
	}

	/**
	 * Perform one-click import/restore from a local backup file.
	 */
	public static function handle_import_backup() {
		if ( ! current_user_can( 'skyhshoso_manage_backups' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage backups.', 'skyhs-hosting-solution' ) );
		}
		check_admin_referer( 'skyhshoso_import_backup', 'skyhshoso_backup_nonce' );

		$filename = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		if ( ! preg_match( '/^skyhs-backup-\d{4}-\d{2}-\d{2}-\d{6}-[a-f0-9]+\.json$/i', $filename ) ) {
			wp_die( esc_html__( 'Invalid backup filename format.', 'skyhs-hosting-solution' ) );
		}

		$upload_dir = wp_upload_dir();
		$backup_dir = $upload_dir['basedir'] . '/skyhshoso-backups';
		$filepath = $backup_dir . '/' . $filename;

		if ( ! file_exists( $filepath ) ) {
			wp_die( esc_html__( 'Backup file not found.', 'skyhs-hosting-solution' ) );
		}

		try {
			$importer = new SkyHSHOSO_Import();
			$results = $importer->import_from_file( $filepath );
			set_transient( 'skyhshoso_backup_restore_results', $results, 120 );
			
			SkyHSHOSO_Activity_Log::log( 'backup', sprintf( __( 'One-click restore executed from %s.', 'skyhs-hosting-solution' ), $filename ), 'success' );
		} catch ( Throwable $e ) {
			set_transient( 'skyhshoso_backup_restore_results', array( 'error' => __( 'Restore failed: ', 'skyhs-hosting-solution' ) . $e->getMessage() ), 120 );
			SkyHSHOSO_Activity_Log::log( 'backup', sprintf( __( 'Restore failed from %s. Error: %s', 'skyhs-hosting-solution' ), $filename, $e->getMessage() ), 'error' );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'skyhshoso-backups', 'backup-imported' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Reschedule the auto backup cron job based on frequency settings.
	 */
	public static function reschedule_backup_cron() {
		self::unschedule_backup_cron();

		$enabled = get_option( 'skyhshoso_backup_enabled', 'no' );
		if ( 'yes' !== $enabled ) {
			return;
		}

		$frequency = get_option( 'skyhshoso_backup_frequency', 'daily' );
		$recurrence = 'daily';
		if ( 'weekly' === $frequency ) {
			$recurrence = 'skyhshoso_weekly';
		} elseif ( 'monthly' === $frequency ) {
			$recurrence = 'skyhshoso_monthly';
		}

		// Schedule first run 1 minute in the future
		wp_schedule_event( time() + 60, $recurrence, 'skyhshoso_run_auto_backup' );
	}

	/**
	 * Unschedule the auto backup cron job.
	 */
	public static function unschedule_backup_cron() {
		wp_clear_scheduled_hook( 'skyhshoso_run_auto_backup' );
	}

	/**
	 * Render the premium administration page view.
	 */
	public static function render_page() {
		$enabled       = get_option( 'skyhshoso_backup_enabled', 'no' );
		$frequency     = get_option( 'skyhshoso_backup_frequency', 'daily' );
		$email_enabled = get_option( 'skyhshoso_backup_email_enabled', 'no' );
		$email_address = get_option( 'skyhshoso_backup_email_address', '' );

		$backups = self::get_backup_files();
		$restore_results = get_transient( 'skyhshoso_backup_restore_results' );
		delete_transient( 'skyhshoso_backup_restore_results' );
		
		$backup_error = get_transient( 'skyhshoso_backup_error' );
		delete_transient( 'skyhshoso_backup_error' );
		?>
		<style>
			.skyhshoso-backup-header {
				background: linear-gradient(135deg, #2271b1 0%, #135e96 100%);
				color: #ffffff;
				border-radius: 12px;
				padding: 30px 40px;
				margin-bottom: 30px;
				box-shadow: 0 4px 20px rgba(34, 113, 177, 0.15);
				position: relative;
				overflow: hidden;
			}
			.skyhshoso-backup-header-title {
				color: #ffffff !important;
				font-size: 28px !important;
				font-weight: 700 !important;
				margin: 0 0 10px 0 !important;
				line-height: 1.2 !important;
			}
			.skyhshoso-backup-header p {
				color: #e0e7ff;
				font-size: 15px;
				margin: 0;
				max-width: 600px;
				line-height: 1.5;
			}
			.skyhshoso-backup-grid {
				display: grid;
				grid-template-columns: 1.5fr 1fr;
				gap: 24px;
				margin-bottom: 30px;
			}
			@media (max-width: 1024px) {
				.skyhshoso-backup-grid {
					grid-template-columns: 1fr;
				}
			}
			.skyhshoso-backup-card {
				background: #ffffff;
				border-radius: 12px;
				box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
				border: 1px solid #e2e8f0;
				padding: 24px;
				box-sizing: border-box;
			}
			.skyhshoso-backup-card h2 {
				font-size: 18px !important;
				font-weight: 600 !important;
				margin: 0 0 20px 0 !important;
				color: #1e293b !important;
				border-bottom: 1px solid #f1f5f9;
				padding-bottom: 12px;
			}
			.skyhshoso-form-group {
				margin-bottom: 20px;
			}
			.skyhshoso-form-group label.skyhshoso-field-label {
				display: block;
				font-weight: 600;
				margin-bottom: 8px;
				color: #334155;
			}
			.skyhshoso-form-group p.description {
				font-size: 12px;
				color: #64748b;
				margin: 6px 0 0 0;
			}
			.skyhshoso-switch-container {
				display: flex;
				align-items: center;
				justify-content: space-between;
				background: #f8fafc;
				padding: 12px 16px;
				border-radius: 8px;
				border: 1px solid #f1f5f9;
				margin-bottom: 16px;
			}
			.skyhshoso-switch-label {
				font-weight: 500;
				color: #334155;
			}
			/* Switch styling */
			.skyhshoso-switch {
				position: relative;
				display: inline-block;
				width: 48px;
				height: 24px;
			}
			.skyhshoso-switch input {
				position: absolute;
				opacity: 0;
				width: 100%;
				height: 100%;
				top: 0;
				left: 0;
				margin: 0;
				cursor: pointer;
				z-index: 2;
			}
			.skyhshoso-slider {
				position: absolute;
				cursor: pointer;
				top: 0;
				left: 0;
				right: 0;
				bottom: 0;
				background-color: #cbd5e1;
				transition: .3s;
				border-radius: 24px;
				z-index: 1;
			}
			.skyhshoso-slider:before {
				position: absolute;
				content: "";
				height: 18px;
				width: 18px;
				left: 3px;
				bottom: 3px;
				background-color: white;
				transition: .3s;
				border-radius: 50%;
				z-index: 2;
			}
			input:checked + .skyhshoso-slider {
				background-color: #2271b1;
			}
			input:checked + .skyhshoso-slider:before {
				transform: translateX(24px);
			}
			
			.skyhshoso-select, .skyhshoso-input-text {
				width: 100%;
				height: 40px;
				padding: 8px 12px;
				border-radius: 8px;
				border: 1px solid #cbd5e1;
				background-color: #ffffff;
				color: #334155;
				font-size: 14px;
				box-shadow: inset 0 1px 2px rgba(0,0,0,0.05);
			}
			.skyhshoso-select:focus, .skyhshoso-input-text:focus {
				border-color: #2271b1;
				outline: none;
				box-shadow: 0 0 0 3px rgba(34, 113, 177, 0.15);
			}
			.skyhshoso-btn {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				padding: 10px 20px;
				border-radius: 8px;
				font-weight: 600;
				font-size: 14px;
				cursor: pointer;
				transition: all 0.2s ease;
				border: 1px solid transparent;
				text-decoration: none;
			}
			.skyhshoso-btn-primary {
				background: #2271b1;
				color: #ffffff !important;
			}
			.skyhshoso-btn-primary:hover {
				background: #135e96;
			}
			.skyhshoso-btn-secondary {
				background: #f8fafc;
				color: #475569 !important;
				border-color: #cbd5e1;
			}
			.skyhshoso-btn-secondary:hover {
				background: #f1f5f9;
				border-color: #94a3b8;
			}
			.skyhshoso-btn-danger {
				background: #ef4444;
				color: #ffffff !important;
			}
			.skyhshoso-btn-danger:hover {
				background: #dc2626;
			}
			.skyhshoso-btn-sm {
				padding: 6px 12px;
				font-size: 12px;
				border-radius: 6px;
			}
			.skyhshoso-btn-group {
				display: flex;
				gap: 8px;
			}
			.skyhshoso-badge {
				display: inline-flex;
				align-items: center;
				padding: 4px 8px;
				border-radius: 9999px;
				font-size: 11px;
				font-weight: 600;
			}
			.skyhshoso-badge-success {
				background-color: #dcfce7;
				color: #166534;
			}
			.skyhshoso-badge-danger {
				background-color: #fee2e2;
				color: #991b1b;
			}
			.skyhshoso-badge-info {
				background-color: #dbeafe;
				color: #1e40af;
			}
			.skyhshoso-table-container {
				background: #ffffff;
				border-radius: 12px;
				border: 1px solid #e2e8f0;
				box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
				overflow: hidden;
			}
			.skyhshoso-table {
				width: 100%;
				border-collapse: collapse;
				margin: 0;
			}
			.skyhshoso-table th {
				background: #f8fafc;
				color: #475569;
				font-weight: 600;
				font-size: 13px;
				padding: 14px 20px;
				text-align: left;
				border-bottom: 2px solid #e2e8f0;
			}
			.skyhshoso-table td {
				padding: 16px 20px;
				border-bottom: 1px solid #f1f5f9;
				color: #334155;
				font-size: 14px;
				vertical-align: middle;
			}
			.skyhshoso-table tr:last-child td {
				border-bottom: none;
			}
			.skyhshoso-table tr:hover td {
				background-color: #f8fafc;
			}
			.skyhshoso-notice-custom {
				padding: 16px 20px;
				border-radius: 8px;
				margin-bottom: 24px;
				font-size: 14px;
				font-weight: 500;
				display: flex;
				align-items: center;
				justify-content: space-between;
			}
			.skyhshoso-notice-success {
				background-color: #f0fdf4;
				border: 1px solid #bbf7d0;
				color: #166534;
			}
			.skyhshoso-notice-error {
				background-color: #fef2f2;
				border: 1px solid #fecaca;
				color: #991b1b;
			}
		</style>

		<div class="wrap skyhshoso-backup-wrapper" style="max-width: 1200px; margin: 20px auto;">
			<h1 class="skyhshoso-admin-header-hidden" style="display: none;"><?php esc_html_e( 'Backup & One-Click Restore', 'skyhs-hosting-solution' ); ?></h1>
			<hr class="wp-header-end">
			
			<div class="skyhshoso-backup-header">
				<div class="skyhshoso-backup-header-title"><?php esc_html_e( 'Backup & One-Click Restore', 'skyhs-hosting-solution' ); ?></div>
				<p><?php esc_html_e( 'Securely manage database backups, schedule automated snapshots, and restore site configurations in a single click.', 'skyhs-hosting-solution' ); ?></p>
			</div>

			<?php
			// Process notice parameters.
			if ( isset( $_GET['settings-saved'] ) ) {
				echo '<div class="skyhshoso-notice-custom skyhshoso-notice-success"><div><span class="dashicons dashicons-yes" style="vertical-align: middle; margin-right: 8px;"></span>' . esc_html__( 'Backup settings saved and updated successfully.', 'skyhs-hosting-solution' ) . '</div></div>';
			}
			if ( isset( $_GET['backup-created'] ) ) {
				if ( '1' === $_GET['backup-created'] ) {
					echo '<div class="skyhshoso-notice-custom skyhshoso-notice-success"><div><span class="dashicons dashicons-yes" style="vertical-align: middle; margin-right: 8px;"></span>' . esc_html__( 'New backup file successfully generated and saved to secure storage.', 'skyhs-hosting-solution' ) . '</div></div>';
				} else {
					$msg = $backup_error ? $backup_error : esc_html__( 'Failed to generate backup.', 'skyhs-hosting-solution' );
					echo '<div class="skyhshoso-notice-custom skyhshoso-notice-error"><div><span class="dashicons dashicons-dismiss" style="vertical-align: middle; margin-right: 8px;"></span>' . esc_html( $msg ) . '</div></div>';
				}
			}
			if ( isset( $_GET['backup-deleted'] ) ) {
				if ( '1' === $_GET['backup-deleted'] ) {
					echo '<div class="skyhshoso-notice-custom skyhshoso-notice-success"><div><span class="dashicons dashicons-yes" style="vertical-align: middle; margin-right: 8px;"></span>' . esc_html__( 'Backup file deleted successfully.', 'skyhs-hosting-solution' ) . '</div></div>';
				} else {
					echo '<div class="skyhshoso-notice-custom skyhshoso-notice-error"><div><span class="dashicons dashicons-dismiss" style="vertical-align: middle; margin-right: 8px;"></span>' . esc_html__( 'Failed to delete backup file. File might not exist.', 'skyhs-hosting-solution' ) . '</div></div>';
				}
			}
			if ( isset( $_GET['backup-imported'] ) ) {
				echo '<div class="skyhshoso-notice-custom skyhshoso-notice-success"><div><span class="dashicons dashicons-yes" style="vertical-align: middle; margin-right: 8px;"></span>' . esc_html__( 'Backup restore operation executed.', 'skyhs-hosting-solution' ) . '</div></div>';
			}

			// Render import results if present
			if ( $restore_results && is_array( $restore_results ) ) {
				self::render_restore_results( $restore_results );
			}
			?>

			<div class="skyhshoso-backup-grid">
				
				<!-- Left Card: Settings Form -->
				<div class="skyhshoso-backup-card">
					<h2><?php esc_html_e( 'Auto-Backup Settings', 'skyhs-hosting-solution' ); ?></h2>
					
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'skyhshoso_save_backup_settings', 'skyhshoso_backup_nonce' ); ?>
						<input type="hidden" name="action" value="skyhshoso_save_backup_settings">

						<!-- Auto Backup Enable Toggle -->
						<div class="skyhshoso-switch-container">
							<span class="skyhshoso-switch-label"><?php esc_html_e( 'Enable Automated Backups', 'skyhs-hosting-solution' ); ?></span>
							<label class="skyhshoso-switch">
								<input type="checkbox" id="skyhshoso_backup_enabled" name="skyhshoso_backup_enabled" value="yes" <?php checked( $enabled, 'yes' ); ?>>
								<span class="skyhshoso-slider"></span>
							</label>
						</div>

						<!-- Frequency selection -->
						<div class="skyhshoso-form-group">
							<label class="skyhshoso-field-label" for="skyhshoso_backup_frequency"><?php esc_html_e( 'Backup Frequency', 'skyhs-hosting-solution' ); ?></label>
							<select class="skyhshoso-select" id="skyhshoso_backup_frequency" name="skyhshoso_backup_frequency">
								<option value="daily" <?php selected( $frequency, 'daily' ); ?>><?php esc_html_e( 'Daily', 'skyhs-hosting-solution' ); ?></option>
								<option value="weekly" <?php selected( $frequency, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'skyhs-hosting-solution' ); ?></option>
								<option value="monthly" <?php selected( $frequency, 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'skyhs-hosting-solution' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Select how often WordPress will run the background task to snapshot plugin configuration and records.', 'skyhs-hosting-solution' ); ?></p>
						</div>

						<!-- Email notifications toggle -->
						<div class="skyhshoso-switch-container">
							<span class="skyhshoso-switch-label"><?php esc_html_e( 'Send Backup to Email', 'skyhs-hosting-solution' ); ?></span>
							<label class="skyhshoso-switch">
								<input type="checkbox" id="skyhshoso_backup_email_enabled" name="skyhshoso_backup_email_enabled" value="yes" <?php checked( $email_enabled, 'yes' ); ?>>
								<span class="skyhshoso-slider"></span>
							</label>
						</div>

						<!-- Email Address Input -->
						<div class="skyhshoso-form-group" id="skyhshoso_email_field_group" style="<?php echo 'yes' === $email_enabled ? '' : 'display:none;'; ?>">
							<label class="skyhshoso-field-label" for="skyhshoso_backup_email_address"><?php esc_html_e( 'Recipient Email Address', 'skyhs-hosting-solution' ); ?></label>
							<input type="email" class="skyhshoso-input-text" id="skyhshoso_backup_email_address" name="skyhshoso_backup_email_address" value="<?php echo esc_attr( $email_address ); ?>" placeholder="admin@example.com">
							<p class="description"><?php esc_html_e( 'Backup files will be sent as email attachments to this address whenever a scheduled backup runs.', 'skyhs-hosting-solution' ); ?></p>
						</div>

						<button type="submit" class="skyhshoso-btn skyhshoso-btn-primary">
							<?php esc_html_e( 'Save Settings', 'skyhs-hosting-solution' ); ?>
						</button>
					</form>
				</div>

				<!-- Right Card: Manual Actions & Disk Info -->
				<div class="skyhshoso-backup-card" style="display:flex; flex-direction:column; justify-content:space-between;">
					<div>
						<h2><?php esc_html_e( 'Quick Tasks', 'skyhs-hosting-solution' ); ?></h2>
						
						<div style="background:#f8fafc; border-radius:8px; padding:16px; border:1px solid #f1f5f9; margin-bottom:20px;">
							<div style="font-size:13px; color:#64748b; font-weight:600; text-transform:uppercase; margin-bottom:4px;"><?php esc_html_e( 'Secure Folder', 'skyhs-hosting-solution' ); ?></div>
							<div style="font-family:monospace; font-size:11px; word-break:break-all; color:#334155; margin-bottom:12px;">wp-content/uploads/skyhshoso-backups/</div>
							
							<div style="font-size:13px; color:#64748b; font-weight:600; text-transform:uppercase; margin-bottom:4px;"><?php esc_html_e( 'Next Scheduled Backup', 'skyhs-hosting-solution' ); ?></div>
							<div style="font-size:14px; font-weight:600; color:#1e293b;">
								<?php
								$next_run = wp_next_scheduled( 'skyhshoso_run_auto_backup' );
								if ( $next_run ) {
									$date = new DateTime( '@' . $next_run );
									$date->setTimezone( wp_timezone() );
									echo esc_html( $date->format( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
								} else {
									echo '<span class="skyhshoso-badge">' . esc_html__( 'Not Scheduled', 'skyhs-hosting-solution' ) . '</span>';
								}
								?>
							</div>
						</div>
					</div>

					<div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'skyhshoso_create_backup', 'skyhshoso_backup_nonce' ); ?>
							<input type="hidden" name="action" value="skyhshoso_create_backup">
							<p style="font-size:13px; color:#64748b; margin-bottom:16px;">
								<?php esc_html_e( 'Need to make immediate configuration changes? Create a manual backup point first to keep your data safe.', 'skyhs-hosting-solution' ); ?>
							</p>
							<button type="submit" class="skyhshoso-btn skyhshoso-btn-primary" style="width:100%;">
								<span class="dashicons dashicons-backup" style="margin-right: 6px;"></span><?php esc_html_e( 'Create Backup Now', 'skyhs-hosting-solution' ); ?>
							</button>
						</form>
					</div>
				</div>

			</div>

			<!-- Bottom Section: Backups Table -->
			<div class="skyhshoso-backup-card">
				<h2><?php esc_html_e( 'Available Backup Snapshots', 'skyhs-hosting-solution' ); ?></h2>
				
				<?php if ( empty( $backups ) ) : ?>
					<div style="text-align:center; padding:40px 20px; color:#64748b;">
						<div style="margin-bottom:15px;"><span class="dashicons dashicons-database" style="font-size:48px; width:48px; height:48px; color:#cbd5e1;"></span></div>
						<h3><?php esc_html_e( 'No backup files found', 'skyhs-hosting-solution' ); ?></h3>
						<p><?php esc_html_e( 'You haven\'t created any backup snapshots yet. Adjust settings or run a manual backup to get started.', 'skyhs-hosting-solution' ); ?></p>
					</div>
				<?php else : ?>
					<div class="skyhshoso-table-container">
						<table class="skyhshoso-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Backup Point (Local Time)', 'skyhs-hosting-solution' ); ?></th>
									<th><?php esc_html_e( 'Filename', 'skyhs-hosting-solution' ); ?></th>
									<th><?php esc_html_e( 'File Size', 'skyhs-hosting-solution' ); ?></th>
									<th style="text-align:right;"><?php esc_html_e( 'Actions', 'skyhs-hosting-solution' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $backups as $backup ) : ?>
									<tr>
										<td style="font-weight:600; color:#1e293b;">
											<span class="dashicons dashicons-calendar-alt" style="vertical-align: middle; margin-right: 5px;"></span><?php echo esc_html( $backup['date_created'] ); ?>
										</td>
										<td style="font-family:monospace; font-size:12px; color:#64748b;">
											<?php echo esc_html( $backup['filename'] ); ?>
										</td>
										<td>
											<span class="skyhshoso-badge skyhshoso-badge-info"><?php echo esc_html( $backup['size'] ); ?></span>
										</td>
										<td style="text-align:right;">
											<div class="skyhshoso-btn-group" style="justify-content:flex-end;">
												<!-- Download -->
												<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=skyhshoso_download_backup&file=' . $backup['filename'] ), 'skyhshoso_download_backup', 'skyhshoso_backup_nonce' ) ); ?>" 
												   class="skyhshoso-btn skyhshoso-btn-secondary skyhshoso-btn-sm" 
												   title="<?php esc_attr_e( 'Download Backup JSON File', 'skyhs-hosting-solution' ); ?>">
													<span class="dashicons dashicons-download" style="vertical-align: middle; margin-right: 4px;"></span><?php esc_html_e( 'Download', 'skyhs-hosting-solution' ); ?>
												</a>
												
												<!-- Restore -->
												<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=skyhshoso_import_backup&file=' . $backup['filename'] ), 'skyhshoso_import_backup', 'skyhshoso_backup_nonce' ) ); ?>" 
												   class="skyhshoso-btn skyhshoso-btn-primary skyhshoso-btn-sm skyhshoso-confirm-restore" 
												   title="<?php esc_attr_e( 'Restore SkyHS settings and items from this backup', 'skyhs-hosting-solution' ); ?>">
													<span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 4px;"></span><?php esc_html_e( 'Restore', 'skyhs-hosting-solution' ); ?>
												</a>
												
												<!-- Delete -->
												<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=skyhshoso_delete_backup&file=' . $backup['filename'] ), 'skyhshoso_delete_backup', 'skyhshoso_backup_nonce' ) ); ?>" 
												   class="skyhshoso-btn skyhshoso-btn-danger skyhshoso-btn-sm skyhshoso-confirm-delete" 
												   title="<?php esc_attr_e( 'Permanently delete backup file from disk', 'skyhs-hosting-solution' ); ?>">
													<span class="dashicons dashicons-trash" style="vertical-align: middle; margin-right: 4px;"></span><?php esc_html_e( 'Delete', 'skyhs-hosting-solution' ); ?>
												</a>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>

		</div>
		<?php
	}

	/**
	 * Render one-click restore operation results log to user.
	 *
	 * @param array $results Import result report array.
	 */
	private static function render_restore_results( $results ) {
		if ( isset( $results['error'] ) ) {
			echo '<div class="skyhshoso-notice-custom skyhshoso-notice-error" style="display:block;">';
			echo esc_html( $results['error'] );
			echo '</div>';
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
		<div class="skyhshoso-backup-card" style="margin-bottom:24px; border-left: 4px solid #10b981;">
			<h3 style="margin-top:0; color:#1e293b; font-size:16px;"><span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 6px;"></span><?php esc_html_e( 'One-Click Restore Results Summary', 'skyhs-hosting-solution' ); ?></h3>
			
			<div style="overflow-x:auto;">
				<table class="skyhshoso-table" style="max-width:100%; font-size:13px; margin: 15px 0;">
					<thead>
						<tr>
							<th style="padding:10px;"><?php esc_html_e( 'Entity Type', 'skyhs-hosting-solution' ); ?></th>
							<th style="padding:10px;"><?php esc_html_e( 'Imported / Created', 'skyhs-hosting-solution' ); ?></th>
							<th style="padding:10px;"><?php esc_html_e( 'Updated', 'skyhs-hosting-solution' ); ?></th>
							<th style="padding:10px;"><?php esc_html_e( 'Skipped', 'skyhs-hosting-solution' ); ?></th>
							<th style="padding:10px;"><?php esc_html_e( 'Errors', 'skyhs-hosting-solution' ); ?></th>
						</tr>
					</thead>
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
						<tr>
							<td style="padding:10px; font-weight:600;"><?php echo esc_html( $label ); ?></td>
							<td style="padding:10px;"><?php echo intval( $r['created'] ); ?></td>
							<td style="padding:10px;"><?php echo intval( $r['updated'] ); ?></td>
							<td style="padding:10px;"><?php echo intval( $r['skipped'] ); ?></td>
							<td style="padding:10px;"><?php echo $ec > 0 ? '<span class="skyhshoso-badge skyhshoso-badge-danger">' . intval( $ec ) . '</span>' : '0'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr style="background:#f8fafc; font-weight:bold;">
							<td style="padding:10px;"><?php esc_html_e( 'Total Summary', 'skyhs-hosting-solution' ); ?></td>
							<td style="padding:10px;"><?php echo intval( $totals['created'] ); ?></td>
							<td style="padding:10px;"><?php echo intval( $totals['updated'] ); ?></td>
							<td style="padding:10px;"><?php echo intval( $totals['skipped'] ); ?></td>
							<td style="padding:10px;"><?php echo intval( $totals['errors'] ); ?></td>
						</tr>
					</tfoot>
				</table>
			</div>

			<?php if ( $has_errors ) : ?>
				<h4 style="color:#ef4444; margin: 15px 0 8px 0; font-size:14px;"><?php esc_html_e( 'Error Log Details', 'skyhs-hosting-solution' ); ?></h4>
				<ul style="max-height:200px; overflow-y:auto; background:#fef2f2; border:1px solid #fee2e2; border-radius:8px; padding:12px 20px; margin:0; list-style-type:disc;">
				<?php foreach ( $labels as $key => $label ) : ?>
					<?php foreach ( ( $results[ $key ]['errors'] ?? array() ) as $err ) : ?>
						<li style="color:#b91c1c; font-size:12px; margin-bottom:4px;">
							<strong>[<?php echo esc_html( $label ); ?>]</strong> <?php echo esc_html( $err ); ?>
						</li>
					<?php endforeach; ?>
				<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}
}
