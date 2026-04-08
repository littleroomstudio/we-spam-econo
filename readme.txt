=== We Spam Econo ===
Contributors: boogah, norcross, grantsplorp
Tags: comments, spam, blacklist
Website Link: https://github.com/littleroomstudio/we-spam-econo
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 2.0.4
Requires PHP: 8.3
License: MIT
License URI: https://opensource.org/licenses/MIT

Block comment spam using a continuously-updated blocklist of 64,000+ known spam terms. We jam econo.

== Description ==

We Spam Econo blocks comment spam by checking every incoming comment against a blocklist of 64,000+ known spam terms. The blocklist is automatically updated daily from a trusted remote source, keeping your site protected from the latest spam patterns.

You can supplement the automatic blocklist with your own terms, or exclude specific terms that are incorrectly flagged. The plugin stores all terms in a custom database table for lightning-fast lookups without slowing down your site.

**Performance Benefits:**

* Zero autoload overhead - terms only loaded when checking comments
* Custom indexed database table for efficient queries
* Removes ~800KB from wp_options on activation
* Separate storage for remote, local, and excluded terms

The default list of terms is fetched from a [GitHub](https://github.com/splorp/wordpress-comment-blocklist/ "Comment Blocklist for WordPress") repository maintained by [Grant Hutchinson](https://splorp.com/ "Interface considerations. Gadget accumulation. Typography. Scotch.").

This is a fork of [Comment Blacklist Manager](https://github.com/norcross/comment-blacklist-manager) by Andrew Norcross.

== Installation ==

**To install the plugin using the WordPress dashboard:**

1. Go to the "Plugins > Add New" page
2. Search for "We Spam Econo"
3. Click the "Install Now" button
4. Activate the plugin on the "Plugins" page
5. (Optional) Add terms to the "Local Blocklist" field in "Settings > Discussion"
6. (Optional) Add terms to the "Excluded Terms" field in "Settings > Discussion"

**To install the plugin manually:**

1. Download the plugin and decompress the archive
2. Upload the `we-spam-econo` folder to the `/wp-content/plugins/` directory on the server
3. Activate the plugin on the "Plugins" page
4. (Optional) Add terms to the "Local Blocklist" field in "Settings > Discussion"
5. (Optional) Add terms to the "Excluded Terms" field in "Settings > Discussion"

== Frequently Asked Questions ==

= What is the source for the default blocklist? =

The default blocklist is maintained by [Grant Hutchinson](https://splorp.com/ "Interface considerations. Gadget accumulation. Typography. Scotch.") on [GitHub](https://github.com/splorp/wordpress-comment-blocklist/ "Comment Blocklist for WordPress").

= How often is the default blocklist updated? =

Generally, the default blocklist is updated several times per month. This includes the addition of new entries and the optimizing of existing entries. Sometimes the default blocklist can undergo multiple updates per week, depending on how much spam is being sent to public WordPress sites we use to test the plugin.

= Can I provide my own blocklist sources? =

Yes, you can. Use the filter `wse_sources` to add different source URLs.

**To replace the default source completely:**
`
add_filter( 'wse_sources', 'my_replace_blacklist_sources' );

function my_replace_blacklist_sources( $list ) {

	return array(
		'http://example.com/blacklist-1.txt',
		'http://example.com/blacklist-2.txt'
	);

}`

**To add a new source to the existing sources:**
`
add_filter( 'wse_sources', 'my_add_blacklist_source' );

function my_add_blacklist_source( $list ) {

	$list[] = 'http://example.com/blacklist-1.txt';

	return $list;

}`

The plugin expects the list of terms to be in plain text format with each entry on its own line. If the source is provided in a different format (eg: a JSON feed or serialized array), then the result must be run through the `wse_parse_data_result` filter, which parses the source as a list of terms and the source URL.

= What is the default update schedule? =

The plugin will update the list of terms from the specified sources every 24 hours.

= Can I change the update schedule? =

Yes, you can. Use the filter `wse_update_schedule` to modify the time between updates.

`add_filter( 'wse_update_schedule', 'my_custom_schedule' );

function my_custom_schedule( $time ) {

	return DAY_IN_SECONDS;

}`

The `return` data should be specified using WordPress [Transient Time Constants](https://codex.wordpress.org/Transients_API#Using_Time_Constants "Transients API: Using Time Constants").

= Can I add my own terms to the blocklist? =

Yes. Individual terms can be added to the "Local Blocklist" field in the "Settings > Discussion" area of WordPress. Each term must be entered on its own line.

= Can I exclude terms from the blocklist? =

Yes. Individual terms can be excluded from the automatically fetched blocklist by adding them to the "Excluded Terms" field in the "Settings > Discussion" area of WordPress. Each term must be entered on its own line.

= Can I customize what happens when a blocklist match is found? =

Yes. Use the `wse_blacklist_action` filter to change the action from 'spam' to something else:

`add_filter( 'wse_blacklist_action', 'my_custom_blacklist_action', 10, 3 );

function my_custom_blacklist_action( $action, $commentdata, $matched_term ) {
	// Return 'trash' to trash instead of spam
	// Return '0' to mark as pending/unapproved
	return 'trash';
}`

= What WP-CLI commands are available? =

* `wp wse debug` - Display table statistics, cron status, and health check
* `wp wse cleanup` - Remove duplicate entries from the blocklist table
* `wp wse optimize` - Remove duplicates and reclaim unused table space (runs weekly automatically)
* `wp wse schedule` - Schedule the cron event for automatic updates
* `wp wse update` - Run blocklist update immediately
* `wp wse flush` - Clear the blocklist cache

== Screenshots ==

1. The "Discussion Settings" screen showing the various blocklist fields

== Changelog ==

= 2.0.4 =
* Added direct file access protection
* Removed deprecated load_plugin_textdomain() call
* Removed unused Domain Path header
* Added Git Updater support

= 2.0.3 =
* Added weekly automatic table optimization to reclaim unused space
* New WP-CLI command: `wp wse optimize` for manual table optimization
* New filter `wse_optimize_schedule` to customize optimization frequency (default: weekly)

= 2.0.2 =
* Security: Added CSRF protection to manual update action
* Security: Enabled SSL certificate verification for remote fetches
* Security: Added HTTP status code validation when fetching blocklist
* Added error logging for failed remote fetches (when WP_DEBUG is enabled)
* Standardized "blocklist" terminology throughout documentation

= 2.0.1 =
* Optimized database schema: removed unused timestamp columns, reduced table size
* Fixed duplicate terms being inserted into the database
* Updated default blocklist source URL
* Improved plugin and readme descriptions

= 2.0.0 =
* Forked and maintained by Jason Cosper (boogah)
* Renamed to "We Spam Econo"
* Major performance improvement: moved all data storage to custom database table
* Eliminated 800KB+ autoload overhead from wp_options
* Added direct comment checking via pre_comment_approved filter
* Added blacklist statistics display on Discussion settings page
* Added WP-CLI commands: `wp wse debug` and `wp wse cleanup`
* Added new filter `wse_blacklist_action` to customize spam handling
* Added new filter `wse_delete_blacklist_keys` to control legacy option cleanup
* Data is preserved on deactivation, fully removed on uninstall

= 1.0.1 — 23-Mar-2020 =
* Fixed admin notice to properly clear when a manual update is run
* Minor code cleanup

= 1.0.0 =
* Initial release
