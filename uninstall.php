<?php
/**
 * Uninstall routine for MCP Abilities.
 *
 * Data removal is opt-in. The plugin keeps rollback copies (wp-content/wpmcp-backups),
 * audit history and any skills an agent wrote, because those are recovery material.
 * A site owner who really wants a clean removal defines this in wp-config.php before
 * deleting the plugin:
 *
 *     define( 'WPMCP_REMOVE_DATA', true );
 *
 * Even then, only options created by this plugin are deleted. Files on disk are left
 * alone: backups and user skills are never removed by the uninstaller.
 *
 * @package MCP Abilities
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'WPMCP_REMOVE_DATA' ) || ! WPMCP_REMOVE_DATA ) {
	return;
}

/**
 * Options owned by the plugin.
 *
 * @return string[]
 */
function wpmcp_uninstall_option_names() {
	return array(
		'wpmcp_audit_log',
		'wpmcp_pagespeed_api_key',
		'wpmcp_skills_migrated',
		'wpmcp_skill_feeds_state',
	);
}

if ( is_multisite() ) {
	$wpmcp_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $wpmcp_site_ids as $wpmcp_site_id ) {
		switch_to_blog( (int) $wpmcp_site_id );
		foreach ( wpmcp_uninstall_option_names() as $wpmcp_option ) {
			delete_option( $wpmcp_option );
		}
		restore_current_blog();
	}
} else {
	foreach ( wpmcp_uninstall_option_names() as $wpmcp_option ) {
		delete_option( $wpmcp_option );
	}
}
