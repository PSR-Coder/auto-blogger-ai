<?php
defined( 'ABSPATH' ) || exit;

class ABA_Cron {
	const HOOK = 'aba_run_campaigns_event';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'custom_cron_intervals' ) );
		add_action( self::HOOK, array( __CLASS__, 'run_all_active_campaigns' ) );
	}

	public static function activate() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			// Schedule to run every 30 minutes by default
			wp_schedule_event( time(), 'thirty_min', self::HOOK );
		}
	}

	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	public static function custom_cron_intervals( $schedules ) {
		$schedules['thirty_min'] = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 30 Minutes', 'auto-blogger-ai' ),
		);
		$schedules['twice_daily'] = array(
			'interval' => 12 * HOUR_IN_SECONDS,
			'display'  => __( 'Twice Daily', 'auto-blogger-ai' ),
		);
		return $schedules;
	}

	public static function run_all_active_campaigns() {
		$args = array(
			'post_type'      => 'ai_campaign',
			'post_status'    => 'publish',
			'meta_query'     => array(
				array(
					'key'     => '_aba_active',
					'value'   => '1',
					'compare' => '=',
				),
			),
			'posts_per_page' => -1,
		);

		$campaigns = get_posts( $args );
		if ( empty( $campaigns ) ) {
			return;
		}

		$interval_map = array(
			'thirty_min'  => 30 * MINUTE_IN_SECONDS,
			'hourly'      => HOUR_IN_SECONDS,
			'twice_daily' => 12 * HOUR_IN_SECONDS,
			'daily'       => DAY_IN_SECONDS,
		);

		foreach ( $campaigns as $camp ) {
			$schedule = get_post_meta( $camp->ID, '_aba_schedule_interval', true );
			$schedule = $schedule ? $schedule : 'daily';
			$interval_seconds = isset( $interval_map[ $schedule ] ) ? $interval_map[ $schedule ] : DAY_IN_SECONDS;

			$last_run = intval( get_post_meta( $camp->ID, '_aba_last_run', true ) );

			// If not enough time passed since last run, skip
			if ( $last_run && ( time() - $last_run ) < $interval_seconds ) {
				continue;
			}

			try {
				ABA_RSS_Engine::run_campaign( $camp->ID );
				update_post_meta( $camp->ID, '_aba_last_run', time() );
			} catch ( Exception $e ) {
				error_log( 'ABA: Exception running campaign ' . $camp->ID . ' - ' . $e->getMessage() );
			}
		}
	}
}
