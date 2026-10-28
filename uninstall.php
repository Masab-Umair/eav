<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 * Data is only removed if "Delete data on uninstall" was enabled in settings.
 *
 * @package ElementorApplicationVetting
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$eav_options = get_option( 'eav_settings', get_option( 'mav_settings', false ) );

if ( is_array( $eav_options ) && ! empty( $eav_options['delete_on_uninstall'] ) ) {
	$eav_ids = get_posts(
		array(
			'post_type'   => array( 'eav_application', 'mav_application' ),
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);
	foreach ( $eav_ids as $eav_id ) {
		wp_delete_post( $eav_id, true );
	}
	delete_option( 'eav_settings' );
	delete_option( 'mav_settings' );
	delete_option( 'eav_legacy_migration_complete' );
}
