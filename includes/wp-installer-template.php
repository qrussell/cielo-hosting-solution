<?php
/**
 * SkyHS WordPress Provisioning Script
 *
 * Deployed temporarily to a cPanel account's document root to install WordPress.
 * Downloads WordPress from wordpress.org, extracts it, configures wp-config.php,
 * runs the silent installer, writes a status file, and self-deletes.
 *
 * Database privileges are granted by the plugin via cPanel UAPI BEFORE this
 * script runs — no mysql CLI GRANT commands are needed here.
 *
 * @package Hosting_Solution
 */

// Custom error handler to capture fatal errors
$skyhs_errors = array();
set_error_handler( function( $errno, $errstr, $errfile, $errline ) use ( &$skyhs_errors ) {
    $skyhs_errors[] = "PHP Error [{$errno}]: {$errstr} in {$errfile}:{$errline}";
    return false; // Let PHP handle it too
} );

// --- TARGET_DIR_PLACEHOLDER ---
// --- STORAGE_PLACEHOLDER ---
// --- MEMORY_PLACEHOLDER ---
// --- PLUGINS_PLACEHOLDER ---

// Required params passed via POST or environment
$required = array( 'db_name', 'db_user', 'db_pass', 'site_title', 'admin_user', 'admin_pass', 'admin_email' );
$missing  = array();
foreach ( $required as $key ) {
    if ( empty( $_POST[ $key ] ) && empty( $GLOBALS[ 'SKYHS_' . strtoupper( $key ) ] ) ) {
        $missing[] = $key;
    }
}
if ( ! empty( $missing ) ) {
    http_response_code( 400 );
    echo 'Missing: ' . implode( ', ', $missing );
    exit;
}

// Resolve parameter from POST or global
function skyhs_get_param( $key ) {
    return ! empty( $_POST[ $key ] ) ? $_POST[ $key ] : ( $GLOBALS[ 'SKYHS_' . strtoupper( $key ) ] ?? '' );
}

$db_name     = skyhs_get_param( 'db_name' );
$db_user     = skyhs_get_param( 'db_user' );
$db_pass     = skyhs_get_param( 'db_pass' );
$db_host     = 'localhost';
$site_title  = skyhs_get_param( 'site_title' );
$admin_user  = skyhs_get_param( 'admin_user' );
$admin_pass  = skyhs_get_param( 'admin_pass' );
$admin_email = skyhs_get_param( 'admin_email' );

// Target install directory (injected by the plugin)
$target_dir = isset( $target_install_dir ) ? $target_install_dir : __DIR__;

// 0. Test database connection BEFORE doing any heavy work
$test_conn = @mysqli_connect( $db_host, $db_user, $db_pass, $db_name );
if ( ! $test_conn ) {
    http_response_code( 500 );
    echo 'DB connection failed: ' . mysqli_connect_error() . ' (user=' . $db_user . ', db=' . $db_name . ')';
    exit;
}
mysqli_close( $test_conn );

// 1. Download WordPress
$wp_zip_url = 'https://wordpress.org/latest.zip';
$zip_file   = $target_dir . '/wordpress-temp.zip';

$wp_content = file_get_contents( $wp_zip_url );
if ( false === $wp_content ) {
    http_response_code( 500 );
    echo 'Failed to download WordPress';
    exit;
}
file_put_contents( $zip_file, $wp_content );

// 2. Extract (use ZipArchive if available, fallback to shell unzip)
if ( class_exists( 'ZipArchive' ) ) {
    $zip = new ZipArchive();
    if ( $zip->open( $zip_file ) === true ) {
        $zip->extractTo( $target_dir );
        $zip->close();
    } else {
        http_response_code( 500 );
        echo 'Failed to open zip archive';
        unlink( $zip_file );
        exit;
    }
} elseif ( function_exists( 'exec' ) ) {
    exec( 'unzip -o ' . escapeshellarg( $zip_file ) . ' -d ' . escapeshellarg( $target_dir ), $output, $return_var );
    if ( $return_var !== 0 ) {
        http_response_code( 500 );
        echo 'Failed to extract zip via unzip';
        unlink( $zip_file );
        exit;
    }
} else {
    http_response_code( 500 );
    echo 'No zip extraction method available';
    unlink( $zip_file );
    exit;
}

// Remove zip file
unlink( $zip_file );

// 3. Move WordPress files from wordpress/ subdirectory to target directory
$wp_src = $target_dir . '/wordpress';
if ( is_dir( $wp_src ) ) {
    $files_to_move = array();
    $dirs_to_create = array();

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $wp_src, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ( $iterator as $item ) {
        $subpath = $iterator->getSubPathname();
        $source = $item->getRealPath() ?: $item->getPathname();
        $destination = $target_dir . '/' . $subpath;

        if ( $item->isDir() ) {
            $dirs_to_create[] = array(
                'source' => $source,
                'dest'   => $destination
            );
        } else {
            $files_to_move[] = array(
                'source' => $source,
                'dest'   => $destination
            );
        }
    }

    // Create all directories first
    foreach ( $dirs_to_create as $dir_info ) {
        if ( ! is_dir( $dir_info['dest'] ) ) {
            mkdir( $dir_info['dest'], 0755, true );
        }
    }

    // Move all files next
    foreach ( $files_to_move as $file_info ) {
        rename( $file_info['source'], $file_info['dest'] );
    }

    // Remove the empty wordpress/ subdirectory and its empty children
    $rmdir_iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $wp_src, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $rmdir_iterator as $item ) {
        $path = $item->getRealPath() ?: $item->getPathname();
        if ( $item->isDir() ) {
            rmdir( $path );
        } else {
            unlink( $path );
        }
    }
    rmdir( $wp_src );
}

// 4. Create wp-config.php
$wp_config_sample = $target_dir . '/wp-config-sample.php';
if ( ! file_exists( $wp_config_sample ) ) {
    http_response_code( 500 );
    echo 'wp-config-sample.php not found after extraction';
    exit;
}

$wp_config = file_get_contents( $wp_config_sample );
$wp_config = str_replace(
    array(
        'database_name_here',
        'username_here',
        'password_here',
        'localhost',
        "'wp_'",
    ),
    array(
        $db_name,
        $db_user,
        $db_pass,
        $db_host,
        "'wp_" . substr( md5( uniqid() ), 0, 6 ) . "_'",
    ),
    $wp_config
);

// Generate salts
$salt_keys = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );
$salt_url  = 'https://api.wordpress.org/secret-key/1.1/salt/';
$salt_content = @file_get_contents( $salt_url );
if ( $salt_content ) {
    foreach ( $salt_keys as $key ) {
        $pattern = "/define\(\s*'{$key}'.*\);/";
        if ( preg_match( "/define\(\s*'{$key}',\s*'([^']+)'\s*\);/", $salt_content, $m ) ) {
            $wp_config = preg_replace( $pattern, $m[0], $wp_config );
        }
    }
}

// Add WP_HOME and WP_SITEURL — use passed site_url or fall back to HTTP_HOST
$site_url = skyhs_get_param( 'site_url' );
if ( empty( $site_url ) ) {
    $site_url = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'];
}

// Use per-plan memory limit (injected by plugin)
$memory_limit = isset( $skyhs_memory_limit ) ? $skyhs_memory_limit : '64M';
$max_memory   = max( 128, (int) $memory_limit ) . 'M';

$wp_config = preg_replace(
    '/\/\* That\'s all, stop editing! Happy publishing\. \*\//',
    "define('WP_HOME', '{$site_url}');\ndefine('WP_SITEURL', '{$site_url}');\ndefine('DISALLOW_FILE_EDIT', true);\ndefine('WP_MEMORY_LIMIT', '{$memory_limit}');\ndefine('WP_MAX_MEMORY_LIMIT', '{$max_memory}');\n\${0}",
    $wp_config
);

file_put_contents( $target_dir . '/wp-config.php', $wp_config );

// 5. Run WordPress silent installer
define( 'WP_INSTALLING', true );
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array(
    'weblog_title'   => $site_title,
    'user_name'      => $admin_user,
    'admin_password' => $admin_pass,
    'admin_email'    => $admin_email,
    'pw_weak'        => '1',
);

// Set up the minimal WP environment
$_SERVER['PHP_SELF'] = '/wp-admin/install.php';
$wp_load_path = $target_dir . '/wp-load.php';
if ( ! file_exists( $wp_load_path ) ) {
    http_response_code( 500 );
    echo 'wp-load.php not found';
    exit;
}

// Override wp_die to prevent WordPress from outputting HTML error pages
// that mask the actual error message
function skyhs_wp_die_handler( $message, $title = '', $args = array() ) {
    if ( is_wp_error( $message ) ) {
        $message = $message->get_error_message();
    }
    if ( is_array( $message ) ) {
        $message = implode( ', ', $message );
    }
    // Strip HTML tags to get clean error text
    $clean_message = strip_tags( (string) $message );
    http_response_code( 500 );
    echo 'WP Error: ' . $clean_message;
    exit;
}

// --- Deploy mu-plugin to wp-content/mu-plugins/ ---
$mu_src = dirname( __FILE__ ) . '/skyhs-mu-plugin.php';
$mu_dir = $target_dir . '/wp-content/mu-plugins';
if ( file_exists( $mu_src ) ) {
    if ( ! is_dir( $mu_dir ) ) {
        mkdir( $mu_dir, 0755, true );
    }
    copy( $mu_src, $mu_dir . '/skyhs-resource-enforcer.php' );
    @unlink( $mu_src ); // clean up from main domain
}

// Register a shutdown function to capture fatal errors
register_shutdown_function( function() {
    $error = error_get_last();
    if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ) ) ) {
        // Only output if nothing has been sent yet
        if ( ! headers_sent() ) {
            http_response_code( 500 );
        }
        echo 'PHP Fatal: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'];
    }
} );

ob_start();
try {
    require_once $wp_load_path;

    // Override wp_die handler after WP is loaded
    add_filter( 'wp_die_handler', function() {
        return 'skyhs_wp_die_handler';
    } );

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    wp_install( $site_title, $admin_user, $admin_email, true, '', $admin_pass );

    // Enable pretty permalinks (/%postname%/) by default so standard .htaccess rewrite rules are written.
    update_option( 'permalink_structure', '/%postname%/' );
    global $wp_rewrite;
    if ( isset( $wp_rewrite ) ) {
        $wp_rewrite->set_permalink_structure( '/%postname%/' );
        $wp_rewrite->flush_rules( true );
    }

    // Install selected plugins from WordPress.org
    if ( ! empty( $plugins_to_install ) ) {
        $plugin_slugs = array_filter( array_map( 'trim', explode( ',', $plugins_to_install ) ) );
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

        $wp_filesystem = new WP_Filesystem_Direct( false );
        $plugins_dir  = WP_CONTENT_DIR . '/plugins';
        $to_activate  = array();

        foreach ( $plugin_slugs as $slug ) {
            $slug = sanitize_title( $slug );
            if ( empty( $slug ) ) {
                continue;
            }
            $plugin_zip_url = 'https://downloads.wordpress.org/plugin/' . $slug . '.latest-stable.zip';
            $zip_content    = @file_get_contents( $plugin_zip_url );
            if ( false === $zip_content ) {
                continue;
            }
            $zip_file = $target_dir . '/plugin-' . $slug . '-temp.zip';
            file_put_contents( $zip_file, $zip_content );

            $extract_to = $plugins_dir;
            if ( class_exists( 'ZipArchive' ) ) {
                $zip = new ZipArchive();
                if ( $zip->open( $zip_file ) === true ) {
                    $zip->extractTo( $extract_to );
                    $zip->close();
                }
            } elseif ( function_exists( 'exec' ) ) {
                exec( 'unzip -o ' . escapeshellarg( $zip_file ) . ' -d ' . escapeshellarg( $extract_to ) );
            }
            @unlink( $zip_file );

            // Find the main plugin file
            $plugin_file = $plugins_dir . '/' . $slug . '/' . $slug . '.php';
            if ( ! file_exists( $plugin_file ) ) {
                $plugin_dir = $plugins_dir . '/' . $slug;
                if ( is_dir( $plugin_dir ) ) {
                    $files = scandir( $plugin_dir );
                    foreach ( $files as $file ) {
                        if ( pathinfo( $file, PATHINFO_EXTENSION ) === 'php' ) {
                            $content = @file_get_contents( $plugin_dir . '/' . $file );
                            if ( $content && preg_match( '/Plugin\s*Name\s*:/', $content ) ) {
                                $plugin_file = $plugin_dir . '/' . $file;
                                break;
                            }
                        }
                    }
                }
                if ( ! file_exists( $plugin_file ) ) {
                    continue;
                }
            }
            $rel_path = str_replace( WP_CONTENT_DIR . '/plugins/', '', $plugin_file );
            $to_activate[] = $rel_path;
        }

        // Activate all plugins at once via active_plugins option
        // This avoids activation hook interference (e.g. onboarding redirects)
        if ( ! empty( $to_activate ) ) {
            $existing = get_option( 'active_plugins', array() );
            $existing = is_array( $existing ) ? $existing : array();
            foreach ( $to_activate as $p ) {
                if ( ! in_array( $p, $existing, true ) ) {
                    $existing[] = $p;
                }
            }
            update_option( 'active_plugins', $existing );
        }
    }

    // Add SkyHS Security rules to root .htaccess
    $htaccess_path = $target_dir . '/.htaccess';
    $skyhs_security_rules = <<<'HTACCESS'

# BEGIN SkyHS Security
<IfModule mod_rewrite.c>
    RewriteEngine On

    # Force HTTPS always (except for AutoSSL/Let's Encrypt challenge files)
    RewriteCond %{HTTPS} !=on
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/ [NC]
    RewriteCond %{REQUEST_URI} !^/\.well-known/cpanel-dcv/ [NC]
    RewriteCond %{REQUEST_URI} !^/\.well-known/pki-validation/ [NC]
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

    # Block PHP execution in uploads folder
    RewriteCond %{REQUEST_URI} ^/wp-content/uploads/.*\.(?:php\d*|phtml|phar|shtml|cgi|pl|py|rb|sh|exe|cmd|bat)$ [NC]
    RewriteRule ^ - [F,L]

    # Block WordPress Author Enumeration
    RewriteCond %{QUERY_STRING} (author=\d+) [NC]
    RewriteRule ^ - [F,L]
</IfModule>

# Block WordPress xmlrpc.php requests
<Files xmlrpc.php>
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</Files>

# Security Headers
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set X-Frame-Options "SAMEORIGIN"
    Header set X-XSS-Protection "1; mode=block"
    Header set Referrer-Policy "strict-origin-when-cross-origin"
    Header set Permissions-Policy "geolocation=(), microphone=(), camera=()"
</IfModule>
# END SkyHS Security
HTACCESS;

    if ( file_exists( $htaccess_path ) ) {
        $current_htaccess = file_get_contents( $htaccess_path );
        if ( strpos( $current_htaccess, '# BEGIN SkyHS Security' ) === false ) {
            file_put_contents( $htaccess_path, $skyhs_security_rules . "\n\n" . $current_htaccess );
        }
    } else {
        file_put_contents( $htaccess_path, $skyhs_security_rules . "\n\n# BEGIN WordPress\n# END WordPress\n" );
    }

    ob_end_clean();
} catch ( Exception $e ) {
    ob_end_clean();
    http_response_code( 500 );
    echo 'WordPress install exception: ' . $e->getMessage();
    exit;
} catch ( \Throwable $e ) {
    ob_end_clean();
    http_response_code( 500 );
    echo 'WordPress install fatal: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    exit;
}

// 6. Write status file with admin info
$status = array(
    'site_url'    => $site_url,
    'admin_url'   => $site_url . '/wp-admin',
    'admin_user'  => $admin_user,
    'admin_email' => $admin_email,
    'installed'   => true,
    'timestamp'   => time(),
);
file_put_contents( $target_dir . '/skyhs-wp-installed.json', json_encode( $status ) );

// Remove sensitive files after install
foreach ( array( '/wp-config-sample.php', '/readme.html', '/license.txt' ) as $rf ) {
    $p = $target_dir . $rf;
    if ( file_exists( $p ) ) { @unlink( $p ); }
}

// 7. Self-delete this script
$self = __FILE__;
register_shutdown_function( function() use ( $self, &$skyhs_errors ) {
    // If the HTTP response is 500, append any collected PHP errors/warnings
    $code = http_response_code();
    if ( $code >= 400 && ! empty( $skyhs_errors ) ) {
        echo "\n\nPHP Errors/Warnings collected:\n" . implode( "\n", $skyhs_errors );
    }

    if ( file_exists( $self ) ) {
        @unlink( $self );
    }
} );

echo 'OK';
