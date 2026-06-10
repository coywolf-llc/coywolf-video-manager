<?php
/**
 * Caption schema data: a per-video cache of caption tracks (each with a
 * downloadable VTT URL) and a plain-text transcript, consumed by the block's
 * VideoObject JSON-LD.
 *
 * Each track's URL prefers Cloudflare's own delivery hostname; when Cloudflare
 * doesn't serve the VTT publicly, the file is downloaded through the
 * authenticated API and mirrored into the uploads folder, and the local URL is
 * used instead. Cache builds run in the background as single cron events:
 * after captions change through the plugin, when the Edit Video screen notices
 * the list has drifted (e.g. dashboard edits), and at most daily otherwise.
 *
 * @package CoywolfVideoManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and serves the per-video caption/transcript cache.
 */
class Coywolf_CVM_Captions {

	/**
	 * Per-video cache option prefix (the suffix is the video UID).
	 */
	const OPTION_PREFIX = 'coywolf_cvm_captions_';

	/**
	 * Single-event cron hook that (re)builds one video's cache.
	 */
	const CRON_HOOK = 'coywolf_cvm_captions_refresh';

	/**
	 * Cloudflare client.
	 *
	 * @var Coywolf_CVM_Cloudflare
	 */
	private $cloudflare;

	/**
	 * Constructor.
	 *
	 * @param Coywolf_CVM_Cloudflare $cloudflare API client.
	 */
	public function __construct( Coywolf_CVM_Cloudflare $cloudflare ) {
		$this->cloudflare = $cloudflare;
		add_action( self::CRON_HOOK, array( $this, 'refresh' ) );
	}

	/* --------------------------------------------------------------------- *
	 * Schema lookup
	 * --------------------------------------------------------------------- */

	/**
	 * Caption schema data for a video, from the cache: the transcript prose
	 * and one MediaObject per caption track ("caption" is a single object for
	 * one track, an array for several). Returns null when the video has no
	 * known captions. Schedules a background build when the cache is missing
	 * and a revalidation when it has gone stale.
	 *
	 * @param string $uid Video UID.
	 * @return array|null { transcript: string, caption: array }, or null.
	 */
	public function schema_data( $uid ) {
		$uid = $this->sanitize_uid( $uid );
		if ( '' === $uid ) {
			return null;
		}

		$entry = get_option( self::OPTION_PREFIX . $uid, false );
		if ( ! is_array( $entry ) ) {
			$this->schedule_refresh( $uid );
			return null;
		}
		if ( time() - (int) ( isset( $entry['checked'] ) ? $entry['checked'] : 0 ) > DAY_IN_SECONDS ) {
			$this->schedule_refresh( $uid );
		}
		if ( empty( $entry['tracks'] ) || ! is_array( $entry['tracks'] ) ) {
			return null;
		}

		$objects = array();
		foreach ( $entry['tracks'] as $track ) {
			$objects[] = array(
				'@type'          => 'MediaObject',
				'contentUrl'     => (string) $track['url'],
				'encodingFormat' => 'text/vtt',
				'inLanguage'     => (string) $track['language'],
				'name'           => (string) $track['label'],
			);
		}

		return array(
			'transcript' => isset( $entry['transcript'] ) ? (string) $entry['transcript'] : '',
			'caption'    => 1 === count( $objects ) ? $objects[0] : $objects,
		);
	}

	/**
	 * Schedule a background (re)build for a video unless one is already queued.
	 *
	 * @param string $uid   Video UID.
	 * @param int    $delay Seconds from now (default: as soon as possible).
	 */
	public function schedule_refresh( $uid, $delay = 0 ) {
		$uid = $this->sanitize_uid( $uid );
		if ( '' === $uid ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK, array( $uid ) ) ) {
			wp_schedule_single_event( time() + max( 0, (int) $delay ), self::CRON_HOOK, array( $uid ) );
		}
	}

	/**
	 * Schedule a rebuild when a freshly fetched caption list no longer matches
	 * the cached tracks (captions added or removed in the Cloudflare
	 * dashboard, or a generation that finished).
	 *
	 * @param string $uid      Video UID.
	 * @param array  $captions Caption list as returned by the API.
	 */
	public function sync_if_changed( $uid, $captions ) {
		$uid = $this->sanitize_uid( $uid );
		if ( '' === $uid || ! is_array( $captions ) ) {
			return;
		}

		$ready = array();
		foreach ( $captions as $caption ) {
			$status = isset( $caption['status'] ) ? (string) $caption['status'] : 'ready';
			if ( 'ready' === $status && ! empty( $caption['language'] ) ) {
				$ready[] = $this->sanitize_lang( (string) $caption['language'] );
			}
		}
		sort( $ready );

		$entry = get_option( self::OPTION_PREFIX . $uid, false );
		$known = array();
		if ( is_array( $entry ) && ! empty( $entry['tracks'] ) && is_array( $entry['tracks'] ) ) {
			foreach ( $entry['tracks'] as $track ) {
				$known[] = (string) $track['language'];
			}
		}
		sort( $known );

		if ( $ready !== $known ) {
			$this->schedule_refresh( $uid );
		}
	}

	/* --------------------------------------------------------------------- *
	 * Cache build
	 * --------------------------------------------------------------------- */

	/**
	 * Rebuild a video's caption cache: list captions, resolve a downloadable
	 * VTT URL per ready track (Cloudflare-direct, else a local mirror), and
	 * derive the transcript. Cron callback for CRON_HOOK; also called
	 * synchronously after caption uploads/deletes.
	 *
	 * @param string $uid Video UID.
	 */
	public function refresh( $uid ) {
		$uid = $this->sanitize_uid( $uid );
		if ( '' === $uid || ! $this->cloudflare->is_configured() ) {
			return;
		}

		$existing = get_option( self::OPTION_PREFIX . $uid, false );
		$captions = $this->cloudflare->list_captions( $uid );
		if ( is_wp_error( $captions ) ) {
			// Keep whatever we had and let the staleness check retry in ~an hour.
			$entry            = is_array( $existing ) ? $existing : array(
				'transcript' => '',
				'tracks'     => array(),
				'pending'    => 0,
			);
			$entry['checked'] = time() - DAY_IN_SECONDS + HOUR_IN_SECONDS;
			update_option( self::OPTION_PREFIX . $uid, $entry, false );
			return;
		}

		$tracks      = array();
		$bodies      = array();
		$local_langs = array();
		$pending     = false;

		foreach ( $captions as $caption ) {
			$lang = isset( $caption['language'] ) ? $this->sanitize_lang( (string) $caption['language'] ) : '';
			if ( '' === $lang ) {
				continue;
			}
			$status = isset( $caption['status'] ) ? (string) $caption['status'] : 'ready';
			if ( 'inprogress' === $status ) {
				$pending = true;
			}
			if ( 'ready' !== $status ) {
				continue;
			}

			$resolved = $this->resolve_track( $uid, $lang );
			if ( null === $resolved ) {
				continue;
			}
			if ( $resolved['local'] ) {
				$local_langs[] = $lang;
			}

			$label    = isset( $caption['label'] ) && '' !== (string) $caption['label'] ? (string) $caption['label'] : $lang;
			$tracks[] = array(
				'language' => $lang,
				'label'    => $label,
				'url'      => $resolved['url'],
				'local'    => $resolved['local'],
			);

			$bodies[ $lang ] = $resolved['vtt'];
		}

		// Drop mirrored files for languages that are gone or now direct-linked.
		$this->prune_local_files( $uid, $local_langs );

		$transcript = '';
		if ( ! empty( $bodies ) ) {
			$preferred  = $this->preferred_language( array_keys( $bodies ) );
			$transcript = self::vtt_to_transcript( $bodies[ $preferred ] );
		}

		$attempts = ( is_array( $existing ) && isset( $existing['pending'] ) ) ? (int) $existing['pending'] : 0;
		$attempts = $pending ? $attempts + 1 : 0;

		update_option(
			self::OPTION_PREFIX . $uid,
			array(
				'checked'    => time(),
				'transcript' => $transcript,
				'tracks'     => $tracks,
				'pending'    => $attempts,
			),
			false
		);

		// A caption generation may still be running on Cloudflare's side; poll
		// every few minutes (bounded, in case a job is stuck "inprogress").
		if ( $pending && $attempts < 24 ) {
			$this->schedule_refresh( $uid, 5 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Remove all cached caption data for a video (cache entry + mirrored files).
	 *
	 * @param string $uid Video UID.
	 */
	public function purge( $uid ) {
		$uid = $this->sanitize_uid( $uid );
		if ( '' === $uid ) {
			return;
		}
		delete_option( self::OPTION_PREFIX . $uid );
		$this->prune_local_files( $uid, array() );

		$timestamp = wp_next_scheduled( self::CRON_HOOK, array( $uid ) );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK, array( $uid ) );
		}
	}

	/* --------------------------------------------------------------------- *
	 * Track resolution
	 * --------------------------------------------------------------------- */

	/**
	 * Resolve a downloadable VTT URL (and its contents) for one caption track.
	 * Cloudflare's delivery URL is preferred when it actually serves the VTT;
	 * otherwise the file is fetched through the authenticated API and mirrored
	 * into the uploads folder.
	 *
	 * @param string $uid  Video UID.
	 * @param string $lang Sanitized language code.
	 * @return array|null { url: string, local: bool, vtt: string }, or null.
	 */
	private function resolve_track( $uid, $lang ) {
		foreach ( $this->cloudflare->caption_public_urls( $uid, $lang ) as $candidate ) {
			$vtt = $this->fetch_public_vtt( $candidate );
			if ( null !== $vtt ) {
				return array(
					'url'   => $candidate,
					'local' => false,
					'vtt'   => $vtt,
				);
			}
		}

		$vtt = $this->cloudflare->caption_vtt( $uid, $lang );
		if ( is_wp_error( $vtt ) || ! $this->looks_like_vtt( $vtt ) ) {
			return null;
		}
		$url = $this->save_local_vtt( $uid, $lang, $vtt );
		if ( '' === $url ) {
			return null;
		}
		return array(
			'url'   => $url,
			'local' => true,
			'vtt'   => $vtt,
		);
	}

	/**
	 * Fetch a candidate public caption URL and return the VTT body, or null
	 * when the URL doesn't serve a VTT (auth wall, 404, HTML error page…).
	 *
	 * @param string $url Candidate URL.
	 * @return string|null
	 */
	private function fetch_public_vtt( $url ) {
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$body = (string) wp_remote_retrieve_body( $response );
		return $this->looks_like_vtt( $body ) ? $body : null;
	}

	/**
	 * Whether a string looks like a WebVTT file.
	 *
	 * @param mixed $body Response body.
	 * @return bool
	 */
	private function looks_like_vtt( $body ) {
		if ( ! is_string( $body ) || '' === $body ) {
			return false;
		}
		$body = preg_replace( '/^\xEF\xBB\xBF/', '', $body );
		return 0 === strpos( ltrim( $body ), 'WEBVTT' );
	}

	/* --------------------------------------------------------------------- *
	 * Local mirror (uploads folder)
	 * --------------------------------------------------------------------- */

	/**
	 * Write a mirrored VTT into the uploads folder and return its URL
	 * ('' on failure). Files are named "{uid}.{lang}.vtt" — the UID charset
	 * excludes dots, so the name parses back unambiguously.
	 *
	 * @param string $uid  Sanitized video UID.
	 * @param string $lang Sanitized language code.
	 * @param string $vtt  Raw VTT contents.
	 * @return string
	 */
	private function save_local_vtt( $uid, $lang, $vtt ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'coywolf-video-manager/captions';
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		$filesystem = $this->filesystem();
		if ( ! $filesystem ) {
			return '';
		}
		$name = $uid . '.' . $lang . '.vtt';
		if ( ! $filesystem->put_contents( $dir . '/' . $name, $vtt, FS_CHMOD_FILE ) ) {
			return '';
		}
		return trailingslashit( $uploads['baseurl'] ) . 'coywolf-video-manager/captions/' . rawurlencode( $name );
	}

	/**
	 * Delete this video's mirrored VTT files except the given languages.
	 *
	 * @param string   $uid        Sanitized video UID.
	 * @param string[] $keep_langs Languages whose mirror files must stay.
	 */
	private function prune_local_files( $uid, $keep_langs ) {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return;
		}
		$dir        = trailingslashit( $uploads['basedir'] ) . 'coywolf-video-manager/captions';
		$filesystem = $this->filesystem();
		if ( ! $filesystem || ! $filesystem->is_dir( $dir ) ) {
			return;
		}
		$files = $filesystem->dirlist( $dir, false, false );
		if ( ! is_array( $files ) ) {
			return;
		}
		foreach ( array_keys( $files ) as $name ) {
			if ( 0 !== strpos( $name, $uid . '.' ) || '.vtt' !== substr( $name, -4 ) ) {
				continue;
			}
			$lang = substr( $name, strlen( $uid ) + 1, -4 );
			if ( ! in_array( $lang, $keep_langs, true ) ) {
				$filesystem->delete( $dir . '/' . $name );
			}
		}
	}

	/**
	 * The WP_Filesystem instance (direct transport on typical hosts), or null.
	 *
	 * @return WP_Filesystem_Base|null
	 */
	private function filesystem() {
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! $wp_filesystem instanceof WP_Filesystem_Base ) {
			WP_Filesystem();
		}
		return ( $wp_filesystem instanceof WP_Filesystem_Base ) ? $wp_filesystem : null;
	}

	/* --------------------------------------------------------------------- *
	 * Transcript
	 * --------------------------------------------------------------------- */

	/**
	 * Reduce a WebVTT file to running prose: the WEBVTT header, NOTE/STYLE/
	 * REGION blocks, cue identifiers, and timestamp lines are dropped; voice
	 * and styling tags are stripped from the cue text; and the cues are joined
	 * with single spaces.
	 *
	 * @param string $vtt Raw VTT contents.
	 * @return string
	 */
	public static function vtt_to_transcript( $vtt ) {
		$vtt = (string) $vtt;
		$vtt = preg_replace( '/^\xEF\xBB\xBF/', '', $vtt );
		$vtt = str_replace( array( "\r\n", "\r" ), "\n", $vtt );

		$pieces = array();
		foreach ( preg_split( '/\n{2,}/', $vtt ) as $block ) {
			$lines = explode( "\n", trim( $block ) );
			$first = $lines[0];
			if ( 0 === strpos( $first, 'WEBVTT' ) || 0 === strpos( $first, 'NOTE' ) || 0 === strpos( $first, 'STYLE' ) || 0 === strpos( $first, 'REGION' ) ) {
				continue;
			}
			// The cue payload is everything after the "-->" timing line (any
			// line before it is a cue identifier).
			$timing = -1;
			foreach ( $lines as $i => $line ) {
				if ( false !== strpos( $line, '-->' ) ) {
					$timing = $i;
					break;
				}
			}
			if ( $timing < 0 ) {
				continue;
			}
			$text = trim( implode( ' ', array_slice( $lines, $timing + 1 ) ) );
			if ( '' !== $text ) {
				$pieces[] = $text;
			}
		}

		$text = implode( ' ', $pieces );
		// Voice/styling tags (<v Name>, </v>, <c.class>, inline timestamps)
		// are markup, not prose. Entities decode after, so a literal &lt;
		// stays text.
		$text = preg_replace( '/<[^>]*>/', '', $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/', ' ', $text );

		return trim( $text );
	}

	/**
	 * The transcript language: the track matching the site language when
	 * available, else the first track.
	 *
	 * @param string[] $langs Available language codes (non-empty).
	 * @return string
	 */
	private function preferred_language( $langs ) {
		$site = strtolower( substr( (string) get_locale(), 0, 2 ) );
		foreach ( $langs as $lang ) {
			if ( strtolower( substr( $lang, 0, 2 ) ) === $site ) {
				return $lang;
			}
		}
		return $langs[0];
	}

	/* --------------------------------------------------------------------- *
	 * Sanitizers
	 * --------------------------------------------------------------------- */

	/**
	 * Validate a video UID ('' when invalid).
	 *
	 * @param string $uid Video UID.
	 * @return string
	 */
	private function sanitize_uid( $uid ) {
		$uid = (string) $uid;
		return preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $uid ) ? $uid : '';
	}

	/**
	 * Sanitize a BCP-47 language code.
	 *
	 * @param string $lang Language.
	 * @return string
	 */
	private function sanitize_lang( $lang ) {
		return preg_replace( '/[^A-Za-z-]/', '', (string) $lang );
	}
}
