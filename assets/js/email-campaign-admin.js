jQuery(function($) {

	var ECA = skyhshoso_eca;
	var campaigns = ECA.campaigns || [];
	var categories = ECA.categories || [];
	var selectedProducts = {};
	var selectedCats = {};

	function escHtml(s) {
		if (!s) return '';
		return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	function showNotice(msg, type) {
		var $el = $('#skyhshoso-hm-notice');
		$el.removeClass('success error').addClass(type || 'success')
			.html('<p>' + msg + '</p>').show();
		setTimeout(function() { $el.fadeOut(); }, 5000);
	}

	/* Convert jQuery POST to a promise-like helper with error handling */
	function ajaxPost(action, data, onSuccess) {
		var payload = $.extend({ nonce: ECA.nonce, action: action }, data);
		return $.post(ECA.ajax_url, payload).done(function(res) {
			if (res.success) {
				if (onSuccess) onSuccess(res);
			} else {
				showNotice(res.data && res.data.message ? res.data.message : 'Request failed.', 'error');
			}
		}).fail(function(jqXHR) {
			var msg = 'AJAX error';
			if (jqXHR.responseText && jqXHR.responseText.length < 200) {
				msg = jqXHR.responseText.trim();
			}
			showNotice(msg + ' — check console for details.', 'error');
			console.error('SkyHS AJAX error:', jqXHR.status, jqXHR.responseText);
		});
	}

	function showModal(id) { $('#' + id).fadeIn(150); }
	function hideModal(id) { $('#' + id).fadeOut(150); }

	function getTargetLabel(c) {
		var m = { products: 'Products', categories: 'Categories', manual: 'Manual' };
		return m[c.target_type] || c.target_type;
	}

	function getTargetValue(c) {
		var ids = c.target_ids || [];
		if (c.target_type === 'products') return ids.length + ' product' + (ids.length !== 1 ? 's' : '');
		if (c.target_type === 'categories') return ids.length + ' categor' + (ids.length !== 1 ? 'ies' : 'y');
		if (c.target_type === 'manual') return ids.length + ' user' + (ids.length !== 1 ? 's' : '');
		return '';
	}

	function formatDate(d) {
		if (!d) return '';
		return new Date(d.replace(' ', 'T') + 'Z').toLocaleDateString();
	}

	/* ---- Table ---- */
	function refreshTable() {
		var $tb = $('#ec-tbody');
		$tb.empty();
		if (!campaigns.length) {
			$tb.append('<tr id="ec-empty-row"><td colspan="7" style="text-align:center;padding:40px;color:#6b7280;">No campaigns yet.</td></tr>');
			return;
		}
		campaigns.forEach(function(c) {
			var active = c.is_active
				? '<span class="ec-badge ec-badge-active">Active</span>'
				: '<span class="ec-badge ec-badge-inactive">Inactive</span>';
			var trigger = c.trigger_type === 'immediate'
				? 'Immediate'
				: c.delay_value + ' ' + c.delay_unit + (c.delay_value > 1 ? 's' : '');
			$tb.append('<tr>' +
				'<td><strong>' + escHtml(c.name) + '</strong></td>' +
				'<td>' + escHtml(getTargetLabel(c)) + '<br><small style="color:#6b7280;">' + escHtml(getTargetValue(c)) + '</small></td>' +
				'<td>' + escHtml(trigger) + '</td>' +
				'<td>' + active + '</td>' +
				'<td><small style="color:#6b7280;">' + formatDate(c.created_at) + '</small></td>' +
				'<td class="ec-actions-cell">' +
					'<button class="ec-btn-edit" data-id="' + c.id + '" style="margin-right:4px;">Edit</button>' +
					'<button class="ec-btn-sendnow" data-id="' + c.id + '" style="margin-right:4px;">Send Now</button>' +
					'<button class="ec-btn-toggle" data-id="' + c.id + '" style="margin-right:4px;">' + (c.is_active ? 'Deactivate' : 'Activate') + '</button>' +
					'<button class="ec-btn-dup" data-id="' + c.id + '" style="margin-right:4px;">Dup</button>' +
					'<button class="ec-btn-del" data-id="' + c.id + '">Del</button>' +
				'</td></tr>');
		});
	} // end refreshTable

	/* ---- Form helpers ---- */
	function resetForm() {
		$('#ec_campaign_id').val('0');
		$('#ec_name,#ec_subject,#ec_body').val('');
		$('#ec_target_type').val('products').trigger('change');
		$('#ec_trigger_type').val('scheduled').trigger('change');
		$('#ec_delay_value').val('3');
		$('#ec_delay_unit').val('days');
		$('#ec_is_active').prop('checked', false);
		clearProducts();
		clearCats();
		$('#ec_manual_user_ids').val('');
		$('#ec-form-title').text('Add New Campaign');
	}

	function clearProducts() {
		selectedProducts = {};
		$('#ec_selected_products').empty();
		$('#ec_target_ids').val('');
	}

	function clearCats() {
		selectedCats = {};
		$('#ec_selected_cats').empty();
	}

	function renderSelectedProducts() {
		var $el = $('#ec_selected_products');
		$el.empty();
		var ids = [];
		$.each(selectedProducts, function(id, p) {
			ids.push(id);
			$el.append('<span class="ec-chip">' + escHtml(p.name) +
				' <button class="ec-chip-remove" data-type="product" data-id="' + id + '">×</button></span>');
		});
		$('#ec_target_ids').val(ids.join(','));
	}

	function renderSelectedCats() {
		var $el = $('#ec_selected_cats');
		$el.empty();
		$.each(selectedCats, function(id, c) {
			$el.append('<span class="ec-chip">' + escHtml(c.name) +
				' <button class="ec-chip-remove" data-type="category" data-id="' + id + '">×</button></span>');
		});
	}

	function populateForm(c) {
		$('#ec_campaign_id').val(c.id);
		$('#ec_name').val(c.name);
		$('#ec_subject').val(c.subject);
		$('#ec_body').val(c.body);
		$('#ec_target_type').val(c.target_type).trigger('change');
		$('#ec_trigger_type').val(c.trigger_type).trigger('change');
		$('#ec_delay_value').val(c.delay_value);
		$('#ec_delay_unit').val(c.delay_unit);
		$('#ec_is_active').prop('checked', c.is_active == 1);
		$('#ec-form-title').text('Edit Campaign');
		clearProducts();
		clearCats();
		$('#ec_manual_user_ids').val('');

		var ids = c.target_ids || [];
		if (c.target_type === 'products') {
			if (ids.length) {
				$('#ec_target_ids').val(ids.join(','));
				ids.forEach(function(pid) { selectedProducts[pid] = { id: pid, name: 'Product #' + pid }; });
				ajaxPost('skyhshoso_campaign_product_search', { search: '' }, function(res) {
					(res.data || []).forEach(function(p) {
						if (ids.indexOf(p.id) !== -1) selectedProducts[p.id] = p;
					});
					renderSelectedProducts();
				});
			}
		} else if (c.target_type === 'categories') {
			ids.forEach(function(cid) {
				var f = categories.find(function(x) { return x.id == cid; });
				selectedCats[cid] = { id: cid, name: f ? f.name : 'Category #' + cid };
			});
			renderSelectedCats();
		} else if (c.target_type === 'manual') {
			$('#ec_manual_user_ids').val(ids.join(', '));
		}
	}

	/* ---- Events ---- */
	$('#ec_target_type').on('change', function() {
		$('.ec-target-group').hide();
		var v = $(this).val();
		if (v === 'products') $('#ec-target-products').show();
		else if (v === 'categories') $('#ec-target-categories').show();
		else if (v === 'manual') $('#ec-target-manual').show();
	});

	$('#ec_trigger_type').on('change', function() {
		$('#ec-delay-row').toggle($(this).val() !== 'immediate');
	});

	$('#ec-btn-add').on('click', function() { resetForm(); $('#ec-form-panel').slideDown(); });
	$('#ec-btn-cancel').on('click', function() { $('#ec-form-panel').slideUp(); resetForm(); });

	/* ---- Product search ---- */
	var productTimer;
	function doProductSearch(q) {
		clearTimeout(productTimer);
		productTimer = setTimeout(function() {
			ajaxPost('skyhshoso_campaign_product_search', { search: q }, function(res) {
				var $r = $('#ec_product_results').empty();
				if (res.data && res.data.length) {
					res.data.forEach(function(p) {
						var added = selectedProducts[p.id];
				$r.append('<div class="ec-search-item' + (added ? ' ec-search-item-added' : '') + '" data-pid="' + p.id + '" data-pname="' + escHtml(p.name.replace(/"/g,'')) + '">' +
					'<strong>' + escHtml(p.name) + '</strong> ' + (p.price ? '<small>' + p.price + '</small>' : '') +
					(added ? ' <span style="color:#059669;">(added)</span>' : '') +
				'</div>');
					});
					$r.show();
				} else {
					$r.append('<div class="ec-search-item ec-search-item-none">No products found.</div>').show();
				}
			});
		}, 200);
	}

	$('#ec_product_search').on('focus input', function() { doProductSearch($(this).val()); });

	$(document).on('click', '.ec-search-item[data-pid]:not(.ec-search-item-added):not(.ec-search-item-none)', function(e) {
		e.preventDefault();
		var id = parseInt($(this).data('pid'));
		var name = $(this).data('pname');
		if (id) {
			selectedProducts[id] = { id: id, name: name };
			renderSelectedProducts();
		}
		$('#ec_product_results').hide();
		$('#ec_product_search').val('').focus();
	});

	/* ---- Category search ---- */
	var catTimer;
	function doCatSearch(q) {
		clearTimeout(catTimer);
		catTimer = setTimeout(function() {
			ajaxPost('skyhshoso_campaign_category_search', { search: q }, function(res) {
				var $r = $('#ec_cat_results').empty();
				if (res.data && res.data.length) {
					res.data.forEach(function(c) {
						var added = selectedCats[c.id];
						$r.append('<div class="ec-search-item' + (added ? ' ec-search-item-added' : '') + '" data-catid="' + c.id + '" data-cname="' + escHtml(c.name.replace(/"/g,'')) + '">' +
							'<strong>' + escHtml(c.name) + '</strong> <small>(' + c.count + ')</small>' +
							(added ? ' <span style="color:#059669;">(added)</span>' : '') +
						'</div>');
					});
					$r.show();
				} else {
					$r.append('<div class="ec-search-item ec-search-item-none">No categories found.</div>').show();
				}
			});
		}, 200);
	}

	$('#ec_cat_search').on('focus input', function() { doCatSearch($(this).val()); });

	$(document).on('click', '.ec-search-item[data-catid]:not(.ec-search-item-added):not(.ec-search-item-none)', function(e) {
		e.preventDefault();
		var id = parseInt($(this).data('catid'));
		var name = $(this).data('cname');
		if (id) {
			selectedCats[id] = { id: id, name: name };
			renderSelectedCats();
		}
		$('#ec_cat_results').hide();
		$('#ec_cat_search').val('').focus();
	});

	/* ---- Chip remove ---- */
	$(document).on('click', '.ec-chip-remove', function(e) {
		e.stopPropagation();
		var t = $(this).data('type');
		var id = $(this).data('id');
		if (t === 'product') { delete selectedProducts[id]; renderSelectedProducts(); }
		else if (t === 'category') { delete selectedCats[id]; renderSelectedCats(); }
	});

	/* Close dropdowns on outside click */
	$(document).on('mousedown', function(e) {
		if (!$(e.target).closest('#ec_product_search, #ec_product_results').length)
			$('#ec_product_results').hide();
		if (!$(e.target).closest('#ec_cat_search, #ec_cat_results').length)
			$('#ec_cat_results').hide();
	});

	/* ---- Test email ---- */
	$('#ec-btn-test').on('click', function() {
		var subj = $('#ec_subject').val().trim();
		if (!subj) { showNotice('Please enter a subject.', 'error'); return; }
		var $b = $(this).prop('disabled', true).text('Sending…');
		ajaxPost('skyhshoso_send_campaign_test', {
			subject: subj,
			body: $('#ec_body').val()
		}, function(res) {
			showNotice(res.data.message, 'success');
		}).always(function() {
			$b.prop('disabled', false).text('Send Test');
		});
	});

	/* ---- Preview modal ---- */
	$('#ec-btn-preview').on('click', function() {
		var subj = $('#ec_subject').val().trim();
		var body = $('#ec_body').val();
		if (!subj) { showNotice('Please enter a subject.', 'error'); return; }
		$('#ec-preview-iframe').contents().find('body').html('<div style="padding:40px;text-align:center;color:#6b7280;">Loading preview…</div>');
		$('#ec-preview-modal').show();
		ajaxPost('skyhshoso_campaign_preview', {
			subject: subj,
			body: body
		}, function(res) {
			$('#ec-preview-subject').text(res.data.subject);
			var iframe = $('#ec-preview-iframe')[0];
			var doc = iframe.contentDocument || iframe.contentWindow.document;
			doc.open();
			doc.write(res.data.body);
			doc.close();
		});
	});

	$('#ec-preview-close, .skyhshoso-modal-backdrop').on('click', function() {
		$('#ec-preview-modal').hide();
	});

	$(document).on('keydown', function(e) {
		if (e.key === 'Escape') {
			if ($('#ec-preview-modal').is(':visible')) $('#ec-preview-modal').hide();
			if ($('#ec-sendnow-modal').is(':visible')) $('#ec-sendnow-modal').hide();
		}
	});
	$('#ec-form').on('submit', function(e) {
		e.preventDefault();
		var name = $('#ec_name').val().trim();
		var subject = $('#ec_subject').val().trim();
		if (!name || !subject) { showNotice('Please fill in name and subject.', 'error'); return; }

		var targetType = $('#ec_target_type').val();
		var payload = {
			campaign_id: $('#ec_campaign_id').val(),
			name: name,
			subject: subject,
			body: $('#ec_body').val(),
			target_type: targetType,
			trigger_type: $('#ec_trigger_type').val(),
			delay_value: $('#ec_delay_value').val(),
			delay_unit: $('#ec_delay_unit').val(),
			is_active: $('#ec_is_active').is(':checked') ? '1' : '0'
		};

		if (targetType === 'products') {
			payload.target_ids = $('#ec_target_ids').val();
		} else if (targetType === 'categories') {
			var cids = [];
			$.each(selectedCats, function(id) { cids.push(id); });
			payload.category_ids = cids.join(',');
		} else if (targetType === 'manual') {
			payload.manual_user_ids = $('#ec_manual_user_ids').val();
		}

		var $btn = $('#ec-btn-save').prop('disabled', true).text('Saving…');
		ajaxPost('skyhshoso_save_campaign', payload, function(res) {
			var s = res.data.campaign;
			var idx = campaigns.findIndex(function(x) { return x.id === s.id; });
			if (idx >= 0) campaigns[idx] = s; else campaigns.unshift(s);
			refreshTable();
			showNotice('Campaign saved.', 'success');
			$('#ec-form-panel').slideUp();
			resetForm();
		}).always(function() {
			$btn.prop('disabled', false).text('Save Campaign');
		});
	});

	/* ---- Row actions ---- */
	$(document).on('click', '.ec-btn-edit', function() {
		var id = $(this).data('id');
		var c = campaigns.find(function(x) { return x.id == id; });
		if (c) { populateForm(c); $('#ec-form-panel').slideDown(); }
		else {
			ajaxPost('skyhshoso_get_campaign', { campaign_id: id }, function(res) {
				if (res.data && res.data.campaign) {
					populateForm(res.data.campaign);
					$('#ec-form-panel').slideDown();
				}
			});
		}
	});

	$(document).on('click', '.ec-btn-toggle', function() {
		var id = $(this).data('id');
		ajaxPost('skyhshoso_toggle_campaign', { campaign_id: id }, function(res) {
			var c = campaigns.find(function(x) { return x.id == id; });
			if (c) c.is_active = res.data.is_active;
			refreshTable();
		});
	});

	$(document).on('click', '.ec-btn-dup', function() {
		var id = $(this).data('id');
		ajaxPost('skyhshoso_duplicate_campaign', { campaign_id: id }, function(res) {
			campaigns.unshift(res.data.campaign);
			refreshTable();
			showNotice('Campaign duplicated.', 'success');
		});
	});

	$(document).on('click', '.ec-btn-sendnow', function() {
		var id = $(this).data('id');
		$('#ec-sendnow-confirm').data('campaign-id', id);
		$('#ec-sendnow-count').text('Loading…');
		showModal('ec-sendnow-modal');
		ajaxPost('skyhshoso_campaign_recipient_count', { campaign_id: id }, function(res) {
			$('#ec-sendnow-count').text(res.data.count);
		});
	});

	$('#ec-sendnow-confirm').on('click', function() {
		var id = $(this).data('campaign-id');
		var $btn = $(this).prop('disabled', true).text('Sending…');
		hideModal('ec-sendnow-modal');
		ajaxPost('skyhshoso_send_to_existing', { campaign_id: id }, function(res) {
			showNotice(res.data.message, 'success');
		}).always(function() {
			$btn.prop('disabled', false).text('Confirm & Send');
		});
	});

	$('.ec-sendnow-cancel, #ec-sendnow-close, #ec-sendnow-modal .skyhshoso-modal-backdrop').on('click', function() {
		hideModal('ec-sendnow-modal');
	});

	$(document).on('click', '.ec-btn-del', function() {
		var id = $(this).data('id');
		if (!confirm('Delete this campaign and all pending queue entries?')) return;
		ajaxPost('skyhshoso_delete_campaign', { campaign_id: id }, function() {
			campaigns = campaigns.filter(function(x) { return x.id != id; });
			refreshTable();
			showNotice('Campaign deleted.', 'success');
		});
	});

	/* ---- Init ---- */
	refreshTable();
	$('#ec_target_type').trigger('change');
	$('#ec_trigger_type').trigger('change');
});
