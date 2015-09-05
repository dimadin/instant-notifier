<?php
/**
 * The Instant Notifier Plugin
 *
 * Check RSSes every five minutes and send mail for new items.
 *
 * @package    Instant_Notifier
 * @subpackage Main
 */

/**
 * Plugin Name: Instant Notifier
 * Plugin URI:  http://blog.milandinic.com/wordpress/plugins/
 * Description: Check RSSes every five minutes and send mail for new items.
 * Author:      Milan Dinić
 * Author URI:  http://blog.milandinic.com/
 * Version:     0.1
 * Text Domain: instant-notifier
 * Domain Path: /languages/
 * License:     GPL
 */

/* Exit if accessed directly */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Schedule install cron event on plugin activation.
 *
 * Since class can't be initialized on activation,
 * installation needs to occur on next page load.
 * That's why we schedule single cron event that
 * will be fired on next page load.
 *
 * @since 1.0
 */
function instant_notifier_activation() {
	wp_schedule_single_event( time(), 'instant_notifier_single_event_activate' );
}
register_activation_hook( __FILE__, 'instant_notifier_activation' );

/**
 * Unschedule Instant Notifier event on deactivation.
 *
 * @since 1.0
 */
function instant_notifier_deactivation() {
	wp_clear_scheduled_hook( 'instant_notifier_event' );
}
register_deactivation_hook( __FILE__, 'instant_notifier_deactivation' );

/**
 * Initialize a plugin.
 *
 * Load class when all plugins are loaded
 * so that other plugins can overwrite it.
 *
 * @since 1.0
 */
function instant_notifier_instantiate() {
	global $instant_notifier;
	$instant_notifier = new Instant_Notifier();
}
add_action( 'plugins_loaded', 'instant_notifier_instantiate', 15 );

if ( ! class_exists( 'Instant_Notifier' ) ) :
/**
 * Instant Notifier main class.
 *
 * Queue and publish posts automatically.
 *
 * @since 1.0
 */
class Instant_Notifier {
	/**
	 * Initialize Instant_Notifier object.
	 *
	 * Set class properties and add main methods to appropriate hooks.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function __construct() {
		// Load translations
		load_plugin_textdomain( 'instant-notifier', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		// Add cron interval
		add_filter( 'cron_schedules',                         array( $this, 'add_interval' )    );

		// Schedule cron event when single event happens
		add_action( 'instant_notifier_single_event_activate', array( $this, 'schedule' ) );

		// Hook feeds fetcher on cron event
		add_action( 'instant_notifier_event',                 array( $this, 'fetch_feeds' ) );
	}

	/**
	 * Add custom cron interval.
	 *
	 * Add a 'fiveminutes' interval to the existing set
	 * of intervals that lasts 5 minutes.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param  array $schedules Existing cron intervals.
	 * @return array $schedules New cron intervals.
	 */
	public function add_interval( $schedules ) {
		$schedules['fiveminutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Five Minutes', 'instant-notifier' )
		);

		return $schedules;
	}

	/**
	 * Schedule event for fetching feeds.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function schedule() {
		wp_schedule_event( time(), 'fiveminutes', 'instant_notifier_event' );
	}

	/**
	 * Get all registered feeds.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function get_feeds() {
		$feeds = (array) apply_filters( 'instant_notifier_feeds', array() );

		return $feeds;
	}

	/**
	 * Return one minute in seconds.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param string $time Time in seconds.
	 * @return string $time Time in seconds. Defulat 60 seconds.
	 */
	public function minute_in_seconds( $time ) {
		return MINUTE_IN_SECONDS;
	}

	/**
	 * Fetch all feeds.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function fetch_feeds() {
		// Get latest posts for all feeds
		$options = (array) get_option( 'instant_notifier_feeds_times' );

		$new_options = $options;

		add_filter( 'wp_feed_cache_transient_lifetime' , array( $this, 'minute_in_seconds' ) );

		foreach ( $this->get_feeds() as $feed ) {
			// Get a SimplePie feed object from the specified feed source.
			$simplepie_object = fetch_feed( $feed );

			// If there was an error, don't proceed with feed
			if ( is_wp_error( $simplepie_object ) ) {
				continue;
			}

			// Figure out how many total items there are
			$maxitems = $simplepie_object->get_item_quantity(); 

			// Build an array of all the items
			$rss_items = $simplepie_object->get_items();

			// If there are no items, don't proceed with feed
			if ( ! $rss_items ) {
				continue;
			}

			// Check latest time of previous fetch
			$last_latest_time = isset( $options[ $feed ] ) ? $options[ $feed ] : '';

			// If latest time of new fetch isn't new, don't proceed
			$new_latest_time = $rss_items[0]->get_gmdate( 'U' );

			if ( $last_latest_time >= $new_latest_time ) {
				continue;
			}

			// Prepare an empty body of email
			$body = '';

			foreach ( $rss_items as $rss_item ) {
				$item_time = $rss_item->get_gmdate( 'U' );

				// Don't check older items
				if ( $last_latest_time >= $item_time ) {
					break;
				}

				$body .= "\r\n" . sprintf( __( '%1$s (%2$s):
%3$s', 'instant-notifier' ), $rss_item->get_title(), date_i18n( get_option( 'date_format' ), $item_time ), $rss_item->get_permalink() ) . "\r\n";
			}

			if ( ! $body ) {
				continue;
			}

			$feed_title = $simplepie_object->get_title();
			$subject = sprintf( __( 'New items from %s', 'instant-notifier' ), $feed_title );

			$message = sprintf( __( 'Here are the latest items from %1$s:
			
%2$s', 'instant-notifier' ), $feed_title, $body );

			// Save new options
			$new_options[ $feed ] = $new_latest_time;

			// Send email
			wp_mail( get_bloginfo( 'admin_email' ), $subject, $message );
		}

		remove_filter( 'wp_feed_cache_transient_lifetime' , array( $this, 'minute_in_seconds' ) );

		if ( $options != $new_options ) {
			update_option( 'instant_notifier_feeds_times', $new_options, false );
		}
	}
}
endif;
