<?php
/**
 * Debug helpers for We Spam Econo.
 *
 * Provides admin notices with table statistics and WP-CLI commands
 * for debugging and maintenance.
 *
 * Admin notices only display when WP_DEBUG or WSE_DEBUG is enabled.
 * WP-CLI commands are always available.
 *
 * @package We_Spam_Econo
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Debug helper class for We Spam Econo.
 */
class WSE_Debug {

	/**
	 * Constructor - set up hooks.
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'display_debug_notice' ) );
	}

	/**
	 * Display debug notice on Discussion settings page.
	 *
	 * @return void
	 */
	public function display_debug_notice() {
		// Only show on options-discussion.php.
		$screen = get_current_screen();
		if ( ! $screen || 'options-discussion' !== $screen->id ) {
			return;
		}

		// Only show to administrators.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;

		$table_name = WSE_Core::get_table_name();

		// Check if table exists.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		if ( ! $table_exists ) {
			echo '<div class="notice notice-warning">';
			echo '<p><strong>' . esc_html__( 'We Spam Econo:', 'we-spam-econo' ) . '</strong> ';
			echo esc_html__( 'Custom table does not exist. Please deactivate and reactivate the plugin.', 'we-spam-econo' );
			echo '</p></div>';
			return;
		}

		// Get counts by term type.
		$counts = WSE_Core::get_term_counts();

		// Get table size.
		$table_size = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT
					ROUND(((data_length + index_length) / 1024), 2) AS size_kb
				FROM information_schema.TABLES
				WHERE table_schema = %s
				AND table_name = %s',
				DB_NAME,
				$table_name
			)
		);

		$size_kb = $table_size ? $table_size->size_kb : 0;

		// Check if blacklist_keys option still exists (should be empty after migration).
		// Note: These are WordPress core option names, not our terminology.
		$blacklist_keys_exists  = get_option( 'blacklist_keys', null );
		$disallowed_keys_exists = get_option( 'disallowed_keys', null );

		$has_legacy_data = ( null !== $blacklist_keys_exists && ! empty( $blacklist_keys_exists ) ) ||
							( null !== $disallowed_keys_exists && ! empty( $disallowed_keys_exists ) );

		// Display the notice.
		echo '<div class="notice notice-info">';
		echo '<p><strong>' . esc_html__( 'We Spam Econo Debug Info:', 'we-spam-econo' ) . '</strong></p>';
		echo '<ul style="margin-left: 20px; list-style-type: disc;">';
		printf(
			/* translators: %s: database table name. */
			'<li>' . esc_html__( 'Table: %s', 'we-spam-econo' ) . '</li>',
			esc_html( $table_name )
		);
		printf(
			/* translators: %d: number of remote terms. */
			'<li>' . esc_html__( 'Remote terms: %d', 'we-spam-econo' ) . '</li>',
			absint( $counts['remote'] )
		);
		printf(
			/* translators: %d: number of local terms. */
			'<li>' . esc_html__( 'Local terms: %d', 'we-spam-econo' ) . '</li>',
			absint( $counts['local'] )
		);
		printf(
			/* translators: %d: number of exclusions. */
			'<li>' . esc_html__( 'Exclusions: %d', 'we-spam-econo' ) . '</li>',
			absint( $counts['exclude'] )
		);
		printf(
			/* translators: %s: table size in kilobytes. */
			'<li>' . esc_html__( 'Table size: %s KB', 'we-spam-econo' ) . '</li>',
			esc_html( number_format( (float) $size_kb, 2 ) )
		);

		// Show next scheduled update.
		$next_update = wp_next_scheduled( 'wse_scheduled_update' );
		if ( $next_update ) {
			$time_until = human_time_diff( time(), $next_update );
			printf(
				/* translators: %s: human-readable time until next update. */
				'<li>' . esc_html__( 'Next scheduled update: %s', 'we-spam-econo' ) . '</li>',
				/* translators: %s: human-readable time difference. */
				esc_html( sprintf( __( 'in %s', 'we-spam-econo' ), $time_until ) )
			);
		} else {
			echo '<li style="color: #d63638;">' . esc_html__( 'Next scheduled update: Not scheduled!', 'we-spam-econo' ) . '</li>';
		}

		echo '</ul>';

		if ( $has_legacy_data ) {
			echo '<p style="color: #d63638;"><strong>' . esc_html__( 'WARNING:', 'we-spam-econo' ) . '</strong> ';
			echo esc_html__( 'Legacy blacklist_keys or disallowed_keys option contains data. This data is NOT being used by the plugin and is wasting space in wp_options. Consider running the cleanup command or re-activating the plugin to migrate this data.', 'we-spam-econo' );
			echo '</p>';
		} else {
			echo '<p style="color: #00a32a;"><strong>' . esc_html__( 'SUCCESS:', 'we-spam-econo' ) . '</strong> ';
			echo esc_html__( 'No legacy data found in wp_options. All blocklist data is stored in the custom table.', 'we-spam-econo' );
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * Get duplicate term count.
	 *
	 * @return int
	 */
	public static function get_duplicate_count() {
		global $wpdb;

		$table_name = WSE_Core::get_table_name();

		// Check if table exists.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( ! $table_exists ) {
			return 0;
		}

		$total  = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
		$unique = $wpdb->get_var( "SELECT COUNT(DISTINCT CONCAT(term_type, '|', term_value)) FROM $table_name" );

		return max( 0, (int) $total - (int) $unique );
	}

	/**
	 * Remove duplicate entries from table.
	 *
	 * @return int Number of duplicates removed.
	 */
	public static function cleanup_duplicates() {
		global $wpdb;

		$table_name = WSE_Core::get_table_name();

		// Check if table exists.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( ! $table_exists ) {
			return 0;
		}

		$before_count = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );

		// Delete duplicates keeping the oldest entry (lowest id).
		$wpdb->query(
			"DELETE t1 FROM $table_name t1
			INNER JOIN $table_name t2
			WHERE t1.id > t2.id
			AND t1.term_type = t2.term_type
			AND t1.term_value = t2.term_value"
		);

		$after_count = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );

		return max( 0, (int) $before_count - (int) $after_count );
	}
}

// Initialize debug helpers only when debugging is enabled.
if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( defined( 'WSE_DEBUG' ) && WSE_DEBUG ) ) {
	new WSE_Debug();
}

/**
 * WP-CLI Commands for We Spam Econo.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * We Spam Econo - manage comment blocklist data.
	 *
	 * ## EXAMPLES
	 *
	 *     # Show debug info
	 *     wp wse debug
	 *
	 *     # Clean up duplicates
	 *     wp wse cleanup
	 *
	 *     # Optimize table (cleanup + reclaim space)
	 *     wp wse optimize
	 *
	 *     # Schedule cron event
	 *     wp wse schedule
	 *
	 *     # Run blocklist update immediately
	 *     wp wse update
	 *
	 *     # Clear the blocklist cache
	 *     wp wse flush
	 */
	class WSE_CLI {

		/**
		 * Display table statistics and health check.
		 *
		 * ## EXAMPLES
		 *
		 *     wp wse debug
		 *
		 * @when after_wp_load
		 */
		public function debug() {
			global $wpdb;

			$table_name = WSE_Core::get_table_name();

			// Check if table exists.
			$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

			if ( ! $table_exists ) {
				WP_CLI::error( 'Custom table does not exist. Please deactivate and reactivate the plugin.' );
				return;
			}

			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( '%BWe Spam Econo - Debug Info%n' ) );
			WP_CLI::log( str_repeat( '-', 50 ) );

			// Get counts.
			$counts = WSE_Core::get_term_counts();

			// Get table size.
			$table_size = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT
						ROUND(((data_length + index_length) / 1024), 2) AS size_kb
					FROM information_schema.TABLES
					WHERE table_schema = %s
					AND table_name = %s',
					DB_NAME,
					$table_name
				)
			);

			$size_kb = $table_size ? $table_size->size_kb : 0;

			// Get duplicate count.
			$duplicates = WSE_Debug::get_duplicate_count();

			WP_CLI::log( sprintf( 'Table: %s', $table_name ) );
			WP_CLI::log( sprintf( 'Remote terms: %d', $counts['remote'] ) );
			WP_CLI::log( sprintf( 'Local terms: %d', $counts['local'] ) );
			WP_CLI::log( sprintf( 'Exclusions: %d', $counts['exclude'] ) );
			WP_CLI::log( sprintf( 'Total unique terms: %d', $counts['remote'] + $counts['local'] + $counts['exclude'] ) );
			WP_CLI::log( sprintf( 'Table size: %s KB', number_format( (float) $size_kb, 2 ) ) );
			WP_CLI::log( sprintf( 'Duplicate entries: %d', $duplicates ) );

			// Show cron status.
			$next_update = wp_next_scheduled( 'wse_scheduled_update' );
			if ( $next_update ) {
				$time_until = human_time_diff( time(), $next_update );
				WP_CLI::log( sprintf( 'Next scheduled update: in %s (%s)', $time_until, gmdate( 'Y-m-d H:i:s', $next_update ) . ' UTC' ) );
			} else {
				WP_CLI::warning( 'Cron event not scheduled! Run "wp wse schedule" to fix.' );
			}

			$next_optimize = wp_next_scheduled( 'wse_scheduled_optimize' );
			if ( $next_optimize ) {
				$time_until_opt = human_time_diff( time(), $next_optimize );
				WP_CLI::log( sprintf( 'Next scheduled optimize: in %s (%s)', $time_until_opt, gmdate( 'Y-m-d H:i:s', $next_optimize ) . ' UTC' ) );
			} else {
				WP_CLI::log( 'Next scheduled optimize: not scheduled' );
			}

			// Check legacy options (WordPress core option names).
			$blacklist_keys  = get_option( 'blacklist_keys', null );
			$disallowed_keys = get_option( 'disallowed_keys', null );

			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( '%BLegacy wp_options Check%n' ) );
			WP_CLI::log( str_repeat( '-', 50 ) );

			if ( null !== $blacklist_keys && ! empty( $blacklist_keys ) ) {
				$size = strlen( $blacklist_keys );
				WP_CLI::warning( sprintf( 'blacklist_keys option exists with %s bytes of data!', number_format( $size ) ) );
			} else {
				WP_CLI::success( 'blacklist_keys option is empty or does not exist.' );
			}

			if ( null !== $disallowed_keys && ! empty( $disallowed_keys ) ) {
				$size = strlen( $disallowed_keys );
				WP_CLI::warning( sprintf( 'disallowed_keys option exists with %s bytes of data!', number_format( $size ) ) );
			} else {
				WP_CLI::success( 'disallowed_keys option is empty or does not exist.' );
			}

			if ( $duplicates > 0 ) {
				WP_CLI::log( '' );
				WP_CLI::warning( sprintf( 'Found %d duplicate entries. Run "wp wse cleanup" to remove them.', $duplicates ) );
			}

			WP_CLI::log( '' );
		}

		/**
		 * Remove duplicate entries from the blocklist table.
		 *
		 * ## EXAMPLES
		 *
		 *     wp wse cleanup
		 *
		 * @when after_wp_load
		 */
		public function cleanup() {
			$duplicates = WSE_Debug::get_duplicate_count();

			if ( 0 === $duplicates ) {
				WP_CLI::success( 'No duplicate entries found. Table is clean.' );
				return;
			}

			WP_CLI::log( sprintf( 'Found %d duplicate entries. Cleaning up...', $duplicates ) );

			$removed = WSE_Debug::cleanup_duplicates();

			WP_CLI::success( sprintf( 'Removed %d duplicate entries.', $removed ) );
		}

		/**
		 * Schedule the next blocklist update cron event.
		 *
		 * ## OPTIONS
		 *
		 * [--force]
		 * : Reschedule even if already scheduled.
		 *
		 * ## EXAMPLES
		 *
		 *     wp wse schedule
		 *     wp wse schedule --force
		 *
		 * @when after_wp_load
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public function schedule( $args, $assoc_args ) {
			$force       = isset( $assoc_args['force'] );
			$next_update = wp_next_scheduled( 'wse_scheduled_update' );

			if ( $next_update ) {
				if ( ! $force ) {
					WP_CLI::warning(
						sprintf(
							'Cron event already scheduled for %s UTC. Use --force to reschedule.',
							gmdate( 'Y-m-d H:i:s', $next_update )
						)
					);
					return;
				}

				// Unschedule existing event.
				wp_unschedule_event( $next_update, 'wse_scheduled_update' );
			}

			WSE_Core::schedule_cron();
			$new_time = wp_next_scheduled( 'wse_scheduled_update' );

			if ( $new_time ) {
				WP_CLI::success(
					sprintf(
						'Cron event scheduled for %s UTC.',
						gmdate( 'Y-m-d H:i:s', $new_time )
					)
				);
			} else {
				WP_CLI::error( 'Failed to schedule cron event.' );
			}
		}

		/**
		 * Run blocklist update immediately.
		 *
		 * ## EXAMPLES
		 *
		 *     wp wse update
		 *
		 * @when after_wp_load
		 */
		public function update() {
			WP_CLI::log( 'Fetching blocklist from remote sources...' );

			WSE_Core::blocklist_process_loader();

			$counts = WSE_Core::get_term_counts();
			WP_CLI::success(
				sprintf(
					'Blocklist updated. Remote terms: %d, Local terms: %d, Exclusions: %d',
					$counts['remote'],
					$counts['local'],
					$counts['exclude']
				)
			);
		}

		/**
		 * Clear the blocklist cache.
		 *
		 * ## EXAMPLES
		 *
		 *     wp wse flush
		 *
		 * @when after_wp_load
		 */
		public function flush() {
			WSE_Core::clear_cache();
			WP_CLI::success( 'Blocklist cache cleared.' );
		}

		/**
		 * Optimize the blocklist table.
		 *
		 * Removes duplicate entries and runs OPTIMIZE TABLE to reclaim
		 * unused space and defragment the table.
		 *
		 * ## EXAMPLES
		 *
		 *     wp wse optimize
		 *
		 * @when after_wp_load
		 */
		public function optimize() {
			WP_CLI::log( 'Optimizing blocklist table...' );

			$results = WSE_Core::optimize_table();

			if ( ! $results['table_optimized'] ) {
				WP_CLI::error( 'Table does not exist. Please deactivate and reactivate the plugin.' );
				return;
			}

			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Duplicates removed:  %d', $results['duplicates_removed'] ) );
			WP_CLI::log( sprintf( 'Size before:         %.2f KB', $results['size_before'] ) );
			WP_CLI::log( sprintf( 'Size after:          %.2f KB', $results['size_after'] ) );

			$saved = $results['size_before'] - $results['size_after'];
			if ( $saved > 0 ) {
				WP_CLI::log( sprintf( 'Space reclaimed:     %.2f KB', $saved ) );
			}

			WP_CLI::log( '' );
			WP_CLI::success( 'Table optimization complete.' );
		}
	}

	WP_CLI::add_command( 'wse', 'WSE_CLI' );
}
