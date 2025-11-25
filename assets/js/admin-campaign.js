/**
 * admin-campaign.js
 * Provides the "Select Campaign Type" modal & interception of Add New for ai_campaign.
 */
( function ( $ ) {
	'use strict';

	$( document ).ready( function () {

		// Only attach when Add New button exists (list table) or when on edit.php for ai_campaign.
		// Our localized ABA_Admin.post_type helps confirm context.
		var post_type = ( typeof ABA_Admin !== 'undefined' && ABA_Admin.post_type ) ? ABA_Admin.post_type : '';

		// Identify Add New button in admin post list UI.
		// There are multiple Add New buttons - .page-title-action and the top "New" menu.
		$( document ).on( 'click', '.page-title-action, .wrap .add-new-h2, .post-type-browse .add-new', function ( e ) {
			var $this = $( this );
			// If the current page is the CPT listing for ai_campaign, open the modal
			// We attempt to detect via the admin anchor or by checking data
			var href = $this.attr( 'href' ) || '';
			// If the link targets post-new.php?post_type=ai_campaign or if current screen contains post_type=ai_campaign in URL
			var isAICPT = ( href.indexOf( 'post_type=' + post_type ) !== -1 ) || ( window.location.href.indexOf( 'post_type=' + post_type ) !== -1 );

			if ( isAICPT ) {
				e.preventDefault();
				showCampaignTypeModal();
				return false;
			}
			// else let normal behaviour happen
		} );

		// Also intercept the "Add New" in the admin bar drop-down for our post type.
		$( document ).on( 'click', '#wp-admin-bar-new-content a', function ( e ) {
			// if admin bar new content points to post-new.php?post_type=ai_campaign and we are adding that type
			var href = $( this ).attr( 'href' ) || '';
			if ( href.indexOf( 'post-new.php?post_type=' + post_type ) !== -1 ) {
				e.preventDefault();
				showCampaignTypeModal();
				return false;
			}
		} );

		// Build and show the modal
		function showCampaignTypeModal() {
			// If modal already exists, show it
			if ( $( '#aba-campaign-type-modal' ).length ) {
				$( '#aba-campaign-type-modal' ).show();
				return;
			}

			var modal = '\
			<div id="aba-campaign-type-modal" style="position:fixed;left:0;top:0;width:100%;height:100%;z-index:99999;display:flex;align-items:center;justify-content:center;">\
				<div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:22px;max-width:480px;width:90%;box-shadow:0 6px 20px rgba(0,0,0,0.2);">\
					<h2 style="margin-top:0;"><?php /* placeholder replaced below */ ?></h2>\
					<p style="color:#666;margin-top:0;"><?php /* optional subtext */ ?></p>\
					<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;">\
						<button class="button aba-campaign-type-btn" data-type="rss" style="flex:1;background:#0073aa;color:#fff;border:none;padding:10px;border-radius:4px;"><?php /* rss */ ?></button>\
						<button class="button aba-campaign-type-btn" data-type="youtube" style="flex:1;border-radius:4px;"><?php /* youtube */ ?></button>\
						<button class="button aba-campaign-type-btn" data-type="keyword" style="flex:1;border-radius:4px;"><?php /* keyword */ ?></button>\
						<button class="button aba-campaign-type-btn" data-type="trends" style="flex:1;border-radius:4px;"><?php /* trends */ ?></button>\
					</div>\
					<div style="text-align:right;margin-top:12px;">\
						<button id="aba-campaign-type-cancel" class="button"><?php /* cancel */ ?></button>\
					</div>\
				</div>\
			</div>';

			// Insert with localization strings
			var localized = window.ABA_Admin && window.ABA_Admin.i18n ? window.ABA_Admin.i18n : {};
			modal = modal.replace('<?php /* placeholder replaced below */ ?>', localized.select_campaign || 'Select Campaign Type');
			modal = modal.replace('<?php /* optional subtext */ ?>', '');
			modal = modal.replace('<?php /* rss */ ?>', localized.rss_feed || 'RSS Feed');
			modal = modal.replace('<?php /* youtube */ ?>', localized.youtube || 'YouTube');
			modal = modal.replace('<?php /* keyword */ ?>', localized.keyword || 'Keyword');
			modal = modal.replace('<?php /* trends */ ?>', localized.trends || 'Google Trends');
			modal = modal.replace('<?php /* cancel */ ?>', 'Cancel');

			$( 'body' ).append( modal );

			// Click handlers
			$( document ).on( 'click', '.aba-campaign-type-btn', function ( e ) {
				var type = $( this ).data( 'type' );
				if ( ! type ) {
					return;
				}
				// Build redirect to post-new.php with type query.
				var newUrl = window.location.origin + '<?php /* placeholder */ ?>' + '/wp-admin/post-new.php?post_type=' + post_type + '&type=' + type;
				// However building admin URL using origin may fail in some setups; safer to use relative path
				var relative = '/wp-admin/post-new.php?post_type=' + post_type + '&type=' + type;
				window.location.href = relative;
			} );

			$( document ).on( 'click', '#aba-campaign-type-cancel', function ( e ) {
				$( '#aba-campaign-type-modal' ).remove();
			} );
		}
	} );
} )( jQuery );
