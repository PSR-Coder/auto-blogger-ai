/**
 * admin-campaign.js
 * Campaign Type Selection modal & Add New interception for ai_campaign
 */
(function ($) {
	'use strict';

	$(document).ready(function () {
		var post_type = (typeof ABA_Admin !== 'undefined' && ABA_Admin.post_type) ? ABA_Admin.post_type : 'ai_campaign';
		var admin_post_new = (typeof ABA_Admin !== 'undefined' && ABA_Admin.admin_post_new) ? ABA_Admin.admin_post_new : '/wp-admin/post-new.php';

		// Intercept Add New buttons on AI Campaign list page
		$(document).on('click', '.page-title-action, .wrap .add-new-h2, .post-type-browse .add-new', function (e) {
			var $this = $(this);
			var href = $this.attr('href') || '';

			// Proceed only if the link indicates our CPT or current page is a CPT listing
			var isAICPT = (href.indexOf('post_type=' + post_type) !== -1) || (window.location.href.indexOf('post_type=' + post_type) !== -1);

			if (isAICPT) {
				e.preventDefault();
				showCampaignTypeModal();
				return false;
			}
			// else allow default behavior
		});

		// Intercept admin bar New->Post for our CPT
		$(document).on('click', '#wp-admin-bar-new-content a', function (e) {
			var href = $(this).attr('href') || '';
			if (href.indexOf('post-new.php?post_type=' + post_type) !== -1) {
				e.preventDefault();
				showCampaignTypeModal();
				return false;
			}
		});

		function showCampaignTypeModal() {
			// If modal exists already, show it
			if ($('#aba-campaign-type-modal').length) {
				$('#aba-campaign-type-modal').show();
				return;
			}

			var localized = (typeof ABA_Admin !== 'undefined' && ABA_Admin.i18n) ? ABA_Admin.i18n : {};
			var titleText = localized.select_campaign || 'Select Campaign Type';
			var rssText = localized.rss_feed || 'RSS Feed';
			var youtubeText = localized.youtube || 'YouTube';
			var keywordText = localized.keyword || 'Keyword';
			var trendsText = localized.trends || 'Google Trends';
			var cancelText = localized.cancel || 'Cancel';

			var modalHtml = ''
				+ '<div id="aba-campaign-type-modal" style="position:fixed;left:0;top:0;width:100%;height:100%;z-index:99999;display:flex;align-items:center;justify-content:center;">'
				+ '<div class="aba-modal-inner" role="dialog" aria-modal="true" style="background:#fff;border-radius:8px;padding:20px;max-width:520px;width:92%;">'
				+ '<h2 style="margin-top:0;">' + escapeHtml(titleText) + '</h2>'
				+ '<div class="aba-campaign-grid" style="margin-top:12px;">'
				+ '<button class="button aba-campaign-option aba-campaign-type-btn" data-type="rss" style="padding:18px;">' + escapeHtml(rssText) + '</button>'
				+ '<button class="button aba-campaign-option aba-campaign-type-btn" data-type="youtube" style="padding:18px;">' + escapeHtml(youtubeText) + '</button>'
				+ '<button class="button aba-campaign-option aba-campaign-type-btn" data-type="keyword" style="padding:18px;">' + escapeHtml(keywordText) + '</button>'
				+ '<button class="button aba-campaign-option aba-campaign-type-btn" data-type="trends" style="padding:18px;">' + escapeHtml(trendsText) + '</button>'
				+ '</div>'
				+ '<div style="text-align:right;margin-top:16px;"><button id="aba-campaign-type-cancel" class="button">' + escapeHtml(cancelText) + '</button></div>'
				+ '</div></div>';

			$('body').append(modalHtml);

			// Click handler for type buttons
			$(document).on('click', '.aba-campaign-type-btn', function (e) {
				var type = $(this).data('type');
				if (!type) return;
				// Build target URL using localized admin_post_new
				var target = admin_post_new + '?post_type=' + encodeURIComponent(post_type) + '&type=' + encodeURIComponent(type);
				window.location.href = target;
			});

			// Cancel
			$(document).on('click', '#aba-campaign-type-cancel', function (e) {
				$('#aba-campaign-type-modal').remove();
			});
		}

		// small helper to avoid XSS when injecting strings
		function escapeHtml(str) {
			if (!str) return '';
			return String(str)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#039;');
		}
	});
})(jQuery);
