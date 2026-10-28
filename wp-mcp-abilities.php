<?php
/**
 * Plugin Name:       WP MCP Abilities
 * Plugin URI:        https://github.com/hidden0705/wp-mcp-abilities
 * Description:       Administrator-level MCP abilities for WordPress: WP-CLI, REST, files with rollback, Elementor builder, site health, and 46 skill playbooks.
 * Version:           2.6.0
 * Requires at least: 6.9
 * Requires PHP:      8.0
 * Requires Plugins:  mcp-adapter
 * Author:            hidden0705
 * Author URI:        https://github.com/hidden0705
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-mcp-abilities
 * Domain Path:       /languages
 * Update URI:        false
 *
 * @package WP MCP Abilities
 * @copyright Copyright (c) 2026 hidden0705
 * @license   GPL-2.0-or-later
 *
 * The mcp-adapter plugin (wordpress.org/plugins/mcp-adapter) exposes these abilities
 * over MCP at /wp-json/mcp/mcp-adapter-default-server (or the ?rest_route= form on
 * plain permalinks). Authentication is handled by that server's transport layer
 * (Application Password Basic auth); this plugin only registers abilities.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WPMCP_VERSION = '2.6.0';

/**
 * The abilities this plugin registers, in tool-list order.
 *
 * agent-context comes first: it is the one call that tells a new agent what the
 * site is, what it may do, and which playbook to load before touching anything.
 *
 * @return string[]
 */
function wpmcp_ability_names() {
	return array(
		'wpmcp/agent-context',
		'wpmcp/rest-request',
		'wpmcp/wpcli',
		'wpmcp/files',
		'wpmcp/elementor',
		'wpmcp/fetch-page',
		'wpmcp/media-import',
		'wpmcp/audit-url',
		'wpmcp/list-skills',
		'wpmcp/get-skill',
		'wpmcp/search-skills',
		'wpmcp/skill-write',
		'wpmcp/site-health',
		'wpmcp/restore',
	);
}

/** Directory containing the bundled skill markdown files (shipped, read-only). */
function wpmcp_skills_dir() {
	return plugin_dir_path( __FILE__ ) . 'skills/';
}

/**
 * Directory for playbooks the agent writes on this site.
 *
 * Kept out of the plugin folder on purpose: a plugin update replaces the folder,
 * which used to delete every playbook an agent had learned. Files here shadow a
 * bundled playbook with the same slug, so an agent can override a shipped
 * playbook without losing the original.
 *
 * @param bool $create Create and protect the directory when it is missing.
 * @return string Absolute path with a trailing slash.
 */
function wpmcp_user_skills_dir( $create = true ) {
	$dir = WP_CONTENT_DIR . '/wpmcp-skills';
	if ( $create && ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	if ( ! is_dir( $dir ) ) {
		return $dir . '/';
	}
	if ( $create ) {
		// Markdown is not executable, but the library can describe a site's
		// internals; keep it out of directory listings and crawlers.
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n" );
		}
		if ( ! file_exists( $dir . '/web.config' ) ) {
			@file_put_contents( $dir . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<security>\n\t\t\t<requestFiltering>\n\t\t\t\t<hiddenSegments>\n\t\t\t\t\t<add segment=\"wpmcp-skills\" />\n\t\t\t\t</hiddenSegments>\n\t\t\t</requestFiltering>\n\t\t</security>\n\t</system.webServer>\n</configuration>\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
	}
	return $dir . '/';
}

/**
 * Checksums of the playbooks shipped in this release.
 *
 * Used to tell a shipped file from one an agent edited in the plugin folder by
 * older versions, so the edited copy can be moved to the site library before an
 * update overwrites it.
 *
 * @return array<string,string> slug => sha256
 */
function wpmcp_skills_manifest() {
	static $manifest = null;
	if ( null !== $manifest ) {
		return $manifest;
	}
	$manifest = array();
	$file     = wpmcp_skills_dir() . 'manifest.json';
	if ( is_file( $file ) ) {
		$decoded = json_decode( (string) file_get_contents( $file ), true );
		if ( is_array( $decoded ) ) {
			$manifest = $decoded;
		}
	}
	return $manifest;
}

/**
 * Move playbooks written into the plugin folder by older versions to the site
 * library, so a plugin update cannot delete them. Runs once per plugin version.
 */
function wpmcp_maybe_migrate_skills() {
	if ( WPMCP_VERSION === (string) get_option( 'wpmcp_skills_migrated' ) ) {
		return;
	}
	$manifest = wpmcp_skills_manifest();
	$user_dir = wpmcp_user_skills_dir();
	foreach ( (array) glob( wpmcp_skills_dir() . '*.md' ) as $file ) {
		$slug = basename( $file, '.md' );
		$hash = (string) hash_file( 'sha256', $file );
		$known = isset( $manifest[ $slug ] ) ? (string) $manifest[ $slug ] : '';
		if ( '' !== $known && hash_equals( $known, $hash ) ) {
			continue; // Untouched shipped playbook.
		}
		$target = $user_dir . $slug . '.md';
		if ( file_exists( $target ) ) {
			continue; // The site library already wins for this slug.
		}
		@copy( $file, $target );
	}
	update_option( 'wpmcp_skills_migrated', WPMCP_VERSION, false );
}
add_action( 'init', 'wpmcp_maybe_migrate_skills' );

/** Translations live in languages/; GitHub releases are not served by WordPress.org. */
add_action( 'init', function () {
	load_plugin_textdomain( 'wp-mcp-abilities', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

/**
 * Cheap local page audit, used when PageSpeed is unavailable.
 *
 * Not Lighthouse: it fetches the HTML once and reports the numbers that most
 * often explain a slow WordPress page (payload size, server response time,
 * asset counts, missing alt text). Zero external API, zero quota.
 *
 * @param string $url Absolute http(s) URL.
 * @return array{stats?:array<string,mixed>,findings?:string[],error?:string}
 */
function wpmcp_local_audit( $url ) {
	$start    = microtime( true );
	$response = wp_remote_get(
		$url,
		array(
			'timeout'            => 25,
			'redirection'        => 5,
			'user-agent'         => 'Mozilla/5.0 (wp-mcp audit)',
			'reject_unsafe_urls' => ! wpmcp_url_allows_private( $url ),
		)
	);
	$elapsed  = (int) round( ( microtime( true ) - $start ) * 1000 );

	if ( is_wp_error( $response ) ) {
		return array( 'error' => 'Local fetch failed: ' . $response->get_error_message() );
	}

	$code  = (int) wp_remote_retrieve_response_code( $response );
	$html  = (string) wp_remote_retrieve_body( $response );
	$bytes = strlen( $html );
	$count = function ( $pattern ) use ( $html ) {
		return (int) preg_match_all( $pattern, $html );
	};

	$stats = array(
		'http_status'        => $code,
		'html_bytes'         => $bytes,
		'ttfb_ms'            => $elapsed,
		'external_scripts'   => $count( '/<script\b[^>]*\bsrc=/i' ),
		'inline_scripts'     => $count( '/<script\b(?![^>]*\bsrc=)/i' ),
		'stylesheets'        => $count( '/<link\b[^>]*rel=["\']stylesheet/i' ),
		'inline_styles'      => $count( '/<style\b/i' ),
		'images'             => $count( '/<img\b/i' ),
		'images_missing_alt' => $count( '/<img\b(?![^>]*\balt=)[^>]*>/i' ),
		'iframes'            => $count( '/<iframe\b/i' ),
		'generator'          => preg_match( '/<meta[^>]+name=["\']generator["\'][^>]+content=["\']([^"\']+)/i', $html, $m ) ? $m[1] : '',
	);

	$findings = array();
	if ( $bytes > 150000 ) {
		$findings[] = 'HTML payload is ' . round( $bytes / 1024 ) . ' KB — cut inline CSS/JS and builder wrappers.';
	}
	if ( $elapsed > 600 ) {
		$findings[] = 'Server responded in ' . $elapsed . ' ms — check full-page cache (LiteSpeed) and slow queries.';
	}
	if ( $stats['stylesheets'] > 12 ) {
		$findings[] = $stats['stylesheets'] . ' stylesheets — dequeue/combine what the page does not render.';
	}
	if ( $stats['external_scripts'] > 15 ) {
		$findings[] = $stats['external_scripts'] . ' external scripts — defer or remove plugin assets the page never uses.';
	}
	if ( $stats['images_missing_alt'] > 0 ) {
		$findings[] = $stats['images_missing_alt'] . ' image(s) without alt text — accessibility fix.';
	}

	return array( 'stats' => $stats, 'findings' => $findings );
}

/**
 * Google PageSpeed Insights API key, if the site has one.
 *
 * Without a key Google applies a shared anonymous IP quota, which on busy hosts
 * is usually already exhausted (HTTP 429). A free key raises the quota to 25k
 * requests/day. First non-empty wins:
 *   1. define( 'WPMCP_PAGESPEED_API_KEY', '...' ) in wp-config.php
 *   2. option wpmcp_pagespeed_api_key
 *   3. filter wpmcp_pagespeed_api_key
 *
 * @return string
 */
function wpmcp_pagespeed_key() {
	$key = '';
	if ( defined( 'WPMCP_PAGESPEED_API_KEY' ) ) {
		$key = (string) WPMCP_PAGESPEED_API_KEY;
	}
	if ( '' === $key && function_exists( 'get_option' ) ) {
		$key = (string) get_option( 'wpmcp_pagespeed_api_key', '' );
	}
	if ( function_exists( 'apply_filters' ) ) {
		$key = (string) apply_filters( 'wpmcp_pagespeed_api_key', $key );
	}
	return trim( $key );
}

/**
 * Whether the WP-CLI ability is available on this site.
 *
 * WP-CLI is shell-level administrator access: `wp eval` is PHP execution, so a
 * leaked application password becomes remote code execution. It is opt-in for
 * that reason. Enable it on sites where an agent is expected to do real work:
 *
 *   define( 'WPMCP_ENABLE_WPCLI', true );          // wp-config.php
 *   add_filter( 'wpmcp_enable_wpcli', '__return_true' );
 *
 * When it is off the ability is not registered at all, so it does not appear in
 * tools/list and an agent cannot call it by accident.
 *
 * @return bool
 */
function wpmcp_wpcli_enabled() {
	$enabled = defined( 'WPMCP_ENABLE_WPCLI' ) ? (bool) WPMCP_ENABLE_WPCLI : false;
	return (bool) apply_filters( 'wpmcp_enable_wpcli', $enabled );
}

/**
 * Refuse URLs that point at the server's own private network.
 *
 * fetch-page, media-import and audit-url take a URL from the agent. Without a
 * guard an agent (or a prompt injection) can read cloud metadata at
 * 169.254.169.254, hit an internal admin panel on 127.0.0.1, or port-scan the
 * private network from inside the web server. Redirects are covered by
 * wp_remote_get( [ 'reject_unsafe_urls' => true ] ), which re-validates every
 * hop; the check here produces a clear message before any request is made.
 *
 * The site's own host is always allowed: many hosts resolve their own domain to
 * a private address behind a proxy, and fetching your own pages is the main use
 * case. Local development can opt out entirely:
 *
 *   add_filter( 'wpmcp_allow_private_urls', '__return_true' );
 *
 * @param string $url Absolute http(s) URL.
 * @return true|WP_Error
 */
function wpmcp_check_url( $url ) {
	$url = trim( (string) $url );
	if ( ! preg_match( '#^https?://#i', $url ) ) {
		return new WP_Error( 'wpmcp_url_scheme', 'Only http(s) URLs are allowed.' );
	}
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return new WP_Error( 'wpmcp_url_host', 'That URL has no host.' );
	}
	if ( wpmcp_url_allows_private( $url ) ) {
		return true;
	}

	$host = strtolower( trim( (string) $parts['host'], '[]' ) );
	$bare = ( false === strpos( $host, '.' ) && ! filter_var( $host, FILTER_VALIDATE_IP ) );
	if ( 'localhost' === $host || '.local' === substr( $host, -6 ) || '.internal' === substr( $host, -9 ) || '.localhost' === substr( $host, -10 ) || $bare ) {
		return new WP_Error( 'wpmcp_url_private_host', 'Refusing to fetch a private hostname (' . $host . '). Fetching your own site and public URLs works; internal URLs need the wpmcp_allow_private_urls filter.' );
	}

	$ips = array();
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		$ips[] = $host;
	} else {
		$v4 = gethostbynamel( $host );
		if ( is_array( $v4 ) ) {
			$ips = $v4;
		}
		$v6 = @dns_get_record( $host, DNS_AAAA );
		if ( is_array( $v6 ) ) {
			foreach ( $v6 as $record ) {
				if ( ! empty( $record['ipv6'] ) ) {
					$ips[] = (string) $record['ipv6'];
				}
			}
		}
	}
	if ( empty( $ips ) ) {
		return new WP_Error( 'wpmcp_url_dns', 'Could not resolve ' . $host . '.' );
	}
	foreach ( $ips as $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return new WP_Error( 'wpmcp_url_private_ip', 'Refusing to fetch ' . $host . ' because it resolves to a private or reserved address (' . $ip . '). Internal URLs need the wpmcp_allow_private_urls filter.' );
		}
	}
	return true;
}

/**
 * True when private addresses are deliberately allowed for this URL.
 *
 * @param string $url Absolute http(s) URL.
 * @return bool
 */
function wpmcp_url_allows_private( $url ) {
	if ( (bool) apply_filters( 'wpmcp_allow_private_urls', false, $url ) ) {
		return true;
	}
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	if ( '' === $host ) {
		return false;
	}
	foreach ( array( home_url( '/' ), site_url( '/' ) ) as $own ) {
		if ( strtolower( (string) wp_parse_url( $own, PHP_URL_HOST ) ) === $host ) {
			return true;
		}
	}
	return false;
}

/**
 * Parse one skill file.
 *
 * Supports optional front matter:
 *   ---
 *   title: ...
 *   category: ...
 *   summary: ...
 *   ---
 * Falls back to the first "# Heading" for the title and the first non-heading
 * line for the summary, so plain markdown files work too.
 *
 * @param string $file Absolute path.
 * @return array{slug:string,title:string,category:string,summary:string,bytes:int,body:string}
 */
function wpmcp_parse_skill( $file ) {
	$raw  = (string) file_get_contents( $file );
	$slug = basename( $file, '.md' );
	$body = $raw;
	$meta = array(
		'title'    => '',
		'category' => '',
		'summary'  => '',
	);

	if ( 0 === strpos( $raw, '---' ) ) {
		$end = strpos( $raw, "\n---", 3 );
		if ( false !== $end ) {
			$head = substr( $raw, 3, $end - 3 );
			foreach ( preg_split( '/\R/', $head ) as $line ) {
				if ( preg_match( '/^\s*([A-Za-z_]+)\s*:\s*(.+?)\s*$/', $line, $m ) ) {
					$meta[ strtolower( $m[1] ) ] = trim( $m[2], " \t\"'" );
				}
			}
			$body = ltrim( (string) substr( $raw, $end + 4 ) );
		}
	}

	$title   = $meta['title'];
	$summary = $meta['summary'];

	if ( '' === $title && preg_match( '/^#\s+(.+)$/m', $body, $m ) ) {
		$title = trim( $m[1] );
	}
	if ( '' === $title ) {
		$title = ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}
	if ( '' === $summary ) {
		foreach ( preg_split( '/\R/', $body ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line && '#' !== ( $line[0] ?? '' ) ) {
				$summary = mb_substr( $line, 0, 200 );
				break;
			}
		}
	}

	return array(
		'slug'     => $slug,
		'title'    => $title,
		'category' => '' !== $meta['category'] ? $meta['category'] : 'General',
		'summary'  => $summary,
		'bytes'    => strlen( $raw ),
		'body'     => $body,
	);
}

/**
 * Absolute path of the playbook that wins for a slug, or '' when there is none.
 *
 * A site playbook shadows a bundled one with the same slug.
 *
 * @param string $slug Skill slug.
 * @return string
 */
function wpmcp_skill_file( $slug ) {
	$slug = (string) $slug;
	if ( '' === $slug || ! preg_match( '/^[a-z0-9\-_]+$/i', $slug ) ) {
		return '';
	}
	$user = wpmcp_user_skills_dir( false ) . $slug . '.md';
	if ( is_file( $user ) ) {
		return $user;
	}
	$bundled = wpmcp_skills_dir() . $slug . '.md';
	return is_file( $bundled ) ? $bundled : '';
}

/**
 * Load every skill, keyed by slug.
 *
 * The site library (wp-content/wpmcp-skills) is read after the bundled folder,
 * so a site playbook wins when both use the same slug. Each entry carries a
 * `source` of `bundled` or `site`.
 *
 * @return array<string,array>
 */
function wpmcp_all_skills() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$cache = array();
	$dirs  = array(
		'bundled' => wpmcp_skills_dir(),
		'site'    => wpmcp_user_skills_dir( false ),
	);
	foreach ( $dirs as $source => $dir ) {
		if ( ! is_dir( $dir ) ) {
			continue;
		}
		foreach ( (array) glob( $dir . '*.md' ) as $file ) {
			$skill = wpmcp_parse_skill( $file );
			unset( $skill['body'], $skill['bytes'] );
			$skill['source'] = $source;
			$cache[ $skill['slug'] ] = $skill;
		}
	}
	ksort( $cache );
	return $cache;
}

/**
 * Words that carry no meaning for a playbook search.
 *
 * Without this list a query like "how do I fix the checkout" scores every
 * playbook that happens to contain "the" and buries the one that matters.
 *
 * @return string[]
 */
function wpmcp_skill_stopwords() {
	return array(
		'a', 'about', 'after', 'all', 'also', 'an', 'and', 'any', 'are', 'as', 'at',
		'be', 'before', 'but', 'by', 'can', 'could', 'did', 'do', 'does', 'doing',
		'for', 'from', 'get', 'gets', 'had', 'has', 'have', 'here', 'how', 'if',
		'in', 'into', 'is', 'it', 'its', 'just', 'make', 'me', 'my', 'need', 'needs',
		'not', 'of', 'on', 'or', 'our', 'should', 'so', 'some', 'than', 'that',
		'the', 'their', 'them', 'then', 'there', 'these', 'they', 'this', 'those',
		'to', 'too', 'up', 'us', 'use', 'used', 'using', 'want', 'wants', 'was',
		'we', 'were', 'what', 'when', 'where', 'which', 'who', 'why', 'will',
		'with', 'would', 'you', 'your',
	);
}

/**
 * Search terms for a skill query.
 *
 * Splits on anything that is not a letter or digit, drops stopwords and very
 * short fragments, and keeps the order the caller used.
 *
 * @param string $query Free-text query.
 * @return string[]
 */
function wpmcp_skill_terms( $query ) {
	$words = preg_split( '/[^a-z0-9]+/', strtolower( (string) $query ) );
	$words = is_array( $words ) ? $words : array();
	$stop  = wpmcp_skill_stopwords();
	$terms = array();
	foreach ( $words as $word ) {
		if ( strlen( $word ) < 3 || in_array( $word, $stop, true ) ) {
			continue;
		}
		$terms[ $word ] = true;
	}
	if ( ! $terms ) {
		// Short but meaningful queries ("wp", "db") still deserve a search.
		foreach ( $words as $word ) {
			if ( strlen( $word ) >= 2 ) {
				$terms[ $word ] = true;
			}
		}
	}
	return array_keys( $terms );
}

/**
 * Keyword search across every playbook, with a relevance floor.
 *
 * The old implementation returned any playbook with one matching word, so
 * "seedprod coming soon page" listed Elementor playbooks scoring 36: they
 * matched "page" and nothing else. That looks like an answer to an agent that
 * cannot see this code. Two rules keep the results honest:
 *
 * - A term no playbook mentions at all (a plugin this library does not cover)
 *   returns no results plus a hint, instead of loosely related playbooks.
 * - A hit must match at least half of the query terms, and results are ranked
 *   by how much of the query they cover before raw score.
 *
 * @param string $query Free-text query.
 * @param int    $limit Maximum hits.
 * @return array{results:array,terms:string[],uncovered?:string[],hint?:string}
 */
function wpmcp_search_skills( $query, $limit = 8 ) {
	$limit = min( 20, max( 1, (int) $limit ) );
	$terms = wpmcp_skill_terms( $query );
	if ( ! $terms ) {
		return array(
			'results' => array(),
			'terms'   => array(),
			'hint'    => 'The query had no searchable words. Try a plugin, action or symptom, e.g. "stock status" or "elementor cache".',
		);
	}

	$docs = array();
	foreach ( array_keys( wpmcp_all_skills() ) as $slug ) {
		$file = wpmcp_skill_file( $slug );
		if ( '' !== $file ) {
			$docs[ $slug ] = wpmcp_parse_skill( $file );
		}
	}

	$df = array_fill_keys( $terms, 0 );
	foreach ( $docs as $skill ) {
		$hay = strtolower( $skill['slug'] . ' ' . $skill['title'] . ' ' . $skill['summary'] . ' ' . $skill['body'] );
		foreach ( $terms as $term ) {
			if ( false !== strpos( $hay, $term ) ) {
				++$df[ $term ];
			}
		}
	}

	// A term that appears in zero playbooks means the library does not cover
	// that plugin. Report the longest such term only: in "seedprod coming soon
	// page" the signal is "seedprod", not the ordinary words around it.
	$uncovered = array();
	foreach ( $terms as $term ) {
		if ( 0 === $df[ $term ] && strlen( $term ) >= 4 ) {
			$uncovered[] = $term;
		}
	}
	if ( $uncovered ) {
		$longest   = max( array_map( 'strlen', $uncovered ) );
		$uncovered = array_values( array_filter( $uncovered, function ( $term ) use ( $longest ) {
			return strlen( $term ) === $longest;
		} ) );
		return array(
			'results'   => array(),
			'terms'     => $terms,
			'uncovered' => $uncovered,
			'hint'      => 'No playbook mentions ' . implode( ', ', $uncovered ) . ', so this library does not cover that plugin or topic. Continue with the core abilities (wpmcp/agent-context lists them), take a backup before writing, verify the result, then save what you learned with wpmcp/skill-write so the next agent starts with it.',
		);
	}

	$need = max( 1, (int) ceil( count( $terms ) / 2 ) );

	// Weight rare terms higher: in "stock status", "stock" tells the two
	// WooCommerce playbooks apart while "status" appears almost everywhere.
	$total   = max( 1, count( $docs ) );
	$weights = array();
	foreach ( $terms as $term ) {
		$weights[ $term ] = 1 + log( ( $total + 1 ) / ( $df[ $term ] + 1 ) );
	}

	$hits = array();
	foreach ( $docs as $slug => $skill ) {
		$title   = strtolower( $skill['title'] );
		$summary = strtolower( $skill['summary'] );
		$body    = strtolower( $skill['body'] );
		$slug_l  = strtolower( $skill['slug'] );
		$score   = 0;
		$matched = 0;
		$anchor  = '';
		foreach ( $terms as $term ) {
			// The slug and title say what a playbook is about; a long body
			// mentions many things in passing, so its contribution is capped.
			$term_score = 15 * substr_count( $slug_l, $term )
				+ 10 * substr_count( $title, $term )
				+ 5 * substr_count( $summary, $term )
				+ min( 30, substr_count( $body, $term ) );
			if ( $term_score > 0 ) {
				++$matched;
				if ( '' === $anchor ) {
					$anchor = $term;
				}
			}
			$score += $weights[ $term ] * $term_score;
		}
		if ( $matched < $need ) {
			continue;
		}
		$pos     = '' === $anchor ? false : stripos( $skill['body'], $anchor );
		$snippet = false === $pos ? '' : mb_substr( $skill['body'], max( 0, $pos - 90 ), 240 );
		$hits[]  = array(
			'slug'     => $skill['slug'],
			'title'    => $skill['title'],
			'category' => $skill['category'],
			'score'    => (int) round( $score ),
			'matched'  => $matched,
			'coverage' => round( $matched / count( $terms ), 2 ),
			'snippet'  => trim( preg_replace( '/\s+/', ' ', (string) $snippet ) ),
		);
	}
	usort(
		$hits,
		function ( $a, $b ) {
			if ( $a['score'] === $b['score'] ) {
				if ( $a['coverage'] === $b['coverage'] ) {
					return strcmp( $a['slug'], $b['slug'] );
				}
				return $b['coverage'] <=> $a['coverage'];
			}
			return $b['score'] <=> $a['score'];
		}
	);

	$payload = array(
		'results' => array_slice( $hits, 0, $limit ),
		'terms'   => $terms,
	);
	if ( ! $hits ) {
		$payload['hint'] = 'No playbook covers enough of this query. Try fewer or different words, or continue with the core abilities and save what you learn with wpmcp/skill-write.';
	}
	return $payload;
}

/** Resolve a path inside WP_CONTENT_DIR; returns absolute path or WP_Error. */
function wpmcp_guard_path( $relative, $must_exist = true ) {
	$base = realpath( WP_CONTENT_DIR );
	if ( ! $base ) {
		return new WP_Error( 'wpmcp_base_missing', 'wp-content directory not found.' );
	}
	$rel = ltrim( str_replace( '\\', '/', (string) $relative ), '/' );
	if ( '' === $rel || false !== strpos( $rel, '..' ) ) {
		return new WP_Error( 'wpmcp_bad_path', 'Path must be a relative path inside wp-content.' );
	}
	$abs = $base . '/' . $rel;
	if ( $must_exist ) {
		$real = realpath( $abs );
		// Trailing slash so "wp-content-backup" cannot pass as a prefix of "wp-content".
		if ( false === $real || 0 !== strpos( $real . '/', $base . '/' ) ) {
			return new WP_Error( 'wpmcp_path_denied', 'File does not exist or resolves outside wp-content.' );
		}
		return $real;
	}
	$parent = realpath( dirname( $abs ) );
	if ( false !== $parent && 0 !== strpos( $parent . '/', $base . '/' ) ) {
		return new WP_Error( 'wpmcp_path_denied', 'Directory resolves outside wp-content.' );
	}
	return $abs;
}

/**
 * Read-only mode.
 *
 * Turn it on for a live site an agent should only inspect:
 *   define( 'WPMCP_READONLY', true );          // wp-config.php
 *   add_filter( 'wpmcp_readonly', '__return_true' );
 *
 * Enforced inside each ability at call time: mutating actions are refused with a
 * clear message, reads keep working. It is a safety rail for the agent, not a
 * security boundary — the credentials still belong to an administrator.
 *
 * @return bool
 */
function wpmcp_is_readonly() {
	$readonly = defined( 'WPMCP_READONLY' ) ? (bool) WPMCP_READONLY : false;
	return (bool) apply_filters( 'wpmcp_readonly', $readonly );
}

/** Consistent refusal for a write attempted while read-only mode is on. */
function wpmcp_readonly_error() {
	return array(
		'ok'      => false,
		'message' => 'Read-only mode is on (WPMCP_READONLY): this ability cannot change the site. Read actions still work. An administrator can remove the constant from wp-config.php.',
	);
}

/** Directory holding rollback copies written by wpmcp/files, skill-write and restore. */
function wpmcp_backup_dir() {
	$dir = WP_CONTENT_DIR . '/wpmcp-backups';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	// Backups are PHP source: keep them off the web even on servers that ignore
	// the .bak extension. Both Apache 2.2/2.4 syntaxes, plus a silence file.
	if ( is_dir( $dir ) && ! file_exists( $dir . '/.htaccess' ) ) {
		@file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n" );
	}
	if ( is_dir( $dir ) && ! file_exists( $dir . '/index.php' ) ) {
		@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
	}
	return $dir;
}

/**
 * Copy a file inside wp-content into the backup directory.
 *
 * Name carries a base64url of the relative path plus a UTC timestamp, so the
 * original location survives a rename and listing needs no index file.
 *
 * @param string $abs Absolute path inside wp-content.
 * @param string $tag Optional label recorded in the file name (e.g. "delete").
 * @return string|WP_Error Backup file name on success.
 */
function wpmcp_backup_file( $abs, $tag = 'edit' ) {
	$real = realpath( $abs );
	if ( false === $real || ! is_file( $real ) ) {
		return new WP_Error( 'wpmcp_backup_missing', 'Nothing to back up.' );
	}
	$base = realpath( WP_CONTENT_DIR );
	if ( false === $base || 0 !== strpos( $real . '/', rtrim( $base, '/' ) . '/' ) ) {
		return new WP_Error( 'wpmcp_backup_outside', 'Path is outside wp-content.' );
	}
	// Never back up a backup: deleting files from the backup directory would
	// otherwise recurse forever and fill the disk.
	$backup_real = realpath( wpmcp_backup_dir() );
	if ( false !== $backup_real && 0 === strpos( $real . '/', rtrim( $backup_real, '/' ) . '/' ) ) {
		return new WP_Error( 'wpmcp_backup_nested', 'File is already inside the backup directory.' );
	}
	$rel  = ltrim( substr( $real, strlen( $base ) ), '/' );
	$micro = substr( sprintf( '%06d', (int) min( 999999, round( ( microtime( true ) - floor( microtime( true ) ) ) * 1000000 ) ) ), 0, 6 );
	$name = rtrim( strtr( base64_encode( $rel ), '+/', '-_' ), '=' )
		. '.' . gmdate( 'Ymd-His' ) . $micro . '.' . substr( md5( $rel . microtime( true ) ), 0, 6 )
		. '.' . preg_replace( '/[^a-z0-9\-]/i', '', (string) $tag ) . '.bak';
	$dir  = wpmcp_backup_dir();
	if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
		return new WP_Error( 'wpmcp_backup_dir', 'Backup directory is not writable.' );
	}
	if ( ! @copy( $real, $dir . '/' . $name ) ) {
		return new WP_Error( 'wpmcp_backup_copy', 'Could not copy the file into the backup directory.' );
	}
	// Keep the newest 10 per source path so a busy agent cannot fill the disk.
	$prefix = rtrim( strtr( base64_encode( $rel ), '+/', '-_' ), '=' ) . '.';
	$same   = array();
	foreach ( (array) glob( $dir . '/' . $prefix . '*.bak' ) as $file ) {
		$same[] = $file;
	}
	if ( count( $same ) > 10 ) {
		sort( $same );
		foreach ( array_slice( $same, 0, count( $same ) - 10 ) as $old ) {
			@unlink( $old );
		}
	}
	return $name;
}

/**
 * Every rollback copy on disk, newest first.
 *
 * @return array<int,array{backup:string,path:string,at:string,bytes:int,tag:string}>
 */
function wpmcp_backup_list() {
	$dir = wpmcp_backup_dir();
	$out = array();
	foreach ( (array) glob( $dir . '/*.bak' ) as $file ) {
		$name  = basename( $file );
		$parts = explode( '.', $name );
		// <payload>.<Ymd-His>.<rand>.<tag>.bak
		if ( count( $parts ) < 5 ) {
			continue;
		}
		$tag     = $parts[ count( $parts ) - 2 ];
		$stamp   = $parts[ count( $parts ) - 4 ];
		$payload = $parts[0];
		$path    = base64_decode( strtr( $payload, '-_', '+/' ), true );
		if ( false === $path || '' === $path ) {
			continue;
		}
		$at = '';
		$digits = preg_replace( '/\D/', '', $stamp );
		if ( strlen( $digits ) >= 14 ) {
			$at = sprintf(
				'%s-%s-%sT%s:%s:%sZ',
				substr( $digits, 0, 4 ),
				substr( $digits, 4, 2 ),
				substr( $digits, 6, 2 ),
				substr( $digits, 8, 2 ),
				substr( $digits, 10, 2 ),
				substr( $digits, 12, 2 )
			);
		}
		$out[] = array(
			'backup' => $name,
			'path'   => $path,
			'at'     => $at,
			'bytes'  => (int) filesize( $file ),
			'tag'    => $tag,
		);
	}
	usort( $out, function ( $a, $b ) {
		$cmp = strcmp( $b['at'], $a['at'] );
		return 0 !== $cmp ? $cmp : strcmp( $b['backup'], $a['backup'] );
	} );
	return $out;
}

/** Short, redacted copy of an ability input for the audit log. */
function wpmcp_audit_summary( $value, $depth = 0 ) {
	if ( $depth > 2 ) {
		return '…';
	}
	if ( is_array( $value ) ) {
		$out = array();
		foreach ( $value as $key => $item ) {
			if ( preg_match( '/pass|token|secret|auth|credential/i', (string) $key ) ) {
				$out[ $key ] = '[redacted]';
				continue;
			}
			$out[ $key ] = wpmcp_audit_summary( $item, $depth + 1 );
		}
		return $out;
	}
	if ( is_string( $value ) ) {
		return mb_substr( $value, 0, 120 );
	}
	if ( is_scalar( $value ) || null === $value ) {
		return $value;
	}
	return '…';
}

/** Refusal payload for a destructive call that did not opt in with confirm: true. */
function wpmcp_confirm_error( $what ) {
	return array(
		'ok'      => false,
		'message' => 'Destructive action (' . $what . '). Repeat the call with "confirm": true if that is intended.',
	);
}

/**
 * Pending update counts straight from the update transients (no HTTP).
 *
 * @return array{core:int,plugins:int,themes:int,plugin_names:string[]}
 */
function wpmcp_pending_updates() {
	$out = array( 'core' => 0, 'plugins' => 0, 'themes' => 0, 'plugin_names' => array() );

	$core = get_site_transient( 'update_core' );
	if ( is_object( $core ) && ! empty( $core->updates ) ) {
		foreach ( (array) $core->updates as $update ) {
			if ( isset( $update->response ) && 'upgrade' === $update->response ) {
				++$out['core'];
			}
		}
	}

	$plugins = get_site_transient( 'update_plugins' );
	if ( is_object( $plugins ) && ! empty( $plugins->response ) ) {
		$out['plugins'] = count( (array) $plugins->response );
		foreach ( (array) $plugins->response as $file => $update ) {
			$out['plugin_names'][] = (string) ( $update->slug ?? $file );
		}
	}

	$themes = get_site_transient( 'update_themes' );
	if ( is_object( $themes ) && ! empty( $themes->response ) ) {
		$out['themes'] = count( (array) $themes->response );
	}

	return $out;
}

/** Active plugin files, as stored by WordPress. */
function wpmcp_active_plugins() {
	$active = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}
	return array_values( array_unique( array_map( 'strval', $active ) ) );
}

/** Slug-ish test used to spot a backup plugin among the active plugins. */
function wpmcp_has_backup_plugin() {
	$known = array( 'updraftplus', 'backwpup', 'duplicator', 'blogvault', 'wpvivid', 'jetpack', 'solid-backups', 'backuply', 'wp-db-backup', 'ai1wm' );
	$hay   = strtolower( implode( ' ', wpmcp_active_plugins() ) . ' ' . (string) get_option( 'template', '' ) );
	foreach ( $known as $needle ) {
		if ( false !== strpos( $hay, $needle ) ) {
			return true;
		}
	}
	return false;
}

/*
 * -------------------------------------------------------------------------
 * Elementor helpers.
 *
 * Elementor keeps a page layout as JSON in the _elementor_data post meta: a
 * list of nodes, each with a 7-hex id, an elType (section|column|container|
 * widget, or an atomic e-* type), an optional widgetType, a settings object
 * and a children list. Everything below works on the decoded array and writes
 * back through one verified routine, so a bad edit cannot leave half a page
 * behind: the previous raw JSON is saved first and the write is read back.
 * -------------------------------------------------------------------------
 */

/** Is Elementor loaded on this request? */
function wpmcp_el_active() {
	return class_exists( '\Elementor\Plugin' );
}

/** Decode the layout tree of a post; empty array when the page has no data. */
function wpmcp_el_tree( $post_id ) {
	$raw = get_post_meta( (int) $post_id, '_elementor_data', true );
	if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
		return array();
	}
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'wpmcp_el_bad_json', 'Stored _elementor_data is not valid JSON. Repair the page (wpmcp/restore rollback, or an Elementor revision) before editing it.' );
	}
	return $data;
}

/** Does this node hold children? */
function wpmcp_el_is_container( $el ) {
	$type = (string) ( $el['elType'] ?? '' );
	if ( '' === $type || 'widget' === $type ) {
		return false;
	}
	if ( in_array( $type, array( 'section', 'container', 'column' ), true ) ) {
		return true;
	}
	if ( 0 === strpos( $type, 'e-' ) ) {
		return in_array( $type, array( 'e-div-block', 'e-flexbox', 'e-grid', 'e-container' ), true );
	}
	return empty( $el['widgetType'] );
}

/** Can $parent hold a child of $child_type? */
function wpmcp_el_accepts( $parent, $child_type ) {
	if ( ! wpmcp_el_is_container( $parent ) ) {
		return false;
	}
	$parent_type = (string) ( $parent['elType'] ?? '' );
	if ( 'column' === $child_type ) {
		return in_array( $parent_type, array( 'section', 'container' ), true );
	}
	// A classic section holds columns only; anything else belongs in a column.
	if ( 'section' === $parent_type ) {
		return false;
	}
	return true;
}

/** Every element id in a subtree, as a lookup map. */
function wpmcp_el_collect_ids( $tree, &$out = array() ) {
	foreach ( (array) $tree as $el ) {
		$id = (string) ( $el['id'] ?? '' );
		if ( '' !== $id ) {
			$out[ $id ] = true;
		}
		if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
			wpmcp_el_collect_ids( $el['elements'], $out );
		}
	}
	return $out;
}

/** Fresh 7-hex element id, unique against $taken. */
function wpmcp_el_new_id( $taken ) {
	do {
		$id = '';
		for ( $i = 0; $i < 7; $i++ ) {
			$id .= dechex( mt_rand( 0, 15 ) );
		}
	} while ( isset( $taken[ $id ] ) );
	return $id;
}

/** Flat id => position map of a tree. */
function wpmcp_el_index( $tree, $parent = '', $depth = 0, &$out = array() ) {
	$i = 0;
	foreach ( (array) $tree as $el ) {
		$id = (string) ( $el['id'] ?? '' );
		if ( '' !== $id ) {
			$out[ $id ] = array(
				'id'         => $id,
				'elType'     => (string) ( $el['elType'] ?? '' ),
				'widgetType' => (string) ( $el['widgetType'] ?? '' ),
				'parent'     => (string) $parent,
				'index'      => $i,
				'depth'      => $depth,
				'children'   => is_array( $el['elements'] ?? null ) ? count( $el['elements'] ) : 0,
				'container'  => wpmcp_el_is_container( $el ),
			);
		}
		if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
			wpmcp_el_index( $el['elements'], $id, $depth + 1, $out );
		}
		++$i;
	}
	return $out;
}

/**
 * Reference to the children array of a node, or to the root list for ''.
 * $found tells the caller whether the id existed.
 */
function &wpmcp_el_children_ref( array &$tree, $parent_id, &$found ) {
	static $null = null;
	$found = false;
	if ( '' === (string) $parent_id ) {
		$found = true;
		return $tree;
	}
	foreach ( $tree as &$el ) {
		if ( (string) ( $el['id'] ?? '' ) === (string) $parent_id ) {
			$found = true;
			if ( ! isset( $el['elements'] ) || ! is_array( $el['elements'] ) ) {
				$el['elements'] = array();
			}
			return $el['elements'];
		}
		if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
			$kids = &wpmcp_el_children_ref( $el['elements'], $parent_id, $found );
			if ( $found ) {
				return $kids;
			}
			unset( $kids );
		}
	}
	unset( $el );
	return $null;
}

/** Reference to a node by id. */
function &wpmcp_el_node_ref( array &$tree, $id, &$found ) {
	static $null = null;
	$found = false;
	foreach ( $tree as &$el ) {
		if ( (string) ( $el['id'] ?? '' ) === (string) $id ) {
			$found = true;
			return $el;
		}
		if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
			$node = &wpmcp_el_node_ref( $el['elements'], $id, $found );
			if ( $found ) {
				return $node;
			}
			unset( $node );
		}
	}
	unset( $el );
	return $null;
}

/** Copy of a node by id (null when missing). */
function wpmcp_el_find( $tree, $id ) {
	foreach ( (array) $tree as $el ) {
		if ( (string) ( $el['id'] ?? '' ) === (string) $id ) {
			return $el;
		}
		if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
			$hit = wpmcp_el_find( $el['elements'], $id );
			if ( null !== $hit ) {
				return $hit;
			}
		}
	}
	return null;
}

/** Deep copy of a node with fresh ids. */
function wpmcp_el_clone( $node, &$taken ) {
	$copy        = $node;
	$copy['id']  = wpmcp_el_new_id( $taken );
	$taken[ $copy['id'] ] = true;
	if ( ! empty( $copy['elements'] ) && is_array( $copy['elements'] ) ) {
		$copy['elements'] = array();
		foreach ( (array) $node['elements'] as $child ) {
			$copy['elements'][] = wpmcp_el_clone( $child, $taken );
		}
	}
	return $copy;
}

/** Short text preview of a widget's settings, for outlines. */
function wpmcp_el_preview( $settings ) {
	$settings = (array) $settings;
	foreach ( array( 'title', 'text', 'editor', 'html', 'shortcode', 'caption', 'alt' ) as $key ) {
		if ( ! empty( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ) {
			$text = trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $settings[ $key ] ) ) );
			if ( '' !== $text ) {
				return mb_substr( $text, 0, 80 );
			}
		}
	}
	foreach ( array( 'image', 'background_image' ) as $key ) {
		if ( ! empty( $settings[ $key ]['url'] ) ) {
			return mb_substr( (string) $settings[ $key ]['url'], 0, 80 );
		}
	}
	if ( ! empty( $settings['tabs'] ) && is_array( $settings['tabs'] ) ) {
		$first = reset( $settings['tabs'] );
		if ( is_array( $first ) && ! empty( $first['tab_title'] ) ) {
			return mb_substr( (string) $first['tab_title'], 0, 80 );
		}
	}
	return '';
}

/** Nested outline of a tree. $full returns whole settings instead of their keys. */
function wpmcp_el_outline( $tree, $full = false, $depth = 0 ) {
	$out = array();
	foreach ( (array) $tree as $i => $el ) {
		$settings = (array) ( $el['settings'] ?? array() );
		$row      = array(
			'id'        => (string) ( $el['id'] ?? '' ),
			'elType'    => (string) ( $el['elType'] ?? '' ),
			'index'     => $i,
			'settings'  => $full ? $settings : array_keys( $settings ),
			'text'      => $full ? null : wpmcp_el_preview( $settings ),
			'container' => wpmcp_el_is_container( $el ),
			'children'  => is_array( $el['elements'] ?? null ) ? count( $el['elements'] ) : 0,
		);
		if ( ! empty( $el['widgetType'] ) ) {
			$row['widgetType'] = (string) $el['widgetType'];
		}
		if ( isset( $el['isInner'] ) ) {
			$row['isInner'] = (bool) $el['isInner'];
		}
		if ( $depth > 0 ) {
			$row['depth'] = $depth;
		}
		if ( $row['children'] > 0 ) {
			$row['elements'] = wpmcp_el_outline( $el['elements'], $full, $depth + 1 );
		}
		$out[] = $row;
	}
	return $out;
}

/** Directory that holds the pre-edit Elementor snapshots. */
function wpmcp_el_snapshot_dir() {
	$dir = wpmcp_backup_dir() . '/elementor';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	if ( is_dir( $dir ) && ! file_exists( $dir . '/index.php' ) ) {
		@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
	}
	return $dir;
}

/**
 * Save the raw _elementor_data of a post before a change.
 *
 * Keeps the newest 10 per post. Name: <post_id>.<Ymd-His><micro6>.<rand>.<tag>.json
 *
 * @return string|WP_Error File name.
 */
function wpmcp_el_snapshot( $post_id, $raw, $tag = 'save' ) {
	$dir = wpmcp_el_snapshot_dir();
	if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
		return new WP_Error( 'wpmcp_el_snapshot_dir', 'Backup directory is not writable: wp-content/wpmcp-backups/elementor.' );
	}
	$micro = substr( sprintf( '%06d', (int) round( ( microtime( true ) - floor( microtime( true ) ) ) * 1000000 ) ), 0, 6 );
	$name  = (int) $post_id . '.' . gmdate( 'Ymd-His' ) . $micro . '.' . substr( md5( $raw . mt_rand() ), 0, 6 )
		. '.' . preg_replace( '/[^a-z0-9\-]/i', '', (string) $tag ) . '.json';
	if ( false === @file_put_contents( $dir . '/' . $name, (string) $raw ) ) {
		return new WP_Error( 'wpmcp_el_snapshot_write', 'Could not write the Elementor snapshot.' );
	}
	$same = (array) glob( $dir . '/' . (int) $post_id . '.*.json' );
	sort( $same );
	if ( count( $same ) > 10 ) {
		foreach ( array_slice( $same, 0, count( $same ) - 10 ) as $old ) {
			@unlink( $old );
		}
	}
	return $name;
}

/** Snapshots on disk, newest first. */
function wpmcp_el_snapshots( $post_id = 0 ) {
	$dir    = wpmcp_el_snapshot_dir();
	$out    = array();
	$prefix = $post_id > 0 ? (int) $post_id . '.' : '';
	foreach ( (array) glob( $dir . '/' . $prefix . '*.json' ) as $file ) {
		$name  = basename( $file );
		$parts = explode( '.', $name );
		if ( count( $parts ) < 4 ) {
			continue;
		}
		$digits = preg_replace( '/\D/', '', $parts[1] );
		$at     = '';
		if ( strlen( $digits ) >= 14 ) {
			$at = sprintf(
				'%s-%s-%sT%s:%s:%sZ',
				substr( $digits, 0, 4 ),
				substr( $digits, 4, 2 ),
				substr( $digits, 6, 2 ),
				substr( $digits, 8, 2 ),
				substr( $digits, 10, 2 ),
				substr( $digits, 12, 2 )
			);
		}
		$out[] = array(
			'backup' => $name,
			'post_id'=> (int) $parts[0],
			'at'     => $at,
			'bytes'  => (int) @filesize( $file ),
			'tag'    => (string) ( $parts[ count( $parts ) - 1 ] === 'json' ? ( $parts[ count( $parts ) - 2 ] ?? '' ) : '' ),
		);
	}
	usort( $out, function ( $a, $b ) {
		$cmp = strcmp( $b['at'], $a['at'] );
		return 0 !== $cmp ? $cmp : strcmp( $b['backup'], $a['backup'] );
	} );
	return $out;
}

/** Drop Elementor's generated CSS and tell LiteSpeed to drop the page. */
/**
 * Invalidate the cached CSS of one post and its page caches.
 *
 * Older versions called Elementor's site-wide clear_cache() after every widget
 * edit: every page's CSS was thrown away and rebuilt on the next request. Now
 * only the edited post's stylesheet is deleted and regenerated.
 *
 * @param int $post_id Post whose layout changed.
 */
function wpmcp_el_flush( $post_id ) {
	$post_id = (int) $post_id;
	$file    = WP_CONTENT_DIR . '/uploads/elementor/css/post-' . $post_id . '.css';
	if ( is_file( $file ) ) {
		@unlink( $file );
	}
	if ( wpmcp_el_active() && class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
		try {
			$css = \Elementor\Core\Files\CSS\Post::create( $post_id );
			if ( method_exists( $css, 'delete' ) ) {
				$css->delete();
			}
			if ( method_exists( $css, 'update' ) ) {
				$css->update();
			}
		} catch ( \Throwable $e ) {
			// The deleted file and the _elementor_css meta are enough; Elementor
			// rebuilds the stylesheet on the next page view.
		}
	}
	do_action( 'litespeed_purge_post', $post_id );
}

/**
 * Site-wide Elementor cache flush.
 *
 * Only for changes that affect every page — the kit's global colors, fonts and
 * typography. A per-post edit must not call this.
 */
function wpmcp_el_flush_all() {
	if ( wpmcp_el_active() ) {
		try {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		} catch ( \Throwable $e ) {
			// Best effort; the kit's _elementor_css meta delete is the fallback.
		}
	}
	do_action( 'litespeed_purge_all' );
}

/**
 * Write a layout JSON string and verify it reads back.
 *
 * @param int    $post_id Target post.
 * @param string $json    Full JSON to store (already encoded).
 * @param string $tag     Snapshot label.
 * @return array|WP_Error
 */
function wpmcp_el_write( $post_id, $json, $tag = 'save' ) {
	$post_id = (int) $post_id;
	if ( ! is_string( $json ) || '' === $json || ! is_array( json_decode( $json, true ) ) ) {
		return new WP_Error( 'wpmcp_el_encode', 'Refusing to write: the layout did not encode to a JSON array.' );
	}
	$before = (string) get_post_meta( $post_id, '_elementor_data', true );
	$backup = wpmcp_el_snapshot( $post_id, $before, $tag );

	update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
	update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
	if ( defined( 'ELEMENTOR_VERSION' ) ) {
		update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
	}
	if ( '' === (string) get_post_meta( $post_id, '_elementor_template_type', true ) ) {
		update_post_meta( $post_id, '_elementor_template_type', 'post' === get_post_type( $post_id ) ? 'wp-post' : 'wp-page' );
	}
	delete_post_meta( $post_id, '_elementor_css' );
	wpmcp_el_flush( $post_id );

	$stored = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
	if ( ! is_array( $stored ) ) {
		if ( '' !== $before ) {
			update_post_meta( $post_id, '_elementor_data', wp_slash( $before ) );
		} else {
			delete_post_meta( $post_id, '_elementor_data' );
		}
		return new WP_Error( 'wpmcp_el_verify', 'The write did not survive the round trip; the previous layout was put back.' );
	}

	return array(
		'ok'       => true,
		'backup'   => is_wp_error( $backup ) ? '' : (string) $backup,
		'bytes'    => strlen( $json ),
		'elements' => count( wpmcp_el_collect_ids( $stored ) ),
	);
}

/**
 * True when an array is a JSON-style list (keys 0..n-1 in order).
 *
 * `array_is_list()` needs PHP 8.1; the plugin supports 8.0.
 *
 * @param mixed $value Candidate.
 * @return bool
 */
function wpmcp_is_list( $value ) {
	if ( ! is_array( $value ) ) {
		return false;
	}
	$expected = 0;
	foreach ( $value as $key => $unused ) {
		if ( $key !== $expected ) {
			return false;
		}
		++$expected;
	}
	return true;
}

/**
 * Deep merge for Elementor settings.
 *
 * - Lists of objects that all carry an `_id` merge by id: send only the item you
 *   change and the rest of the list is preserved.
 * - Any other list replaces the old one (a list of strings, a list without ids).
 * - An explicit empty list clears a list; an empty object on a map is a no-op.
 * - Maps merge key by key.
 *
 * Elementor kit settings such as `system_colors` are id-tagged lists, so a
 * partial update must not drop the other entries; typography presets behave the
 * same way. This function is what keeps those partial updates safe.
 *
 * @param mixed $old Current value.
 * @param mixed $new Incoming value.
 * @return mixed
 */
function wpmcp_el_merge( $old, $new ) {
	if ( ! is_array( $old ) || ! is_array( $new ) ) {
		return $new;
	}
	if ( array() === $new ) {
		return wpmcp_is_list( $old ) ? array() : $old;
	}
	if ( ! wpmcp_is_list( $new ) ) {
		$out = $old;
		foreach ( $new as $key => $value ) {
			$out[ $key ] = array_key_exists( $key, $old ) ? wpmcp_el_merge( $old[ $key ], $value ) : $value;
		}
		return $out;
	}
	if ( ! wpmcp_is_list( $old ) ) {
		return $new;
	}

	// Id-tagged list? Every incoming item needs a non-empty _id to merge by id.
	foreach ( $new as $item ) {
		if ( ! is_array( $item ) || ! isset( $item['_id'] ) || '' === (string) $item['_id'] ) {
			return $new;
		}
	}

	$by_id = array();
	foreach ( $old as $index => $item ) {
		if ( is_array( $item ) && isset( $item['_id'] ) && '' !== (string) $item['_id'] ) {
			$by_id[ (string) $item['_id'] ] = $index;
		}
	}
	$merged = $old;
	foreach ( $new as $item ) {
		$id = (string) $item['_id'];
		if ( isset( $by_id[ $id ] ) ) {
			$merged[ $by_id[ $id ] ] = wpmcp_el_merge( $merged[ $by_id[ $id ] ], $item );
			continue;
		}
		$merged[]     = $item;
		$by_id[ $id ] = array_key_last( $merged );
	}
	return array_values( $merged );
}

/**
 * Best parent for an appended widget: the deepest column or container.
 *
 * Columns take any number of widgets, so an occupied column is still a valid
 * target — only sections cannot hold widgets directly.
 */
function wpmcp_el_append_target( $tree ) {
	$choice = '';
	$score  = -1;
	$walk   = function ( $els, $depth ) use ( &$walk, &$choice, &$score ) {
		foreach ( (array) $els as $el ) {
			if ( ! wpmcp_el_is_container( $el ) ) {
				continue;
			}
			$type = (string) ( $el['elType'] ?? '' );
			if ( in_array( $type, array( 'column', 'container' ), true ) || 0 === strpos( $type, 'e-' ) ) {
				if ( $depth >= $score ) {
					$score  = $depth;
					$choice = (string) ( $el['id'] ?? '' );
				}
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$walk( $el['elements'], $depth + 1 );
			}
		}
	};
	$walk( $tree, 0 );
	return $choice;
}

/** An existing section that has no columns yet, so a new column can fill it. */
function wpmcp_el_empty_section( $tree ) {
	foreach ( (array) $tree as $el ) {
		if ( 'section' === (string) ( $el['elType'] ?? '' ) && empty( $el['elements'] ) ) {
			return (string) ( $el['id'] ?? '' );
		}
		if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
			$hit = wpmcp_el_empty_section( $el['elements'] );
			if ( '' !== $hit ) {
				return $hit;
			}
		}
	}
	return '';
}

/** A new column node for an existing section. */
function wpmcp_el_column_node( &$taken ) {
	$id = wpmcp_el_new_id( $taken );
	$taken[ $id ] = true;
	return array(
		'id'       => $id,
		'elType'   => 'column',
		'settings' => array( '_column_size' => 100, '_inline_size' => null ),
		'elements' => array(),
		'isInner'  => false,
	);
}

/** Build the section > column pair that classic Elementor needs for a page. */
function wpmcp_el_section_pair( &$taken ) {
	$section_id = wpmcp_el_new_id( $taken );
	$taken[ $section_id ] = true;
	$column     = wpmcp_el_column_node( $taken );
	return array(
		'section' => array(
			'id'       => $section_id,
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array( $column ),
			'isInner'  => false,
		),
		'column'  => $column['id'],
	);
}

add_action( 'wp_abilities_api_categories_init', function () {
	if ( function_exists( 'wp_register_ability_category' ) ) {
		wp_register_ability_category( 'wpmcp', array(
			'label'       => 'WP MCP',
			'description' => 'Administrator-level abilities on this site for MCP agents: REST, WP-CLI, files, media, audits and a WordPress expert skills library.',
		) );
	}
} );

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	$admin_gate = function () {
		return current_user_can( 'manage_options' );
	};

	$annotations = function ( $title, $readonly, $destructive, $idempotent, $open_world = false ) {
		return array(
			'public'      => true,
			'annotations' => array(
				'readonly'      => (bool) $readonly,
				'destructive'   => (bool) $destructive,
				'idempotent'    => (bool) $idempotent,
				'openWorldHint' => (bool) $open_world,
				'title'         => $title,
			),
		);
	};

	// ---------------------------------------------------------------------
	// 1. Generic REST proxy.
	// ---------------------------------------------------------------------
	wp_register_ability( 'wpmcp/rest-request', array(
		'label'        => 'REST Request (admin)',
		'description'  => 'Execute any WordPress or WooCommerce REST route as an administrator. Examples: GET /wp/v2/plugins, POST /wp/v2/plugins {slug, status:active} (install+activate), PUT /wp/v2/plugins/{plugin} (update), GET /wp/v2/users, PUT /wp/v2/settings/{key}, POST /wc/v3/products/batch, GET /wc/v3/orders?status=processing. Route is the path after /wp-json. Returns HTTP status plus the JSON body.',
		'category'     => 'wpmcp',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'method' => array(
					'type'        => 'string',
					'enum'        => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ),
					'description' => 'HTTP method.',
				),
				'route'  => array(
					'type'        => 'string',
					'description' => 'REST route after /wp-json, with a leading slash. Query params may be inline: /wp/v2/plugins?search=mcp.',
				),
				'query'  => array(
					'type'        => 'object',
					'description' => 'Optional query params as an object, e.g. {"force":true,"per_page":50}. Merged with any inline query string; body wins on conflicts.',
				),
				'body'   => array(
					'type'        => 'object',
					'description' => 'JSON body for POST/PUT/PATCH, e.g. {"slug":"mcp-adapter","status":"active"}.',
				),
			),
			'required'   => array( 'method', 'route' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'status' => array( 'type' => 'integer' ),
				'body'   => array( 'description' => 'Decoded JSON response from the route.' ),
			),
		),
		'execute_callback' => function ( $input ) {
			// No user impersonation: the REST stack must run as the authenticated caller,
			// otherwise a route could execute with more rights than the caller has.
			if ( wpmcp_is_readonly() && 'GET' !== strtoupper( (string) ( $input['method'] ?? 'GET' ) ) ) {
				return array(
					'status' => 403,
					'body'   => array( 'error' => 'read_only_mode', 'message' => 'Read-only mode is on (WPMCP_READONLY): only GET requests are allowed.' ),
				);
			}
			if ( ! get_current_user_id() ) {
				return array(
					'status' => 401,
					'body'   => array( 'error' => 'no_current_user', 'message' => 'No authenticated user in this request context.' ),
				);
			}
			$method  = strtoupper( (string) ( $input['method'] ?? 'GET' ) );
			$route   = '/' . ltrim( (string) ( $input['route'] ?? '' ), '/' );
			$request = new WP_REST_Request( $method, $route );
			if ( str_contains( $route, '?' ) ) {
				$query_part = wp_parse_url( $route, PHP_URL_QUERY );
				$request->set_route( (string) strtok( $route, '?' ) );
				parse_str( (string) $query_part, $query_args );
				foreach ( $query_args as $key => $value ) {
					$request->set_param( $key, $value );
				}
			}
			$body = $input['body'] ?? null;
			$query = $input['query'] ?? null;
			if ( is_array( $query ) ) {
				foreach ( $query as $key => $value ) {
					$request->set_param( $key, $value );
				}
			}
			if ( is_array( $body ) ) {
				foreach ( $body as $key => $value ) {
					$request->set_param( $key, $value );
				}
			}
			$response = rest_do_request( $request );
			$data     = $response->get_data();
			if ( $data instanceof WP_Error ) {
				return array(
					'status' => $response->get_status(),
					'body'   => array( 'error' => $data->get_error_code(), 'message' => $data->get_error_message() ),
				);
			}
			return array( 'status' => $response->get_status(), 'body' => $data );
		},
		'permission_callback' => $admin_gate,
		'meta'                => $annotations( 'WP REST Request (admin)', false, false, false ),
	) );

	// ---------------------------------------------------------------------
	// 2. WP-CLI runner.
	// ---------------------------------------------------------------------
	// WP-CLI is opt-in: shell-level access, so it is absent unless the site asks for it.
	if ( wpmcp_wpcli_enabled() ) {
		wp_register_ability( 'wpmcp/wpcli', array(
			'label'        => 'WP-CLI Command (admin)',
			'description'  => 'Run a WP-CLI command through the wp binary as shell-level admin. Only available when the site opts in with define( \'WPMCP_ENABLE_WPCLI\', true ) in wp-config.php; without it this tool is not registered. Examples: "plugin list --format=json", "plugin update --all --yes", "cache flush", "db query \\"SELECT ID FROM wp_posts LIMIT 5\\" --format=json", "search-replace old new --all-tables --dry-run", "media import https://example.com/a.jpg", "eval \'echo json_encode(get_option(\\"blogname\\"));\'". The string is handed to a shell, so pipes work; newlines are stripped. 90-second timeout, last ~20 KB of each stream. Use wpmcp/rest-request when proc_open is unavailable.',
			'category'     => 'wpmcp',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'command' => array(
						'type'        => 'string',
						'description' => 'WP-CLI command without the "wp" prefix. Runs through a shell, so pipes and redirects work.',
					),
					'confirm' => array(
						'type'        => 'boolean',
						'description' => 'Must be true for destructive commands (db drop/reset/delete/import, site empty, */delete).',
					),
				),
				'required'   => array( 'command' ),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'success'   => array( 'type' => 'boolean' ),
					'exit_code' => array( 'type' => 'integer' ),
					'stdout'    => array( 'type' => 'string' ),
					'stderr'    => array( 'type' => 'string' ),
				),
			),
			'execute_callback' => function ( $input ) {
				if ( wpmcp_is_readonly() ) {
					return array( 'success' => false, 'exit_code' => -1, 'stdout' => '', 'stderr' => 'Read-only mode is on (WPMCP_READONLY): WP-CLI is disabled because any command can write. Use wpmcp/rest-request GET or wpmcp/files read.' );
				}
				$raw_command = (string) ( $input['command'] ?? '' );
				if ( preg_match( '/\b(db\s+(drop|reset|delete|import)|site\s+empty|(post|user|comment|plugin|theme|option|transient|media)\s+delete)\b/i', $raw_command ) && empty( $input['confirm'] ) ) {
					return array( 'success' => false, 'exit_code' => -1, 'stdout' => '', 'stderr' => 'Destructive WP-CLI command. Repeat with "confirm": true if that is intended.' );
				}
				// Runs through a shell by design so pipelines work. Newlines and NUL bytes are
				// stripped only to keep the call single-line; this is not a sandbox.
				$cmdline = trim( str_replace( array( "\0", "\r", "\n" ), ' ', $raw_command ) );
				if ( '' === $cmdline ) {
					return array( 'success' => false, 'exit_code' => -1, 'stdout' => '', 'stderr' => 'Empty command.' );
				}
				if ( ! function_exists( 'proc_open' ) ) {
					return array( 'success' => false, 'exit_code' => -1, 'stdout' => '', 'stderr' => 'proc_open is disabled on this host; use wpmcp/rest-request instead.' );
				}
				$wp_binary = '';
				foreach ( array( '/usr/local/bin/wp', '/usr/bin/wp', '/usr/local/bin/wp-cli.phar', '/usr/bin/wp-cli.phar' ) as $candidate ) {
					if ( is_executable( $candidate ) ) {
						$wp_binary = $candidate;
						break;
					}
				}
				if ( '' === $wp_binary ) {
					$found = shell_exec( 'command -v wp 2>/dev/null' );
					if ( is_string( $found ) && '' !== trim( $found ) ) {
						$wp_binary = trim( $found );
					}
				}
				if ( '' === $wp_binary ) {
					return array( 'success' => false, 'exit_code' => -1, 'stdout' => '', 'stderr' => 'wp binary not found; use wpmcp/rest-request instead.' );
				}
				$full_command = 'timeout 90 ' . escapeshellarg( $wp_binary ) . ' --path=' . escapeshellarg( ABSPATH ) . ' ' . $cmdline;
				$descriptors  = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
				$process      = proc_open( $full_command, $descriptors, $pipes, ABSPATH );
				if ( ! is_resource( $process ) ) {
					return array( 'success' => false, 'exit_code' => -1, 'stdout' => '', 'stderr' => 'proc_open failed to start the wp process.' );
				}
				fclose( $pipes[0] );
				stream_set_blocking( $pipes[1], false );
				stream_set_blocking( $pipes[2], false );
				$stdout    = '';
				$stderr    = '';
				$exit_code = null;
				$start     = time();
				while ( true ) {
					$read   = array( $pipes[1], $pipes[2] );
					$write  = null;
					$except = null;
					$ready  = @stream_select( $read, $write, $except, 2 );
					if ( false === $ready ) {
						break;
					}
					if ( $ready > 0 ) {
						if ( in_array( $pipes[1], $read, true ) ) {
							$stdout .= (string) stream_get_contents( $pipes[1] );
						}
						if ( in_array( $pipes[2], $read, true ) ) {
							$stderr .= (string) stream_get_contents( $pipes[2] );
						}
					}
					$status = proc_get_status( $process );
					if ( ! $status['running'] ) {
						$exit_code = (int) $status['exitcode'];
						break;
					}
					if ( ( time() - $start ) > 95 ) {
						$stderr .= "\n[wpmcp] killed after 95s";
						proc_terminate( $process );
						break;
					}
				}
				$stdout .= (string) stream_get_contents( $pipes[1] );
				$stderr .= (string) stream_get_contents( $pipes[2] );
				$closed = proc_close( $process );
				if ( null === $exit_code ) {
					$exit_code = (int) $closed;
				}
				return array(
					'success'   => 0 === $exit_code,
					'exit_code' => $exit_code,
					'stdout'    => mb_substr( $stdout, -20000 ),
					'stderr'    => mb_substr( $stderr, -20000 ),
				);
			},
			'permission_callback' => $admin_gate,
			'meta'                => $annotations( 'WP-CLI (admin)', false, true, false ),
		) );
	}

	// ---------------------------------------------------------------------
	// 3. Files: list/read/write/delete/search under wp-content.
	// ---------------------------------------------------------------------
	wp_register_ability( 'wpmcp/files', array(
		'label'        => 'Files (wp-content)',
		'description'  => 'List, read, write, delete and search files under wp-content (themes, plugins, uploads, mu-plugins). Paths are relative to wp-content, e.g. "themes/oceanwp-child-theme-master/functions.php". Guarded: cannot escape wp-content and cannot contain "..". Every write and delete first saves a rollback copy under wp-content/wpmcp-backups (wpmcp/restore lists and restores them), writes are atomic (temp file + rename), and delete needs "confirm": true. A PHP syntax error still makes the site fatal, so prefer a child theme or mu-plugin for custom code. Actions: list, read, write, delete, search.',
		'category'     => 'wpmcp',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'action'  => array( 'type' => 'string', 'enum' => array( 'list', 'read', 'write', 'delete', 'search' ) ),
				'path'    => array( 'type' => 'string', 'description' => 'Relative path under wp-content. Empty for list/search means the wp-content root.' ),
				'content' => array( 'type' => 'string', 'description' => 'New file content for action=write.' ),
				'pattern' => array( 'type' => 'string', 'description' => 'Literal text to find for action=search.' ),
				'confirm' => array( 'type' => 'boolean', 'description' => 'Must be true for action=delete.' ),
			),
			'required' => array( 'action' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'ok'      => array( 'type' => 'boolean' ),
				'entries' => array( 'description' => 'Directory entries or search hits.' ),
				'content' => array( 'type' => 'string' ),
				'backup'  => array( 'type' => 'string', 'description' => 'Rollback file name, restore with wpmcp/restore.' ),
				'message' => array( 'type' => 'string' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$action = (string) ( $input['action'] ?? 'list' );
			$path   = (string) ( $input['path'] ?? '' );

			if ( 'list' === $action ) {
				$dir = ( '' === trim( $path ) ) ? realpath( WP_CONTENT_DIR ) : wpmcp_guard_path( $path );
				if ( is_wp_error( $dir ) ) {
					return array( 'ok' => false, 'message' => $dir->get_error_message() );
				}
				if ( ! $dir || ! is_dir( $dir ) ) {
					return array( 'ok' => false, 'message' => 'Not a directory: ' . $path );
				}
				$entries = array();
				foreach ( (array) scandir( $dir ) as $item ) {
					if ( '.' === $item || '..' === $item ) {
						continue;
					}
					$full      = $dir . '/' . $item;
					$entries[] = array(
						'name'  => $item,
						'type'  => is_dir( $full ) ? 'dir' : 'file',
						'bytes' => is_file( $full ) ? filesize( $full ) : 0,
					);
				}
				return array( 'ok' => true, 'entries' => $entries );
			}

			if ( 'search' === $action ) {
				$pattern = (string) ( $input['pattern'] ?? '' );
				if ( '' === $pattern ) {
					return array( 'ok' => false, 'message' => 'Pattern required for search.' );
				}
				$root = ( '' === trim( $path ) ) ? realpath( WP_CONTENT_DIR ) : wpmcp_guard_path( $path );
				if ( is_wp_error( $root ) ) {
					return array( 'ok' => false, 'message' => $root->get_error_message() );
				}
				if ( ! $root || ! is_dir( $root ) ) {
					return array( 'ok' => false, 'message' => 'Not a directory: ' . $path );
				}
				$hits = array();
				$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
				foreach ( $it as $file ) {
					if ( ! $file->isFile() || $file->getSize() > 1048576 ) {
						continue;
					}
					$body = (string) file_get_contents( $file->getPathname() );
					if ( false !== stripos( $body, $pattern ) ) {
						$hits[] = substr( $file->getPathname(), strlen( WP_CONTENT_DIR ) + 1 );
						if ( count( $hits ) >= 50 ) {
							break;
						}
					}
				}
				return array( 'ok' => true, 'entries' => $hits, 'message' => count( $hits ) . ' file(s) matched.' );
			}

			$abs = wpmcp_guard_path( $path, 'write' !== $action );

			if ( 'read' === $action ) {
				if ( is_wp_error( $abs ) ) {
					return array( 'ok' => false, 'message' => $abs->get_error_message() );
				}
				if ( ! is_file( $abs ) ) {
					return array( 'ok' => false, 'message' => 'Not a file: ' . $path );
				}
				$body = (string) file_get_contents( $abs );
				return array( 'ok' => true, 'content' => mb_substr( $body, 0, 200000 ), 'message' => strlen( $body ) . ' bytes.' );
			}

			if ( 'write' === $action ) {
				if ( wpmcp_is_readonly() ) {
					return wpmcp_readonly_error();
				}
				if ( is_wp_error( $abs ) ) {
					return array( 'ok' => false, 'message' => $abs->get_error_message() );
				}
				$dir = dirname( $abs );
				if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
					return array( 'ok' => false, 'message' => 'Could not create directory: ' . $dir );
				}
				$backup  = '';
				$existed = is_file( $abs );
				if ( $existed ) {
					$saved = wpmcp_backup_file( $abs, 'edit' );
					$backup = is_wp_error( $saved ) ? '' : (string) $saved;
				}
				$content = (string) ( $input['content'] ?? '' );
				$tmp     = $abs . '.wpmcp-tmp';
				if ( false === file_put_contents( $tmp, $content ) ) {
					return array( 'ok' => false, 'message' => 'Write failed (temporary file).' );
				}
				if ( ! @rename( $tmp, $abs ) ) {
					@unlink( $tmp );
					return array( 'ok' => false, 'message' => 'Write failed (rename).' );
				}
				return array(
					'ok'      => true,
					'backup'  => $backup,
					'message' => strlen( $content ) . ' bytes written to ' . $path . ( $existed ? ' (previous version backed up)' : '' ),
				);
			}

			if ( 'delete' === $action ) {
				if ( wpmcp_is_readonly() ) {
					return wpmcp_readonly_error();
				}
				if ( empty( $input['confirm'] ) ) {
					return wpmcp_confirm_error( 'delete ' . $path );
				}
				if ( is_wp_error( $abs ) ) {
					return array( 'ok' => false, 'message' => $abs->get_error_message() );
				}
				if ( ! is_file( $abs ) ) {
					return array( 'ok' => false, 'message' => 'Only files can be deleted: ' . $path );
				}
				$saved  = wpmcp_backup_file( $abs, 'delete' );
				$backup = is_wp_error( $saved ) ? '' : (string) $saved;
				return array(
					'ok'      => (bool) unlink( $abs ),
					'backup'  => $backup,
					'message' => 'Deleted ' . $path . ( $backup ? ' — restore with wpmcp/restore backup=' . $backup : '' ),
				);
			}

			return array( 'ok' => false, 'message' => 'Unknown action.' );
		},
		'permission_callback' => $admin_gate,
		'meta'                => $annotations( 'WP Files (wp-content)', false, true, false ),
	) );

	// ---------------------------------------------------------------------
	// 4. Elementor builder: read and edit the layout tree safely.
	// ---------------------------------------------------------------------
	wp_register_ability( 'wpmcp/elementor', array(
		'label'        => 'Elementor Builder',
		'description'  => 'Read and edit an Elementor page at the data level, safely. Actions: get-structure (nested outline of the layout with ids, types and setting keys), get-widget (one node with full settings), add-widget (append a widget/section/column; on an empty page it creates the section+column pair for you), update-widget (merge or replace settings), move-widget, duplicate-widget, delete-widget (needs confirm), create-page, set-page-settings, get-globals / set-globals (kit colors, fonts, layout; set needs confirm), list-templates, apply-template, regenerate-css, snapshots, rollback. Every write saves the previous _elementor_data under wp-content/wpmcp-backups/elementor and is read back to verify before returning. After a change call wpmcp/fetch-page on the URL.',
		'category'     => 'wpmcp',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'action'     => array(
					'type'        => 'string',
					'enum'        => array( 'get-structure', 'get-widget', 'add-widget', 'update-widget', 'move-widget', 'duplicate-widget', 'delete-widget', 'create-page', 'set-page-settings', 'get-globals', 'set-globals', 'list-templates', 'apply-template', 'regenerate-css', 'snapshots', 'rollback' ),
					'description' => 'get-structure and get-widget are the read calls; everything else writes (except snapshots).',
				),
				'post_id'    => array( 'type' => 'integer', 'description' => 'Page or post id to work on (required for every action except get-globals, set-globals, list-templates and regenerate-css without a post).' ),
				'element_id' => array( 'type' => 'string', 'description' => 'Element id from get-structure, e.g. "01e8487".' ),
				'widget_type'=> array( 'type' => 'string', 'description' => 'For add-widget: a widget type ("heading", "text-editor", "button", "image", "accordion"...) or a structural elType ("section", "container", "column").' ),
				'settings'   => array( 'type' => 'object', 'description' => 'Widget, page or kit settings to merge. Setting keys are widget-specific: copy the shape from an existing node with get-widget.' ),
				'parent_id'  => array( 'type' => 'string', 'description' => 'Target container id. Empty means auto: deepest open container, or a new section+column on an empty page.' ),
				'index'      => array( 'type' => 'integer', 'description' => 'Position inside the parent, 0-based. Omit to append.' ),
				'template_id'=> array( 'type' => 'integer', 'description' => 'For apply-template: id from list-templates.' ),
				'replace'    => array( 'type' => 'boolean', 'description' => 'update-widget: true replaces the whole settings object instead of merging.' ),
				'full'       => array( 'type' => 'boolean', 'description' => 'get-structure: include full settings for every node.' ),
				'return_structure' => array( 'type' => 'boolean', 'description' => 'Writes: include the updated outline in the response.' ),
				'is_inner'   => array( 'type' => 'boolean', 'description' => 'add-widget with a structural type: mark it as an inner (nested) element.' ),
				'elements'   => array( 'type' => 'array', 'description' => 'create-page: initial layout tree; leave empty and use add-widget instead.' ),
				'title'      => array( 'type' => 'string', 'description' => 'create-page: post title.' ),
				'post_type'  => array( 'type' => 'string', 'enum' => array( 'page', 'post' ), 'description' => 'create-page: page (default) or post.' ),
				'status'     => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'private' ), 'description' => 'create-page: status, default draft.' ),
				'scope'      => array( 'type' => 'string', 'enum' => array( 'post', 'all' ), 'description' => 'regenerate-css: one post (post_id) or every Elementor CSS file.' ),
				'backup'     => array( 'type' => 'string', 'description' => 'rollback: snapshot file name from action=snapshots. Empty means the newest for this post.' ),
				'confirm'    => array( 'type' => 'boolean', 'description' => 'Required true for delete-widget, set-globals and rollback.' ),
			),
			'required'   => array( 'action' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'ok'         => array( 'type' => 'boolean' ),
				'action'     => array( 'type' => 'string' ),
				'post_id'    => array( 'type' => 'integer' ),
				'element_id' => array( 'type' => 'string' ),
				'parent_id'  => array( 'type' => 'string' ),
				'index'      => array( 'type' => 'integer' ),
				'elements'   => array( 'type' => 'integer' ),
				'backup'     => array( 'type' => 'string' ),
				'structure'  => array( 'description' => 'Nested outline when requested.' ),
				'element'    => array( 'description' => 'Node returned by get-widget.' ),
				'entries'    => array( 'description' => 'Snapshots or templates.' ),
				'globals'    => array( 'description' => 'Kit colors, typography and layout.' ),
				'message'    => array( 'type' => 'string' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$action  = (string) ( $input['action'] ?? '' );
			$post_id = (int) ( $input['post_id'] ?? 0 );
			$writes  = array( 'add-widget', 'update-widget', 'move-widget', 'duplicate-widget', 'delete-widget', 'create-page', 'set-page-settings', 'set-globals', 'apply-template', 'regenerate-css', 'rollback' );

			if ( wpmcp_is_readonly() && in_array( $action, $writes, true ) ) {
				return wpmcp_readonly_error();
			}

			$resolve = function ( $id ) {
				$id = (int) $id;
				if ( $id <= 0 || ! get_post( $id ) ) {
					return new WP_Error( 'wpmcp_el_post', 'post_id is required and must be an existing page or post.' );
				}
				if ( ! current_user_can( 'edit_post', $id ) ) {
					return new WP_Error( 'wpmcp_el_cap', 'You cannot edit that post.' );
				}
				return $id;
			};

			$fail = function ( $error ) {
				return array( 'ok' => false, 'message' => is_wp_error( $error ) ? $error->get_error_message() : (string) $error );
			};

			if ( 'get-globals' === $action ) {
				$kit = (int) get_option( 'elementor_active_kit' );
				if ( ! $kit ) {
					$found = get_posts( array( 'post_type' => 'elementor_library', 'numberposts' => 1, 'post_status' => 'any', 'meta_key' => '_elementor_template_type', 'meta_value' => 'kit', 'fields' => 'ids' ) );
					$kit   = (int) ( $found[0] ?? 0 );
				}
				if ( ! $kit ) {
					return array( 'ok' => false, 'message' => 'No Elementor kit found (option elementor_active_kit is empty).' );
				}
				$settings = (array) get_post_meta( $kit, '_elementor_page_settings', true );
				$layout   = array();
				foreach ( array( 'container_width', 'space_between_widgets', 'page_title_selector', 'default_generic_fonts', 'site_name', 'viewport_md', 'viewport_lg' ) as $key ) {
					if ( array_key_exists( $key, $settings ) ) {
						$layout[ $key ] = $settings[ $key ];
					}
				}
				return array(
					'ok'       => true,
					'action'   => 'get-globals',
					'post_id'  => $kit,
					'globals'  => array(
						'system_colors'     => $settings['system_colors'] ?? array(),
						'custom_colors'     => $settings['custom_colors'] ?? array(),
						'system_typography' => $settings['system_typography'] ?? array(),
						'custom_typography' => $settings['custom_typography'] ?? array(),
						'layout'            => $layout,
					),
					'message'  => 'Kit ' . $kit . '. Change colors or fonts with set-globals; values merge by _id.',
				);
			}

			if ( 'list-templates' === $action ) {
				$posts = get_posts( array( 'post_type' => 'elementor_library', 'numberposts' => 200, 'post_status' => 'any', 'orderby' => 'title', 'order' => 'ASC' ) );
				$rows  = array();
				foreach ( $posts as $tpl ) {
					$data = wpmcp_el_tree( $tpl->ID );
					$rows[] = array(
						'id'        => (int) $tpl->ID,
						'title'     => (string) $tpl->post_title,
						'type'      => (string) get_post_meta( $tpl->ID, '_elementor_template_type', true ),
						'status'    => (string) $tpl->post_status,
						'elements'  => is_array( $data ) ? count( wpmcp_el_collect_ids( $data ) ) : 0,
					);
				}
				return array(
					'ok'      => true,
					'action'  => 'list-templates',
					'entries' => $rows,
					'message' => count( $rows ) . ' Elementor template(s). Use apply-template with template_id and post_id.',
				);
			}

			if ( 'snapshots' === $action ) {
				$rows = wpmcp_el_snapshots( $post_id );
				return array(
					'ok'      => true,
					'action'  => 'snapshots',
					'post_id' => $post_id,
					'entries' => array_slice( $rows, 0, 50 ),
					'message' => count( $rows ) . ' Elementor snapshot(s)' . ( $post_id ? ' for post ' . $post_id : '' ) . '. Restore one with action=rollback.',
				);
			}

			if ( 'regenerate-css' === $action ) {
				$css_dir = WP_CONTENT_DIR . '/uploads/elementor/css';
				if ( $post_id > 0 ) {
					delete_post_meta( $post_id, '_elementor_css' );
					wpmcp_el_flush( $post_id );
					return array(
						'ok'      => true,
						'action'  => 'regenerate-css',
						'post_id' => $post_id,
						'message' => 'Cleared the Elementor CSS for post ' . $post_id . '. It is rebuilt on the next page view.',
					);
				}
				$before = is_dir( $css_dir ) ? count( (array) glob( $css_dir . '/*.css' ) ) : 0;
				if ( wpmcp_el_active() ) {
					try {
						\Elementor\Plugin::$instance->files_manager->clear_cache();
					} catch ( \Throwable $e ) {
						if ( is_dir( $css_dir ) ) {
							foreach ( (array) glob( $css_dir . '/*.css' ) as $file ) {
								@unlink( $file );
							}
						}
					}
				}
				$after = is_dir( $css_dir ) ? count( (array) glob( $css_dir . '/*.css' ) ) : 0;
				do_action( 'litespeed_purge_all' );
				return array(
					'ok'      => true,
					'action'  => 'regenerate-css',
					'elements'=> $before - $after,
					'message' => 'Cleared Elementor CSS files (' . $before . ' before, ' . $after . ' after) and purged LiteSpeed. CSS rebuilds on demand.',
				);
			}

			// create-page has no post yet and set-globals works on the kit, so
			// neither may go through the per-post gate below.
			$needs_post = ! in_array( $action, array( 'create-page', 'set-globals' ), true );
			if ( $needs_post ) {
				$post_id = $resolve( $post_id );
				if ( is_wp_error( $post_id ) ) {
					return $fail( $post_id );
				}
			}
			$tree = $needs_post ? wpmcp_el_tree( $post_id ) : array();
			if ( is_wp_error( $tree ) ) {
				return $fail( $tree );
			}
			$taken = $needs_post ? wpmcp_el_collect_ids( $tree ) : array();

			switch ( $action ) {
				case 'get-structure':
					$full = ! empty( $input['full'] );
					return array(
						'ok'             => true,
						'action'         => 'get-structure',
						'post_id'        => $post_id,
						'title'          => (string) get_the_title( $post_id ),
						'post_type'      => (string) get_post_type( $post_id ),
						'edit_mode'      => (string) get_post_meta( $post_id, '_elementor_edit_mode', true ),
						'template_type'  => (string) get_post_meta( $post_id, '_elementor_template_type', true ),
						'elementor_version' => (string) get_post_meta( $post_id, '_elementor_version', true ),
						'elements'       => count( $taken ),
						'structure'      => wpmcp_el_outline( $tree, $full ),
						'page_settings'  => (array) get_post_meta( $post_id, '_elementor_page_settings', true ),
						'message'        => count( $taken ) . ' element(s). settings is a key list unless full=true. Use get-widget for the full node.',
					);

				case 'get-widget':
					$element_id = (string) ( $input['element_id'] ?? '' );
					$node       = wpmcp_el_find( $tree, $element_id );
					if ( null === $node ) {
						return array(
							'ok'      => false,
							'action'  => 'get-widget',
							'post_id' => $post_id,
							'message' => 'Element ' . $element_id . ' not found. Known ids: ' . implode( ', ', array_slice( array_keys( $taken ), 0, 40 ) ),
						);
					}
					$index = wpmcp_el_index( $tree );
					return array(
						'ok'         => true,
						'action'     => 'get-widget',
						'post_id'    => $post_id,
						'element_id' => $element_id,
						'element'    => $node,
						'parent_id'  => (string) ( $index[ $element_id ]['parent'] ?? '' ),
						'message'    => 'Edit it with update-widget {element_id, settings}.',
					);

				case 'add-widget':
					$type = strtolower( trim( (string) ( $input['widget_type'] ?? '' ) ) );
					$type = (string) preg_replace( '/[^a-z0-9_\-]/', '', $type );
					if ( '' === $type ) {
						return array( 'ok' => false, 'action' => 'add-widget', 'message' => 'widget_type is required, e.g. "heading" or "section".' );
					}
					$structural = in_array( $type, array( 'section', 'container', 'column' ), true );
					if ( ! $structural ) {
						$known = null;
						if ( wpmcp_el_active() && isset( \Elementor\Plugin::$instance->widgets_manager ) ) {
							$known = \Elementor\Plugin::$instance->widgets_manager->get_widget_types( $type );
						}
						if ( ! $known ) {
							$available = array();
							if ( wpmcp_el_active() && isset( \Elementor\Plugin::$instance->widgets_manager ) ) {
								$available = array_slice( array_keys( (array) \Elementor\Plugin::$instance->widgets_manager->get_widget_types() ), 0, 80 );
							}
							$hint = 0 === strpos( $type, 'e-' )
								? 'Atomic elements are off on this site (elementor_experiment-e_atomic_elements is not active), so e-* types cannot be added. Use classic widgets.'
								: 'Unknown widget type "' . $type . '".';
							return array( 'ok' => false, 'action' => 'add-widget', 'entries' => $available, 'message' => $hint . ' Known types: ' . implode( ', ', $available ) );
						}
					}

					$parent = (string) ( $input['parent_id'] ?? '' );
					$auto   = '';
					if ( '' !== $parent ) {
						if ( ! isset( $taken[ $parent ] ) ) {
							return array( 'ok' => false, 'action' => 'add-widget', 'message' => 'parent_id ' . $parent . ' does not exist on this page. Call get-structure.' );
						}
						$pel = wpmcp_el_find( $tree, $parent );
						if ( 'section' === (string) ( $pel['elType'] ?? '' ) && 'column' !== $type ) {
							// A section cannot hold a widget directly; fill it with a column first.
							$column = wpmcp_el_column_node( $taken );
							$kids   = &wpmcp_el_children_ref( $tree, $parent, $found );
							if ( $found ) {
								$kids[] = $column;
							}
							unset( $kids );
							$parent = $column['id'];
							$auto   = 'column';
						} elseif ( ! wpmcp_el_accepts( $pel, $type ) ) {
							return array( 'ok' => false, 'action' => 'add-widget', 'message' => 'Cannot put a ' . $type . ' inside a ' . ( $pel['elType'] ?? '?' ) . '. Pick a container from get-structure.' );
						}
					} elseif ( 'column' === $type ) {
						$index_all  = wpmcp_el_index( $tree );
						$candidates = array();
						foreach ( $index_all as $id => $info ) {
							if ( in_array( $info['elType'], array( 'section', 'container' ), true ) && 0 === $info['children'] ) {
								$candidates[] = $id;
							}
						}
						if ( $candidates ) {
							$parent = (string) end( $candidates );
						} else {
							$pair   = wpmcp_el_section_pair( $taken );
							$tree[] = $pair['section'];
							$parent = $pair['section']['id'];
							$auto   = 'section';
						}
					} elseif ( in_array( $type, array( 'section', 'container' ), true ) ) {
						$parent = '';
					} else {
						$atomic = false;
						$index_all = wpmcp_el_index( $tree );
						foreach ( $index_all as $info ) {
							if ( 0 === strpos( $info['elType'], 'e-' ) ) {
								$atomic = true;
								break;
							}
						}
						$parent = wpmcp_el_append_target( $tree );
						if ( '' === $parent ) {
							// A section without columns just needs one.
							$empty = $atomic ? '' : wpmcp_el_empty_section( $tree );
							if ( '' !== $empty ) {
								$column = wpmcp_el_column_node( $taken );
								$kids   = &wpmcp_el_children_ref( $tree, $empty, $found );
								if ( $found ) {
									$kids[] = $column;
								}
								unset( $kids );
								$parent = $column['id'];
								$auto   = 'column';
							} elseif ( $atomic ) {
								return array( 'ok' => false, 'action' => 'add-widget', 'message' => 'This is an atomic page, so there is no classic column to append to. Pass parent_id of an e-div-block from get-structure.' );
							} else {
								$pair   = wpmcp_el_section_pair( $taken );
								$tree[] = $pair['section'];
								$parent = $pair['column'];
								$auto   = 'section+column';
							}
						}
					}

					$node = array(
						'id'       => wpmcp_el_new_id( $taken ),
						'elType'   => $structural ? $type : 'widget',
						'settings' => (array) ( $input['settings'] ?? array() ),
						'elements' => array(),
					);
					if ( ! $structural ) {
						$node['widgetType'] = $type;
					}
					if ( 'column' === $type && empty( $node['settings']['_column_size'] ) ) {
						$node['settings']['_column_size'] = 100;
					}
					if ( $structural ) {
						$node['isInner'] = ! empty( $input['is_inner'] );
					}
					$taken[ $node['id'] ] = true;

					$kids = &wpmcp_el_children_ref( $tree, $parent, $found );
					if ( ! $found ) {
						unset( $kids );
						return array( 'ok' => false, 'action' => 'add-widget', 'message' => 'Parent ' . $parent . ' vanished while editing.' );
					}
					$position = isset( $input['index'] ) ? max( 0, min( (int) $input['index'], count( $kids ) ) ) : count( $kids );
					array_splice( $kids, $position, 0, array( $node ) );
					unset( $kids );

					$written = wpmcp_el_write( $post_id, (string) wp_json_encode( $tree ), 'add-widget' );
					if ( is_wp_error( $written ) ) {
						return $fail( $written );
					}
					$out = array(
						'ok'         => true,
						'action'     => 'add-widget',
						'post_id'    => $post_id,
						'element_id' => $node['id'],
						'parent_id'  => $parent,
						'index'      => $position,
						'elements'   => $written['elements'],
						'backup'     => $written['backup'],
						'message'    => 'Added ' . $type . ' as ' . $node['id'] . ( $auto ? ' (created ' . $auto . ' automatically)' : '' ) . '. Verify with wpmcp/fetch-page, then update-widget for its content.',
					);
					if ( ! empty( $input['return_structure'] ) ) {
						$out['structure'] = wpmcp_el_outline( $tree );
					}
					return $out;

				case 'update-widget':
					$element_id = (string) ( $input['element_id'] ?? '' );
					$node       = &wpmcp_el_node_ref( $tree, $element_id, $found );
					if ( ! $found ) {
						unset( $node );
						return array( 'ok' => false, 'action' => 'update-widget', 'message' => 'Element ' . $element_id . ' not found. Call get-structure.' );
					}
					$settings = (array) ( $input['settings'] ?? array() );
					if ( empty( $settings ) ) {
						unset( $node );
						return array( 'ok' => false, 'action' => 'update-widget', 'message' => 'settings is empty: give at least one key to change.' );
					}
					$before_keys = array_keys( (array) ( $node['settings'] ?? array() ) );
					$node['settings'] = ! empty( $input['replace'] )
						? $settings
						: wpmcp_el_merge( (array) ( $node['settings'] ?? array() ), $settings );
					$after_keys = array_keys( (array) $node['settings'] );
					unset( $node );

					$written = wpmcp_el_write( $post_id, (string) wp_json_encode( $tree ), 'update-widget' );
					if ( is_wp_error( $written ) ) {
						return $fail( $written );
					}
					$out = array(
						'ok'         => true,
						'action'     => 'update-widget',
						'post_id'    => $post_id,
						'element_id' => $element_id,
						'elements'   => $written['elements'],
						'backup'     => $written['backup'],
						'message'    => 'Updated ' . $element_id . ' (' . ( ! empty( $input['replace'] ) ? 'replaced' : 'merged' ) . '; ' . count( $before_keys ) . ' -> ' . count( $after_keys ) . ' keys). Verify with wpmcp/fetch-page.',
					);
					if ( ! empty( $input['return_structure'] ) ) {
						$out['structure'] = wpmcp_el_outline( $tree );
					}
					return $out;

				case 'move-widget':
					$element_id = (string) ( $input['element_id'] ?? '' );
					$index_all  = wpmcp_el_index( $tree );
					if ( ! isset( $index_all[ $element_id ] ) ) {
						return array( 'ok' => false, 'action' => 'move-widget', 'message' => 'Element ' . $element_id . ' not found. Call get-structure.' );
					}
					$node      = wpmcp_el_find( $tree, $element_id );
					$subtree   = wpmcp_el_collect_ids( array( $node ) );
					$target    = (string) ( $input['parent_id'] ?? '' );
					if ( '' === $target ) {
						$target = (string) $index_all[ $element_id ]['parent'];
					}
					if ( $target === $element_id || isset( $subtree[ $target ] ) ) {
						return array( 'ok' => false, 'action' => 'move-widget', 'message' => 'Cannot move an element into itself or its own children.' );
					}
					if ( '' !== $target ) {
						if ( ! isset( $taken[ $target ] ) ) {
							return array( 'ok' => false, 'action' => 'move-widget', 'message' => 'Target parent ' . $target . ' does not exist.' );
						}
						$pel = wpmcp_el_find( $tree, $target );
						if ( ! wpmcp_el_accepts( $pel, (string) ( $node['elType'] ?? '' ) ) ) {
							return array( 'ok' => false, 'action' => 'move-widget', 'message' => 'Target ' . $target . ' is a ' . ( $pel['elType'] ?? '?' ) . ' and cannot hold a ' . ( $node['elType'] ?? '?' ) . '.' );
						}
					}

					$src_parent = (string) $index_all[ $element_id ]['parent'];
					$src_index  = (int) $index_all[ $element_id ]['index'];
					$source     = &wpmcp_el_children_ref( $tree, $src_parent, $found );
					if ( ! $found || ! isset( $source[ $src_index ] ) ) {
						unset( $source );
						return array( 'ok' => false, 'action' => 'move-widget', 'message' => 'Source position changed while editing; retry.' );
					}
					$moving = $source[ $src_index ];
					array_splice( $source, $src_index, 1 );
					unset( $source );

					$dest = &wpmcp_el_children_ref( $tree, $target, $found );
					if ( ! $found ) {
						unset( $dest );
						return array( 'ok' => false, 'action' => 'move-widget', 'message' => 'Target parent ' . $target . ' vanished while editing.' );
					}
					$position = isset( $input['index'] ) ? max( 0, min( (int) $input['index'], count( $dest ) ) ) : count( $dest );
					array_splice( $dest, $position, 0, array( $moving ) );
					unset( $dest );

					$written = wpmcp_el_write( $post_id, (string) wp_json_encode( $tree ), 'move-widget' );
					if ( is_wp_error( $written ) ) {
						return $fail( $written );
					}
					return array(
						'ok'         => true,
						'action'     => 'move-widget',
						'post_id'    => $post_id,
						'element_id' => $element_id,
						'parent_id'  => $target,
						'index'      => $position,
						'elements'   => $written['elements'],
						'backup'     => $written['backup'],
						'message'    => 'Moved ' . $element_id . ' to ' . ( '' === $target ? 'the page root' : $target ) . ' at ' . $position . '.',
					);

				case 'duplicate-widget':
					$element_id = (string) ( $input['element_id'] ?? '' );
					$index_all  = wpmcp_el_index( $tree );
					if ( ! isset( $index_all[ $element_id ] ) ) {
						return array( 'ok' => false, 'action' => 'duplicate-widget', 'message' => 'Element ' . $element_id . ' not found. Call get-structure.' );
					}
					$node  = wpmcp_el_find( $tree, $element_id );
					$copy  = wpmcp_el_clone( $node, $taken );
					$kids  = &wpmcp_el_children_ref( $tree, (string) $index_all[ $element_id ]['parent'], $found );
					if ( ! $found ) {
						unset( $kids );
						return array( 'ok' => false, 'action' => 'duplicate-widget', 'message' => 'Parent not found; retry.' );
					}
					$position = (int) $index_all[ $element_id ]['index'] + 1;
					array_splice( $kids, $position, 0, array( $copy ) );
					unset( $kids );

					$written = wpmcp_el_write( $post_id, (string) wp_json_encode( $tree ), 'duplicate-widget' );
					if ( is_wp_error( $written ) ) {
						return $fail( $written );
					}
					return array(
						'ok'         => true,
						'action'     => 'duplicate-widget',
						'post_id'    => $post_id,
						'element_id' => $copy['id'],
						'parent_id'  => (string) $index_all[ $element_id ]['parent'],
						'index'      => $position,
						'elements'   => $written['elements'],
						'backup'     => $written['backup'],
						'message'    => 'Copied ' . $element_id . ' to ' . $copy['id'] . ' (new ids for the whole subtree).',
					);

				case 'delete-widget':
					if ( empty( $input['confirm'] ) ) {
						return wpmcp_confirm_error( 'delete element ' . (string) ( $input['element_id'] ?? '' ) );
					}
					$element_id = (string) ( $input['element_id'] ?? '' );
					$index_all  = wpmcp_el_index( $tree );
					if ( ! isset( $index_all[ $element_id ] ) ) {
						return array( 'ok' => false, 'action' => 'delete-widget', 'message' => 'Element ' . $element_id . ' not found. Call get-structure.' );
					}
					$kids = &wpmcp_el_children_ref( $tree, (string) $index_all[ $element_id ]['parent'], $found );
					if ( ! $found ) {
						unset( $kids );
						return array( 'ok' => false, 'action' => 'delete-widget', 'message' => 'Parent not found; retry.' );
					}
					array_splice( $kids, (int) $index_all[ $element_id ]['index'], 1 );
					unset( $kids );

					$written = wpmcp_el_write( $post_id, (string) wp_json_encode( $tree ), 'delete-widget' );
					if ( is_wp_error( $written ) ) {
						return $fail( $written );
					}
					return array(
						'ok'         => true,
						'action'     => 'delete-widget',
						'post_id'    => $post_id,
						'element_id' => $element_id,
						'elements'   => $written['elements'],
						'backup'     => $written['backup'],
						'message'    => 'Deleted ' . $element_id . '. Undo with action=rollback backup=' . $written['backup'],
					);

				case 'create-page':
					$title = trim( (string) ( $input['title'] ?? '' ) );
					if ( '' === $title ) {
						return array( 'ok' => false, 'action' => 'create-page', 'message' => 'title is required.' );
					}
					$post_type = 'post' === ( $input['post_type'] ?? 'page' ) ? 'post' : 'page';
					$status    = in_array( (string) ( $input['status'] ?? 'draft' ), array( 'draft', 'publish', 'private' ), true ) ? (string) ( $input['status'] ?? 'draft' ) : 'draft';
					$new_id    = wp_insert_post(
						array(
							'post_title'   => $title,
							'post_type'    => $post_type,
							'post_status'  => $status,
							'post_content' => '',
						),
						true
					);
					if ( is_wp_error( $new_id ) ) {
						return $fail( $new_id );
					}
					if ( ! empty( $input['parent_id'] ) && is_numeric( $input['parent_id'] ) ) {
						wp_update_post( array( 'ID' => (int) $new_id, 'post_parent' => (int) $input['parent_id'] ) );
					}
					$elements = isset( $input['elements'] ) && is_array( $input['elements'] ) ? array_values( $input['elements'] ) : array();
					$seen     = array();
					$layout   = array();
					$loose    = array();
					foreach ( $elements as $node ) {
						if ( ! is_array( $node ) || empty( $node['elType'] ) || ! is_string( $node['elType'] ) ) {
							return array( 'ok' => false, 'action' => 'create-page', 'message' => 'Every entry in elements needs an elType, e.g. {"elType":"section","elements":[...]}. Omit elements to create an empty page, then use add-widget.' );
						}
						$id = (string) ( $node['id'] ?? '' );
						if ( '' === $id || isset( $seen[ $id ] ) ) {
							$id = wpmcp_el_new_id( $seen );
						}
						$seen[ $id ] = true;
						$node['id']  = $id;
						if ( ! isset( $node['settings'] ) || ! is_array( $node['settings'] ) ) {
							$node['settings'] = array();
						}
						if ( ! isset( $node['elements'] ) ) {
							$node['elements'] = array();
						}
						if ( ! is_array( $node['elements'] ) ) {
							return array( 'ok' => false, 'action' => 'create-page', 'message' => 'elements inside a node must be an array of child nodes.' );
						}
						$type = (string) $node['elType'];
						if ( in_array( $type, array( 'section', 'container' ), true ) || 0 === strpos( $type, 'e-' ) ) {
							$layout[] = $node;
						} else {
							// A raw widget at the top level is not a valid Elementor
							// document: wrap the loose nodes in section > column.
							$loose[] = $node;
						}
					}
					if ( $loose ) {
						$pair                                    = wpmcp_el_section_pair( $seen );
						$pair['section']['elements'][0]['elements'] = $loose;
						$layout[]                                = $pair['section'];
					}
					$written = wpmcp_el_write( (int) $new_id, (string) wp_json_encode( $layout ), 'create-page' );
					if ( is_wp_error( $written ) ) {
						return $fail( $written );
					}
					if ( ! empty( $input['settings'] ) && is_array( $input['settings'] ) ) {
						update_post_meta( (int) $new_id, '_elementor_page_settings', wp_slash( (array) $input['settings'] ) );
					}
					return array(
						'ok'         => true,
						'action'     => 'create-page',
						'post_id'    => (int) $new_id,
						'elements'   => $written['elements'],
						'backup'     => $written['backup'],
						'message'    => 'Created ' . $post_type . ' ' . $new_id . ' (' . $status . ') in Elementor mode. Add sections with add-widget, then open ' . admin_url( 'post.php?post=' . (int) $new_id . '&action=elementor' ),
					);

				case 'set-page-settings':
					$settings = (array) ( $input['settings'] ?? array() );
					if ( empty( $settings ) ) {
						return array( 'ok' => false, 'action' => 'set-page-settings', 'message' => 'settings is empty: give at least one page setting.' );
					}
					$current = (array) get_post_meta( $post_id, '_elementor_page_settings', true );
					$merged  = wpmcp_el_merge( $current, $settings );
					update_post_meta( $post_id, '_elementor_page_settings', wp_slash( $merged ) );
					delete_post_meta( $post_id, '_elementor_css' );
					wpmcp_el_flush( $post_id );
					return array(
						'ok'         => true,
						'action'     => 'set-page-settings',
						'post_id'    => $post_id,
						'elements'   => count( $taken ),
						'message'    => 'Page settings updated: ' . implode( ', ', array_keys( $settings ) ) . '. Common keys: template (default|elementor_canvas|elementor_header_footer), hide_title, page_layout, background_background, custom_css.',
					);

				case 'set-globals':
					if ( empty( $input['confirm'] ) ) {
						return wpmcp_confirm_error( 'change site-wide Elementor globals' );
					}
					$settings = (array) ( $input['settings'] ?? array() );
					if ( empty( $settings ) ) {
						return array( 'ok' => false, 'action' => 'set-globals', 'message' => 'settings is empty.' );
					}
					$kit = (int) get_option( 'elementor_active_kit' );
					if ( ! $kit ) {
						return array( 'ok' => false, 'action' => 'set-globals', 'message' => 'No active Elementor kit.' );
					}
					$current = (array) get_post_meta( $kit, '_elementor_page_settings', true );
					$merged  = wpmcp_el_merge( $current, $settings );
					update_post_meta( $kit, '_elementor_page_settings', wp_slash( $merged ) );
					delete_post_meta( $kit, '_elementor_css' );
					wpmcp_el_flush_all();
					return array(
						'ok'      => true,
						'action'  => 'set-globals',
						'post_id' => $kit,
						'message' => 'Kit updated: ' . implode( ', ', array_keys( $settings ) ) . '. Lists merge by _id, so send only the item you change, e.g. settings.system_colors = [{_id:"primary", color:"#0B6E4F"}].',
					);

				case 'apply-template':
					$template_id = (int) ( $input['template_id'] ?? 0 );
					if ( $template_id <= 0 || 'elementor_library' !== get_post_type( $template_id ) ) {
						return array( 'ok' => false, 'action' => 'apply-template', 'message' => 'template_id must come from list-templates (post type elementor_library).' );
					}
					$tpl = wpmcp_el_tree( $template_id );
					if ( is_wp_error( $tpl ) || empty( $tpl ) ) {
						return array( 'ok' => false, 'action' => 'apply-template', 'message' => 'That template has no Elementor layout.' );
					}
					// Templates store the same list-of-nodes shape as a page; tolerate a bare single node too.
					$nodes = ( isset( $tpl['elType'] ) && ! isset( $tpl[0] ) ) ? array( $tpl ) : $tpl;
					$clones = array();
					foreach ( $nodes as $tpl_node ) {
						$clones[] = wpmcp_el_clone( $tpl_node, $taken );
					}
					$parent = (string) ( $input['parent_id'] ?? '' );
					if ( '' !== $parent ) {
						$pel = wpmcp_el_find( $tree, $parent );
						if ( null === $pel ) {
							return array( 'ok' => false, 'action' => 'apply-template', 'message' => 'parent_id ' . $parent . ' does not exist on this page.' );
						}
						if ( 'section' === (string) ( $pel['elType'] ?? '' ) && 'column' !== (string) ( $clones[0]['elType'] ?? '' ) ) {
							$column = wpmcp_el_column_node( $taken );
							$kids   = &wpmcp_el_children_ref( $tree, $parent, $found );
							if ( $found ) {
								$kids[] = $column;
							}
							unset( $kids );
							$parent = $column['id'];
						}
						$kids = &wpmcp_el_children_ref( $tree, $parent, $found );
						if ( ! $found ) {
							unset( $kids );
							return array( 'ok' => false, 'action' => 'apply-template', 'message' => 'parent_id ' . $parent . ' does not exist on this page.' );
						}
						$position = isset( $input['index'] ) ? max( 0, min( (int) $input['index'], count( $kids ) ) ) : count( $kids );
						array_splice( $kids, $position, 0, $clones );
						unset( $kids );
					} else {
						$position = count( $tree );
						$tree     = array_merge( $tree, $clones );
					}
					$written = wpmcp_el_write( $post_id, (string) wp_json_encode( $tree ), 'apply-template' );
					if ( is_wp_error( $written ) ) {
						return $fail( $written );
					}
					return array(
						'ok'       => true,
						'action'   => 'apply-template',
						'post_id'  => $post_id,
						'elements' => $written['elements'],
						'backup'   => $written['backup'],
						'message'  => 'Inserted template ' . $template_id . ' (' . count( $clones ) . ' node(s), new ids) at ' . $position . '.',
					);

				case 'rollback':
					if ( empty( $input['confirm'] ) ) {
						return wpmcp_confirm_error( 'roll back the Elementor layout of post ' . $post_id );
					}
					$name = basename( (string) ( $input['backup'] ?? '' ) );
					if ( '' === $name ) {
						$rows = wpmcp_el_snapshots( $post_id );
						$name = (string) ( $rows[0]['backup'] ?? '' );
					}
					$dir  = wpmcp_el_snapshot_dir();
					$file = $dir . '/' . $name;
					if ( '' === $name || ! is_file( $file ) || ! preg_match( '/^\d+\.\d{8}-\d{6}\d*\./', $name ) ) {
						return array( 'ok' => false, 'action' => 'rollback', 'post_id' => $post_id, 'message' => 'Snapshot not found. Call action=snapshots first.' );
					}
					$raw = (string) file_get_contents( $file );
					if ( '' !== trim( $raw ) && ! is_array( json_decode( $raw, true ) ) ) {
						return array( 'ok' => false, 'action' => 'rollback', 'message' => 'That snapshot is not valid JSON; refusing to write it.' );
					}
					if ( '' === trim( $raw ) ) {
						$keep = wpmcp_el_snapshot( $post_id, (string) get_post_meta( $post_id, '_elementor_data', true ), 'pre-rollback' );
						delete_post_meta( $post_id, '_elementor_data' );
						delete_post_meta( $post_id, '_elementor_css' );
						wpmcp_el_flush( $post_id );
						return array(
							'ok'      => true,
							'action'  => 'rollback',
							'post_id' => $post_id,
							'elements'=> 0,
							'backup'  => is_wp_error( $keep ) ? '' : (string) $keep,
							'message' => 'Restored the empty pre-builder state of post ' . $post_id . '. The layout it replaced is saved as ' . ( is_wp_error( $keep ) ? '(snapshot failed)' : $keep ) . '.',
						);
					}
					$written = wpmcp_el_write( $post_id, $raw, 'rollback' );
					if ( is_wp_error( $written ) ) {
						return $fail( $written );
					}
					return array(
						'ok'       => true,
						'action'   => 'rollback',
						'post_id'  => $post_id,
						'elements' => $written['elements'],
						'backup'   => $written['backup'],
						'message'  => 'Rolled post ' . $post_id . ' back to ' . $name . '. The version it replaced is saved as ' . $written['backup'] . '.',
					);
			}

			return array( 'ok' => false, 'message' => 'Unknown action "' . $action . '".' );
		},
		'permission_callback' => $admin_gate,
		'meta'                => $annotations( 'WP Elementor Builder', false, true, false ),
	) );

	// ---------------------------------------------------------------------
	// 5. Fetch rendered page HTML.
	// ---------------------------------------------------------------------
	wp_register_ability( 'wpmcp/fetch-page', array(
		'label'        => 'Fetch Page HTML',
		'description'  => 'Download the rendered HTML of any public http(s) URL (first ~60 KB returned, full size reported). Use it to verify front-end output after edits, inspect canonical/meta tags, or read a page before changing it.',
		'category'     => 'wpmcp',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'url' => array( 'type' => 'string', 'description' => 'Absolute http(s) URL.' ),
			),
			'required'   => array( 'url' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'ok'           => array( 'type' => 'boolean' ),
				'status'       => array( 'type' => 'integer' ),
				'content_type' => array( 'type' => 'string' ),
				'bytes'        => array( 'type' => 'integer' ),
				'html'         => array( 'type' => 'string' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$url   = (string) ( $input['url'] ?? '' );
			$guard = wpmcp_check_url( $url );
			if ( is_wp_error( $guard ) ) {
				return array( 'ok' => false, 'status' => 0, 'content_type' => '', 'bytes' => 0, 'html' => $guard->get_error_message() );
			}
			$response = wp_remote_get(
				$url,
				array(
					'timeout'            => 25,
					'redirection'        => 5,
					'user-agent'         => 'Mozilla/5.0 (wp-mcp)',
					'reject_unsafe_urls' => ! wpmcp_url_allows_private( $url ),
				)
			);
			if ( is_wp_error( $response ) ) {
				return array( 'ok' => false, 'status' => 0, 'content_type' => '', 'bytes' => 0, 'html' => $response->get_error_message() );
			}
			$body = (string) wp_remote_retrieve_body( $response );
			return array(
				'ok'           => true,
				'status'       => (int) wp_remote_retrieve_response_code( $response ),
				'content_type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
				'bytes'        => strlen( $body ),
				'html'         => mb_substr( $body, 0, 60000 ),
			);
		},
		'permission_callback' => $admin_gate,
		'meta'                => $annotations( 'WP Fetch Page', true, false, false, true ),
	) );

	// ---------------------------------------------------------------------
	// 6. Media import from URL.
	// ---------------------------------------------------------------------
	wp_register_ability( 'wpmcp/media-import', array(
		'label'        => 'Import Media from URL',
		'description'  => 'Download an image/file from an http(s) URL into the Media Library. Returns attachment id, URL and mime type. Optional title and alt text. Max 25 MB. WordPress generates the registered thumbnail sizes.',
		'category'     => 'wpmcp',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'url'   => array( 'type' => 'string', 'description' => 'Absolute http(s) file URL ending in a real filename.' ),
				'title' => array( 'type' => 'string', 'description' => 'Optional attachment title.' ),
				'alt'   => array( 'type' => 'string', 'description' => 'Optional alt text.' ),
			),
			'required'   => array( 'url' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'ok'   => array( 'type' => 'boolean' ),
				'id'   => array( 'type' => 'integer' ),
				'url'  => array( 'type' => 'string' ),
				'mime' => array( 'type' => 'string' ),
			),
		),
		'execute_callback' => function ( $input ) {
			if ( wpmcp_is_readonly() ) {
				return array( 'ok' => false, 'id' => 0, 'url' => '', 'mime' => 'Read-only mode is on (WPMCP_READONLY): media import is disabled.' );
			}
			$url = (string) ( $input['url'] ?? '' );
			$guard = wpmcp_check_url( $url );
			if ( is_wp_error( $guard ) ) {
				return array( 'ok' => false, 'id' => 0, 'url' => '', 'mime' => $guard->get_error_message() );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$tmp = download_url( $url, 30 );
			if ( is_wp_error( $tmp ) ) {
				return array( 'ok' => false, 'id' => 0, 'url' => '', 'mime' => $tmp->get_error_message() );
			}
			$size = (int) @filesize( $tmp );
			if ( $size > 26214400 ) {
				@unlink( $tmp );
				return array( 'ok' => false, 'id' => 0, 'url' => '', 'mime' => 'File is ' . round( $size / 1048576, 1 ) . ' MB; the limit is 25 MB.' );
			}
			$name = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			if ( '' === $name || '.' === $name ) {
				$name = 'import-' . gmdate( 'Ymd-His' );
			}
			$file = array(
				'name'     => $name,
				'tmp_name' => $tmp,
			);
			$id = media_handle_sideload( $file, 0, (string) ( $input['title'] ?? '' ) );
			if ( is_wp_error( $id ) ) {
				@unlink( $tmp );
				return array( 'ok' => false, 'id' => 0, 'url' => '', 'mime' => $id->get_error_message() );
			}
			if ( ! empty( $input['alt'] ) ) {
				update_post_meta( (int) $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt'] ) );
			}
			return array(
				'ok'   => true,
				'id'   => (int) $id,
				'url'  => (string) wp_get_attachment_url( $id ),
				'mime' => (string) get_post_mime_type( $id ),
			);
		},
		'permission_callback' => $admin_gate,
		'meta'                => $annotations( 'WP Media Import', false, false, false, true ),
	) );

	// ---------------------------------------------------------------------
	// 7. PageSpeed Insights audit.
	// ---------------------------------------------------------------------
	wp_register_ability( 'wpmcp/audit-url', array(
		'label'        => 'PageSpeed Audit',
		'description'  => 'Audit a page for performance. Tries Google PageSpeed Insights (Lighthouse) first, which may take 20-60s, and returns performance/accessibility/best-practices/SEO scores plus the top opportunities. Without an API key Google often rejects the shared anonymous quota (HTTP 429); a free key lifts that. If PageSpeed is unavailable it falls back to a local single-fetch audit: payload size, server response time, stylesheet/script/image counts, images missing alt text, plus concrete findings. Strategy: mobile (default) or desktop.',
		'category'     => 'wpmcp',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'url'      => array( 'type' => 'string', 'description' => 'Absolute http(s) URL to audit.' ),
				'strategy' => array( 'type' => 'string', 'enum' => array( 'mobile', 'desktop' ), 'description' => 'Default mobile.' ),
			),
			'required'   => array( 'url' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'ok'            => array( 'type' => 'boolean' ),
				'source'        => array( 'type' => 'string', 'description' => 'pagespeed when Google answered, none when only local metrics are available.' ),
				'scores'        => array( 'description' => 'Map of category => 0-100.' ),
				'opportunities' => array( 'description' => 'Top improvements.' ),
				'local'         => array( 'description' => 'Local fallback metrics (http_status, html_bytes, ttfb_ms, asset counts, findings) when PageSpeed is unavailable.' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$url   = (string) ( $input['url'] ?? '' );
			$guard = wpmcp_check_url( $url );
			if ( is_wp_error( $guard ) ) {
				return array( 'ok' => false, 'source' => 'none', 'scores' => array(), 'opportunities' => array( array( 'error' => $guard->get_error_message() ) ) );
			}
			$strategy = ( 'desktop' === ( $input['strategy'] ?? '' ) ) ? 'desktop' : 'mobile';
			$endpoint = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=' . rawurlencode( $url ) . '&strategy=' . $strategy . '&category=performance&category=accessibility&category=best-practices&category=seo';
			$key      = wpmcp_pagespeed_key();
			if ( '' !== $key ) {
				$endpoint .= '&key=' . rawurlencode( $key );
			}

			// Two attempts at most: PageSpeed is slow, and a 429 is a quota verdict,
			// not a blip. Only transport errors and 5xx are retried.
			$attempts = 0;
			$last     = '';
			$data     = null;
			while ( $attempts < 2 ) {
				++$attempts;
				$response = wp_remote_get( $endpoint, array( 'timeout' => 70 ) );
				if ( is_wp_error( $response ) ) {
					$last = 'PageSpeed request failed: ' . $response->get_error_message();
					sleep( 2 );
					continue;
				}
				$code    = (int) wp_remote_retrieve_response_code( $response );
				$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
				if ( 200 === $code && is_array( $decoded ) ) {
					$data = $decoded;
					break;
				}
				$last = 'PageSpeed API HTTP ' . $code;
				if ( is_array( $decoded ) && isset( $decoded['error']['message'] ) ) {
					$last .= ': ' . substr( (string) $decoded['error']['message'], 0, 200 );
				}
				if ( $code < 500 ) {
					break;
				}
				sleep( 2 );
			}

			if ( null === $data ) {
				$hint = '';
				if ( '' === $key ) {
					$hint = ' No API key configured, so Google applies the shared anonymous IP quota. Add a free key: define( \'WPMCP_PAGESPEED_API_KEY\', \'...\' ) in wp-config.php, or save the option wpmcp_pagespeed_api_key.';
				}
				$local = wpmcp_local_audit( $url );
				return array(
					'ok'            => false,
					'source'        => 'none',
					'scores'        => array(),
					'opportunities' => array( array( 'error' => $last . $hint ) ),
					'local'         => $local,
				);
			}

			$scores = array();
			foreach ( (array) ( $data['categories'] ?? array() ) as $key => $cat ) {
				if ( isset( $cat['score'] ) ) {
					$scores[ $key ] = (int) round( $cat['score'] * 100 );
				}
			}
			$audits = array();
			foreach ( (array) ( $data['lighthouseResult']['categories'] ?? array() ) as $cat ) {
				foreach ( (array) ( $cat['auditRefs'] ?? array() ) as $ref ) {
					$audits[ $ref['id'] ] = true;
				}
			}
			$opportunities = array();
			foreach ( array_keys( $audits ) as $audit_id ) {
				$audit = $data['lighthouseResult']['audits'][ $audit_id ] ?? null;
				if ( ! is_array( $audit ) || empty( $audit['details']['overallSavingsMs'] ) || $audit['details']['overallSavingsMs'] < 100 ) {
					continue;
				}
				$opportunities[] = array(
					'title'      => (string) ( $audit['title'] ?? $audit_id ),
					'savings_ms' => (int) $audit['details']['overallSavingsMs'],
				);
			}
			usort( $opportunities, function ( $a, $b ) {
				return $b['savings_ms'] <=> $a['savings_ms'];
			} );
			return array( 'ok' => true, 'source' => 'pagespeed', 'scores' => $scores, 'opportunities' => array_slice( $opportunities, 0, 6 ) );
		},
'permission_callback' => $admin_gate,
		'meta'                => $annotations( 'WP PageSpeed Audit', true, false, false, true ),
	) );

	// ---------------------------------------------------------------------
	// 8. Skills: list.
	// ---------------------------------------------------------------------
	wp_register_ability( 'wpmcp/list-skills', array(
		'label'        => 'List Skills',
		'description'  => 'List the WordPress/WooCommerce/Elementor skill playbooks (bundled with the plugin plus any written on this site) with slug, title, category, source and summary. Load one with wpmcp/get-skill before starting a matching task. Optionally filter by category or a keyword.',
		'category'     => 'wpmcp',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'category' => array( 'type' => 'string', 'description' => 'Optional exact category, e.g. "WooCommerce".' ),
				'query'    => array( 'type' => 'string', 'description' => 'Optional keyword filter over slug, title and summary.' ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'skills' => array( 'description' => 'slug => {title, category, summary, source}' ),
				'count'  => array( 'type' => 'integer' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$category = strtolower( trim( (string) ( $input['category'] ?? '' ) ) );
			$query    = strtolower( trim( (string) ( $input['query'] ?? '' ) ) );
			$out      = array();
			foreach ( wpmcp_all_skills() as $slug => $skill ) {
				if ( '' !== $category && strtolower( $skill['category'] ) !== $category ) {
					continue;
				}
				if ( '' !== $query && false === strpos( strtolower( $slug . ' ' . $skill['title'] . ' ' . $skill['summary'] ), $query ) ) {
					continue;
				}
				$out[ $slug ] = $skill;
			}
			return array( 'skills' => $out, 'count' => count( $out ) );
		},
		'permission_callback' => $admin_gate,
		'meta'                => $annotations( 'WP List Skills', true, false, true ),
	) );

	// ---------------------------------------------------------------------
	// 9. Skills: get.
	// ---------------------------------------------------------------------
	wp_register_ability( 'wpmcp/get-skill', array(
		'label'        => 'Get Skill',
		'description'  => 'Return the full markdown playbook for one skill slug (see wpmcp/list-skills). A site playbook shadows a bundled one with the same slug. Do not guess slugs.',
		'category'     => 'wpmcp',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'slug' => array( 'type' => 'string', 'description' => 'Skill slug without .md, e.g. elementor-editing.' ),
			),
			'required'   => array( 'slug' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'ok'      => array( 'type' => 'boolean' ),
				'slug'    => array( 'type' => 'string' ),
				'content' => array( 'type' => 'string' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$slug = preg_replace( '/[^a-z0-9\-_]/i', '', (string) ( $input['slug'] ?? '' ) );
			$file = wpmcp_skill_file( $slug );
			if ( '' === $slug || '' === $file ) {
				return array( 'ok' => false, 'slug' => $slug, 'content' => 'Skill not found. Call wpmcp/list-skills first.' );
			}
			return array( 'ok' => true, 'slug' => $slug, 'content' => (string) file_get_contents( $file ) );
		},
		'permission_callback' => $admin_gate,
		'meta'                => $annotations( 'WP Get Skill', true, false, true ),
	) );

	// ---------------------------------------------------------------------
	// 10. Skills: keyword search across bodies.
	// ---------------------------------------------------------------------
	wp_register_ability( 'wpmcp/search-skills', array(
		'label'        => 'Search Skills',
		'description'  => 'Keyword-search the full text of every skill (bundled and site-written) and return the best matching slugs, ranked by how much of the query they cover, with a snippet around the first hit. If the library does not cover a term (a plugin with no playbook), it returns no results and a hint instead of loosely related playbooks. Use it when you are unsure which playbook covers a task.',
		'category'     => 'wpmcp',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'query'    => array( 'type' => 'string', 'description' => 'Words to look for, e.g. "stock status" or "canonical".' ),
				'limit'    => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'description' => 'Max results, default 8.' ),
			),
			'required'   => array( 'query' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'results'   => array( 'description' => 'slug, title, category, score, matched, coverage, snippet.' ),
				'terms'     => array( 'description' => 'Query terms after stopword removal.' ),
				'uncovered' => array( 'description' => 'Terms no playbook mentions; when present, results is empty.' ),
				'hint'      => array( 'description' => 'What to do when nothing matched.' ),
			),
		),
		'execute_callback' => function ( $input ) {
			return wpmcp_search_skills(
				(string) ( $input['query'] ?? '' ),
				(int) ( $input['limit'] ?? 8 )
			);
		},
		'permission_callback' => $admin_gate,
		'meta'                => $annotations( 'WP Search Skills', true, false, true ),
	) );

	// ---------------------------------------------------------------------
	// 11. Agent context: one call that orients a new agent.
	// ---------------------------------------------------------------------
	wp_register_ability( 'wpmcp/agent-context', array(
		'label'        => 'Agent Context',
		'description'  => 'Start here. One orientation payload for this site: WordPress/PHP/database versions, theme, active plugins, read-only state, health warnings, every wpmcp ability with when to use it, and the skill index (slug, title, category, summary). Call this first in a new session, then wpmcp/get-skill for the playbook that matches the task.',
		'category'     => 'wpmcp',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'include_skills'    => array( 'type' => 'boolean', 'description' => 'Include the skill index. Default true.' ),
				'include_abilities' => array( 'type' => 'boolean', 'description' => 'Include the ability catalog. Default true.' ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'ok'         => array( 'type' => 'boolean' ),
				'readonly'   => array( 'type' => 'boolean' ),
				'site'       => array( 'description' => 'Versions, URLs, theme, locale, permalinks, builder state.' ),
				'stack'      => array( 'description' => 'Active plugins with name and version.' ),
				'abilities'  => array( 'description' => 'Ability name => label, when to use, readonly, destructive.' ),
				'skills'     => array( 'description' => 'slug => title, category, summary, source.' ),
				'warnings'   => array( 'description' => 'Cheap problems worth knowing before editing.' ),
				'guardrails' => array( 'description' => 'Operating rules for this site.' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$with_skills    = ! isset( $input['include_skills'] ) || ! empty( $input['include_skills'] );
			$with_abilities = ! isset( $input['include_abilities'] ) || ! empty( $input['include_abilities'] );
			global $wpdb;

			$theme_name    = '';
			$theme_version = '';
			$theme_parent  = '';
			if ( function_exists( 'wp_get_theme' ) ) {
				$theme         = wp_get_theme();
				$theme_name    = (string) $theme->get( 'Name' );
				$theme_version = (string) $theme->get( 'Version' );
				$parent        = $theme->parent();
				$theme_parent  = $parent ? (string) $parent->get( 'Name' ) : '';
			}

			$stack = array();
			if ( ! function_exists( 'get_plugin_data' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			if ( function_exists( 'get_plugin_data' ) ) {
				foreach ( wpmcp_active_plugins() as $file ) {
					$path = WP_PLUGIN_DIR . '/' . $file;
					if ( ! is_file( $path ) ) {
						continue;
					}
					$data    = get_plugin_data( $path, false, false );
					$stack[] = array(
						'file'    => $file,
						'name'    => (string) ( $data['Name'] ?? $file ),
						'version' => (string) ( $data['Version'] ?? '' ),
					);
				}
			}

			$updates  = wpmcp_pending_updates();
			$warnings = array();

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY ) ) {
				$warnings[] = 'WP_DEBUG + WP_DEBUG_DISPLAY are on: PHP notices are printed into page HTML. Turn display off on a live site.';
			}
			if ( ! defined( 'DISALLOW_FILE_EDIT' ) || ! DISALLOW_FILE_EDIT ) {
				$warnings[] = 'DISALLOW_FILE_EDIT is not set: the wp-admin plugin/theme editor can write PHP.';
			}
			if ( $updates['core'] > 0 || $updates['plugins'] > 0 || $updates['themes'] > 0 ) {
				$warnings[] = 'Updates pending: core ' . $updates['core'] . ', plugins ' . $updates['plugins'] . ', themes ' . $updates['themes'] . '. Run wpmcp/site-health for the list.';
			}
			if ( ! wpmcp_has_backup_plugin() ) {
				$warnings[] = 'No known backup plugin is active. Take a database export (wpmcp/wpcli: db export) before destructive work.';
			}
			if ( ! wpmcp_wpcli_enabled() ) {
				$warnings[] = 'WP-CLI is opt-in and currently off: wpmcp/wpcli is not registered. Use wpmcp/rest-request and wpmcp/files, or ask the administrator to set define( \'WPMCP_ENABLE_WPCLI\', true ) in wp-config.php.';
			}
			$log_path = WP_CONTENT_DIR . '/debug.log';
			if ( is_file( $log_path ) && filesize( $log_path ) > 1048576 ) {
				$warnings[] = 'wp-content/debug.log is ' . round( filesize( $log_path ) / 1048576, 1 ) . ' MB — read the tail with wpmcp/site-health sections=["errors"].';
			}
			if ( ! wp_using_ext_object_cache() ) {
				$warnings[] = 'No persistent object cache. Heavy WooCommerce/Elementor queries hit MySQL every request.';
			}

			$site = array(
				'name'                 => (string) get_option( 'blogname', '' ),
				'url'                  => (string) home_url( '/' ),
				'site_url'             => (string) site_url( '/' ),
				'wp_version'           => (string) get_bloginfo( 'version' ),
				'php_version'          => PHP_VERSION,
				'db_version'           => method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '',
				'multisite'            => is_multisite(),
				'language'             => (string) get_option( 'WPLANG', 'en_US' ),
				'timezone'             => (string) get_option( 'timezone_string', '' ),
				'permalink_structure'  => (string) get_option( 'permalink_structure', '' ),
				'https'                => is_ssl(),
				'theme'                => array( 'name' => $theme_name, 'version' => $theme_version, 'parent' => $theme_parent ),
				'active_plugins'       => count( wpmcp_active_plugins() ),
				'pending_updates'      => $updates,
				'readonly'             => wpmcp_is_readonly(),
				'wpcli'                => wpmcp_wpcli_enabled() ? 'enabled' : 'disabled (opt-in: WPMCP_ENABLE_WPCLI)',
			);
			if ( defined( 'ELEMENTOR_VERSION' ) ) {
				$site['elementor'] = array( 'version' => ELEMENTOR_VERSION );
			}
			if ( class_exists( 'WooCommerce' ) && defined( 'WC_VERSION' ) ) {
				$site['woocommerce'] = array(
					'version'  => WC_VERSION,
					'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
				);
			}

			$catalog = array();
			if ( $with_abilities && function_exists( 'wp_get_ability' ) ) {
				foreach ( wpmcp_ability_names() as $name ) {
					$ability = wp_get_ability( $name );
					if ( ! $ability ) {
						continue;
					}
					$meta        = method_exists( $ability, 'get_meta' ) ? (array) $ability->get_meta() : array();
					$description = method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '';
					$first       = preg_split( '/(?<=\.)\s/', $description );
					$catalog[ $name ] = array(
						'label'       => method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : $name,
						'when'        => trim( (string) ( $first[0] ?? '' ) ),
						'readonly'    => (bool) ( $meta['annotations']['readonly'] ?? false ),
						'destructive' => (bool) ( $meta['annotations']['destructive'] ?? false ),
					);
				}
			}

			$skills = array();
			if ( $with_skills ) {
				foreach ( wpmcp_all_skills() as $slug => $skill ) {
					$skills[ $slug ] = array(
						'title'    => $skill['title'],
						'category' => $skill['category'],
						'summary'  => mb_substr( $skill['summary'], 0, 160 ),
					);
				}
			}

			return array(
				'ok'         => true,
				'readonly'   => wpmcp_is_readonly(),
				'site'       => $site,
				'stack'      => $stack,
				'abilities'  => $catalog,
				'skills'     => $skills,
				'warnings'   => $warnings,
				'guardrails' => array(
					'Load a skill playbook with wpmcp/get-skill before editing a domain you have not touched in this session.',
					'If wpmcp/search-skills finds no playbook, do not improvise from a near match: continue with the core abilities, take a backup before writing, verify the result, then save the lesson with wpmcp/skill-write so the next agent starts with it.',
					'Export the database with wpmcp/wpcli "db export" (when WP-CLI is enabled) before schema, plugin or bulk content changes.',
					'Prefer wpmcp/rest-request for CRUD; use wpmcp/wpcli, when enabled, for what REST cannot reach (menus, widgets, options, transients, search-replace).',
					'Every wpmcp/files write and delete keeps a rollback copy: list them with wpmcp/restore action=list.',
					'Verify front-end output with wpmcp/fetch-page after any change, and purge LiteSpeed (wpmcp/wpcli "litespeed-purge all" when enabled).',
					'Never store credentials in content, options, files or logs. The audit log records ability calls, not secrets.',
				),
			);
		},
		'permission_callback' => $admin_gate,
		'meta'                => $annotations( 'WP Agent Context', true, false, true ),
	) );

	// ---------------------------------------------------------------------
	// 12. Site health: scored, structured diagnostics.
	// ---------------------------------------------------------------------
	wp_register_ability( 'wpmcp/site-health', array(
		'label'        => 'Site Health',
		'description'  => 'Structured diagnostics with a 0-100 score. Sections: overview (versions, PHP limits, disk, autoload size, object cache, permalinks), updates (core/plugin/theme pending), security (debug flags, file editor, admin accounts, XML-RPC, wp-config permissions, PHP files in uploads), errors (debug.log and PHP error_log tails), cron (late events, next runs), plugins (active/inactive, drop-ins). Use before an audit or after an incident; read-only.',
		'category'     => 'wpmcp',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'sections'  => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string', 'enum' => array( 'overview', 'updates', 'security', 'errors', 'cron', 'plugins' ) ),
					'description' => 'Sections to compute. Default: all.',
				),
				'log_lines' => array( 'type' => 'integer', 'minimum' => 5, 'maximum' => 200, 'description' => 'Tail length for logs, default 40.' ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'ok'       => array( 'type' => 'boolean' ),
				'score'    => array( 'type' => 'integer' ),
				'grade'    => array( 'type' => 'string' ),
				'checks'   => array( 'description' => 'id, status (good|warn|fail|info), label, detail.' ),
				'findings' => array( 'description' => 'Human-readable list of what to fix, most severe first.' ),
				'sections' => array( 'description' => 'Raw per-section data.' ),
			),
		),
		'execute_callback' => function ( $input ) {
			global $wpdb;
			$all      = array( 'overview', 'updates', 'security', 'errors', 'cron', 'plugins' );
			$wanted   = isset( $input['sections'] ) && is_array( $input['sections'] ) ? array_values( array_intersect( $all, $input['sections'] ) ) : $all;
			$lines    = min( 200, max( 5, (int) ( $input['log_lines'] ?? 40 ) ) );
			$score    = 100;
			$checks   = array();
			$findings = array();
			$data     = array();

			$check = function ( $id, $status, $label, $detail, $deduct = 0 ) use ( &$checks, &$score, &$findings ) {
				$checks[] = array( 'id' => $id, 'status' => $status, 'label' => $label, 'detail' => $detail );
				if ( $deduct > 0 && in_array( $status, array( 'warn', 'fail' ), true ) ) {
					$score -= $deduct;
				}
				if ( 'fail' === $status || 'warn' === $status ) {
					$findings[] = $label . ': ' . $detail;
				}
			};

			$tail = function ( $file ) use ( $lines ) {
				if ( ! $file || ! is_file( $file ) || ! is_readable( $file ) ) {
					return array();
				}
				$rows = @file( $file, FILE_IGNORE_NEW_LINES );
				if ( ! is_array( $rows ) ) {
					return array();
				}
				return array_map( function ( $row ) {
					return mb_substr( (string) $row, 0, 300 );
				}, array_slice( $rows, -1 * $lines ) );
			};

			if ( in_array( 'overview', $wanted, true ) ) {
				$memory  = (string) ini_get( 'memory_limit' );
				$free    = @disk_free_space( ABSPATH );
				$total   = @disk_total_space( ABSPATH );
				$autoload = (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on')" );
				$data['overview'] = array(
					'wp_version'          => (string) get_bloginfo( 'version' ),
					'php_version'         => PHP_VERSION,
					'db_version'          => method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '',
					'server'              => (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ),
					'memory_limit'        => $memory,
					'max_execution_time'  => (string) ini_get( 'max_execution_time' ),
					'upload_max_filesize' => (string) ini_get( 'upload_max_filesize' ),
					'post_max_size'       => (string) ini_get( 'post_max_size' ),
					'disk_free_mb'        => false === $free ? null : (int) round( $free / 1048576 ),
					'disk_total_mb'       => false === $total ? null : (int) round( $total / 1048576 ),
					'autoloaded_options_kb' => (int) round( $autoload / 1024 ),
					'object_cache'        => wp_using_ext_object_cache() ? 'persistent' : 'none',
					'permalink_structure' => (string) get_option( 'permalink_structure', '' ),
					'https'               => is_ssl(),
					'multisite'           => is_multisite(),
				);
				$check( 'wp_version', 'info', 'WordPress', (string) get_bloginfo( 'version' ) . ' on PHP ' . PHP_VERSION );
				if ( $autoload > 1048576 ) {
					$check( 'autoload', 'warn', 'Autoloaded options', round( $autoload / 1024 ) . ' KB — over 1 MB slows every request; audit with wpmcp/wpcli "option list --autoload=on --fields=option_name,size"', 5 );
				} else {
					$check( 'autoload', 'good', 'Autoloaded options', round( $autoload / 1024 ) . ' KB' );
				}
				if ( false !== $free && $free < 500 * 1048576 ) {
					$check( 'disk', 'fail', 'Free disk space', round( $free / 1048576 ) . ' MB left', 10 );
				}
				if ( ! wp_using_ext_object_cache() ) {
					$check( 'object_cache', 'info', 'Object cache', 'No persistent object cache' );
				}
			}

			if ( in_array( 'updates', $wanted, true ) ) {
				$updates = wpmcp_pending_updates();
				$data['updates'] = $updates;
				$check( 'updates_core', $updates['core'] ? 'fail' : 'good', 'Core updates', $updates['core'] . ' pending', 5 );
				$check( 'updates_plugins', $updates['plugins'] ? 'warn' : 'good', 'Plugin updates', $updates['plugins'] ? implode( ', ', array_slice( $updates['plugin_names'], 0, 12 ) ) : 'none', 5 );
				$check( 'updates_themes', $updates['themes'] ? 'warn' : 'good', 'Theme updates', (string) $updates['themes'], 0 );
			}

			if ( in_array( 'security', $wanted, true ) ) {
				$admins = function_exists( 'get_users' ) ? get_users( array( 'role' => 'administrator', 'fields' => array( 'ID', 'user_login' ), 'number' => 20 ) ) : array();
				$config = ABSPATH . 'wp-config.php';
				$perms  = is_file( $config ) ? ( fileperms( $config ) & 0777 ) : 0;
				$upload = wp_upload_dir();
				$ubase  = (string) ( $upload['basedir'] ?? ( WP_CONTENT_DIR . '/uploads' ) );
				$php_in_uploads = 0;
				if ( is_dir( $ubase ) ) {
					try {
						$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $ubase, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY );
						$seen  = 0;
						foreach ( $it as $file ) {
							if ( ++$seen > 3000 ) {
								break;
							}
							if ( $file->isFile() && preg_match( '/\.(php|phtml|phar)$/i', $file->getFilename() ) ) {
								++$php_in_uploads;
							}
						}
					} catch ( Exception $e ) {
						$php_in_uploads = -1;
					}
				}
				$data['security'] = array(
					'wp_debug'            => defined( 'WP_DEBUG' ) && WP_DEBUG,
					'wp_debug_display'    => ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY,
					'wp_debug_log'        => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
					'disallow_file_edit'  => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
					'force_ssl_admin'     => defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN,
					'xmlrpc_enabled'      => (bool) apply_filters( 'xmlrpc_enabled', true ),
					'wp_config_perms'     => $perms ? substr( sprintf( '%o', $perms ), -4 ) : '',
					'wp_config_writable'  => $perms ? (bool) ( $perms & 0022 ) : false,
					'administrators'      => array_map(
						function ( $user ) {
							return is_object( $user ) ? (string) ( $user->user_login ?? '' ) : (string) $user;
						},
						(array) $admins
					),
					'default_admin_login' => false,
					'php_files_in_uploads' => $php_in_uploads,
				);
				foreach ( (array) $admins as $admin ) {
					if ( isset( $admin->user_login ) && 'admin' === $admin->user_login ) {
						$data['security']['default_admin_login'] = true;
					}
				}
				$debug_display_risk = $data['security']['wp_debug'] && $data['security']['wp_debug_display'];
				$check( 'debug_display', $debug_display_risk ? 'warn' : 'good', 'Debug display', $debug_display_risk ? 'PHP notices can print into HTML' : ( $data['security']['wp_debug'] ? 'off' : 'WP_DEBUG is off' ), 5 );
				$check( 'file_edit', $data['security']['disallow_file_edit'] ? 'good' : 'warn', 'File editor', $data['security']['disallow_file_edit'] ? 'disabled' : 'enabled — define DISALLOW_FILE_EDIT', 5 );
				$check( 'xmlrpc', $data['security']['xmlrpc_enabled'] ? 'warn' : 'good', 'XML-RPC', $data['security']['xmlrpc_enabled'] ? 'enabled — common brute-force target' : 'disabled', 3 );
				if ( $data['security']['wp_config_writable'] ) {
					$check( 'wp_config_perms', 'fail', 'wp-config.php permissions', $data['security']['wp_config_perms'] . ' — group/world writable', 10 );
				} else {
					$check( 'wp_config_perms', 'good', 'wp-config.php permissions', $data['security']['wp_config_perms'] ?: 'unreadable' );
				}
				if ( $php_in_uploads > 0 ) {
					$check( 'uploads_php', 'fail', 'PHP in uploads', $php_in_uploads . ' executable file(s) — possible backdoor', 15 );
				} else {
					$check( 'uploads_php', 'good', 'PHP in uploads', $php_in_uploads < 0 ? 'not scanned' : 'none found' );
				}
				if ( $data['security']['default_admin_login'] ) {
					$check( 'admin_login', 'warn', 'Admin username', 'A user named "admin" exists — rename it', 5 );
				}
				if ( count( (array) $admins ) > 3 ) {
					$check( 'admin_count', 'warn', 'Administrators', count( (array) $admins ) . ' accounts — least privilege', 3 );
				}
				if ( ! wpmcp_has_backup_plugin() ) {
					$check( 'backup_plugin', 'warn', 'Backups', 'No known backup plugin active', 5 );
				}
			}

			if ( in_array( 'errors', $wanted, true ) ) {
				$debug    = WP_CONTENT_DIR . '/debug.log';
				$php_log  = (string) ini_get( 'error_log' );
				$php_log  = ( '' !== $php_log && ! preg_match( '#^/#', $php_log ) ) ? ABSPATH . $php_log : $php_log;
				$data['errors'] = array(
					'debug_log' => array(
						'path'  => is_file( $debug ) ? 'wp-content/debug.log' : '',
						'bytes' => is_file( $debug ) ? (int) filesize( $debug ) : 0,
						'tail'  => $tail( $debug ),
					),
					'php_error_log' => array(
						'path'  => ( $php_log && is_file( $php_log ) ) ? $php_log : '',
						'bytes' => ( $php_log && is_file( $php_log ) ) ? (int) filesize( $php_log ) : 0,
						'tail'  => $tail( $php_log ),
					),
				);
				if ( is_file( $debug ) && filesize( $debug ) > 5242880 ) {
					$check( 'debug_log_size', 'warn', 'debug.log size', round( filesize( $debug ) / 1048576, 1 ) . ' MB', 3 );
				}
			}

			if ( in_array( 'cron', $wanted, true ) ) {
				$cron   = function_exists( '_get_cron_array' ) ? (array) _get_cron_array() : array();
				$total  = 0;
				$late   = 0;
				$now    = time();
				$next   = array();
				foreach ( $cron as $timestamp => $hooks ) {
					foreach ( (array) $hooks as $hook => $events ) {
						$total += count( (array) $events );
						if ( $timestamp < $now - 900 ) {
							$late += count( (array) $events );
						}
						if ( $timestamp >= $now && count( $next ) < 5 ) {
							$next[] = array( 'hook' => $hook, 'in_seconds' => $timestamp - $now );
						}
					}
				}
				usort( $next, function ( $a, $b ) {
					return $a['in_seconds'] <=> $b['in_seconds'];
				} );
				$data['cron'] = array( 'events' => $total, 'late' => $late, 'next' => array_slice( $next, 0, 5 ) );
				$check( 'cron_late', $late > 20 ? 'warn' : 'good', 'Overdue cron events', (string) $late, $late > 20 ? 5 : 0 );
			}

			if ( in_array( 'plugins', $wanted, true ) ) {
				$active   = wpmcp_active_plugins();
				$drop_ins = array();
				foreach ( array( 'object-cache.php', 'advanced-cache.php', 'db.php', 'maintenance.php', 'sunrise.php' ) as $drop_in ) {
					if ( is_file( WP_CONTENT_DIR . '/' . $drop_in ) ) {
						$drop_ins[] = $drop_in;
					}
				}
				$data['plugins'] = array(
					'active'   => count( $active ),
					'drop_ins' => $drop_ins,
					'list'     => array_values( $active ),
				);
			}

			$score = max( 0, min( 100, $score ) );
			$grade = $score >= 90 ? 'A' : ( $score >= 75 ? 'B' : ( $score >= 60 ? 'C' : ( $score >= 40 ? 'D' : 'F' ) ) );

			return array(
				'ok'       => true,
				'score'    => $score,
				'grade'    => $grade,
				'checks'   => $checks,
				'findings' => array_values( array_unique( $findings ) ),
				'sections' => $data,
			);
		},
		'permission_callback' => $admin_gate,
		'meta'                => $annotations( 'WP Site Health', true, false, true ),
	) );

	// ---------------------------------------------------------------------
	// 13. Skill write: let the agent extend the site's own playbook library.
	// ---------------------------------------------------------------------
	wp_register_ability( 'wpmcp/skill-write', array(
		'label'        => 'Write Skill',
		'description'  => 'Create, update or delete a skill playbook in the site\'s own library (wp-content/wpmcp-skills, which survives plugin updates), so what an agent learns here stays here for every future client. A site playbook shadows a bundled one with the same slug. action=save takes slug, title, category, summary and body (markdown, 200-8000 chars ideal, must start with a "# " heading) and writes the front matter; the previous version is backed up first. action=delete needs confirm:true and also keeps a backup; bundled playbooks cannot be deleted, only overridden. Refused in read-only mode.',
		'category'     => 'wpmcp',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'action'   => array( 'type' => 'string', 'enum' => array( 'save', 'delete' ) ),
				'slug'     => array( 'type' => 'string', 'description' => 'Lowercase slug without .md, e.g. "elementor-atomic-widgets".' ),
				'title'    => array( 'type' => 'string' ),
				'category' => array( 'type' => 'string', 'description' => 'e.g. Elementor, WooCommerce, Core, Operations.' ),
				'summary'  => array( 'type' => 'string', 'description' => 'One or two sentences: when to load this skill.' ),
				'body'     => array( 'type' => 'string', 'description' => 'Full markdown playbook. Start with "# Title" then steps, code and traps.' ),
				'confirm'  => array( 'type' => 'boolean', 'description' => 'Must be true for action=delete.' ),
			),
			'required' => array( 'action', 'slug' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'ok'      => array( 'type' => 'boolean' ),
				'slug'    => array( 'type' => 'string' ),
				'bytes'   => array( 'type' => 'integer' ),
				'created' => array( 'type' => 'boolean' ),
				'backup'  => array( 'type' => 'string' ),
				'message' => array( 'type' => 'string' ),
			),
		),
		'execute_callback' => function ( $input ) {
			if ( wpmcp_is_readonly() ) {
				return wpmcp_readonly_error() + array( 'slug' => '', 'bytes' => 0 );
			}
			$action = (string) ( $input['action'] ?? 'save' );
			$slug   = strtolower( preg_replace( '/[^a-z0-9\-]/', '', str_replace( '_', '-', strtolower( (string) ( $input['slug'] ?? '' ) ) ) ) );
			$slug   = trim( $slug, '-' );
			if ( '' === $slug || strlen( $slug ) > 64 ) {
				return array( 'ok' => false, 'slug' => $slug, 'bytes' => 0, 'message' => 'Slug must be 1-64 chars of a-z, 0-9 and hyphens.' );
			}
			$file = wpmcp_user_skills_dir() . $slug . '.md';

			if ( 'delete' === $action ) {
				if ( empty( $input['confirm'] ) ) {
					return wpmcp_confirm_error( 'delete skill ' . $slug ) + array( 'slug' => $slug, 'bytes' => 0 );
				}
				if ( ! is_file( $file ) ) {
					$bundled = is_file( wpmcp_skills_dir() . $slug . '.md' );
					return array(
						'ok'      => false,
						'slug'    => $slug,
						'bytes'   => 0,
						'created' => false,
						'backup'  => '',
						'message' => $bundled
							? 'That slug is a bundled playbook and cannot be deleted; save a site version with the same slug to override it.'
							: 'Skill not found in the site library.',
					);
				}
				$saved = wpmcp_backup_file( $file, 'skill-delete' );
				$ok    = @unlink( $file );
				return array(
					'ok'      => $ok,
					'slug'    => $slug,
					'bytes'   => 0,
					'created' => false,
					'backup'  => is_wp_error( $saved ) ? '' : (string) $saved,
					'message' => $ok ? 'Skill deleted. Restore with wpmcp/restore.' : 'Delete failed.',
				);
			}

			$title    = trim( str_replace( array( "\r", "\n" ), ' ', (string) ( $input['title'] ?? '' ) ) );
			$category = trim( str_replace( array( "\r", "\n" ), ' ', (string) ( $input['category'] ?? '' ) ) );
			$summary  = trim( str_replace( array( "\r", "\n" ), ' ', (string) ( $input['summary'] ?? '' ) ) );
			$body     = trim( (string) ( $input['body'] ?? '' ) );

			if ( '' === $title || '' === $body ) {
				return array( 'ok' => false, 'slug' => $slug, 'bytes' => 0, 'message' => 'title and body are required for action=save.' );
			}
			if ( strlen( $body ) > 200000 ) {
				return array( 'ok' => false, 'slug' => $slug, 'bytes' => 0, 'message' => 'Body is over 200 KB; split it into several skills.' );
			}
			if ( '' === $category ) {
				$category = 'General';
			}
			if ( '' === $summary ) {
				$summary = mb_substr( preg_replace( '/\s+/', ' ', strip_tags( $body ) ), 0, 200 );
			}
			if ( ! preg_match( '/^#\s+/m', $body ) ) {
				$body = '# ' . $title . "\n\n" . $body;
			}
			$doc = "---\ntitle: " . $title . "\ncategory: " . $category . "\nsummary: " . $summary . "\n---\n\n" . $body . "\n";

			$existed = is_file( $file );
			$backup  = '';
			if ( $existed ) {
				$saved  = wpmcp_backup_file( $file, 'skill-edit' );
				$backup = is_wp_error( $saved ) ? '' : (string) $saved;
			}
			$tmp = $file . '.tmp';
			if ( false === @file_put_contents( $tmp, $doc ) ) {
				return array( 'ok' => false, 'slug' => $slug, 'bytes' => 0, 'message' => 'Could not write the temporary file. Is wp-content writable?' );
			}
			if ( ! @rename( $tmp, $file ) ) {
				@unlink( $tmp );
				return array( 'ok' => false, 'slug' => $slug, 'bytes' => 0, 'message' => 'Could not move the new skill into place.' );
			}
			return array(
				'ok'      => true,
				'slug'    => $slug,
				'bytes'   => strlen( $doc ),
				'created' => ! $existed,
				'backup'  => $backup,
				'message' => ( $existed ? 'Skill updated' : 'Skill created' ) . ': ' . $slug . '.md (' . strlen( $doc ) . ' bytes). It is live for every MCP client immediately.',
			);
		},
		'permission_callback' => $admin_gate,
		'meta'                => $annotations( 'WP Write Skill', false, true, false ),
	) );

	// ---------------------------------------------------------------------
	// 14. Restore: list and roll back the automatic backups.
	// ---------------------------------------------------------------------
	wp_register_ability( 'wpmcp/restore', array(
		'label'        => 'Restore Backup',
		'description'  => 'List and roll back the copies that wpmcp/files and wpmcp/skill-write save under wp-content/wpmcp-backups. action=list (optional path filter) shows relative path, timestamp, size and tag. action=restore takes a backup file name (or a path for the newest copy), saves the current file first, then copies the backup back — needs confirm:true. action=prune (days, default 30) deletes old copies — needs confirm:true. This is the undo button for file edits.',
		'category'     => 'wpmcp',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'action' => array( 'type' => 'string', 'enum' => array( 'list', 'restore', 'prune' ) ),
				'path'   => array( 'type' => 'string', 'description' => 'Relative path inside wp-content: filter for list, or target for restore (newest backup).' ),
				'backup' => array( 'type' => 'string', 'description' => 'Exact backup file name from action=list.' ),
				'days'   => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 3650, 'description' => 'Prune backups older than this many days, default 30.' ),
				'confirm' => array( 'type' => 'boolean', 'description' => 'Must be true for restore and prune.' ),
			),
			'required' => array( 'action' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'ok'      => array( 'type' => 'boolean' ),
				'entries' => array( 'description' => 'backup, path, at, bytes, tag.' ),
				'restored' => array( 'type' => 'string' ),
				'backup'  => array( 'type' => 'string' ),
				'deleted' => array( 'type' => 'integer' ),
				'message' => array( 'type' => 'string' ),
			),
		),
		'execute_callback' => function ( $input ) {
			$action = (string) ( $input['action'] ?? 'list' );
			$dir    = wpmcp_backup_dir();

			if ( 'list' === $action ) {
				$filter = ltrim( str_replace( '\\', '/', (string) ( $input['path'] ?? '' ) ), '/' );
				$rows   = array();
				foreach ( wpmcp_backup_list() as $row ) {
					if ( '' !== $filter && false === strpos( $row['path'], $filter ) ) {
						continue;
					}
					$rows[] = $row;
				}
				return array( 'ok' => true, 'entries' => array_slice( $rows, 0, 100 ), 'message' => count( $rows ) . ' backup(s).' );
			}

			if ( wpmcp_is_readonly() ) {
				return wpmcp_readonly_error() + array( 'deleted' => 0 );
			}

			if ( 'prune' === $action ) {
				if ( empty( $input['confirm'] ) ) {
					return wpmcp_confirm_error( 'prune backups' ) + array( 'deleted' => 0 );
				}
				$days    = min( 3650, max( 1, (int) ( $input['days'] ?? 30 ) ) );
				$cutoff  = time() - ( $days * 86400 );
				$deleted = 0;
				foreach ( (array) glob( $dir . '/*.bak' ) as $file ) {
					if ( @filemtime( $file ) < $cutoff && @unlink( $file ) ) {
						++$deleted;
					}
				}
				return array( 'ok' => true, 'deleted' => $deleted, 'message' => 'Pruned ' . $deleted . ' backup(s) older than ' . $days . ' days.' );
			}

			if ( 'restore' === $action ) {
				if ( empty( $input['confirm'] ) ) {
					return wpmcp_confirm_error( 'restore a file' ) + array( 'deleted' => 0 );
				}
				$name = basename( (string) ( $input['backup'] ?? '' ) );
				if ( '' === $name ) {
					$wanted = ltrim( str_replace( '\\', '/', (string) ( $input['path'] ?? '' ) ), '/' );
					foreach ( wpmcp_backup_list() as $row ) {
						if ( $wanted === $row['path'] ) {
							$name = $row['backup'];
							break;
						}
					}
				}
				if ( '' === $name || ! is_file( $dir . '/' . $name ) ) {
					return array( 'ok' => false, 'deleted' => 0, 'message' => 'Backup not found. Call action=list first.' );
				}
				if ( ! preg_match( '/^([A-Za-z0-9_-]+)\./', $name, $m ) ) {
					return array( 'ok' => false, 'deleted' => 0, 'message' => 'Unrecognised backup name.' );
				}
				$rel = base64_decode( strtr( $m[1], '-_', '+/' ), true );
				if ( false === $rel || '' === $rel ) {
					return array( 'ok' => false, 'deleted' => 0, 'message' => 'Backup name does not decode to a path.' );
				}
				$abs = wpmcp_guard_path( $rel, false );
				if ( is_wp_error( $abs ) ) {
					return array( 'ok' => false, 'deleted' => 0, 'message' => $abs->get_error_message() );
				}
				if ( ! is_dir( dirname( $abs ) ) && ! wp_mkdir_p( dirname( $abs ) ) ) {
					return array( 'ok' => false, 'deleted' => 0, 'message' => 'Target directory is missing and could not be created.' );
				}
				$pre = '';
				if ( is_file( $abs ) ) {
					$saved = wpmcp_backup_file( $abs, 'pre-restore' );
					$pre   = is_wp_error( $saved ) ? '' : (string) $saved;
				}
				if ( ! @copy( $dir . '/' . $name, $abs ) ) {
					return array( 'ok' => false, 'deleted' => 0, 'message' => 'Copy failed.' );
				}
				return array(
					'ok'       => true,
					'deleted'  => 0,
					'restored' => $rel,
					'backup'   => $pre,
					'message'  => 'Restored ' . $rel . ' from ' . $name . ( $pre ? '. The version it replaced is saved as ' . $pre . '.' : '.' ),
				);
			}

			return array( 'ok' => false, 'deleted' => 0, 'message' => 'Unknown action. Use list, restore or prune.' );
		},
		'permission_callback' => $admin_gate,
		'meta'                => $annotations( 'WP Restore Backup', false, true, false ),
	) );
} );

/**
 * Expose these abilities as first-class MCP tools.
 *
 * mcp-adapter's default server ships only three meta-tools (discover / get-info /
 * execute), so every real call costs three round trips. Adding our abilities to
 * the server's `tools` list makes each one a normal MCP tool with its full input
 * schema, which is what an agent needs to use it in one call. Abilities from
 * other plugins are left exactly as they were.
 *
 * Elementor's own MCP abilities (shipped by the Angie plugin) are added too: kit
 * styles, classes, components, widget schemas, page structure, preview links and
 * publishing, which our wpmcp/elementor super-ability does not cover. Angie's own
 * /elementor/mcp server cannot be used from any client on this host because it
 * publishes every ability on the site (89 tools) and the host WAF blocks that
 * tools/list response; this endpoint is the WAF-safe way to reach the same tools.
 *
 * Only the namespaces listed in `wpmcp_extra_tool_namespaces` are pulled in. The
 * angie/* group (PHP execution, file reads, snippet writes) is deliberately
 * excluded: combined with wpmcp/wpcli it pushes the tools/list response past the
 * WAF's response-inspection threshold and the whole endpoint starts returning 403.
 *
 * Priority 20: runs after ability registration, so wp_get_ability() can confirm
 * each name before it is added (a missing ability would fail server creation).
 */
add_filter( 'mcp_adapter_default_server_config', function ( $config ) {
	if ( ! is_array( $config ) ) {
		return $config;
	}

	$tools = isset( $config['tools'] ) && is_array( $config['tools'] ) ? $config['tools'] : array();

	foreach ( wpmcp_ability_names() as $name ) {
		if ( function_exists( 'wp_get_ability' ) && ! wp_get_ability( $name ) ) {
			continue;
		}
		$tools[] = $name;
	}

	$resources = isset( $config['resources'] ) && is_array( $config['resources'] ) ? $config['resources'] : array();

	// Third-party namespaces that are safe to surface on this server.
	$extra = apply_filters( 'wpmcp_extra_tool_namespaces', array( 'elementor/', 'core/' ) );
	if ( is_array( $extra ) && $extra && function_exists( 'wp_get_abilities' ) ) {
		$prefixes = array_values( array_filter( array_map( 'strval', $extra ) ) );
		foreach ( (array) wp_get_abilities() as $name => $ability ) {
			$matched = false;
			foreach ( $prefixes as $prefix ) {
				if ( '' !== $prefix && 0 === strpos( (string) $name, $prefix ) ) {
					$matched = true;
					break;
				}
			}
			if ( ! $matched ) {
				continue;
			}

			// `mcp.type: resource` abilities are read-only documents (kit classes,
			// global variables, interaction schema); the adapter only accepts them
			// in the resources list, never as tools.
			$meta = is_object( $ability ) && method_exists( $ability, 'get_meta' ) ? (array) $ability->get_meta() : array();
			$mcp  = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
			if ( isset( $mcp['type'] ) && 'resource' === $mcp['type'] ) {
				$resources[] = $name;
				continue;
			}

			$tools[] = $name;
		}
	}

	$config['tools']     = array_values( array_unique( $tools ) );
	$config['resources'] = array_values( array_unique( $resources ) );

	return $config;
}, 20 );

/**
 * Audit log: the last 200 wpmcp calls, newest last, in a non-autoloaded option
 * (never in the option cache). Inputs are truncated and secret-looking keys are
 * redacted. Read it with: wpmcp/wpcli "option get wpmcp_audit_log --format=json".
 */
add_action( 'wp_after_execute_ability', function ( $name, $input, $result ) {
	if ( 0 !== strpos( (string) $name, 'wpmcp/' ) ) {
		return;
	}
	$log = get_option( 'wpmcp_audit_log', array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	$log[] = array(
		'at'      => gmdate( 'c' ),
		'ability' => (string) $name,
		'args'    => wpmcp_audit_summary( $input ),
		'ok'      => ! is_wp_error( $result ),
	);
	if ( count( $log ) > 200 ) {
		$log = array_slice( $log, -200 );
	}
	update_option( 'wpmcp_audit_log', $log, false );
}, 10, 3 );
