<?php
/**
 * Plugin Name: SkyHS Hardened Security
 * Description: Upload security, PHP injection protection (MU Plugin)
 * Author: SkyHS
 */

defined('ABSPATH') || exit;

if (defined('WP_INSTALLING') && WP_INSTALLING) {
    return;
}

// ═══════════════════════════════════════════════════════════════
// CONFIGURATION — injected during provisioning
// ═══════════════════════════════════════════════════════════════

define('SKYHS_STORAGE_MB',    STORAGE_MB_VALUE);
define('SKYHS_STORAGE_BYTES', SKYHS_STORAGE_MB * 1048576);
define('SKYHS_CACHE_TTL',     300);

// ═══════════════════════════════════════════════════════════════
// 1. STORAGE — HELPERS
// ═══════════════════════════════════════════════════════════════

function skyhs_calculate_usage(): float {
    $total = 0;
    $roots = array_unique([
        rtrim(ABSPATH, '/'),
        rtrim(WP_CONTENT_DIR, '/'),
        rtrim(wp_upload_dir()['basedir'] ?? '', '/'),
    ]);
    foreach ($roots as $root) {
        if (!is_dir($root)) continue;
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $f) {
                if ($f->isFile() && !$f->isLink()) $total += $f->getSize();
            }
        } catch (Exception $e) {}
    }
    return (float) $total;
}

function skyhs_get_usage(bool $force = false): float {
    if (!$force) {
        $cached = get_transient('skyhs_storage_used_bytes');
        if (false !== $cached) return (float) $cached;
    }
    $bytes = skyhs_calculate_usage();
    set_transient('skyhs_storage_used_bytes', $bytes, SKYHS_CACHE_TTL);
    return $bytes;
}

function skyhs_is_full(bool $force = false): bool {
    return skyhs_get_usage($force) >= SKYHS_STORAGE_BYTES;
}

function skyhs_bust_cache(): void {
    delete_transient('skyhs_storage_used_bytes');
}

// ═══════════════════════════════════════════════════════════════
// 2. STORAGE — ENFORCEMENT
// ═══════════════════════════════════════════════════════════════

add_filter('upgrader_pre_install', function ($return, $extra) {
    if (is_wp_error($return)) return $return;
    if (skyhs_is_full()) {
        return new WP_Error(
            'skyhs_storage_full',
            sprintf('Storage limit of %d MB reached. Free space before installing.', SKYHS_STORAGE_MB)
        );
    }
    return $return;
}, 999, 2);

add_filter('wp_handle_upload_prefilter', function ($file) {
    if (skyhs_is_full()) {
        $file['error'] = sprintf('Storage limit of %d MB reached.', SKYHS_STORAGE_MB);
    }
    return $file;
});

add_filter('wp_handle_sideload_prefilter', function ($file) {
    if (skyhs_is_full()) {
        $file['error'] = sprintf('Storage limit of %d MB reached.', SKYHS_STORAGE_MB);
    }
    return $file;
});

add_action('xmlrpc_call', function ($method) {
    if (in_array($method, ['metaWeblog.newMediaObject', 'wp.uploadFile'], true)) {
        if (skyhs_is_full()) {
            wp_die(
                sprintf('Storage limit of %d MB reached.', SKYHS_STORAGE_MB),
                'Storage Full',
                ['response' => 507]
            );
        }
    }
});

add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    if (!is_null($result)) return $result;
    if (empty($request->get_file_params())) return $result;
    if (skyhs_is_full()) {
        return new WP_Error(
            'skyhs_storage_full',
            sprintf('Storage limit of %d MB reached.', SKYHS_STORAGE_MB),
            ['status' => 507]
        );
    }
    return $result;
}, 10, 3);

foreach (['add_attachment', 'delete_attachment', 'upgrader_process_complete',
          'wp_handle_upload', 'wp_handle_sideload'] as $hook) {
    add_action($hook, 'skyhs_bust_cache');
}

add_action('init', function () {
    if (!is_admin() && !wp_doing_ajax() && !defined('XMLRPC_REQUEST')) return;
    if (empty($_FILES) && !doing_action('upgrader_pre_install')) return;
    $remaining_bytes = max(0, SKYHS_STORAGE_BYTES - skyhs_get_usage());
    $remaining_mb    = max(1, (int)($remaining_bytes / 1048576));
    $server_limit    = (int)ini_get('upload_max_filesize') * 1048576;
    if ($remaining_bytes < $server_limit) {
        @ini_set('upload_max_filesize', $remaining_mb . 'M');
        @ini_set('post_max_size', ($remaining_mb + 2) . 'M');
    }
});

// ═══════════════════════════════════════════════════════════════
// 3. BLOCK PHP FILES IN UPLOADS
// ═══════════════════════════════════════════════════════════════

add_filter('wp_handle_upload_prefilter', function ($file) {
    $blocked = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8',
        'phtml', 'phar', 'shtml', 'cgi', 'pl', 'py',
        'rb', 'sh', 'bash', 'exe', 'com', 'bat', 'cmd',
        'htaccess', 'htpasswd', 'ini', 'svg'
    ];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (in_array($ext, $blocked, true)) {
        $file['error'] = 'File type not allowed for security reasons.';
    }
    return $file;
}, 1);

add_filter('wp_check_filetype_and_ext', function ($data, $file, $filename, $mimes) {
    if (!empty($data['ext']) && !empty($data['type'])) return $data;
    $content = file_get_contents($file, false, null, 0, 8192);
    if ($content === false) return $data;
    if (preg_match('/<\?(?:php|=)/i', $content)) {
        return ['ext' => false, 'type' => false, 'proper_filename' => false];
    }
    return $data;
}, 10, 4);

// ═══════════════════════════════════════════════════════════════
// 4. PROTECT ITSELF
// ═══════════════════════════════════════════════════════════════

if (!defined('DISALLOW_FILE_EDIT')) define('DISALLOW_FILE_EDIT', true);

// ═══════════════════════════════════════════════════════════════
// 5. PREVENT CROSS-SITE SYMLINK TRAVERSAL
// ═══════════════════════════════════════════════════════════════

add_filter('wp_handle_upload_prefilter', function ($file) {
    $real_upload = realpath(wp_upload_dir()['basedir']);
    $real_tmp    = realpath($file['tmp_name'] ?? '');
    if ($real_tmp && $real_upload && is_link($file['tmp_name'] ?? '')) {
        $file['error'] = 'Symlink uploads are not permitted.';
    }
    return $file;
}, 2);

// ═══════════════════════════════════════════════════════════════
// 6. ADMIN NOTICE — STORAGE USAGE
// ═══════════════════════════════════════════════════════════════

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    $used_bytes = skyhs_get_usage();
    $used_mb    = $used_bytes / 1048576;
    $pct        = min(100, ($used_mb / SKYHS_STORAGE_MB) * 100);
    $color      = $pct >= 90 ? '#dc3232' : ($pct >= 70 ? '#ffb900' : '#46b450');
    printf(
        '<div class="notice notice-info"><p>
            <strong>SkyHS Storage:</strong> %.1f MB / %d MB used (%.0f%%)
            <span style="display:inline-block;margin-left:10px;width:150px;height:10px;
                background:#ddd;border-radius:5px;vertical-align:middle;">
                <span style="display:block;width:%s%%;height:100%%;background:%s;
                    border-radius:5px;"></span>
            </span>
        </p></div>',
        $used_mb, SKYHS_STORAGE_MB, $pct,
        esc_attr(number_format($pct, 1)), esc_attr($color)
    );
});
