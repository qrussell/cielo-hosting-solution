jQuery(document).ready(function($) {
    let currentStep = 1;
    const totalSteps = 3;

    function showStep(step) {
        $('.skyhshoso-wizard-step').removeClass('active');
        $('#step-' + step).addClass('active');

        $('.skyhshoso-wizard-step-link').removeClass('active completed');
        for (let i = 1; i <= totalSteps; i++) {
            if (i < step) {
                $('.skyhshoso-wizard-step-link[data-step="' + i + '"]').addClass('completed');
            } else if (i === step) {
                $('.skyhshoso-wizard-step-link[data-step="' + i + '"]').addClass('active');
            }
        }
    }

    function showNotice(stepId, message, type) {
        const noticeEl = $('#' + stepId).find('.skyhshoso-wizard-notice');
        noticeEl.removeClass('error success').addClass(type).html(message).show();
    }

    function hideNotice(stepId) {
        $('#' + stepId).find('.skyhshoso-wizard-notice').hide();
    }

    // Step 1: Save Server
    $('#save-server-btn').on('click', function(e) {
        e.preventDefault();
        const btn = $(this);
        const stepId = 'step-1';
        
        hideNotice(stepId);
        
        const serverName = $('#server_name').val();
        const whmUserId = $('#whm_user_id').val();
        const whmToken = $('#whm_token').val();
        const whmHost = $('#whm_host').val();

        if (!serverName || !whmUserId || !whmToken || !whmHost) {
            showNotice(stepId, skyhshoso_wizard_data.strings.fill_all_fields, 'error');
            return;
        }

        btn.prop('disabled', true);
        btn.siblings('.skyhshoso-loader').css('display', 'inline-block');

        $.ajax({
            url: skyhshoso_wizard_data.ajax_url,
            type: 'POST',
            data: {
                action: 'skyhshoso_wizard_save_server',
                nonce: skyhshoso_wizard_data.nonce,
                server_name: serverName,
                whm_user_id: whmUserId,
                whm_token: whmToken,
                whm_host: whmHost
            },
            success: function(response) {
                if (response.success) {
                    showNotice(stepId, response.data.message, 'success');
                    
                    if (response.data.packages && response.data.packages.length > 0) {
                        let pkgHtml = '<h4>' + skyhshoso_wizard_data.strings.packages_found + '</h4><ul>';
                        response.data.packages.forEach(function(pkg) {
                            pkgHtml += '<li>' + pkg.replace(/_/g, ' ') + '</li>';
                        });
                        pkgHtml += '</ul>';
                        $('#wizard-packages-container').html(pkgHtml).show();
                    }
                    
                    setTimeout(function() {
                        currentStep = 2;
                        showStep(currentStep);
                    }, 2000);
                } else {
                    showNotice(stepId, response.data || skyhshoso_wizard_data.strings.unknown_error, 'error');
                }
            },
            error: function() {
                showNotice(stepId, skyhshoso_wizard_data.strings.ajax_error, 'error');
            },
            complete: function() {
                btn.prop('disabled', false);
                btn.siblings('.skyhshoso-loader').hide();
            }
        });
    });

    // Step 2: Save Enom
    $('#save-enom-btn').on('click', function(e) {
        e.preventDefault();
        const btn = $(this);
        const stepId = 'step-2';
        
        hideNotice(stepId);
        
        btn.prop('disabled', true);
        btn.siblings('.skyhshoso-loader').css('display', 'inline-block');

        $.ajax({
            url: skyhshoso_wizard_data.ajax_url,
            type: 'POST',
            data: {
                action: 'skyhshoso_wizard_save_enom',
                nonce: skyhshoso_wizard_data.nonce,
                enom_mode: $('#enom_mode').val(),
                enom_live_username: $('#enom_live_username').val(),
                enom_live_password: $('#enom_live_password').val(),
                enom_test_username: $('#enom_test_username').val(),
                enom_test_password: $('#enom_test_password').val()
            },
            success: function(response) {
                if (response.success) {
                    showNotice(stepId, response.data.message, 'success');
                    setTimeout(function() {
                        currentStep = 3;
                        showStep(currentStep);
                    }, 1000);
                } else {
                    showNotice(stepId, response.data || skyhshoso_wizard_data.strings.unknown_error, 'error');
                }
            },
            error: function() {
                showNotice(stepId, skyhshoso_wizard_data.strings.ajax_error, 'error');
            },
            complete: function() {
                btn.prop('disabled', false);
                btn.siblings('.skyhshoso-loader').hide();
            }
        });
    });

    // Step 3: Save Dashboard
    $('#save-dashboard-btn').on('click', function(e) {
        e.preventDefault();
        const btn = $(this);
        const stepId = 'step-3';
        
        hideNotice(stepId);
        
        btn.prop('disabled', true);
        btn.siblings('.skyhshoso-loader').css('display', 'inline-block');

        $.ajax({
            url: skyhshoso_wizard_data.ajax_url,
            type: 'POST',
            data: {
                action: 'skyhshoso_wizard_save_dashboard',
                nonce: skyhshoso_wizard_data.nonce,
                dashboard_action: $('input[name="dashboard_action"]:checked').val(),
                existing_page_id: $('#existing_page_id').val()
            },
            success: function(response) {
                if (response.success) {
                    showNotice(stepId, response.data.message, 'success');
                    
                    // Transform step 3 into success screen
                    $('#step-3 h2').text(skyhshoso_wizard_data.strings.setup_complete);
                    $('#step-3 .skyhshoso-wizard-form-group').hide();
                    $('#step-3 .skyhshoso-wizard-actions').html('<a href="' + skyhshoso_wizard_data.dashboard_url + '" class="skyhshoso-wizard-btn skyhshoso-wizard-btn-primary">' + skyhshoso_wizard_data.strings.go_to_dashboard + '</a>');
                    $('#step-3').prepend('<div class="skyhshoso-wizard-success-icon">✓</div>');
                    
                } else {
                    showNotice(stepId, response.data || skyhshoso_wizard_data.strings.unknown_error, 'error');
                }
            },
            error: function() {
                showNotice(stepId, skyhshoso_wizard_data.strings.ajax_error, 'error');
            },
            complete: function() {
                btn.prop('disabled', false);
                btn.siblings('.skyhshoso-loader').hide();
            }
        });
    });

    // Toggle Dashboard Select
    $('input[name="dashboard_action"]').on('change', function() {
        if ($(this).val() === 'existing') {
            $('#existing_page_container').slideDown();
        } else {
            $('#existing_page_container').slideUp();
        }
    });

    // Skip Buttons
    $('.skip-step').on('click', function(e) {
        e.preventDefault();
        if (currentStep < totalSteps) {
            currentStep++;
            showStep(currentStep);
        }
    });
});
