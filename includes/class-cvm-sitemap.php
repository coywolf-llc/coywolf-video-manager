<?php
/**
 * Video XML sitemap.
 *
 * Serves /coywolf-video-sitemap.xml listing every published post/page that
 * embeds a video, with a <video:video> entry per video. Served on parse_request
 * by raw path so it runs before Yoast SEO's sitemap handler (which would
 * otherwise capture a "*-sitemap.xml" URL); output is gated on the setting.
 *
 * @package CoywolfVideoManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The video sitemap.
 */
class Coywolf_CVM_Sitemap {

	/**
	 * The sitemap path (relative to the site root). Deliberately NOT
	 * "*-sitemap.xml", which Yoast SEO's rewrite rules capture.
	 */
	const SLUG = 'coywolf-video-sitemap.xml';

	/**
	 * Cloudflare client.
	 *
	 * @var Coywolf_CVM_Cloudflare
	 */
	private $cloudflare;

	/**
	 * Usage index.
	 *
	 * @var Coywolf_CVM_Index
	 */
	private $index;

	/**
	 * Settings.
	 *
	 * @var Coywolf_CVM_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Coywolf_CVM_Cloudflare $cloudflare API client.
	 * @param Coywolf_CVM_Index      $index      Usage index.
	 * @param Coywolf_CVM_Settings   $settings   Settings.
	 */
	public function __construct( Coywolf_CVM_Cloudflare $cloudflare, Coywolf_CVM_Index $index, Coywolf_CVM_Settings $settings ) {
		$this->cloudflare = $cloudflare;
		$this->index      = $index;
		$this->settings   = $settings;

		add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		// Serve on parse_request (by raw path) so we run before Yoast SEO's
		// sitemap handler (pre_get_posts) and before redirect_canonical, and
		// don't depend on which plugin's rewrite rule "won".
		add_action( 'parse_request', array( $this, 'maybe_serve' ) );
		add_filter( 'redirect_canonical', array( $this, 'block_canonical' ) );
		add_action( 'update_option_coywolf_cvm_settings', array( $this, 'on_settings_changed' ), 10, 2 );
	}

	/**
	 * Don't let WordPress canonical-redirect the sitemap URL.
	 *
	 * @param string|false $redirect Proposed redirect URL.
	 * @return string|false
	 */
	public function block_canonical( $redirect ) {
		if ( get_query_var( 'coywolf_cvm_sitemap' ) || $this->is_sitemap_request() ) {
			return false;
		}
		return $redirect;
	}

	/**
	 * Register the rewrite rule. Called on init and on activation.
	 */
	public static function register_rewrite() {
		add_rewrite_rule( '^' . preg_quote( self::SLUG ) . '$', 'index.php?coywolf_cvm_sitemap=1', 'top' );
	}

	/**
	 * Register the query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function add_query_var( $vars ) {
		$vars[] = 'coywolf_cvm_sitemap';
		return $vars;
	}

	/**
	 * Re-flush rewrites when the sitemap setting is toggled.
	 *
	 * @param mixed $old Old settings.
	 * @param mixed $new New settings.
	 */
	public function on_settings_changed( $old, $new ) {
		$was = is_array( $old ) && ! empty( $old['sitemap_enabled'] );
		$now = is_array( $new ) && ! empty( $new['sitemap_enabled'] );
		if ( $was !== $now ) {
			self::register_rewrite();
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Whether the current request is for our sitemap path. Matches the raw
	 * request URI so it works no matter which plugin's rewrite rule matched.
	 *
	 * @return bool
	 */
	private function is_sitemap_request() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$path = sanitize_text_field( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		return self::SLUG === basename( $path );
	}

	/**
	 * Serve the sitemap when requested (by query var or raw path) and enabled.
	 *
	 * @param WP $wp Current WordPress environment (from parse_request).
	 */
	public function maybe_serve( $wp = null ) {
		if ( ! $this->settings->get( 'sitemap_enabled' ) ) {
			return;
		}
		$by_var = is_object( $wp ) && ! empty( $wp->query_vars['coywolf_cvm_sitemap'] );
		if ( ! $by_var && ! $this->is_sitemap_request() ) {
			return;
		}

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		echo $this->render_xml(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per node below.
		exit;
	}

	/**
	 * Build the sitemap XML.
	 *
	 * @return string
	 */
	public function render_xml() {
		$entries   = $this->index->sitemap_entries();
		$stats     = Coywolf_Video_Manager::instance()->stats();
		$durations = $this->duration_map();

		// Upload date source: the post/page publish date when enabled, otherwise
		// each video's Cloudflare upload timestamp.
		$date_from_post = (bool) $this->settings->get( 'date_from_post' );
		$created_map    = $date_from_post ? array() : $this->created_map();

		// Batch the per-video data up front so the loop issues no per-video
		// queries: one stats query for all UIDs, and one read of the descriptions.
		$all_uids = array();
		foreach ( $entries as $entry_uids ) {
			foreach ( (array) $entry_uids as $entry_uid ) {
				$all_uids[ $entry_uid ] = true;
			}
		}
		$counts_map   = $stats->get_counts_map( array_keys( $all_uids ) );
		$descriptions = get_option( 'coywolf_cvm_descriptions', array() );
		if ( ! is_array( $descriptions ) ) {
			$descriptions = array();
		}
		$posters = get_option( 'coywolf_cvm_posters', array() );
		if ( ! is_array( $posters ) ) {
			$posters = array();
		}
		$downloads = get_option( 'coywolf_cvm_downloads', array() );
		if ( ! is_array( $downloads ) ) {
			$downloads = array();
		}

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . "\n";

		foreach ( $entries as $post_id => $uids ) {
			$permalink = get_permalink( $post_id );
			if ( ! $permalink ) {
				continue;
			}
			$title       = get_the_title( $post_id );
			$description = $this->post_description( $post_id, $title );
			$post_date   = get_the_date( 'c', $post_id );

			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . esc_url( $permalink ) . "</loc>\n";

			foreach ( array_unique( $uids ) as $uid ) {
				$counts   = isset( $counts_map[ $uid ] ) ? $counts_map[ $uid ] : array( 'plays' => 0, 'likes' => 0 );
				$vid_desc = isset( $descriptions[ $uid ] ) ? (string) $descriptions[ $uid ] : '';
				$desc     = '' !== $vid_desc ? $vid_desc : $description;
				$duration = isset( $durations[ $uid ] ) ? (int) $durations[ $uid ] : 0;
				$published = $date_from_post
					? $post_date
					: ( ! empty( $created_map[ $uid ] ) ? (string) $created_map[ $uid ] : $post_date );

				// Element order follows the sitemap-video 1.1 schema sequence.
				$xml .= "\t\t<video:video>\n";
				$poster   = ( isset( $posters[ $uid ] ) && is_array( $posters[ $uid ] ) ) ? $posters[ $uid ] : null;
				$p_mode   = ( $poster && isset( $poster['mode'] ) ) ? $poster['mode'] : '';
				if ( 'image' === $p_mode && ! empty( $poster['image_url'] ) ) {
					$thumb = esc_url_raw( $poster['image_url'] );
				} else {
					$p_time = ( 'timestamp' === $p_mode && isset( $poster['time'] ) ) ? max( 0, (float) $poster['time'] ) : 0;
					$thumb  = $this->cloudflare->thumbnail_url( $uid, array( 'time' => $p_time . 's' ) );
				}
				$xml .= "\t\t\t<video:thumbnail_loc>" . esc_url( $thumb ) . "</video:thumbnail_loc>\n";
				$xml .= "\t\t\t<video:title>" . $this->xml_text( $title ) . "</video:title>\n";
				$xml .= "\t\t\t<video:description>" . $this->xml_text( wp_strip_all_tags( $desc ) ) . "</video:description>\n";
				$mp4   = ( ! empty( $downloads[ $uid ] ) ) ? (string) $downloads[ $uid ] : '';
				$xml  .= "\t\t\t<video:content_loc>" . esc_url( '' !== $mp4 ? $mp4 : $this->cloudflare->watch_url( $uid ) ) . "</video:content_loc>\n";
				$xml .= "\t\t\t<video:player_loc>" . esc_url( $this->cloudflare->iframe_url( $uid ) ) . "</video:player_loc>\n";
				if ( $duration > 0 && $duration <= 28800 ) {
					$xml .= "\t\t\t<video:duration>" . $duration . "</video:duration>\n";
				}
				$xml .= "\t\t\t<video:view_count>" . (int) $counts['plays'] . "</video:view_count>\n";
				if ( $published ) {
					$xml .= "\t\t\t<video:publication_date>" . esc_xml( $published ) . "</video:publication_date>\n";
				}
				$xml .= "\t\t\t<video:requires_subscription>no</video:requires_subscription>\n";
				$xml .= "\t\t\t<video:live>no</video:live>\n";
				$xml .= "\t\t</video:video>\n";
			}

			$xml .= "\t</url>\n";
		}

		$xml .= '</urlset>' . "\n";
		return $xml;
	}

	/**
	 * Map of video UID => duration in whole seconds, from the cached library.
	 *
	 * @return array
	 */
	private function duration_map() {
		$map    = array();
		$videos = $this->cloudflare->list_videos();
		if ( is_wp_error( $videos ) || ! is_array( $videos ) ) {
			return $map;
		}
		foreach ( $videos as $video ) {
			if ( ! empty( $video['uid'] ) && isset( $video['duration'] ) ) {
				$map[ (string) $video['uid'] ] = (int) round( (float) $video['duration'] );
			}
		}
		return $map;
	}

	/**
	 * Map of video UID => Cloudflare upload date (ISO-8601), from the cached
	 * library. Used for publication_date when the publish-date option is off.
	 *
	 * @return array
	 */
	private function created_map() {
		$map    = array();
		$videos = $this->cloudflare->list_videos();
		if ( is_wp_error( $videos ) || ! is_array( $videos ) ) {
			return $map;
		}
		foreach ( $videos as $video ) {
			if ( ! empty( $video['uid'] ) && ! empty( $video['created'] ) ) {
				$map[ (string) $video['uid'] ] = (string) $video['created'];
			}
		}
		return $map;
	}

	/**
	 * Escape text for an XML element: the five XML specials via esc_xml (quotes
	 * included), then every remaining non-ASCII character (smart quotes, dashes,
	 * accents, emoji…) as a numeric character reference, so the sitemap is pure
	 * ASCII + entities and validates everywhere.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function xml_text( $text ) {
		$text = esc_xml( (string) $text );

		if ( function_exists( 'mb_encode_numericentity' ) ) {
			return mb_encode_numericentity( $text, array( 0x80, 0x10ffff, 0, 0x1fffff ), 'UTF-8' );
		}

		// Fallback when the mbstring extension is unavailable: decode each
		// multibyte UTF-8 sequence to its code point and emit a numeric reference.
		return (string) preg_replace_callback(
			'/[\x{0080}-\x{10FFFF}]/u',
			static function ( $match ) {
				$bytes = $match[0];
				$len   = strlen( $bytes );
				if ( 2 === $len ) {
					$code = ( ( ord( $bytes[0] ) & 0x1F ) << 6 ) | ( ord( $bytes[1] ) & 0x3F );
				} elseif ( 3 === $len ) {
					$code = ( ( ord( $bytes[0] ) & 0x0F ) << 12 ) | ( ( ord( $bytes[1] ) & 0x3F ) << 6 ) | ( ord( $bytes[2] ) & 0x3F );
				} elseif ( 4 === $len ) {
					$code = ( ( ord( $bytes[0] ) & 0x07 ) << 18 ) | ( ( ord( $bytes[1] ) & 0x3F ) << 12 ) | ( ( ord( $bytes[2] ) & 0x3F ) << 6 ) | ( ord( $bytes[3] ) & 0x3F );
				} else {
					return $bytes;
				}
				return '&#' . $code . ';';
			},
			$text
		);
	}

	/**
	 * A short, XML-safe description for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $fallback Fallback (the title).
	 * @return string
	 */
	private function post_description( $post_id, $fallback ) {
		$excerpt = get_the_excerpt( $post_id );
		$excerpt = trim( wp_strip_all_tags( (string) $excerpt ) );
		if ( '' === $excerpt ) {
			$excerpt = $fallback;
		}
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $excerpt, 0, 2000 );
		}
		return substr( $excerpt, 0, 2000 );
	}
}
