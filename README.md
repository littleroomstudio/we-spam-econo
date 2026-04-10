# We Spam Econo

Block comment spam using a continuously-updated blocklist of 64,000+ known spam terms. We jam econo.

This is a fork of [Comment Blacklist Manager](https://github.com/norcross/comment-blacklist-manager) by Andrew Norcross. Version 2.0.x stores blocklist terms in a custom database table instead of `wp_options`, making spam checks dramatically faster.

## Performance

Benchmarking We Spam Econo against WordPress core's native spam filtering using the [Comment Blocklist for WordPress](https://github.com/splorp/wordpress-comment-blocklist) (~64,000 terms):

| Metric | We Spam Econo | WordPress Core |
|--------|---------------|----------------|
| Average time | 1.306 ms | 108.528 ms |
| Throughput | 766 checks/sec | 9 checks/sec |
| Spam detection | 100% | 100% |
| Storage | Custom table | wp_options (841 KB autoloaded) |

We Spam Econo is ~83x faster than WordPress core's spam filtering when using a 64,000+ term blocklist. The core function (`wp_check_comment_disallowed_list()`) iterates through all terms and runs regex matches against every comment field, which becomes extremely slow with large blocklists. We Spam Econo uses direct SQL matching for exact term lookups instead.

## Why This Exists

The original plugin stored all blocklist terms in `wp_options`. With 64,000+ terms, that's 800KB+ of data autoloaded on every single page request—front-end and admin. That's a lot of overhead for something that only matters when a comment is submitted.

We Spam Econo moves everything to a custom database table. Terms are only loaded when checking comments. Zero autoload overhead.

## Installation

### From GitHub

1. Download the [latest release](https://github.com/littleroomstudio/we-spam-econo/releases)
2. Upload the `we-spam-econo` folder to `/wp-content/plugins/`
3. Activate the plugin
4. (Optional) Add your own terms to "Local Blocklist" in Settings → Discussion
5. (Optional) Add exclusions to "Excluded Terms" in Settings → Discussion

### From WordPress.org

1. Go to Plugins → Add New
2. Search for "We Spam Econo"
3. Click Install Now, then Activate

## How It Works

1. The plugin fetches 64,000+ spam terms from [Grant Hutchinson's blocklist](https://github.com/splorp/wordpress-comment-blocklist/) daily
2. Terms are stored in a custom database table (`wp_wse_blocklist`)
3. When a comment is submitted, it's checked against the blocklist via SQL query
4. Matched comments are marked as spam (customizable via filter)

You can add your own terms, exclude terms that cause false positives, or use completely different blocklist sources.

## Configuration

### Adding Your Own Terms

Go to Settings → Discussion and add terms to the "Local Blocklist" field. One term per line.

### Excluding Terms

If a term in the remote blocklist is flagging legitimate comments, add it to the "Excluded Terms" field in Settings → Discussion.

### Custom Blocklist Sources

Replace or add to the default blocklist source:

```php
// Replace the default source completely
add_filter( 'wse_sources', function( $sources ) {
    return array( 'https://example.com/my-blocklist.txt' );
});

// Add an additional source
add_filter( 'wse_sources', function( $sources ) {
    $sources[] = 'https://example.com/my-blocklist.txt';
    return $sources;
});
```

### Custom Spam Handling

By default, matched comments are marked as spam. Change the behavior with:

```php
add_filter( 'wse_blacklist_action', function( $action, $commentdata, $matched_term ) {
    // 'spam' - mark as spam (default)
    // 'trash' - move to trash
    // '0' - mark as pending/unapproved
    return 'trash';
}, 10, 3 );
```

### Update Schedule

The blocklist updates every 24 hours. Customize with:

```php
add_filter( 'wse_update_schedule', function( $seconds ) {
    return WEEK_IN_SECONDS; // Update weekly instead
});
```

## WP-CLI Commands

```bash
wp wse debug      # Show table stats, cron status, health check
wp wse cleanup    # Remove duplicate entries
wp wse optimize   # Remove duplicates and reclaim table space
wp wse schedule   # Schedule the cron event for automatic updates
wp wse update     # Run blocklist update immediately
wp wse flush      # Clear the blocklist cache
```

## Requirements

- WordPress 6.0+
- PHP 8.3+

## Credits

- Original plugin by [Andrew Norcross](https://github.com/norcross)
- Default blocklist maintained by [Grant Hutchinson](https://github.com/splorp)
- Forked and maintained by [Jason Cosper](https://github.com/boogah)

## License

MIT. See [LICENSE](LICENSE) for details.
