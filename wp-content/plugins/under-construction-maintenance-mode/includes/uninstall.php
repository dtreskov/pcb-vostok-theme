<?php

/**
 * Uninstall Under Construction Maintenance Mode
 *
 * @author WPBrigade
 * @since 2.1.0
 */

$ucmm_settings = get_option( 'ucmm_wpbrigade_setting' );
if ( ! isset( $ucmm_settings['ucmm-uninstall'] ) || 'on' !== $ucmm_settings['ucmm-uninstall'] ) {
	return;
}

// Array of Plugin's Option.
$ucmm_unintstall_option = array(
	'ucmm_wpbrigade_setting',
	'ucmm_wpbrigade_customization',
);

if ( ! is_multisite() ) {
	// Delete all plugin Options.
	foreach ( $ucmm_unintstall_option as $option ) {
		if ( get_option( $option ) ) {
			delete_option( $option );
		}
	}
} else {

	global $wpdb;
	$ucmm_blog_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );

	foreach ( $ucmm_blog_ids as $blog_id ) {

		switch_to_blog( $blog_id );


		// Delete all plugin Options.
		foreach ( $ucmm_unintstall_option as $option ) {
			if ( get_option( $option ) ) {
				delete_option( $option );
			}
		}

		restore_current_blog();
	}
}
