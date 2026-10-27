<?php
/**
 * Removes the plugin's data when it is deleted from the Plugins screen.
 *
 * @package AIFAQ
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'aifaq_passages', 'aifaq_calls', 'aifaq_questions' ) as $aifaq_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own tables.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $aifaq_table ) );
}

foreach ( array( 'aifaq_settings', 'aifaq_db_version', 'aifaq_index_queue' ) as $aifaq_option ) {
	delete_option( $aifaq_option );
}
delete_post_meta_by_key( '_aifaq_indexed_hash' );

foreach ( array( 'aifaq_index_post', 'aifaq_index_batch', 'aifaq_prune_questions' ) as $aifaq_hook ) {
	wp_unschedule_hook( $aifaq_hook );
}
