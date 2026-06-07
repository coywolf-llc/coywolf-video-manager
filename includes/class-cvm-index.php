<?php
/**
 * Post ↔ video usage index.
 *
 * Parses posts/pages on save for coywolf/video blocks and records which videos
 * they embed — in postmeta (for quick per-post lookups) and in a usage table
 * (for the All Videos counts, the filtered admin views, and the sitemap).
 *
 * Custom-table reads use the %i identifier placeholder (WordPress 6.2+); the
 * DirectDatabaseQuery notices are suppressed because these are plugin-owned
 * tables with no Core API equivalent.
 *
 * @package CoywolfVideoManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks which posts and pages embed which videos.
 */
class Coywolf_CVM_Index {

	/**
	 * Post types indexed.
	 *
	 * @var array
	 */
	private $types = array( 'post', 'page' );

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 2 );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_delete' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_admin_query' ) );
	}

	/**
	 * Usage table name.
	 *
	 * @return string
	 */
	public static function usage_table() {
		global $wpdb;
		return $wpdb->prefix . 'coywolf_cvm_usage';
	}

	/**
	 * Create the usage table on activation.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$usage   = self::usage_table();
		dbDelta(
			"CREATE TABLE {$usage} (
  uid varchar(64) NOT NULL,
  post_id bigint(20) unsigned NOT NULL,
  post_type varchar(20) NOT NULL DEFAULT 'post',
  PRIMARY KEY  (uid,post_id),
  KEY uid (uid),
  KEY post_id (post_id)
) {$charset};"
		);
	}

	/* --------------------------------------------------------------------- *
	 * Indexing
	 * --------------------------------------------------------------------- */

	/**
	 * Reindex on content save.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public function on_save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$this->reindex( $post );
	}

	/**
	 * Reindex on status change (publish/unpublish/trash).
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public function on_transition( $new_status, $old_status, $post ) {
		unset( $new_status, $old_status );
		if ( $post instanceof WP_Post ) {
			$this->reindex( $post );
		}
	}

	/**
	 * Remove usage rows when a post is permanently deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_delete( $post_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::usage_table(), array( 'post_id' => (int) $post_id ), array( '%d' ) );
	}

	/**
	 * Rebuild the postmeta + usage rows for one post.
	 *
	 * @param WP_Post $post Post.
	 */
	private function reindex( $post ) {
		if ( ! in_array( $post->post_type, $this->types, true ) ) {
			return;
		}

		$uids = $this->extract_uids( $post->post_content );

		if ( empty( $uids ) ) {
			delete_post_meta( $post->ID, 'coywolf_cvm_videos' );
		} else {
			update_post_meta( $post->ID, 'coywolf_cvm_videos', $uids );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::usage_table(), array( 'post_id' => (int) $post->ID ), array( '%d' ) );

		// Only count publicly visible content as "embedding" a video.
		if ( 'publish' === $post->post_status ) {
			foreach ( $uids as $uid ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->insert(
					self::usage_table(),
					array(
						'uid'       => $uid,
						'post_id'   => (int) $post->ID,
						'post_type' => $post->post_type,
					),
					array( '%s', '%d', '%s' )
				);
			}
		}
	}

	/**
	 * Pull unique video UIDs out of post content.
	 *
	 * @param string $content Post content.
	 * @return array
	 */
	private function extract_uids( $content ) {
		if ( false === strpos( $content, 'coywolf/video' ) ) {
			return array();
		}
		$uids = array();
		$this->walk_blocks( parse_blocks( $content ), $uids );
		return array_values( array_unique( array_filter( $uids ) ) );
	}

	/**
	 * Recursively collect video UIDs from parsed blocks.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param array $uids   Accumulator (by reference).
	 */
	private function walk_blocks( $blocks, &$uids ) {
		foreach ( $blocks as $block ) {
			if ( isset( $block['blockName'] ) && 'coywolf/video' === $block['blockName'] && ! empty( $block['attrs']['videoId'] ) ) {
				$uids[] = (string) $block['attrs']['videoId'];
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->walk_blocks( $block['innerBlocks'], $uids );
			}
		}
	}

	/* --------------------------------------------------------------------- *
	 * Reads
	 * --------------------------------------------------------------------- */

	/**
	 * Usage counts per video, keyed by UID: { posts, pages }.
	 *
	 * @param array $uids Video UIDs.
	 * @return array
	 */
	public function usage_map( $uids ) {
		global $wpdb;
		$uids = array_values( array_filter( array_map( 'strval', (array) $uids ) ) );
		if ( empty( $uids ) ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $uids ), '%s' ) );
		$args         = array_merge( array( self::usage_table() ), $uids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT uid, post_type, COUNT(*) AS c FROM %i WHERE uid IN ($placeholders) GROUP BY uid, post_type", $args ), ARRAY_A );

		$map = array();
		foreach ( (array) $rows as $row ) {
			$uid = $row['uid'];
			if ( ! isset( $map[ $uid ] ) ) {
				$map[ $uid ] = array(
					'posts' => 0,
					'pages' => 0,
				);
			}
			if ( 'page' === $row['post_type'] ) {
				$map[ $uid ]['pages'] += (int) $row['c'];
			} else {
				$map[ $uid ]['posts'] += (int) $row['c'];
			}
		}
		return $map;
	}

	/**
	 * Post IDs that embed a given video.
	 *
	 * @param string $uid Video UID.
	 * @return array
	 */
	private function post_ids_for( $uid ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE uid = %s', self::usage_table(), $uid ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Published posts/pages and the videos they embed, for the sitemap.
	 *
	 * @return array [ post_id => [ uid, … ] ].
	 */
	public function sitemap_entries() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT u.post_id, u.uid FROM %i u INNER JOIN %i p ON p.ID = u.post_id WHERE p.post_status = %s AND p.post_type IN (%s, %s) ORDER BY u.post_id ASC', self::usage_table(), $wpdb->posts, 'publish', 'post', 'page' ), ARRAY_A );

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['post_id'] ][] = (string) $row['uid'];
		}
		return $map;
	}

	/* --------------------------------------------------------------------- *
	 * Admin filtered view
	 * --------------------------------------------------------------------- */

	/**
	 * Register the filter query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function add_query_var( $vars ) {
		$vars[] = 'coywolf_cvm_video';
		return $vars;
	}

	/**
	 * On edit.php, when ?coywolf_cvm_video=<uid> is set, restrict the list to
	 * posts that embed that video.
	 *
	 * @param WP_Query $query Query.
	 */
	public function filter_admin_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		global $pagenow;
		if ( 'edit.php' !== $pagenow ) {
			return;
		}
		$uid = isset( $_GET['coywolf_cvm_video'] ) ? sanitize_text_field( wp_unslash( $_GET['coywolf_cvm_video'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $uid ) {
			return;
		}
		$ids = $this->post_ids_for( $uid );
		$query->set( 'post__in', ! empty( $ids ) ? $ids : array( 0 ) );
	}

	/**
	 * Remove usage rows for posts that no longer exist or are no longer published.
	 */
	public function prune() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT post_id FROM %i', self::usage_table() ) ) );
		if ( empty( $ids ) ) {
			return;
		}
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$args         = array_merge( array( $wpdb->posts, 'publish' ), $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$valid   = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_status = %s AND ID IN ($placeholders)", $args ) );
		$invalid = array_diff( $ids, array_map( 'intval', (array) $valid ) );
		if ( empty( $invalid ) ) {
			return;
		}
		$placeholders = implode( ', ', array_fill( 0, count( $invalid ), '%d' ) );
		$args         = array_merge( array( self::usage_table() ), $invalid );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE post_id IN ($placeholders)", $args ) );
	}
}
