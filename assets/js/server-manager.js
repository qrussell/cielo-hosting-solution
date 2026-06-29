/**
 * SkyHS Server Manager UI
 */
jQuery(document).ready(function($) {
    'use strict';

    var data = window.skyhshoso_sm || {};

    function showNotice(type, msg) {
        var $n = $('#skyhshoso-sm-notice');
        $n.removeClass('notice-success notice-error notice-info').addClass('notice-' + type).html('<p>' + msg + '</p>').show();
        if (type === 'success') $n.delay(6000).fadeOut();
    }

    // -------------------------------------------------------------------------
    // Edit server — populate form
    // -------------------------------------------------------------------------

    $(document).on('click', '.sm-edit-server', function() {
        var id = $(this).data('id');

        // Find server in local data
        var server = null;
        $.each(data.servers, function(i, s) {
            if (String(s.id) === String(id)) { server = s; return false; }
        });

        if (!server) {
            var $card = $(this).closest('.skyhshoso-sm-server-card');
            var name = $card.find('h3').text().trim();
            $('#sm_server_id').val(id);
            $('#sm_name').val(name);
            $('#sm_host').val(server ? server.host : '');
            $('#sm_user').val(server ? server.user : '');
            $('#sm_token').val('').prop('placeholder', 'Leave blank to keep existing');
            $('#skyhshoso-sm-form-title').text('Edit Server');
            $('#sm-submit').text('Update Server');
            $('#skyhshoso-sm-form').data('edit-mode', '1');
            $('html, body').animate({ scrollTop: $('#skyhshoso-sm-form').offset().top - 40 }, 400);
            return;
        }

        $('#sm_server_id').val(server.id);
        $('#sm_name').val(server.name);
        $('#sm_host').val(server.host);
        $('#sm_user').val(server.user);
        $('#sm_token').val(server.token);
        $('#sm_server_ip').val(server.server_ip || '');
        if (server.nameservers && server.nameservers.length > 0) {
            $('.sm-ns-input').each(function(i) {
                $(this).val(server.nameservers[i] || '');
            });
        }
        $('#skyhshoso-sm-form-title').text('Edit Server');
        $('#sm-submit').text('Update Server');
        $('#skyhshoso-sm-form').data('edit-mode', '1');

        // Show saved plans in test results area
        if (server.plan_list && server.plan_list.length > 0) {
            var html = '<p><strong>Saved packages:</strong></p><div class="sm-plan-tags">';
            $.each(server.plan_list, function(i, pkg) {
                html += '<span class="sm-plan-tag">' + pkg.replace(/_/g, ' ') + '</span>';
            });
            html += '</div>';
            $('#sm-test-plans').html(html);
            $('#sm-test-status').html('<div class="notice notice-success inline"><p>Server has ' + server.plan_list.length + ' packages.</p></div>');
            $('#sm-test-results').show();
        } else {
            $('#sm-test-results').hide();
        }

        $('html, body').animate({ scrollTop: $('#skyhshoso-sm-form').offset().top - 40 }, 400);
    });

    // -------------------------------------------------------------------------
    // Reset form for new server
    // -------------------------------------------------------------------------

    function resetForm() {
        $('#skyhshoso-sm-form')[0].reset();
        $('#sm_server_id').val('0');
        $('#sm_token').prop('placeholder', '');
        $('#skyhshoso-sm-form-title').text('Add New Server');
        $('#sm-submit').text('Save Server');
        $('#skyhshoso-sm-form').data('edit-mode', '0');
        $('#sm-test-results').hide();
        $('#sm-test-status').empty();
        $('#sm-test-plans').empty();
        $('#sm-test-result').text('').removeClass('success error');
    }

    // "Add New" button in header (if we add one later)
    $(document).on('click', '.sm-add-new', resetForm);

    // -------------------------------------------------------------------------
    // Test & Sync WHM connection
    // -------------------------------------------------------------------------

    $('#sm-test-btn').on('click', function() {
        var host = $('#sm_host').val().trim();
        var user = $('#sm_user').val().trim();
        var token = $('#sm_token').val().trim();

        if (!host || !user || !token) {
            $('#sm-test-result').text('Fill WHM credentials first.').addClass('error');
            return;
        }

        var $btn = $(this);
        var $result = $('#sm-test-result');
        $btn.prop('disabled', true);
        $result.text(data.strings.testing).removeClass('success error');
        $('#sm-loader').addClass('is-active');
        $('#sm-test-results').hide();

        $.post(data.ajax_url, {
            action: 'skyhshoso_test_whm',
            nonce: data.nonce_test,
            host: host,
            user: user,
            token: token
        }, function(resp) {
            if (resp.success) {
                $result.text(resp.data.message).addClass('success');
                // Show plans
                var $plans = $('#sm-test-plans');
                $plans.empty();
                if (resp.data.plans && Object.keys(resp.data.plans).length > 0) {
                    var html = '<p><strong>Packages found:</strong></p><div class="sm-plan-tags">';
                    $.each(resp.data.plans, function(key, label) {
                        html += '<span class="sm-plan-tag">' + label + '</span>';
                    });
                    html += '</div>';
                    $plans.html(html);
                } else {
                    $plans.html('<p>No packages with default feature list found.</p>');
                }
                $('#sm-test-results').show();
                $('#sm-test-status').html('<div class="notice notice-success inline"><p>' + resp.data.message + '</p></div>');
            } else {
                $result.text(resp.data.message || 'Connection failed.').addClass('error');
                $('#sm-test-results').show();
                $('#sm-test-status').html('<div class="notice notice-error inline"><p>' + (resp.data.message || 'Connection failed.') + '</p></div>');
                $('#sm-test-plans').empty();
            }
        }).fail(function() {
            $result.text('Request failed.').addClass('error');
        }).always(function() {
            $btn.prop('disabled', false);
            $('#sm-loader').removeClass('is-active');
        });
    });

    // -------------------------------------------------------------------------
    // Sync existing server (from card)
    // -------------------------------------------------------------------------

    $(document).on('click', '.sm-sync-server', function() {
        var id = $(this).data('id');
        var $btn = $(this);
        var $card = $(this).closest('.skyhshoso-sm-server-card');

        // Fetch server data via POST with the save endpoint but with existing ID
        // Actually, we just trigger a re-sync by saving the same server
        // Find in our data
        var server = null;
        $.each(data.servers, function(i, s) {
            if (String(s.id) === String(id)) { server = s; return false; }
        });

        if (!server) return;

        $btn.prop('disabled', true).text('Syncing...');

        $.post(data.ajax_url, {
            action: 'skyhshoso_save_server',
            nonce: data.nonce_save,
            server_id: id,
            name: server.name,
            host: server.host,
            user: server.user,
            token: 'EXISTING_TOKEN_PLACEHOLDER', // token exists in meta
            server_ip: server.server_ip || '',
            nameservers: server.nameservers || []
        }, function(resp) {
            if (resp.success) {
                showNotice('success', resp.data.message);
                // Reload after short delay
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                showNotice('error', resp.data.message);
                $btn.prop('disabled', false).text('Sync');
            }
        }).fail(function() {
            showNotice('error', 'Sync request failed.');
            $btn.prop('disabled', false).text('Sync');
        });
    });

    // -------------------------------------------------------------------------
    // Sync cPanel accounts from server card
    // -------------------------------------------------------------------------

    $(document).on('click', '.sm-sync-cpanel', function() {
        var id = $(this).data('id');
        var $btn = $(this);

        $btn.prop('disabled', true).text('Syncing...');

        $.post(data.ajax_url, {
            action: 'skyhshoso_cpanel_sync_fetch',
            nonce: data.nonce_cpanel_sync,
            server_id: id
        }, function(resp) {
            if (resp.success) {
                showNotice('success', resp.data.message);
            } else {
                showNotice('error', resp.data.message || 'Sync failed.');
            }
        }).fail(function() {
            showNotice('error', 'Sync request failed.');
        }).always(function() {
            $btn.prop('disabled', false).text('Sync cPanel');
        });
    });

    // -------------------------------------------------------------------------
    // Delete server
    // -------------------------------------------------------------------------

    $(document).on('click', '.sm-delete-server', function() {
        if (!confirm(data.strings.confirm_delete)) return;

        var id = $(this).data('id');
        var $btn = $(this);
        var $card = $(this).closest('.skyhshoso-sm-server-card');

        $btn.prop('disabled', true).text('...');

        $.post(data.ajax_url, {
            action: 'skyhshoso_delete_server',
            nonce: data.nonce_delete,
            server_id: id
        }, function(resp) {
            if (resp.success) {
                $card.fadeOut(300, function() { $(this).remove(); });
                showNotice('success', resp.data.message);
                if ($('.skyhshoso-sm-server-card').length === 0) {
                    location.reload(); // reload to show empty state
                }
            } else {
                showNotice('error', resp.data.message);
                $btn.prop('disabled', false).text('Delete');
            }
        }).fail(function() {
            showNotice('error', 'Delete request failed.');
            $btn.prop('disabled', false).text('Delete');
        });
    });

    // -------------------------------------------------------------------------
    // Save server form
    // -------------------------------------------------------------------------

    $('#skyhshoso-sm-form').on('submit', function(e) {
        e.preventDefault();

        var name = $('#sm_name').val().trim();
        var host = $('#sm_host').val().trim();
        var user = $('#sm_user').val().trim();
        var token = $('#sm_token').val().trim();
        var serverIp = $('#sm_server_ip').val().trim();
        var nameservers = [];
        $('.sm-ns-input').each(function() {
            nameservers.push($(this).val().trim());
        });
        var serverId = $('#sm_server_id').val();

        // In edit mode, token is optional (keep existing)
        var isEdit = $(this).data('edit-mode') === '1';

        if (!name || !host || !user) {
            showNotice('error', data.strings.fill_fields);
            return;
        }

        if (!isEdit && !token) {
            showNotice('error', data.strings.fill_fields);
            return;
        }

        // In edit mode, send placeholder so PHP keeps the existing token
        if (isEdit && !token) {
            token = 'EXISTING_TOKEN_PLACEHOLDER';
        }

        var $btn = $('#sm-submit');
        var $loader = $('#sm-loader');
        $btn.prop('disabled', true);
        $loader.addClass('is-active');
        showNotice('info', data.strings.saving);

        $.post(data.ajax_url, {
            action: 'skyhshoso_save_server',
            nonce: data.nonce_save,
            server_id: serverId,
            name: name,
            host: host,
            user: user,
            token: token,
            server_ip: serverIp,
            nameservers: nameservers
        }, function(resp) {
            if (resp.success) {
                showNotice('success', resp.data.message);
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                showNotice('error', resp.data.message);
            }
        }).fail(function() {
            showNotice('error', data.strings.error);
        }).always(function() {
            $btn.prop('disabled', false);
            $loader.removeClass('is-active');
        });
    });
});
