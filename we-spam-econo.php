<?php
/**
 * Plugin Name:        We Spam Econo
 * Plugin URI:         https://github.com/littleroomstudio/we-spam-econo
 * GitHub Plugin URI:  https://github.com/littleroomstudio/we-spam-econo
 * Primary Branch:     main
 * Description:        Block comment spam using a continuously-updated blocklist of 64,000+ known spam terms stored in a high-performance custom database table.
 * Version:            2.0.4
 * Author:             Little Room
 * Author URI:         https://littleroom.studio
 * Text Domain:        we-spam-econo
 * License:            MIT
 * License URI:        https://opensource.org/licenses/MIT
 *
 * @package We_Spam_Econo
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WSE_BASE' ) ) {
	define( 'WSE_BASE', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'WSE_DIR' ) ) {
	define( 'WSE_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'WSE_VER' ) ) {
	define( 'WSE_VER', '2.0.4' );
}

/**
 * Main plugin class for We Spam Econo.
 *
 * Handles blocklist management, comment checking, and admin settings.
 */
class WSE_Core {

	/**
	 * Static property to hold our singleton instance.
	 *
	 * @var WSE_Core|false
	 */
	private static $instance = false;

	/**
	 * Custom table name (without prefix).
	 *
	 * @var string
	 */
	const TABLE_NAME = 'wse_blocklist';

	/**
	 * Store matched term for comment meta.
	 *
	 * @var string|null
	 */
	private $matched_term = null;

	/**
	 * Store matched field for comment meta.
	 *
	 * @var string|null
	 */
	private $matched_field = null;

	/**
	 * Cron hook name for blocklist updates.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'wse_scheduled_update';

	/**
	 * Cron hook name for table optimization.
	 *
	 * @var string
	 */
	const OPTIMIZE_HOOK = 'wse_scheduled_optimize';

	/**
	 * Constructor - hooks into WordPress.
	 */
	private function __construct() {
		add_action( 'admin_init', array( $this, 'load_settings' ) );
		add_action( 'admin_init', array( $this, 'update_blocklist_manual' ) );
		add_action( 'admin_notices', array( $this, 'manual_update_notice' ) );
		add_filter( 'removable_query_args', array( $this, 'add_removable_args' ) );
		add_filter( 'pre_comment_approved', array( $this, 'check_comment_blocklist' ), 10, 2 );
		add_action( 'wp_insert_comment', array( $this, 'save_spam_meta' ), 10, 2 );
		add_filter( 'comment_row_actions', array( $this, 'add_comment_row_info' ), 10, 2 );
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_update' ) );
		add_action( self::OPTIMIZE_HOOK, array( __CLASS__, 'run_scheduled_optimize' ) );
		register_activation_hook( __FILE__, array( $this, 'run_initial_process' ) );
		register_deactivation_hook( __FILE__, array( $this, 'remove_settings' ) );
	}

	/**
	 * Get singleton instance.
	 *
	 * @return WSE_Core
	 */
	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get the full table name with prefix.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create the custom database table.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			term_type varchar(10) NOT NULL DEFAULT 'remote',
			term_value text NOT NULL,
			PRIMARY KEY  (id),
			KEY term_type (term_type)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Optimize table schema by removing unused columns.
	 *
	 * Drops created_at and updated_at columns if they exist, and
	 * tightens term_type from varchar(20) to varchar(10).
	 *
	 * @return void
	 */
	public static function optimize_table_schema() {
		global $wpdb;

		$table_name = self::get_table_name();

		// Check if created_at column exists (indicates old schema).
		$column_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$table_name,
				'created_at'
			)
		);

		if ( $column_exists ) {
			// Drop unused timestamp columns and tighten term_type.
			$wpdb->query( "ALTER TABLE $table_name DROP COLUMN created_at, DROP COLUMN updated_at, MODIFY term_type varchar(10) NOT NULL DEFAULT 'remote'" );
		}
	}

	/**
	 * Activation: create table, migrate data, clean up wp_options, schedule cron.
	 *
	 * @return void
	 */
	public function run_initial_process() {
		// Create the custom table.
		self::create_table();

		// Optimize existing table schema if needed.
		self::optimize_table_schema();

		// Migrate existing blacklist_local option to custom table (WP core option name).
		$local = get_option( 'blacklist_local' );
		if ( $local && ! empty( $local ) ) {
			$terms = self::datalist_clean( $local );
			self::save_terms( $terms, 'local' );
			delete_option( 'blacklist_local' );
		}

		// Migrate existing blacklist_exclude option to custom table (WP core option name).
		$exclude = get_option( 'blacklist_exclude' );
		if ( $exclude && ! empty( $exclude ) ) {
			$terms = self::datalist_clean( $exclude );
			self::save_terms( $terms, 'exclude' );
			delete_option( 'blacklist_exclude' );
		}

		// Fetch remote sources and save as remote terms.
		self::blocklist_process_loader();

		// Clean up old wp_options entries to remove bloat (WP core option names).
		if ( apply_filters( 'wse_delete_blacklist_keys', true ) ) {
			delete_option( 'blacklist_keys' );
			delete_option( 'disallowed_keys' );
		}

		// Schedule the cron events.
		self::schedule_cron();
		self::schedule_optimize();
	}

	/**
	 * Schedule the cron event for blocklist updates.
	 *
	 * @return void
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$interval = apply_filters( 'wse_update_schedule', DAY_IN_SECONDS );
			wp_schedule_single_event( time() + $interval, self::CRON_HOOK );
		}
	}

	/**
	 * Schedule the cron event for weekly table optimization.
	 *
	 * @return void
	 */
	public static function schedule_optimize() {
		if ( ! wp_next_scheduled( self::OPTIMIZE_HOOK ) ) {
			$interval = apply_filters( 'wse_optimize_schedule', WEEK_IN_SECONDS );
			wp_schedule_single_event( time() + $interval, self::OPTIMIZE_HOOK );
		}
	}

	/**
	 * Run the scheduled blocklist update (called by cron).
	 *
	 * @return void
	 */
	public function run_scheduled_update() {
		self::blocklist_process_loader();

		// Schedule the next update.
		$interval = apply_filters( 'wse_update_schedule', DAY_IN_SECONDS );
		wp_schedule_single_event( time() + $interval, self::CRON_HOOK );
	}

	/**
	 * Run the scheduled table optimization (called by cron).
	 *
	 * Optimizes the blocklist table to reclaim space and defragment.
	 * For InnoDB tables, this recreates the table and rebuilds indexes.
	 *
	 * @return void
	 */
	public static function run_scheduled_optimize() {
		self::optimize_table();

		// Schedule the next optimization.
		$interval = apply_filters( 'wse_optimize_schedule', WEEK_IN_SECONDS );
		wp_schedule_single_event( time() + $interval, self::OPTIMIZE_HOOK );
	}

	/**
	 * Optimize the blocklist table.
	 *
	 * Runs OPTIMIZE TABLE to reclaim unused space and defragment.
	 * Also removes any duplicate entries before optimizing.
	 *
	 * @return array Results with 'duplicates_removed' and 'table_optimized' keys.
	 */
	public static function optimize_table() {
		global $wpdb;

		$table_name = self::get_table_name();
		$results    = array(
			'duplicates_removed' => 0,
			'table_optimized'    => false,
			'size_before'        => 0,
			'size_after'         => 0,
		);

		// Check if table exists.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( ! $table_exists ) {
			return $results;
		}

		// Get size before optimization.
		$size_before            = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ROUND((data_length + index_length) / 1024, 2)
				FROM information_schema.TABLES
				WHERE table_schema = %s AND table_name = %s',
				DB_NAME,
				$table_name
			)
		);
		$results['size_before'] = (float) $size_before;

		// Remove duplicates first (keeps oldest entry by id).
		$before_count = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );

		$wpdb->query(
			"DELETE t1 FROM $table_name t1
			INNER JOIN $table_name t2
			WHERE t1.id > t2.id
			AND t1.term_type = t2.term_type
			AND t1.term_value = t2.term_value"
		);

		$after_count                   = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
		$results['duplicates_removed'] = max( 0, (int) $before_count - (int) $after_count );

		// Optimize the table (for InnoDB this recreates table + rebuilds indexes).
		$wpdb->query( "OPTIMIZE TABLE $table_name" );
		$results['table_optimized'] = true;

		// Get size after optimization.
		$size_after            = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ROUND((data_length + index_length) / 1024, 2)
				FROM information_schema.TABLES
				WHERE table_schema = %s AND table_name = %s',
				DB_NAME,
				$table_name
			)
		);
		$results['size_after'] = (float) $size_after;

		// Clear caches after optimization.
		self::clear_cache();

		return $results;
	}

	/**
	 * Deactivation: clear cron events and transient, preserve table data.
	 *
	 * @return void
	 */
	public function remove_settings() {
		// Clear the scheduled update cron event.
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}

		// Clear the scheduled optimize cron event.
		$optimize_timestamp = wp_next_scheduled( self::OPTIMIZE_HOOK );
		if ( $optimize_timestamp ) {
			wp_unschedule_event( $optimize_timestamp, self::OPTIMIZE_HOOK );
		}

		// Clean up legacy transient (no longer used but may exist from older versions).
		delete_transient( 'wse_update_process' );
	}

	/**
	 * Register settings and add settings fields.
	 *
	 * @return void
	 */
	public function load_settings() {
		// Register virtual settings for the form inputs.
		register_setting( 'discussion', 'wse_local_input', array( $this, 'save_local_terms' ) );
		register_setting( 'discussion', 'wse_exclude_input', array( $this, 'save_exclude_terms' ) );

		// Load the source list field.
		add_settings_field( 'wse-source', __( 'Blocklist Source', 'we-spam-econo' ), array( $this, 'source_field' ), 'discussion', 'default' );

		// Load the custom list field.
		add_settings_field( 'wse-local', __( 'Local Blocklist', 'we-spam-econo' ), array( $this, 'local_field' ), 'discussion', 'default' );

		// Load the exclusion field.
		add_settings_field( 'wse-exclude', __( 'Excluded Terms', 'we-spam-econo' ), array( $this, 'exclude_field' ), 'discussion', 'default' );

		// Load the stats field.
		add_settings_field( 'wse-stats', __( 'Blocklist Statistics', 'we-spam-econo' ), array( $this, 'stats_field' ), 'discussion', 'default' );
	}

	/**
	 * Display blocklist source URLs.
	 *
	 * @return void
	 */
	public function source_field() {
		echo '<fieldset>';
		echo '<legend class="screen-reader-text"><span>' . esc_html__( 'Blocklist Source', 'we-spam-econo' ) . '</span></legend>';

		echo '<p>';
		echo '<label>' . esc_html__( 'Data from the sources below will be loaded into the comment blocklist automatically.', 'we-spam-econo' ) . '</label>';
		echo '</p>';

		$sources = self::blocklist_sources();

		if ( ! $sources || empty( $sources ) ) {
			echo '<p class="description">' . esc_html__( 'No blocklist sources have been defined.', 'we-spam-econo' ) . '</p>';
		}

		echo '<ul>';
		foreach ( (array) $sources as $source ) {
			echo '<li class="widefat"><a href="' . esc_url( $source ) . '" title="' . esc_attr__( 'View external source', 'we-spam-econo' ) . '" target="_blank"><span class="dashicons dashicons-external"></span></a>&nbsp;' . esc_url( $source ) . '</li>';
		}
		echo '</ul>';

		$update_url = wp_nonce_url( admin_url( 'options-discussion.php' ) . '?wse-update=manual', 'wse_manual_update', 'wse_nonce' );
		echo '<a class="button button-secondary" href="' . esc_url( $update_url ) . '">' . esc_html__( 'Run manual update', 'we-spam-econo' ) . '</a>';
		echo '</fieldset>';
	}

	/**
	 * Display local blocklist textarea.
	 *
	 * @return void
	 */
	public function local_field() {
		$terms = self::get_terms_by_type( 'local' );
		$value = implode( "\n", $terms );

		echo '<fieldset>';
		echo '<legend class="screen-reader-text"><span>' . esc_html__( 'Local Blocklist', 'we-spam-econo' ) . '</span></legend>';

		echo '<p>';
		echo '<label for="wse_local_input">' . esc_html__( 'Any terms entered below will be added to the data retrieved from the blocklist sources. One word or IP per line. It will match inside words, so "press" will match "WordPress".', 'we-spam-econo' ) . '</label>';
		echo '</p>';

		echo '<p>';
		echo '<textarea id="wse_local_input" class="large-text code" cols="50" rows="6" name="wse_local_input">' . esc_textarea( $value ) . '</textarea>';
		echo '</p>';
		echo '</fieldset>';
	}

	/**
	 * Display excluded terms textarea.
	 *
	 * @return void
	 */
	public function exclude_field() {
		$terms = self::get_terms_by_type( 'exclude' );
		$value = implode( "\n", $terms );

		echo '<fieldset>';
		echo '<legend class="screen-reader-text"><span>' . esc_html__( 'Excluded Terms', 'we-spam-econo' ) . '</span></legend>';

		echo '<p>';
		echo '<label for="wse_exclude_input">' . esc_html__( 'Any terms entered below will be excluded from the blocklist updates. One word or IP per line. It will match inside words, so "press" will match "WordPress".', 'we-spam-econo' ) . '</label>';
		echo '</p>';

		echo '<p>';
		echo '<textarea id="wse_exclude_input" class="large-text code" cols="50" rows="6" name="wse_exclude_input">' . esc_textarea( $value ) . '</textarea>';
		echo '</p>';
		echo '</fieldset>';
	}

	/**
	 * Display blocklist statistics.
	 *
	 * @return void
	 */
	public function stats_field() {
		$counts = self::get_term_counts();

		echo '<fieldset>';
		echo '<legend class="screen-reader-text"><span>' . esc_html__( 'Blocklist Statistics', 'we-spam-econo' ) . '</span></legend>';

		echo '<p class="description">';
		printf(
			/* translators: %1$d: remote term count, %2$d: local term count, %3$d: exclusion count */
			esc_html__( 'Current blocklist: %1$d remote terms, %2$d local terms, %3$d exclusions', 'we-spam-econo' ),
			absint( $counts['remote'] ),
			absint( $counts['local'] ),
			absint( $counts['exclude'] )
		);
		echo '</p>';
		echo '</fieldset>';
	}

	/**
	 * Save local terms callback.
	 *
	 * @param string $input The textarea input.
	 * @return string
	 */
	public function save_local_terms( $input ) {
		$input = stripslashes( $input );
		if ( ! empty( $input ) ) {
			$terms = self::datalist_clean( $input );
			self::save_terms( $terms, 'local' );
		} else {
			self::delete_terms_by_type( 'local' );
		}
		return '';
	}

	/**
	 * Save exclude terms callback.
	 *
	 * @param string $input The textarea input.
	 * @return string
	 */
	public function save_exclude_terms( $input ) {
		$input = stripslashes( $input );
		if ( ! empty( $input ) ) {
			$terms = self::datalist_clean( $input );
			self::save_terms( $terms, 'exclude' );
		} else {
			self::delete_terms_by_type( 'exclude' );
		}
		// Trigger refresh after saving exclusions.
		self::blocklist_process_loader();
		return '';
	}

	/**
	 * Save terms to custom table using bulk inserts.
	 *
	 * @param array  $terms     Array of terms to save.
	 * @param string $term_type Type: 'remote', 'local', or 'exclude'.
	 * @return void
	 */
	public static function save_terms( $terms, $term_type ) {
		global $wpdb;

		$table_name = self::get_table_name();

		// Delete existing terms of this type.
		$wpdb->delete( $table_name, array( 'term_type' => $term_type ), array( '%s' ) );

		// Filter, clean, and deduplicate terms.
		$terms = array_unique( array_filter( array_map( 'trim', $terms ) ) );

		if ( empty( $terms ) ) {
			return;
		}

		// Bulk insert in batches of 500 to avoid max_allowed_packet issues.
		$batch_size = 500;
		$batches    = array_chunk( $terms, $batch_size );

		foreach ( $batches as $batch ) {
			$placeholders = array();
			$values       = array();

			foreach ( $batch as $term ) {
				$placeholders[] = '(%s, %s)';
				$values[]       = $term_type;
				$values[]       = $term;
			}

			$query = "INSERT INTO $table_name (term_type, term_value) VALUES " . implode( ', ', $placeholders );
			$wpdb->query( $wpdb->prepare( $query, $values ) );
		}

		// Clear all caches.
		self::clear_cache();
	}

	/**
	 * Get terms by type from custom table with caching.
	 *
	 * @param string $term_type Type: 'remote', 'local', or 'exclude'.
	 * @return array
	 */
	public static function get_terms_by_type( $term_type ) {
		global $wpdb;

		// Try to get from cache first.
		$cache_key = 'wse_terms_' . $term_type;
		$cached    = wp_cache_get( $cache_key, 'wse' );

		if ( false !== $cached ) {
			return $cached;
		}

		$table_name = self::get_table_name();

		// Check if table exists.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( ! $table_exists ) {
			return array();
		}

		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT term_value FROM $table_name WHERE term_type = %s",
				$term_type
			)
		);

		$results = $results ? $results : array();

		// Cache results (persistent if object cache available, otherwise per-request only).
		wp_cache_set( $cache_key, $results, 'wse' );

		return $results;
	}

	/**
	 * Clear all blocklist caches.
	 *
	 * @return void
	 */
	public static function clear_cache() {
		wp_cache_delete( 'wse_terms_remote', 'wse' );
		wp_cache_delete( 'wse_terms_local', 'wse' );
		wp_cache_delete( 'wse_terms_exclude', 'wse' );
		wp_cache_delete( 'wse_combined_blocklist', 'wse' );
	}

	/**
	 * Delete all terms of a specific type.
	 *
	 * @param string $term_type Type: 'remote', 'local', or 'exclude'.
	 * @return void
	 */
	public static function delete_terms_by_type( $term_type ) {
		global $wpdb;

		$table_name = self::get_table_name();
		$wpdb->delete( $table_name, array( 'term_type' => $term_type ), array( '%s' ) );

		// Clear all caches.
		self::clear_cache();
	}

	/**
	 * Get term counts by type.
	 *
	 * @return array
	 */
	public static function get_term_counts() {
		global $wpdb;

		$table_name = self::get_table_name();
		$counts     = array(
			'remote'  => 0,
			'local'   => 0,
			'exclude' => 0,
		);

		// Check if table exists.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( ! $table_exists ) {
			return $counts;
		}

		$results = $wpdb->get_results(
			"SELECT term_type, COUNT(DISTINCT term_value) as count FROM $table_name GROUP BY term_type"
		);

		if ( $results ) {
			foreach ( $results as $row ) {
				if ( isset( $counts[ $row->term_type ] ) ) {
					$counts[ $row->term_type ] = (int) $row->count;
				}
			}
		}

		return $counts;
	}

	/**
	 * Check comment against blocklist (hooks into pre_comment_approved).
	 *
	 * @param int|string|WP_Error $approved    Current approval status.
	 * @param array               $commentdata Comment data array.
	 * @return int|string|WP_Error
	 */
	public function check_comment_blocklist( $approved, $commentdata ) {
		// Reset matched term.
		$this->matched_term  = null;
		$this->matched_field = null;

		// If already spam or trashed, don't process.
		if ( 'spam' === $approved || 'trash' === $approved ) {
			return $approved;
		}

		// Get combined blocklist (cached).
		$blocklist_terms = $this->get_combined_blocklist();

		if ( empty( $blocklist_terms ) ) {
			return $approved;
		}

		// Fields to check (same as WordPress core).
		$comment_fields = array(
			'comment_author'       => isset( $commentdata['comment_author'] ) ? $commentdata['comment_author'] : '',
			'comment_author_email' => isset( $commentdata['comment_author_email'] ) ? $commentdata['comment_author_email'] : '',
			'comment_author_url'   => isset( $commentdata['comment_author_url'] ) ? $commentdata['comment_author_url'] : '',
			'comment_author_IP'    => isset( $commentdata['comment_author_IP'] ) ? $commentdata['comment_author_IP'] : '',
			'comment_agent'        => isset( $commentdata['comment_agent'] ) ? $commentdata['comment_agent'] : '',
			'comment_content'      => isset( $commentdata['comment_content'] ) ? $commentdata['comment_content'] : '',
		);

		// Combine all fields into one string for faster checking.
		// Use a delimiter unlikely to appear in content.
		$combined_content = implode( "\n\x00\n", array_filter( $comment_fields ) );

		if ( empty( $combined_content ) ) {
			return $approved;
		}

		// Check each term against combined content (single pass per term).
		foreach ( $blocklist_terms as $term ) {
			// Use case-insensitive string search for simple terms (faster than regex).
			if ( false !== stripos( $combined_content, $term ) ) {
				// Found a match - now determine which field for logging.
				$matched_field = $this->find_matched_field( $term, $comment_fields );

				$this->matched_term  = $term;
				$this->matched_field = $matched_field;

				// Default action is 'spam'.
				$action = 'spam';

				/**
				 * Filter the action taken when a blocklist match is found.
				 *
				 * @param string $action       The action: 'spam', 'trash', or a custom status.
				 * @param array  $commentdata  The comment data array.
				 * @param string $matched_term The term that matched.
				 */
				$action = apply_filters( 'wse_blocklist_action', $action, $commentdata, $term );

				return $action;
			}
		}

		return $approved;
	}

	/**
	 * Get combined blocklist with caching.
	 *
	 * @return array
	 */
	private function get_combined_blocklist() {
		$cache_key = 'wse_combined_blocklist';
		$cached    = wp_cache_get( $cache_key, 'wse' );

		if ( false !== $cached ) {
			return $cached;
		}

		// Get blocklist terms (remote + local).
		$blocklist_terms = self::get_terms_by_type( 'remote' );
		$local_terms     = self::get_terms_by_type( 'local' );
		$blocklist_terms = array_unique( array_merge( $blocklist_terms, $local_terms ) );

		// Get exclusions and remove them from blocklist.
		$exclusions      = self::get_terms_by_type( 'exclude' );
		$blocklist_terms = array_diff( $blocklist_terms, $exclusions );

		// Filter out empty terms and comments (lines starting with #).
		$blocklist_terms = array_filter(
			$blocklist_terms,
			function ( $term ) {
				$term = trim( $term );
				return ! empty( $term ) && '#' !== substr( $term, 0, 1 );
			}
		);

		// Re-index array.
		$blocklist_terms = array_values( $blocklist_terms );

		// Cache results (persistent if object cache available, otherwise per-request only).
		wp_cache_set( $cache_key, $blocklist_terms, 'wse' );

		return $blocklist_terms;
	}

	/**
	 * Find which field the term matched in.
	 *
	 * @param string $term   The matched term.
	 * @param array  $fields The comment fields.
	 * @return string
	 */
	private function find_matched_field( $term, $fields ) {
		$field_labels = array(
			'comment_author'       => __( 'author name', 'we-spam-econo' ),
			'comment_author_email' => __( 'author email', 'we-spam-econo' ),
			'comment_author_url'   => __( 'author URL', 'we-spam-econo' ),
			'comment_author_IP'    => __( 'author IP', 'we-spam-econo' ),
			'comment_agent'        => __( 'user agent', 'we-spam-econo' ),
			'comment_content'      => __( 'comment content', 'we-spam-econo' ),
		);

		foreach ( $fields as $field_key => $field_value ) {
			if ( ! empty( $field_value ) && false !== stripos( $field_value, $term ) ) {
				return isset( $field_labels[ $field_key ] ) ? $field_labels[ $field_key ] : $field_key;
			}
		}

		return __( 'comment', 'we-spam-econo' );
	}

	/**
	 * Save spam meta after comment is inserted.
	 *
	 * @param int        $comment_id The comment ID.
	 * @param WP_Comment $comment    The comment object.
	 * @return void
	 */
	public function save_spam_meta( $comment_id, $comment ) {
		// Only add meta if we flagged this comment.
		if ( null === $this->matched_term ) {
			return;
		}

		// Only for spam comments.
		if ( 'spam' !== $comment->comment_approved ) {
			return;
		}

		// Add comment meta with the match details.
		add_comment_meta( $comment_id, 'wse_flagged', true );
		add_comment_meta( $comment_id, 'wse_matched_term', $this->matched_term );
		add_comment_meta( $comment_id, 'wse_matched_field', $this->matched_field );
		add_comment_meta( $comment_id, 'wse_flagged_time', current_time( 'mysql' ) );

		// Reset for next comment.
		$this->matched_term  = null;
		$this->matched_field = null;
	}

	/**
	 * Add We Spam Econo info to comment row in admin.
	 *
	 * @param array      $actions The existing actions.
	 * @param WP_Comment $comment The comment object.
	 * @return array
	 */
	public function add_comment_row_info( $actions, $comment ) {
		// Check if this comment was flagged by us.
		$flagged = get_comment_meta( $comment->comment_ID, 'wse_flagged', true );

		if ( ! $flagged ) {
			return $actions;
		}

		$matched_term  = get_comment_meta( $comment->comment_ID, 'wse_matched_term', true );
		$matched_field = get_comment_meta( $comment->comment_ID, 'wse_matched_field', true );

		if ( $matched_term ) {
			// Add info at the beginning of actions.
			$info = sprintf(
				'<span class="wse-flagged" style="color: #d63638;">%s</span>',
				sprintf(
					/* translators: %1$s: matched term, %2$s: field name */
					esc_html__( 'Flagged by We Spam Econo: "%1$s" found in %2$s', 'we-spam-econo' ),
					esc_html( $matched_term ),
					esc_html( $matched_field )
				)
			);

			$actions = array( 'wse_info' => $info ) + $actions;
		}

		return $actions;
	}

	/**
	 * Manual update via button press.
	 *
	 * @return void
	 */
	public function update_blocklist_manual() {
		if ( ! isset( $_REQUEST['wse-update'] ) || 'manual' !== $_REQUEST['wse-update'] ) {
			return;
		}

		// Verify user has permission.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Verify nonce for CSRF protection.
		if ( ! isset( $_REQUEST['wse_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['wse_nonce'] ) ), 'wse_manual_update' ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'we-spam-econo' ) );
		}

		self::blocklist_process_loader();

		$redirect = add_query_arg( array( 'wse-update' => 'success' ), admin_url( 'options-discussion.php' ) );
		wp_safe_redirect( $redirect );
		exit();
	}

	/**
	 * Display manual update success notice.
	 *
	 * @return void
	 */
	public function manual_update_notice() {
		if ( ! isset( $_GET['wse-update'] ) || 'success' !== sanitize_text_field( wp_unslash( $_GET['wse-update'] ) ) ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible">';
		echo '<p><strong>' . esc_html__( 'Blocklist terms were updated successfully.', 'we-spam-econo' ) . '</strong></p>';
		echo '</div>';
	}

	/**
	 * Add custom query args to removable list.
	 *
	 * @param array $args Existing removable args.
	 * @return array
	 */
	public function add_removable_args( $args ) {
		$set_removable_args = apply_filters( 'wse_removable_args', array( 'wse-update' ) );
		return wp_parse_args( $set_removable_args, $args );
	}

	/**
	 * Main update process: fetch remote data and save to custom table.
	 *
	 * @return void
	 */
	public static function blocklist_process_loader() {
		// Fetch remote blocklist data.
		$data = self::fetch_blocklist_data();

		if ( ! $data || empty( $data ) ) {
			return;
		}

		// Get exclusions from custom table.
		$exclusions = self::get_terms_by_type( 'exclude' );

		// Filter out exclusions.
		if ( ! empty( $exclusions ) ) {
			$data = array_diff( $data, $exclusions );
		}

		// Save remote terms to custom table.
		self::save_terms( $data, 'remote' );
	}

	/**
	 * Fetch data from all blocklist sources.
	 *
	 * @return array|null
	 */
	public static function fetch_blocklist_data() {
		$sources = self::blocklist_sources();

		if ( ! $sources || empty( $sources ) ) {
			return null;
		}

		$data = '';

		foreach ( $sources as $source ) {
			$data .= self::parse_data_source( esc_url( $source ) ) . "\n";
		}

		if ( ! $data ) {
			return null;
		}

		return self::datalist_clean( $data );
	}

	/**
	 * Get blocklist source URLs.
	 *
	 * @return array
	 */
	public static function blocklist_sources() {
		$lists = array(
			'https://raw.githubusercontent.com/splorp/wordpress-comment-blocklist/master/blacklist.txt',
		);

		return apply_filters( 'wse_sources', (array) $lists );
	}

	/**
	 * Fetch and parse a data source.
	 *
	 * @param string $source The URL to fetch.
	 * @return string
	 */
	public static function parse_data_source( $source ) {
		if ( ! $source ) {
			return '';
		}

		$response = wp_remote_get( $source );

		if ( is_wp_error( $response ) ) {
			// Log the error for debugging.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( 'We Spam Econo: Failed to fetch %s - %s', $source, $response->get_error_message() ) );
			}
			return '';
		}

		// Verify we got a successful HTTP response.
		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( 'We Spam Econo: Failed to fetch %s - HTTP %d', $source, $response_code ) );
			}
			return '';
		}

		$result = wp_remote_retrieve_body( $response );

		if ( empty( $result ) ) {
			return '';
		}

		$result = apply_filters( 'wse_parse_data_result', $result, $source );

		if ( $result && is_array( $result ) ) {
			$result = implode( "\n", $result );
		}

		if ( ! $result ) {
			return '';
		}

		return trim( $result );
	}

	/**
	 * Clean and split text into array of terms.
	 *
	 * @param string $text Raw text data.
	 * @return array
	 */
	public static function datalist_clean( $text ) {
		$data = preg_replace( '/\n$/', '', preg_replace( '/^\n/', '', preg_replace( '/[\r\n]+/', "\n", $text ) ) );
		return explode( "\n", $data );
	}
}

// Instantiate the plugin.
$wse_core = WSE_Core::get_instance();

// Include debug helpers if in admin.
if ( is_admin() ) {
	require_once WSE_DIR . 'debug-helpers.php';
}
