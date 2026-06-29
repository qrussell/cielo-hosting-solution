/**
 * Admin menu functionality for Hosting Solution plugin
 * Ensures submenus stay open when visiting custom pages
 */
(function ($) {
    $(document).ready(function () {
        // Get current URL
        var currentUrl = window.location.href;

        // Check if we're on Enom settings page
        if (currentUrl.indexOf('page=enom-settings') !== -1) {
            // Find and highlight the Enom settings menu item
            $('#toplevel_page_skyhshoso-dashboard').addClass('wp-has-current-submenu wp-menu-open').removeClass('wp-not-current-submenu');
            $('#toplevel_page_skyhshoso-dashboard > a').addClass('wp-has-current-submenu').removeClass('wp-not-current-submenu');
            $('#toplevel_page_skyhshoso-dashboard li a[href*="enom-settings"]').addClass('current');
        }

    });
})(jQuery); 