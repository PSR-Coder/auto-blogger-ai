<?php
defined( 'ABSPATH' ) || exit;

/**
 * ABA_Cron
 *
 * Register custom intervals, schedule event and run campaigns.
 */
class ABA_Cron {

	const HOOK = 'aba_run_campaigns_event';

	public static function init() {
		// Add custom intervals
		add_filter( 'cron_schedules', array( __CLASS__, 'custom_cron_intervals' ) );

		// Hook our runner.
		add_action( self::HOOK, array( __CLASS__, 'run_all_active_campaigns' ) );
	}

	/**
	 * Activation hook: schedule if not scheduled
	 */
	public static function activate() {
		// Default schedule: every 30 minutes
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'thirty_min', self::HOOK );
		}
	}

	/**
	 * Deactivation hook: clear scheduled event
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * Add custom intervals: 30 minutes, twice daily
	 *
	 * @param array $schedules
	 * @return array
	 */
	public static function custom_cron_intervals( $schedules ) {
		$schedules['thirty_min'] = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 30 Minutes', 'auto-blogger-ai' ),
		);
		$schedules['twice_daily'] = array(
			'interval' => 12 * HOUR_IN_SECONDS,
			'display'  => __( 'Twice Daily', 'auto-blogger-ai' ),
		);

		// Ensure 'hourly' and 'daily' already exist in WP core
		return $schedules;
	}

	/**
	 * Loop through active campaigns and run them.
	 *
	 * This runs on a single hook (can be partitioned later). Each campaign defines its own schedule interval -
	 * for now we'll run all active campaigns on every execution but we check per-campaign 'active' meta and
	 * can also keep a last-run timestamp to respect custom schedule intervals.
	 */
	public static function run_all_active_campaigns() {
		$args = array(
			'post_type' => 'ai_campaign',
			'post_status' => 'publish',
			'meta_query' => array(
				array(
					'key' => '_aba_active',
					'value' => '1',
					'compare' => '=',
				),
			),
			'posts_per_page' => -1,
		);

		$campaigns = get_posts( $args );
		if ( empty( $campaigns ) ) {
			return;
		}

		foreach ( $campaigns as $camp ) {
			try {
				ABA_RSS_Engine::run_campaign( $camp->ID );
			} catch ( Exception $e ) {
				error_log( 'ABA: Exception running campaign ' . $camp->ID . ' - ' . $e->getMessage() );
			}
		}
	}
}
