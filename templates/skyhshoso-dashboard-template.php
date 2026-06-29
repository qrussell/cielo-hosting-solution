<?php
/**
 * Template Name: SkyHS Dashboard Canvas
 * Description: An isolated canvas template for the SkyHS Dashboard that prevents theme CSS overrides.
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

// 1. Check guest access setting
$guest_dashboard_enabled = SkyHSHOSO_Settings::is_guest_dashboard_enabled();

// Determine guest-accessible tabs (only when guest dashboard is enabled)
$guest_allowed = false;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'dashboard';

if ( $guest_dashboard_enabled ) {
    if ( 'dashboard' === $active_tab ) {
        $guest_allowed = true;
    } elseif ( 'skyhshoso_hosting' === $active_tab && isset( $_GET['new_hosting'] ) ) {
        $guest_allowed = true;
    } elseif ( 'domains' === $active_tab && ( isset( $_GET['new_domain'] ) || isset( $_GET['transfer_domain'] ) ) ) {
        $guest_allowed = true;
    } elseif ( 'wp_sites' === $active_tab && isset( $_GET['new_wp_site'] ) ) {
        $guest_allowed = true;
    }
}

// If not logged in and not on a guest-allowed page, redirect to login.
if ( ! is_user_logged_in() && ! $guest_allowed ) {
    $current_url = home_url( add_query_arg( array(), $GLOBALS['wp']->request ) );
    $login_url   = add_query_arg( 'redirect', $current_url, wc_get_page_permalink( 'myaccount' ) );
    wp_safe_redirect( $login_url );
    exit;
}

$is_guest     = ! is_user_logged_in();
$current_user = $is_guest ? null : wp_get_current_user();
$display_name = $is_guest ? '' : ( $current_user->display_name ? $current_user->display_name : $current_user->user_login );

// 2. Ensure dashboard styles and scripts are loaded
wp_enqueue_style( 'skyhshoso-dashboard' );
wp_enqueue_script( 'skyhshoso-dashboard' );

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php esc_html_e( 'Client Dashboard', 'skyhs-hosting-solution' ); ?></title>
    
    <!-- Modern Typography Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <?php wp_head(); ?>

    <!-- Minimal premium styles for the standalone app frame -->
    <style>
        :root {
            /* Design Tokens (Color & Font Customization System Ready) */
            --skyhs-primary: #2563eb;
            --skyhs-primary-hover: #1d4ed8;
            --skyhs-bg-main: #f8fafc;
            --skyhs-font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;

            --app-bg: var(--skyhs-bg-main);
            --header-bg: rgba(255, 255, 255, 0.95);
            --header-border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --primary-color: var(--skyhs-primary);
            --primary-hover: var(--skyhs-primary-hover);
            --danger-color: #ef4444;
            --radius-md: 8px;
            --radius-lg: 12px;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
        }

        /* Set baseline styles on body to isolate from theme layout */
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            background-color: var(--app-bg) !important;
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
            color: var(--text-main) !important;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .skyhshoso-app-container {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Modern Premium Header Bar */
        .skyhshoso-app-header {
            background: var(--header-bg);
            border-bottom: 1px solid var(--header-border);
            position: sticky;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            box-shadow: var(--shadow-sm);
        }

        .skyhshoso-app-header-content {
            max-width: 1280px;
            margin: 0 auto;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .skyhshoso-app-logo-area {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .skyhshoso-app-logo-icon {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, var(--primary-color), #3b82f6);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 18px;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.2);
        }

        .skyhshoso-app-logo-text {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-main);
            letter-spacing: -0.5px;
        }

        .skyhshoso-app-user-menu {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .skyhshoso-app-nav-links {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .skyhshoso-app-nav-btn {
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: var(--radius-md);
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .skyhshoso-app-nav-btn-secondary {
            color: var(--text-muted);
            background: transparent;
        }

        .skyhshoso-app-nav-btn-secondary:hover {
            color: var(--text-main);
            background: rgba(0, 0, 0, 0.03);
        }

        .skyhshoso-app-nav-btn-primary {
            color: white;
            background: var(--primary-color);
            box-shadow: 0 2px 4px rgba(37, 99, 235, 0.1);
        }

        .skyhshoso-app-nav-btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }

        .skyhshoso-btn-icon-only {
            display: none;
        }

        /* Custom Header Navigation Menu */
        .skyhshoso-header-nav-desktop {
            display: flex;
            align-items: center;
        }

        .skyhshoso-header-menu-list {
            display: flex;
            align-items: center;
            gap: 24px;
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .skyhshoso-header-menu-list li {
            position: relative;
        }

        .skyhshoso-header-menu-list li a {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.2s ease;
            padding: 8px 0;
            display: inline-flex;
            align-items: center;
            position: relative;
        }

        .skyhshoso-header-menu-list li a:hover {
            color: var(--primary-color);
        }

        .skyhshoso-header-menu-list li.current-menu-item > a,
        .skyhshoso-header-menu-list li.current-menu-ancestor > a {
            color: var(--primary-color);
        }

        /* Underline animation on hover/active */
        .skyhshoso-header-menu-list li a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 2px;
            background-color: var(--primary-color);
            transform: scaleX(0);
            transform-origin: right;
            transition: transform 0.2s ease-in-out;
        }

        .skyhshoso-header-menu-list li a:hover::after,
        .skyhshoso-header-menu-list li.current-menu-item > a::after {
            transform: scaleX(1);
            transform-origin: left;
        }

        /* Nested Desktop Dropdowns (depth 2) */
        .skyhshoso-header-menu-list li ul {
            position: absolute;
            top: 100%;
            left: 0;
            background: white;
            border: 1px solid var(--header-border);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-md);
            padding: 8px;
            list-style: none;
            min-width: 180px;
            display: none;
            flex-direction: column;
            gap: 4px;
            z-index: 1010;
            margin-top: 8px;
        }

        .skyhshoso-header-menu-list li:hover > ul {
            display: flex;
        }

        .skyhshoso-header-menu-list li ul li a {
            padding: 8px 12px;
            border-radius: var(--radius-md);
            color: var(--text-main);
            display: block;
            white-space: nowrap;
        }

        .skyhshoso-header-menu-list li ul li a:hover {
            background: var(--skyhs-bg-main);
            color: var(--primary-color);
        }

        .skyhshoso-header-menu-list li ul li a::after {
            display: none;
        }

        /* Mobile Menu Toggle Button */
        .skyhshoso-mobile-menu-toggle {
            display: none;
            background: transparent;
            border: 1px solid var(--header-border);
            border-radius: var(--radius-md);
            padding: 8px;
            color: var(--text-muted);
            cursor: pointer;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            outline: none;
        }

        .skyhshoso-mobile-menu-toggle:hover,
        .skyhshoso-mobile-menu-toggle:focus {
            color: var(--text-main);
            border-color: #cbd5e1;
            background: rgba(0, 0, 0, 0.02);
        }

        .skyhshoso-mobile-menu-toggle svg {
            display: block;
        }

        /* Mobile Dropdown container */
        .skyhshoso-mobile-menu-dropdown {
            background: white;
            border-top: 1px solid var(--header-border);
            border-bottom: 1px solid var(--header-border);
            position: absolute;
            top: 100%;
            left: 0;
            width: 100%;
            z-index: 999;
            box-shadow: var(--shadow-md);
            padding: 16px 24px;
            box-sizing: border-box;
            animation: skyhshoso-fade-in 0.15s ease-out;
        }

        .skyhshoso-mobile-menu-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .skyhshoso-mobile-menu-list li {
            position: relative;
        }

        .skyhshoso-mobile-menu-list li a {
            display: block;
            font-size: 15px;
            font-weight: 600;
            color: var(--text-main);
            text-decoration: none;
            padding: 8px 12px;
            border-radius: var(--radius-md);
            transition: all 0.15s ease;
        }

        .skyhshoso-mobile-menu-list li a:hover,
        .skyhshoso-mobile-menu-list li.current-menu-item > a {
            background: var(--skyhs-bg-main);
            color: var(--primary-color);
        }

        /* Mobile submenus */
        .skyhshoso-mobile-menu-list li ul {
            list-style: none;
            margin: 8px 0 0 16px;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 6px;
            border-left: 2px solid var(--header-border);
            padding-left: 12px;
        }

        .skyhshoso-mobile-menu-list li ul li a {
            font-size: 14px;
            font-weight: 500;
            padding: 6px 10px;
        }

        /* Profile Dropdown Styling */
        .skyhshoso-app-profile-container {
            position: relative;
            display: inline-block;
        }

        .skyhshoso-app-profile-trigger {
            display: flex;
            align-items: center;
            gap: 8px;
            background: transparent;
            border: 1px solid var(--header-border);
            padding: 6px 14px;
            border-radius: 9999px;
            cursor: pointer;
            transition: all 0.2s ease;
            outline: none;
        }

        .skyhshoso-app-profile-trigger:hover,
        .skyhshoso-app-profile-trigger:focus {
            background: rgba(0, 0, 0, 0.02);
            border-color: #cbd5e1;
        }

        .skyhshoso-app-user-icon {
            width: 20px;
            height: 20px;
            color: var(--text-muted);
            display: block;
            transition: color 0.2s ease;
        }

        .skyhshoso-app-profile-trigger:hover .skyhshoso-app-user-icon,
        .skyhshoso-app-profile-trigger:focus .skyhshoso-app-user-icon {
            color: var(--primary-color);
        }

        .skyhshoso-app-profile-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-main);
        }

        .skyhshoso-app-chevron {
            color: var(--text-muted);
            transition: transform 0.2s ease;
        }

        .skyhshoso-app-profile-container.active .skyhshoso-app-chevron {
            transform: rotate(180deg);
        }

        .skyhshoso-app-profile-dropdown {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            width: 200px;
            background: white;
            border: 1px solid var(--header-border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            padding: 8px;
            display: none;
            flex-direction: column;
            gap: 4px;
            z-index: 1001;
            transform-origin: top right;
            animation: skyhshoso-fade-in 0.15s ease-out;
        }

        .skyhshoso-app-profile-container.active .skyhshoso-app-profile-dropdown {
            display: flex;
        }

        @keyframes skyhshoso-fade-in {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .skyhshoso-dropdown-header {
            padding: 8px 12px;
            display: flex;
            flex-direction: column;
            text-align: left;
        }

        .skyhshoso-dropdown-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            font-weight: 500;
        }

        .skyhshoso-dropdown-username {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-main);
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .skyhshoso-dropdown-divider {
            height: 1px;
            background: var(--header-border);
            margin: 4px 0;
        }

        .skyhshoso-dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-main);
            text-decoration: none;
            border-radius: var(--radius-md);
            transition: all 0.15s ease;
            text-align: left;
        }

        .skyhshoso-dropdown-item:hover {
            background: var(--skyhs-bg-main);
            color: var(--primary-color);
        }

        .skyhshoso-dropdown-item svg {
            color: var(--text-muted);
            transition: color 0.15s ease;
            flex-shrink: 0;
        }

        .skyhshoso-dropdown-item:hover svg {
            color: var(--primary-color);
        }

        .skyhshoso-dropdown-item-danger {
            color: var(--danger-color);
        }

        .skyhshoso-dropdown-item-danger:hover {
            background: #fef2f2;
            color: var(--danger-color);
        }

        .skyhshoso-dropdown-item-danger svg {
            color: #fca5a5;
        }

        .skyhshoso-dropdown-item-danger:hover svg {
            color: var(--danger-color);
        }

        /* Layout Body */
        .skyhshoso-app-body {
            flex: 1;
            max-width: 1280px;
            width: 100%;
            margin: 0 auto;
            padding: 32px 24px;
            box-sizing: border-box;
        }

        /* Adjust dashboard margin settings to occupy page cleanly */
        .skyhshoso-app-body .skyhshoso-dashboard-container {
            margin-top: 0 !important;
            padding: 0 !important;
            box-shadow: none !important;
            background: transparent !important;
        }

        /* Minimal clean footer */
        .skyhshoso-app-footer {
            text-align: center;
            padding: 24px;
            font-size: 13px;
            color: var(--text-muted);
            border-top: 1px solid var(--header-border);
            background: white;
            margin-top: auto;
        }

        /* Responsive changes */
        @media (max-width: 768px) {
            .skyhshoso-app-header-content {
                padding: 12px 16px;
            }
            .skyhshoso-app-profile-name {
                display: none;
            }
            .skyhshoso-app-profile-trigger {
                padding: 6px;
            }
            .skyhshoso-app-body {
                padding: 16px;
            }
            .skyhshoso-btn-text {
                display: none;
            }
            .skyhshoso-btn-icon-only {
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }
            .skyhshoso-app-nav-btn {
                padding: 8px;
                border-radius: 50%;
                width: 36px;
                height: 36px;
            }
            .skyhshoso-header-nav-desktop {
                display: none;
            }
            .skyhshoso-mobile-menu-toggle {
                display: inline-flex;
            }
        }
    </style>
</head>
<body <?php body_class( 'skyhshoso-standalone-canvas' ); ?>>

<div class="skyhshoso-app-container">

    <?php
    $options         = get_option( 'skyhshoso_settings_group', array() );
    $custom_logo     = isset( $options['custom_logo'] ) ? $options['custom_logo'] : '';
    $custom_sitename = isset( $options['custom_sitename'] ) && '' !== $options['custom_sitename'] ? $options['custom_sitename'] : get_bloginfo( 'name' );
    $show_only_logo  = isset( $options['show_only_logo'] ) ? (bool) $options['show_only_logo'] : false;

    // Custom Header Navigation Menu Logic
    $header_menu_id  = isset( $options['header_menu_id'] ) ? $options['header_menu_id'] : '';
    $use_custom_menu = false;
    $menu_args       = false;

    if ( 'location' === $header_menu_id || ( empty( $header_menu_id ) && has_nav_menu( 'skyhshoso_dashboard_header' ) ) ) {
        $use_custom_menu = true;
        $menu_args = array(
            'theme_location' => 'skyhshoso_dashboard_header',
            'fallback_cb'    => false,
            'container'      => false,
            'menu_class'     => 'skyhshoso-header-menu-list',
            'depth'          => 2,
        );
    } elseif ( ! empty( $header_menu_id ) && is_numeric( $header_menu_id ) ) {
        $use_custom_menu = true;
        $menu_args = array(
            'menu'        => (int) $header_menu_id,
            'fallback_cb' => false,
            'container'   => false,
            'menu_class'  => 'skyhshoso-header-menu-list',
            'depth'          => 2,
        );
    }

    // Grab first letter of sitename for fallback logo icon
    $logo_fallback_letter = mb_strtoupper( mb_substr( $custom_sitename, 0, 1 ) );
    ?>

    <!-- Premium Header -->
    <header class="skyhshoso-app-header">
        <div class="skyhshoso-app-header-content">
            <!-- Logo Section -->
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="skyhshoso-app-logo-area">
                <?php if ( ! empty( $custom_logo ) ) : ?>
                    <img class="skyhshoso-app-logo-image" src="<?php echo esc_url( $custom_logo ); ?>" alt="<?php echo esc_attr( $custom_sitename ); ?>" style="max-height: 36px; width: auto; object-fit: contain; border-radius: var(--radius-md);" />
                <?php else : ?>
                    <div class="skyhshoso-app-logo-icon"><?php echo esc_html( $logo_fallback_letter ); ?></div>
                <?php endif; ?>

                <?php if ( ! $show_only_logo ) : ?>
                    <span class="skyhshoso-app-logo-text"><?php echo esc_html( $custom_sitename ); ?></span>
                <?php endif; ?>
            </a>

            <!-- Navigation Menu Section (Desktop) -->
            <?php if ( $use_custom_menu ) : ?>
                <nav class="skyhshoso-header-nav-desktop" aria-label="<?php esc_attr_e( 'Header Navigation', 'skyhs-hosting-solution' ); ?>">
                    <?php wp_nav_menu( $menu_args ); ?>
                </nav>
            <?php endif; ?>

            <!-- User Menu Section -->
            <div class="skyhshoso-app-user-menu">
                <?php if ( $is_guest ) : ?>
                    <div class="skyhshoso-app-nav-links">
                        <?php if ( ! $use_custom_menu ) : ?>
                            <a href="<?php echo esc_url( SkyHSHOSO_Settings::get_back_to_site_url() ); ?>" class="skyhshoso-app-nav-btn skyhshoso-app-nav-btn-secondary" title="<?php esc_attr_e( 'Return to Site', 'skyhs-hosting-solution' ); ?>">
                                <span class="skyhshoso-btn-icon-only">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                                </span>
                                <span class="skyhshoso-btn-text"><?php esc_html_e( 'Return to Site', 'skyhs-hosting-solution' ); ?></span>
                            </a>
                        <?php endif; ?>
                        <a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" class="skyhshoso-app-nav-btn skyhshoso-app-nav-btn-primary" title="<?php esc_attr_e( 'Sign In', 'skyhs-hosting-solution' ); ?>">
                            <span class="skyhshoso-btn-icon-only">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                            </span>
                            <span class="skyhshoso-btn-text"><?php esc_html_e( 'Sign In', 'skyhs-hosting-solution' ); ?></span>
                        </a>
                    </div>
                <?php else : ?>
                    <div class="skyhshoso-app-profile-container">
                        <button class="skyhshoso-app-profile-trigger" aria-haspopup="true" aria-expanded="false">
                            <svg class="skyhshoso-app-user-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <span class="skyhshoso-app-profile-name"><?php echo esc_html( $display_name ); ?></span>
                            <svg class="skyhshoso-app-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="skyhshoso-app-profile-dropdown">
                            <div class="skyhshoso-dropdown-header">
                                <span class="skyhshoso-dropdown-label"><?php esc_html_e( 'Logged in as', 'skyhs-hosting-solution' ); ?></span>
                                <span class="skyhshoso-dropdown-username" title="<?php echo esc_attr( $display_name ); ?>"><?php echo esc_html( $display_name ); ?></span>
                            </div>
                            <div class="skyhshoso-dropdown-divider"></div>
                            <a href="<?php echo esc_url( SkyHSHOSO_Settings::get_back_to_site_url() ); ?>" class="skyhshoso-dropdown-item">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                                <?php esc_html_e( 'Return to Site', 'skyhs-hosting-solution' ); ?>
                            </a>
                            <a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>" class="skyhshoso-dropdown-item skyhshoso-dropdown-item-danger">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1-2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                                <?php esc_html_e( 'Log Out', 'skyhs-hosting-solution' ); ?>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ( $use_custom_menu ) : ?>
                    <button class="skyhshoso-mobile-menu-toggle" aria-label="<?php esc_attr_e( 'Toggle navigation menu', 'skyhs-hosting-solution' ); ?>" aria-expanded="false">
                        <svg class="skyhshoso-menu-icon-open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/></svg>
                        <svg class="skyhshoso-menu-icon-close" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><line x1="18" x2="6" y1="6" y2="18"/><line x1="6" x2="18" y1="6" y2="18"/></svg>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Mobile Menu Section -->
        <?php if ( $use_custom_menu ) : ?>
            <div class="skyhshoso-mobile-menu-dropdown" style="display: none;">
                <nav class="skyhshoso-header-nav-mobile" aria-label="<?php esc_attr_e( 'Mobile Header Navigation', 'skyhs-hosting-solution' ); ?>">
                    <?php
                    wp_nav_menu( array_merge( $menu_args, array( 'menu_class' => 'skyhshoso-mobile-menu-list' ) ) );
                    ?>
                </nav>
            </div>
        <?php endif; ?>
    </header>

    <!-- Main Content Canvas -->
    <main class="skyhshoso-app-body">
        <?php
        // Loop posts to print the shortcode content of the dashboard page
        while ( have_posts() ) :
            the_post();
            the_content();
        endwhile;
        ?>
    </main>

    <!-- Footer Section -->
    <footer class="skyhshoso-app-footer">
        <p>&copy; <?php echo esc_html( date( 'Y' ) ); ?> <?php echo esc_html( $custom_sitename ); ?>. <?php esc_html_e( 'All rights reserved.', 'skyhs-hosting-solution' ); ?></p>
    </footer>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var trigger = document.querySelector('.skyhshoso-app-profile-trigger');
    var container = document.querySelector('.skyhshoso-app-profile-container');
    
    if (trigger && container) {
        trigger.addEventListener('click', function(e) {
            e.stopPropagation();
            var isActive = container.classList.contains('active');
            if (isActive) {
                container.classList.remove('active');
                trigger.setAttribute('aria-expanded', 'false');
            } else {
                container.classList.add('active');
                trigger.setAttribute('aria-expanded', 'true');
                
                // Close mobile navigation menu if open
                if (mobileToggle && mobileToggle.getAttribute('aria-expanded') === 'true') {
                    mobileToggle.setAttribute('aria-expanded', 'false');
                    mobileDropdown.style.display = 'none';
                    if (openIcon) openIcon.style.display = 'block';
                    if (closeIcon) closeIcon.style.display = 'none';
                }
            }
        });
        
        document.addEventListener('click', function(e) {
            if (!container.contains(e.target)) {
                container.classList.remove('active');
                trigger.setAttribute('aria-expanded', 'false');
            }
        });
    }

    var mobileToggle = document.querySelector('.skyhshoso-mobile-menu-toggle');
    var mobileDropdown = document.querySelector('.skyhshoso-mobile-menu-dropdown');
    var openIcon = document.querySelector('.skyhshoso-menu-icon-open');
    var closeIcon = document.querySelector('.skyhshoso-menu-icon-close');
    
    if (mobileToggle && mobileDropdown) {
        mobileToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            var isExpanded = mobileToggle.getAttribute('aria-expanded') === 'true';
            if (isExpanded) {
                mobileToggle.setAttribute('aria-expanded', 'false');
                mobileDropdown.style.display = 'none';
                if (openIcon) openIcon.style.display = 'block';
                if (closeIcon) closeIcon.style.display = 'none';
            } else {
                mobileToggle.setAttribute('aria-expanded', 'true');
                mobileDropdown.style.display = 'block';
                if (openIcon) openIcon.style.display = 'none';
                if (closeIcon) closeIcon.style.display = 'block';
                
                // Close user profile dropdown if open
                if (container && container.classList.contains('active')) {
                    container.classList.remove('active');
                    if (trigger) trigger.setAttribute('aria-expanded', 'false');
                }
            }
        });
        
        document.addEventListener('click', function(e) {
            if (!mobileDropdown.contains(e.target) && !mobileToggle.contains(e.target)) {
                mobileToggle.setAttribute('aria-expanded', 'false');
                mobileDropdown.style.display = 'none';
                if (openIcon) openIcon.style.display = 'block';
                if (closeIcon) closeIcon.style.display = 'none';
            }
        });
    }
});
</script>

<?php wp_footer(); ?>
</body>
</html>
