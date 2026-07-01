<?php
/**
 * Local play/like statistics store.
 *
 * Cloudflare Stream does not expose play or like counts, so they are recorded
 * locally: a denormalized counter row per video plus one row per unique like for
 * deduplication. Plays are throttled per visitor session; likes are deduped per
 * voter with a daily-rotating salt.
 *
 * Custom-table reads use the %i identifier placeholder (WordPress 6.2+) so table
 * names are escaped by $wpdb->prepare(); the DirectDatabaseQuery notices are
 * suppressed because these are plugin-owned tables with no Core API equivalent.
 *
 * @package CoywolfVideoManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plays and likes.
 */
class Coywolf_CVM_Stats {

	/**
	 * Play-throttle window in seconds (one count per visitor per video).
	 */
	const PLAY_THROTTLE = 1800;

	/**
	 * Constructor.
	 */
	public function __construct() {}

	/**
	 * Stats table name.
	 *
	 * @return string
	 */
	public static function stats_table() {
		global $wpdb;
		return $wpdb->prefix . 'coywolf_cvm_stats';
	}

	/**
	 * Likes table name.
	 *
	 * @return string
	 */
	public static function likes_table() {
		global $wpdb;
		return $wpdb->prefix . 'coywolf_cvm_likes';
	}

	/**
	 * Create the custom tables on activation.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$stats   = self::stats_table();
		$likes   = self::likes_table();

		dbDelta(
			"CREATE TABLE {$stats} (
  uid varchar(64) NOT NULL,
  plays bigint(20) unsigned NOT NULL DEFAULT 0,
  likes bigint(20) unsigned NOT NULL DEFAULT 0,
  updated datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (uid)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$likes} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uid varchar(64) NOT NULL,
  voter_hash char(64) NOT NULL,
  created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY uid_voter (uid,voter_hash),
  KEY uid (uid)
) {$charset};"
		);
	}

	/* --------------------------------------------------------------------- *
	 * Reads
	 * --------------------------------------------------------------------- */

	/**
	 * Counts for one video.
	 *
	 * @param string $uid Video UID.
	 * @return array { plays, likes }.
	 */
	public function get_counts( $uid ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT plays, likes FROM %i WHERE uid = %s', self::stats_table(), $uid ), ARRAY_A );
		$plays = $row ? (int) $row['plays'] : 0;
		$likes = $row ? (int) $row['likes'] : 0;
		return array(
			'plays' => $plays,
			// Never display more likes than plays — looks like manipulation to
			// search engines and guards against like-count inflation.
			'likes' => min( $likes, $plays ),
		);
	}

	/**
	 * Counts for many videos, keyed by UID.
	 *
	 * @param array $uids Video UIDs.
	 * @return array
	 */
	public function get_counts_map( $uids ) {
		global $wpdb;
		$uids = array_values( array_filter( array_map( 'strval', (array) $uids ) ) );
		if ( empty( $uids ) ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $uids ), '%s' ) );
		$args         = array_merge( array( self::stats_table() ), $uids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT uid, plays, likes FROM %i WHERE uid IN ($placeholders)", $args ), ARRAY_A );

		$map = array();
		foreach ( (array) $rows as $row ) {
			$plays = (int) $row['plays'];
			$map[ $row['uid'] ] = array(
				'plays' => $plays,
				// Cap likes at plays (see get_counts).
				'likes' => min( (int) $row['likes'], $plays ),
			);
		}
		return $map;
	}

	/**
	 * Whether a voter has already liked a video.
	 *
	 * @param string $uid        Video UID.
	 * @param string $voter_hash Voter hash.
	 * @return bool
	 */
	public function has_liked( $uid, $voter_hash ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE uid = %s AND voter_hash = %s', self::likes_table(), $uid, $voter_hash ) );
		return ! empty( $id );
	}

	/* --------------------------------------------------------------------- *
	 * Writes
	 * --------------------------------------------------------------------- */

	/**
	 * Record a play, throttled to once per visitor per video.
	 *
	 * @param string $uid Video UID.
	 * @return array { plays, throttled }.
	 */
	public function record_play( $uid ) {
		$throttle_key = 'coywolf_cvm_play_' . md5( $uid . '|' . $this->visitor_token() );
		if ( false !== get_transient( $throttle_key ) ) {
			return array(
				'plays'     => $this->get_counts( $uid )['plays'],
				'throttled' => true,
			);
		}
		set_transient( $throttle_key, 1, self::PLAY_THROTTLE );

		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET plays = plays + 1, updated = %s WHERE uid = %s', self::stats_table(), $now, $uid ) );
		if ( ! $affected ) {
			if ( ! Coywolf_CVM_Index::is_embedded( $uid ) ) {
				// No existing row was updated and this UID isn't embedded
				// anywhere on the site: refuse to create a row so anonymous
				// callers can't bloat the stats table with arbitrary UIDs.
				return array(
					'plays'     => 0,
					'throttled' => false,
				);
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				self::stats_table(),
				array(
					'uid'     => $uid,
					'plays'   => 1,
					'likes'   => 0,
					'updated' => $now,
				),
				array( '%s', '%d', '%d', '%s' )
			);
		}

		return array(
			'plays'     => $this->get_counts( $uid )['plays'],
			'throttled' => false,
		);
	}

	/**
	 * Toggle a like for a voter.
	 *
	 * @param string $uid        Video UID.
	 * @param string $voter_hash Voter hash.
	 * @return array { liked, likes }.
	 */
	public function toggle_like( $uid, $voter_hash ) {
		global $wpdb;
		$now = current_time( 'mysql', true );

		if ( $this->has_liked( $uid, $voter_hash ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( self::likes_table(), array( 'uid' => $uid, 'voter_hash' => $voter_hash ), array( '%s', '%s' ) );
			$liked = false;
		} else {
			if ( ! Coywolf_CVM_Index::is_embedded( $uid ) ) {
				// Don't create a like row for a UID that isn't embedded
				// anywhere on the site (anti-bloat; matches record_play()).
				return array(
					'liked' => false,
					'likes' => 0,
				);
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				self::likes_table(),
				array(
					'uid'        => $uid,
					'voter_hash' => $voter_hash,
					'created'    => $now,
				),
				array( '%s', '%s', '%s' )
			);
			$liked = true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE uid = %s', self::likes_table(), $uid ) );
		$this->set_like_count( $uid, $count, $now );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$plays = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT plays FROM %i WHERE uid = %s', self::stats_table(), $uid ) );

		return array(
			'liked' => $liked,
			// Displayed count never exceeds plays (see get_counts).
			'likes' => min( $count, $plays ),
		);
	}

	/**
	 * Write the denormalized like counter on the stats row.
	 *
	 * @param string $uid   Video UID.
	 * @param int    $count Like count.
	 * @param string $now   MySQL datetime.
	 */
	private function set_like_count( $uid, $count, $now ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET likes = %d, updated = %s WHERE uid = %s', self::stats_table(), $count, $now, $uid ) );
		if ( ! $affected ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				self::stats_table(),
				array(
					'uid'     => $uid,
					'plays'   => 0,
					'likes'   => $count,
					'updated' => $now,
				),
				array( '%s', '%d', '%d', '%s' )
			);
		}
	}

	/**
	 * Delete all stored stats + likes for a (deleted) video.
	 *
	 * @param string $uid Video UID.
	 */
	public function delete_uid( $uid ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::stats_table(), array( 'uid' => $uid ), array( '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::likes_table(), array( 'uid' => $uid ), array( '%s' ) );
	}

	/* --------------------------------------------------------------------- *
	 * Visitor identity
	 * --------------------------------------------------------------------- */

	/**
	 * A coarse per-visitor token for play throttling (not stored).
	 *
	 * @return string
	 */
	private function visitor_token() {
		return sha1( $this->client_ip() . '|' . $this->user_agent() );
	}

	/**
	 * A stable per-voter hash for like dedup, salted with a daily-rotating value
	 * so it cannot be correlated across days. Logged-in users hash by user ID.
	 *
	 * @return string
	 */
	public function voter_hash() {
		$user_id = get_current_user_id();
		$base    = $user_id ? 'user:' . $user_id : 'anon:' . $this->client_ip() . '|' . $this->user_agent();
		return hash( 'sha256', $base . '|' . $this->daily_salt() );
	}

	/**
	 * The current day's like salt, regenerated daily.
	 *
	 * @return string
	 */
	private function daily_salt() {
		$today = gmdate( 'Y-m-d' );
		$store = get_option( 'coywolf_cvm_like_salt', array() );
		if ( ! is_array( $store ) || ! isset( $store['date'], $store['salt'] ) || $store['date'] !== $today ) {
			$store = array(
				'date' => $today,
				'salt' => wp_generate_password( 32, true, true ),
			);
			update_option( 'coywolf_cvm_like_salt', $store, false );
		}
		return $store['salt'];
	}

	/**
	 * Best-effort client IP.
	 *
	 * @return string
	 */
	private function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Truncated user-agent.
	 *
	 * @return string
	 */
	private function user_agent() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return substr( $ua, 0, 200 );
	}
}
