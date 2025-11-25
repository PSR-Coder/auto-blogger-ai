<?php
/**
 * Plugin Name: Auto Blogger AI
 * Plugin URI:  https://finzedia.com
 * Description: Automate content creation from RSS feeds using AI rewriting. Phase 1 supports RSS campaigns. Extensible architecture for YouTube/Trends.
 * Version:     0.1.0
 * Author:      Partha
 * License:     GPLv2+
 * Text Domain: auto-blogger-ai
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'ABA_Plugin' ) ) :

final class ABA_Plugin {

	/**
	 * Plugin version.
	 */
	const VERSION = '0.1.0';

	/**
	 * Option key for plugin settings.
	 */
	const OPTION_KEY = 'aba_settings';

	/**
	 * Singleton instance.
	 */
	protected static $instance = null;

	/**
	 * Get singleton.
	 *
	 * @return ABA_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->setup_constants();
			self::$instance->includes();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function __construct() {
		// Intentionally left blank.
	}

	private function setup_constants() {
		define( 'ABA_PLUGIN_FILE', __FILE__ );
		define( 'ABA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
		define( 'ABA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
	}

	private function includes() {
		require_once ABA_PLUGIN_DIR . 'includes/class-aba-cpt.php';
		require_once ABA_PLUGIN_DIR . 'includes/class-aba-rss-engine.php';
		require_once ABA_PLUGIN_DIR . 'includes/class-aba-processor.php';
		require_once ABA_PLUGIN_DIR . 'includes/class-aba-cron.php';
	}

	private function hooks() {
		register_activation_hook( ABA_PLUGIN_FILE, array( 'ABA_Cron', 'activate' ) );
		register_deactivation_hook( ABA_PLUGIN_FILE, array( 'ABA_Cron', 'deactivate' ) );

		add_action( 'init', array( 'ABA_CPT', 'init' ), 5 );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );

		// Initialize Cron class to register custom intervals and hook.
		add_action( 'init', array( 'ABA_Cron', 'init' ) );
	}

	/**
	 * Register top-level menu and submenu pages.
	 */
	public function register_admin_menu() {
		$capability = 'manage_options';

		add_menu_page(
			__( 'Auto Blogger AI', 'auto-blogger-ai' ),
			__( 'Auto Blogger AI', 'auto-blogger-ai' ),
			$capability,
			'aba_root',
			array( $this, 'render_dashboard' ),
			'dashicons-admin-post',
			26
		);

		// All Campaigns -> links to CPT list screen
		add_submenu_page(
			'aba_root',
			__( 'All Campaigns', 'auto-blogger-ai' ),
			__( 'All Campaigns', 'auto-blogger-ai' ),
			'edit_posts',
			'edit.php?post_type=ai_campaign'
		);

		// Settings
		add_submenu_page(
			'aba_root',
			__( 'Settings', 'auto-blogger-ai' ),
			__( 'Settings', 'auto-blogger-ai' ),
			$capability,
			'aba_settings',
			array( $this, 'render_settings_page' )
		);

		// Logs (simple placeholder page)
		add_submenu_page(
			'aba_root',
			__( 'Logs', 'auto-blogger-ai' ),
			__( 'Logs', 'auto-blogger-ai' ),
			$capability,
			'aba_logs',
			array( $this, 'render_logs_page' )
		);
	}

	/**
	 * Dashboard landing (simple).
	 */
	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'auto-blogger-ai' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Auto Blogger AI', 'auto-blogger-ai' ); ?></h1>
			<p><?php esc_html_e( 'Manage campaigns, configure API keys, and review logs.', 'auto-blogger-ai' ); ?></p>
			<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=ai_campaign' ) ); ?>"><?php esc_html_e( 'All Campaigns', 'auto-blogger-ai' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * Register settings via Settings API
	 */
	public function register_settings() {
		register_setting(
			'aba_settings_group',
			self::OPTION_KEY,
			array( $this, 'sanitize_settings' )
		);

		add_settings_section(
			'aba_api_section',
			__( 'API Keys', 'auto-blogger-ai' ),
			function() {
				echo '<p>' . esc_html__( 'Enter API keys for AI providers.', 'auto-blogger-ai' ) . '</p>';
			},
			'aba_settings'
		);

		add_settings_field(
			'gemini_api_key',
			__( 'Gemini API Key', 'auto-blogger-ai' ),
			array( $this, 'field_gemini_api_key' ),
			'aba_settings',
			'aba_api_section'
		);

		add_settings_field(
			'openai_api_key',
			__( 'OpenAI API Key', 'auto-blogger-ai' ),
			array( $this, 'field_openai_api_key' ),
			'aba_settings',
			'aba_api_section'
		);
	}

	public function sanitize_settings( $input ) {
		$sanitized = array();
		$sanitized['gemini_api_key'] = isset( $input['gemini_api_key'] ) ? sanitize_text_field( $input['gemini_api_key'] ) : '';
		$sanitized['openai_api_key'] = isset( $input['openai_api_key'] ) ? sanitize_text_field( $input['openai_api_key'] ) : '';
		return $sanitized;
	}

	public function field_gemini_api_key() {
		$options = get_option( self::OPTION_KEY, array() );
		$value   = isset( $options['gemini_api_key'] ) ? $options['gemini_api_key'] : '';
		printf(
			'<input type="text" name="%1$s[gemini_api_key]" value="%2$s" class="regular-text" />',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $value )
		);
	}

	public function field_openai_api_key() {
		$options = get_option( self::OPTION_KEY, array() );
		$value   = isset( $options['openai_api_key'] ) ? $options['openai_api_key'] : '';
		printf(
			'<input type="text" name="%1$s[openai_api_key]" value="%2$s" class="regular-text" />',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $value )
		);
	}

	/**
	 * Settings page HTML.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'auto-blogger-ai' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Auto Blogger AI Settings', 'auto-blogger-ai' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'aba_settings_group' );
				do_settings_sections( 'aba_settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Simple logs page (expand as needed).
	 */
	public function render_logs_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'auto-blogger-ai' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Auto Blogger AI Logs', 'auto-blogger-ai' ); ?></h1>
			<p><?php esc_html_e( 'Logs will appear here in future releases.', 'auto-blogger-ai' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Enqueue admin assets: scripts + styles for modal and meta box UI.
	 *
	 * @param string $hook Current admin page hook.
	 */
  public function admin_assets( $hook ) {
    // Only load on admin screens where it's required
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    $allowed_screen_ids = array(
      'edit-ai_campaign',           // campaigns list
      'ai_campaign',                // single edit for our CPT
      'post-new.php',               // new post screen (we check post_type)
      'toplevel_page_aba_root',     // plugin dashboard page
    );

    $enqueue = false;

    // If get_current_screen is available, more precise check
    if ( $screen ) {
      // screen->id may contain post type information
      if ( in_array( $screen->id, $allowed_screen_ids, true ) || ( isset( $screen->post_type ) && 'ai_campaign' === $screen->post_type ) ) {
        $enqueue = true;
      }
    }

    // Fallback: check current URL for post_type=ai_campaign (for cases where get_current_screen is not callable)
    if ( ! $enqueue ) {
      $uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
      if ( false !== strpos( $uri, 'post_type=ai_campaign' ) || false !== strpos( $uri, 'page=aba_settings' ) || false !== strpos( $uri, '/edit.php?post_type=ai_campaign' ) ) {
        $enqueue = true;
      }
    }

    if ( ! $enqueue ) {
      return;
    }

    // Enqueue styles and scripts
    wp_enqueue_style( 'aba-admin-css', ABA_PLUGIN_URL . 'assets/css/admin.css', array(), self::VERSION );
    wp_enqueue_script( 'aba-admin-campaign', ABA_PLUGIN_URL . 'assets/js/admin-campaign.js', array( 'jquery' ), self::VERSION, true );

    // Localize required values (no PHP placeholders in JS file)
    wp_localize_script(
      'aba-admin-campaign',
      'ABA_Admin',
      array(
        'ajax_nonce'     => wp_create_nonce( 'aba_admin_nonce' ),
        'post_type'      => 'ai_campaign',
        'admin_post_new' => admin_url( 'post-new.php' ),
        'i18n'           => array(
          'select_campaign' => __( 'Select Campaign Type', 'auto-blogger-ai' ),
          'rss_feed'        => __( 'RSS Feed', 'auto-blogger-ai' ),
          'youtube'         => __( 'YouTube', 'auto-blogger-ai' ),
          'keyword'         => __( 'Keyword', 'auto-blogger-ai' ),
          'trends'          => __( 'Google Trends', 'auto-blogger-ai' ),
          'cancel'          => __( 'Cancel', 'auto-blogger-ai' ),
        ),
      )
    );
  }
}

// Initialize plugin.
ABA_Plugin::instance();
