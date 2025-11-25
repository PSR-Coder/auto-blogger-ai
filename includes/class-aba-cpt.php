<?php
defined( 'ABSPATH' ) || exit;

/**
 * ABA_CPT
 *
 * Registers ai_campaign CPT and manages meta boxes, saving and
 * the 'Select Campaign Type' flow via query var.
 */
class ABA_CPT {

	/**
	 * Initialize actions.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ), 0 );

		// Only add meta boxes when editing/creating ai_campaign
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );

		// Save post metadata securely.
		add_action( 'save_post', array( __CLASS__, 'save_post_meta' ), 10, 2 );

		// Add screen option or column modifications if needed (not required now).
	}

	/**
	 * Register the ai_campaign CPT.
	 */
	public static function register_post_type() {
		$labels = array(
			'name'                  => __( 'AI Campaigns', 'auto-blogger-ai' ),
			'singular_name'         => __( 'AI Campaign', 'auto-blogger-ai' ),
			'add_new_item'          => __( 'Add New Campaign', 'auto-blogger-ai' ),
			'edit_item'             => __( 'Edit Campaign', 'auto-blogger-ai' ),
			'new_item'              => __( 'New Campaign', 'auto-blogger-ai' ),
			'view_item'             => __( 'View Campaign', 'auto-blogger-ai' ),
			'all_items'             => __( 'All Campaigns', 'auto-blogger-ai' ),
			'search_items'          => __( 'Search Campaigns', 'auto-blogger-ai' ),
			'not_found'             => __( 'No campaigns found', 'auto-blogger-ai' ),
			'not_found_in_trash'    => __( 'No campaigns found in Trash', 'auto-blogger-ai' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'show_ui'            => true,
			'show_in_menu'       => false, // we add via parent plugin menu
			'capability_type'    => 'post',
			'has_archive'        => false,
			'supports'           => array( 'title' ),
			'menu_position'      => 26,
			'show_in_rest'       => true,
		);

		register_post_type( 'ai_campaign', $args );
	}

	/**
	 * Add meta boxes based on campaign type.
	 */
	public static function add_meta_boxes( $post_type ) {
		global $post;

		if ( 'ai_campaign' !== $post_type ) {
			return;
		}

		// Determine campaign type: If creating new campaign, check query var.
		$campaign_type = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';

		// If editing existing campaign we can read saved meta type
		if ( ! $campaign_type && isset( $post->ID ) ) {
			$saved_type = get_post_meta( $post->ID, '_aba_campaign_type', true );
			if ( $saved_type ) {
				$campaign_type = sanitize_text_field( $saved_type );
			}
		}

		/*
		 * If no type specified and post is new (no ID or status auto-draft), we simply show a notice to choose a type.
		 * However our admin JS intercepts Add New and redirects to ?type=rss for RSS campaigns, so in normal flow we expect a type param.
		 */

		// Save campaign type hidden meta field on creation/edit regardless.
		add_meta_box(
			'aba_campaign_type_box',
			__( 'Campaign Type', 'auto-blogger-ai' ),
			array( __CLASS__, 'meta_box_campaign_type' ),
			'ai_campaign',
			'side',
			'core',
			array( 'campaign_type' => $campaign_type )
		);

		// Only render RSS-specific meta boxes when type is 'rss'
		if ( 'rss' === $campaign_type ) {
			add_meta_box(
				'aba_feed_settings',
				__( 'Feed Settings', 'auto-blogger-ai' ),
				array( __CLASS__, 'meta_box_feed_settings' ),
				'ai_campaign',
				'normal',
				'high'
			);

			add_meta_box(
				'aba_content_extraction',
				__( 'Content Extraction', 'auto-blogger-ai' ),
				array( __CLASS__, 'meta_box_content_extraction' ),
				'ai_campaign',
				'normal',
				'default'
			);

			add_meta_box(
				'aba_filtering_cleaning',
				__( 'Filtering & Cleaning', 'auto-blogger-ai' ),
				array( __CLASS__, 'meta_box_filtering_cleaning' ),
				'ai_campaign',
				'normal',
				'default'
			);

			add_meta_box(
				'aba_ai_rewrite',
				__( 'AI & Rewriting', 'auto-blogger-ai' ),
				array( __CLASS__, 'meta_box_ai_rewriting' ),
				'ai_campaign',
				'normal',
				'default'
			);

			add_meta_box(
				'aba_images',
				__( 'Images', 'auto-blogger-ai' ),
				array( __CLASS__, 'meta_box_images' ),
				'ai_campaign',
				'side',
				'default'
			);

			add_meta_box(
				'aba_post_settings',
				__( 'Post Settings', 'auto-blogger-ai' ),
				array( __CLASS__, 'meta_box_post_settings' ),
				'ai_campaign',
				'side',
				'default'
			);

			add_meta_box(
				'aba_automation',
				__( 'Automation', 'auto-blogger-ai' ),
				array( __CLASS__, 'meta_box_automation' ),
				'ai_campaign',
				'side',
				'default'
			);
		} else {
			// If campaign type unknown, show guidance
			add_meta_box(
				'aba_campaign_help',
				__( 'Campaign Type Selection', 'auto-blogger-ai' ),
				array( __CLASS__, 'meta_box_campaign_help' ),
				'ai_campaign',
				'normal',
				'default'
			);
		}
	}

	/**
	 * Campaign type meta box (side).
	 *
	 * @param WP_Post $post
	 * @param array   $args
	 */
	public static function meta_box_campaign_type( $post, $args ) {
		$campaign_type = isset( $args['args']['campaign_type'] ) ? $args['args']['campaign_type'] : get_post_meta( $post->ID, '_aba_campaign_type', true );
		$campaign_type = sanitize_text_field( $campaign_type );
		?>
		<p>
			<strong><?php esc_html_e( 'Type:', 'auto-blogger-ai' ); ?></strong>
			<span><?php echo esc_html( $campaign_type ? $campaign_type : __( 'Not selected', 'auto-blogger-ai' ) ); ?></span>
		</p>
		<input type="hidden" name="aba_campaign_type" value="<?php echo esc_attr( $campaign_type ); ?>" />
		<?php wp_nonce_field( 'aba_campaign_type_nonce', 'aba_campaign_type_nonce' ); ?>
		<?php
	}

	/**
	 * Help meta box shown when no type selected.
	 */
	public static function meta_box_campaign_help() {
		?>
		<p><?php esc_html_e( 'Please select a campaign type. Click "Add New" -> choose a type from the popup. If you did not select a type the required meta boxes will not appear.', 'auto-blogger-ai' ); ?></p>
		<?php
	}

	/**
	 * Feed Settings meta box.
	 */
	public static function meta_box_feed_settings( $post ) {
		wp_nonce_field( 'aba_feed_settings_nonce', 'aba_feed_settings_nonce' );

		$feed_url     = get_post_meta( $post->ID, '_aba_feed_url', true );
		$max_posts    = get_post_meta( $post->ID, '_aba_max_posts', true );
		$check_latest = get_post_meta( $post->ID, '_aba_check_latest', true );

		$max_posts = $max_posts ? intval( $max_posts ) : 2000;
		?>
		<table class="form-table">
			<tr>
				<th><label for="aba_feed_url"><?php esc_html_e( 'Feed URL', 'auto-blogger-ai' ); ?></label></th>
				<td><input type="url" id="aba_feed_url" name="aba_feed_url" value="<?php echo esc_attr( $feed_url ); ?>" class="regular-text" required /></td>
			</tr>
			<tr>
				<th><label for="aba_max_posts"><?php esc_html_e( 'Max posts to fetch', 'auto-blogger-ai' ); ?></label></th>
				<td><input type="number" id="aba_max_posts" name="aba_max_posts" value="<?php echo esc_attr( $max_posts ); ?>" class="small-text" min="1" /></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Check only latest?', 'auto-blogger-ai' ); ?></th>
				<td><label><input type="checkbox" name="aba_check_latest" value="1" <?php checked( $check_latest, '1' ); ?> /> <?php esc_html_e( 'If checked, only fetch newest items since last run', 'auto-blogger-ai' ); ?></label></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Content extraction meta box.
	 */
	public static function meta_box_content_extraction( $post ) {
		wp_nonce_field( 'aba_content_extraction_nonce', 'aba_content_extraction_nonce' );

		$method       = get_post_meta( $post->ID, '_aba_extraction_method', true );
		$css_selector = get_post_meta( $post->ID, '_aba_css_selector', true );

		$method = $method ? $method : 'auto';
		?>
		<table class="form-table">
			<tr>
				<th><label for="aba_extraction_method"><?php esc_html_e( 'Extraction Method', 'auto-blogger-ai' ); ?></label></th>
				<td>
					<select id="aba_extraction_method" name="aba_extraction_method">
						<option value="auto" <?php selected( $method, 'auto' ); ?>><?php esc_html_e( 'Auto', 'auto-blogger-ai' ); ?></option>
						<option value="css" <?php selected( $method, 'css' ); ?>><?php esc_html_e( 'CSS Selector', 'auto-blogger-ai' ); ?></option>
					</select>
				</td>
			</tr>
			<tr class="aba-css-row" <?php if ( 'css' !== $method ) { echo 'style="display:none"'; } ?>>
				<th><label for="aba_css_selector"><?php esc_html_e( 'CSS Selector', 'auto-blogger-ai' ); ?></label></th>
				<td><input type="text" id="aba_css_selector" name="aba_css_selector" value="<?php echo esc_attr( $css_selector ); ?>" class="regular-text" placeholder="<?php esc_attr_e( '.entry-content, article', 'auto-blogger-ai' ); ?>" /></td>
			</tr>
		</table>

		<script>
		// Small admin-side behaviour: toggle selector field (kept inline for clarity)
		( function( $ ) {
			$( function() {
				$( '#aba_extraction_method' ).on( 'change', function() {
					if ( 'css' === $( this ).val() ) {
						$( '.aba-css-row' ).show();
					} else {
						$( '.aba-css-row' ).hide();
					}
				} );
			} );
		} )( jQuery );
		</script>
		<?php
	}

	/**
	 * Filtering & Cleaning meta box.
	 */
	public static function meta_box_filtering_cleaning( $post ) {
		wp_nonce_field( 'aba_filtering_cleaning_nonce', 'aba_filtering_cleaning_nonce' );

		$remove_classes = get_post_meta( $post->ID, '_aba_remove_elements_by_class', true );
		$remove_ids     = get_post_meta( $post->ID, '_aba_remove_elements_by_id', true );
		$strip_links    = get_post_meta( $post->ID, '_aba_strip_links', true );
		$add_nofollow   = get_post_meta( $post->ID, '_aba_add_nofollow', true );
		$strip_images   = get_post_meta( $post->ID, '_aba_strip_images', true );
		$min_word_count = get_post_meta( $post->ID, '_aba_min_word_count', true );
		$max_word_count = get_post_meta( $post->ID, '_aba_max_word_count', true );
		$required_kw    = get_post_meta( $post->ID, '_aba_required_keywords', true );
		$banned_kw      = get_post_meta( $post->ID, '_aba_banned_keywords', true );

		?>
		<table class="form-table">
			<tr>
				<th><label for="aba_remove_elements_by_class"><?php esc_html_e( 'Remove elements by class (comma separated)', 'auto-blogger-ai' ); ?></label></th>
				<td><input type="text" id="aba_remove_elements_by_class" name="aba_remove_elements_by_class" value="<?php echo esc_attr( $remove_classes ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="aba_remove_elements_by_id"><?php esc_html_e( 'Remove elements by id (comma separated)', 'auto-blogger-ai' ); ?></label></th>
				<td><input type="text" id="aba_remove_elements_by_id" name="aba_remove_elements_by_id" value="<?php echo esc_attr( $remove_ids ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Strip links', 'auto-blogger-ai' ); ?></th>
				<td><label><input type="checkbox" name="aba_strip_links" value="1" <?php checked( $strip_links, '1' ); ?> /> <?php esc_html_e( 'Remove anchor tags but keep text', 'auto-blogger-ai' ); ?></label></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Add nofollow', 'auto-blogger-ai' ); ?></th>
				<td><label><input type="checkbox" name="aba_add_nofollow" value="1" <?php checked( $add_nofollow, '1' ); ?> /> <?php esc_html_e( 'Add rel="nofollow" to external links', 'auto-blogger-ai' ); ?></label></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Strip images', 'auto-blogger-ai' ); ?></th>
				<td><label><input type="checkbox" name="aba_strip_images" value="1" <?php checked( $strip_images, '1' ); ?> /> <?php esc_html_e( 'Remove <img> tags from content', 'auto-blogger-ai' ); ?></label></td>
			</tr>
			<tr>
				<th><label for="aba_min_word_count"><?php esc_html_e( 'Min word count', 'auto-blogger-ai' ); ?></label></th>
				<td><input type="number" id="aba_min_word_count" name="aba_min_word_count" value="<?php echo esc_attr( $min_word_count ); ?>" class="small-text" min="0" /></td>
			</tr>
			<tr>
				<th><label for="aba_max_word_count"><?php esc_html_e( 'Max word count', 'auto-blogger-ai' ); ?></label></th>
				<td><input type="number" id="aba_max_word_count" name="aba_max_word_count" value="<?php echo esc_attr( $max_word_count ); ?>" class="small-text" min="0" /></td>
			</tr>
			<tr>
				<th><label for="aba_required_keywords"><?php esc_html_e( 'Required keywords (comma separated)', 'auto-blogger-ai' ); ?></label></th>
				<td><input type="text" id="aba_required_keywords" name="aba_required_keywords" value="<?php echo esc_attr( $required_kw ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="aba_banned_keywords"><?php esc_html_e( 'Banned keywords (comma separated)', 'auto-blogger-ai' ); ?></label></th>
				<td><input type="text" id="aba_banned_keywords" name="aba_banned_keywords" value="<?php echo esc_attr( $banned_kw ); ?>" class="regular-text" /></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * AI & Rewriting meta box.
	 */
	public static function meta_box_ai_rewriting( $post ) {
		wp_nonce_field( 'aba_ai_rewriting_nonce', 'aba_ai_rewriting_nonce' );

		$enable_ai = get_post_meta( $post->ID, '_aba_enable_ai', true );
		$ai_prompt = get_post_meta( $post->ID, '_aba_ai_prompt', true );
		$ai_model  = get_post_meta( $post->ID, '_aba_ai_model', true );

		if ( ! $ai_prompt ) {
			$ai_prompt = 'Rewrite this content: [content]';
		}

		$ai_model = $ai_model ? $ai_model : 'gemini';
		?>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Enable AI', 'auto-blogger-ai' ); ?></th>
				<td><label><input type="checkbox" name="aba_enable_ai" value="1" <?php checked( $enable_ai, '1' ); ?> /> <?php esc_html_e( 'Send cleaned content to AI for rewriting', 'auto-blogger-ai' ); ?></label></td>
			</tr>
			<tr>
				<th><label for="aba_ai_prompt"><?php esc_html_e( 'AI Prompt', 'auto-blogger-ai' ); ?></label></th>
				<td><textarea id="aba_ai_prompt" name="aba_ai_prompt" rows="4" class="large-text"><?php echo esc_textarea( $ai_prompt ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="aba_ai_model"><?php esc_html_e( 'AI Model', 'auto-blogger-ai' ); ?></label></th>
				<td>
					<select id="aba_ai_model" name="aba_ai_model">
						<option value="gemini" <?php selected( $ai_model, 'gemini' ); ?>><?php esc_html_e( 'Gemini', 'auto-blogger-ai' ); ?></option>
						<option value="gpt4" <?php selected( $ai_model, 'gpt4' ); ?>><?php esc_html_e( 'GPT-4', 'auto-blogger-ai' ); ?></option>
					</select>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Images meta box.
	 */
	public static function meta_box_images( $post ) {
		wp_nonce_field( 'aba_images_nonce', 'aba_images_nonce' );

		$download_images    = get_post_meta( $post->ID, '_aba_download_images', true );
		$set_featured_image = get_post_meta( $post->ID, '_aba_set_featured_image', true );
		?>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Download images', 'auto-blogger-ai' ); ?></th>
				<td><label><input type="checkbox" name="aba_download_images" value="1" <?php checked( $download_images, '1' ); ?> /> <?php esc_html_e( 'Download remote images to Media Library', 'auto-blogger-ai' ); ?></label></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Set as featured image', 'auto-blogger-ai' ); ?></th>
				<td><label><input type="checkbox" name="aba_set_featured_image" value="1" <?php checked( $set_featured_image, '1' ); ?> /> <?php esc_html_e( 'Use first image as featured image', 'auto-blogger-ai' ); ?></label></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Post Settings meta box.
	 */
	public static function meta_box_post_settings( $post ) {
		wp_nonce_field( 'aba_post_settings_nonce', 'aba_post_settings_nonce' );

		$target_author   = get_post_meta( $post->ID, '_aba_target_author', true );
		$target_category = get_post_meta( $post->ID, '_aba_target_category', true );
		$post_status     = get_post_meta( $post->ID, '_aba_post_status', true );

		// Authors dropdown
		$users = get_users( array( 'orderby' => 'display_name' ) );
		?>
		<table class="form-table">
			<tr>
				<th><label for="aba_target_author"><?php esc_html_e( 'Target Author', 'auto-blogger-ai' ); ?></label></th>
				<td>
					<select id="aba_target_author" name="aba_target_author">
						<?php foreach ( $users as $user ) : ?>
							<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $target_author, $user->ID ); ?>><?php echo esc_html( $user->display_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>

			<tr>
				<th><label for="aba_target_category"><?php esc_html_e( 'Target Category', 'auto-blogger-ai' ); ?></label></th>
				<td>
					<?php
					$cats = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) );
					?>
					<select id="aba_target_category" name="aba_target_category">
						<option value=""><?php esc_html_e( '— Select —', 'auto-blogger-ai' ); ?></option>
						<?php foreach ( $cats as $cat ) : ?>
							<option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( $target_category, $cat->term_id ); ?>><?php echo esc_html( $cat->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>

			<tr>
				<th><label for="aba_post_status"><?php esc_html_e( 'Post Status', 'auto-blogger-ai' ); ?></label></th>
				<td>
					<select id="aba_post_status" name="aba_post_status">
						<option value="publish" <?php selected( $post_status, 'publish' ); ?>><?php esc_html_e( 'Publish', 'auto-blogger-ai' ); ?></option>
						<option value="draft" <?php selected( $post_status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'auto-blogger-ai' ); ?></option>
						<option value="pending" <?php selected( $post_status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'auto-blogger-ai' ); ?></option>
					</select>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Automation meta box.
	 */
	public static function meta_box_automation( $post ) {
		wp_nonce_field( 'aba_automation_nonce', 'aba_automation_nonce' );

		$schedule_interval = get_post_meta( $post->ID, '_aba_schedule_interval', true );
		$schedule_interval = $schedule_interval ? $schedule_interval : 'daily';
		?>
		<table class="form-table">
			<tr>
				<th><label for="aba_schedule_interval"><?php esc_html_e( 'Schedule Interval', 'auto-blogger-ai' ); ?></label></th>
				<td>
					<select id="aba_schedule_interval" name="aba_schedule_interval">
						<option value="hourly" <?php selected( $schedule_interval, 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'auto-blogger-ai' ); ?></option>
						<option value="thirty_min" <?php selected( $schedule_interval, 'thirty_min' ); ?>><?php esc_html_e( 'Every 30 minutes', 'auto-blogger-ai' ); ?></option>
						<option value="daily" <?php selected( $schedule_interval, 'daily' ); ?>><?php esc_html_e( 'Daily', 'auto-blogger-ai' ); ?></option>
						<option value="twice_daily" <?php selected( $schedule_interval, 'twice_daily' ); ?>><?php esc_html_e( 'Twice Daily', 'auto-blogger-ai' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Active', 'auto-blogger-ai' ); ?></th>
				<td><label><input type="checkbox" name="aba_active" value="1" <?php checked( get_post_meta( $post->ID, '_aba_active', true ), '1' ); ?> /> <?php esc_html_e( 'Enable automation for this campaign', 'auto-blogger-ai' ); ?></label></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save post meta securely.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public static function save_post_meta( $post_id, $post ) {
		// Only save for our post type.
		if ( 'ai_campaign' !== $post->post_type ) {
			return;
		}

		// Verify autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check user capability.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// --- Campaign type ---
		if ( isset( $_POST['aba_campaign_type_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aba_campaign_type_nonce'] ) ), 'aba_campaign_type_nonce' ) ) {
			$campaign_type = isset( $_POST['aba_campaign_type'] ) ? sanitize_text_field( wp_unslash( $_POST['aba_campaign_type'] ) ) : '';
			if ( $campaign_type ) {
				update_post_meta( $post_id, '_aba_campaign_type', $campaign_type );
			}
		}

		// --- Feed settings ---
		if ( isset( $_POST['aba_feed_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aba_feed_settings_nonce'] ) ), 'aba_feed_settings_nonce' ) ) {
			$feed_url  = isset( $_POST['aba_feed_url'] ) ? esc_url_raw( wp_unslash( $_POST['aba_feed_url'] ) ) : '';
			$max_posts = isset( $_POST['aba_max_posts'] ) ? intval( $_POST['aba_max_posts'] ) : 2000;
			$check_lat = isset( $_POST['aba_check_latest'] ) ? '1' : '0';

			update_post_meta( $post_id, '_aba_feed_url', $feed_url );
			update_post_meta( $post_id, '_aba_max_posts', $max_posts );
			update_post_meta( $post_id, '_aba_check_latest', $check_lat );
		}

		// --- Content extraction ---
		if ( isset( $_POST['aba_content_extraction_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aba_content_extraction_nonce'] ) ), 'aba_content_extraction_nonce' ) ) {
			$method       = isset( $_POST['aba_extraction_method'] ) ? sanitize_text_field( wp_unslash( $_POST['aba_extraction_method'] ) ) : 'auto';
			$css_selector = isset( $_POST['aba_css_selector'] ) ? sanitize_text_field( wp_unslash( $_POST['aba_css_selector'] ) ) : '';

			update_post_meta( $post_id, '_aba_extraction_method', $method );
			update_post_meta( $post_id, '_aba_css_selector', $css_selector );
		}

		// --- Filtering & Cleaning ---
		if ( isset( $_POST['aba_filtering_cleaning_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aba_filtering_cleaning_nonce'] ) ), 'aba_filtering_cleaning_nonce' ) ) {
			$remove_by_class = isset( $_POST['aba_remove_elements_by_class'] ) ? sanitize_text_field( wp_unslash( $_POST['aba_remove_elements_by_class'] ) ) : '';
			$remove_by_id    = isset( $_POST['aba_remove_elements_by_id'] ) ? sanitize_text_field( wp_unslash( $_POST['aba_remove_elements_by_id'] ) ) : '';
			$strip_links     = isset( $_POST['aba_strip_links'] ) ? '1' : '0';
			$add_nofollow    = isset( $_POST['aba_add_nofollow'] ) ? '1' : '0';
			$strip_images    = isset( $_POST['aba_strip_images'] ) ? '1' : '0';
			$min_wc          = isset( $_POST['aba_min_word_count'] ) ? intval( $_POST['aba_min_word_count'] ) : 0;
			$max_wc          = isset( $_POST['aba_max_word_count'] ) ? intval( $_POST['aba_max_word_count'] ) : 0;
			$required_kw     = isset( $_POST['aba_required_keywords'] ) ? sanitize_text_field( wp_unslash( $_POST['aba_required_keywords'] ) ) : '';
			$banned_kw       = isset( $_POST['aba_banned_keywords'] ) ? sanitize_text_field( wp_unslash( $_POST['aba_banned_keywords'] ) ) : '';

			update_post_meta( $post_id, '_aba_remove_elements_by_class', $remove_by_class );
			update_post_meta( $post_id, '_aba_remove_elements_by_id', $remove_by_id );
			update_post_meta( $post_id, '_aba_strip_links', $strip_links );
			update_post_meta( $post_id, '_aba_add_nofollow', $add_nofollow );
			update_post_meta( $post_id, '_aba_strip_images', $strip_images );
			update_post_meta( $post_id, '_aba_min_word_count', $min_wc );
			update_post_meta( $post_id, '_aba_max_word_count', $max_wc );
			update_post_meta( $post_id, '_aba_required_keywords', $required_kw );
			update_post_meta( $post_id, '_aba_banned_keywords', $banned_kw );
		}

		// --- AI & Rewriting ---
		if ( isset( $_POST['aba_ai_rewriting_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aba_ai_rewriting_nonce'] ) ), 'aba_ai_rewriting_nonce' ) ) {
			$enable_ai = isset( $_POST['aba_enable_ai'] ) ? '1' : '0';
			$ai_prompt = isset( $_POST['aba_ai_prompt'] ) ? wp_kses_post( wp_unslash( $_POST['aba_ai_prompt'] ) ) : '';
			$ai_model  = isset( $_POST['aba_ai_model'] ) ? sanitize_text_field( wp_unslash( $_POST['aba_ai_model'] ) ) : 'gemini';

			update_post_meta( $post_id, '_aba_enable_ai', $enable_ai );
			update_post_meta( $post_id, '_aba_ai_prompt', $ai_prompt );
			update_post_meta( $post_id, '_aba_ai_model', $ai_model );
		}

		// --- Images ---
		if ( isset( $_POST['aba_images_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aba_images_nonce'] ) ), 'aba_images_nonce' ) ) {
			$download_images    = isset( $_POST['aba_download_images'] ) ? '1' : '0';
			$set_featured_image = isset( $_POST['aba_set_featured_image'] ) ? '1' : '0';

			update_post_meta( $post_id, '_aba_download_images', $download_images );
			update_post_meta( $post_id, '_aba_set_featured_image', $set_featured_image );
		}

		// --- Post settings ---
		if ( isset( $_POST['aba_post_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aba_post_settings_nonce'] ) ), 'aba_post_settings_nonce' ) ) {
			$target_author   = isset( $_POST['aba_target_author'] ) ? intval( $_POST['aba_target_author'] ) : get_current_user_id();
			$target_category = isset( $_POST['aba_target_category'] ) ? intval( $_POST['aba_target_category'] ) : 0;
			$post_status     = isset( $_POST['aba_post_status'] ) ? sanitize_text_field( wp_unslash( $_POST['aba_post_status'] ) ) : 'draft';

			update_post_meta( $post_id, '_aba_target_author', $target_author );
			update_post_meta( $post_id, '_aba_target_category', $target_category );
			update_post_meta( $post_id, '_aba_post_status', $post_status );
		}

		// --- Automation ---
		if ( isset( $_POST['aba_automation_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aba_automation_nonce'] ) ), 'aba_automation_nonce' ) ) {
			$schedule_interval = isset( $_POST['aba_schedule_interval'] ) ? sanitize_text_field( wp_unslash( $_POST['aba_schedule_interval'] ) ) : 'daily';
			$active            = isset( $_POST['aba_active'] ) ? '1' : '0';

			update_post_meta( $post_id, '_aba_schedule_interval', $schedule_interval );
			update_post_meta( $post_id, '_aba_active', $active );
		}
	}
}
