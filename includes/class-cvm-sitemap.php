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
		$entries = $this->index->sitemap_entries();
		$stats   = Coywolf_Video_Manager::instance()->stats();

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . "\n";

		foreach ( $entries as $post_id => $uids ) {
			$permalink = get_permalink( $post_id );
			if ( ! $permalink ) {
				continue;
			}
			$title       = get_the_title( $post_id );
			$description = $this->post_description( $post_id, $title );

			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . esc_url( $permalink ) . "</loc>\n";

			foreach ( array_unique( $uids ) as $uid ) {
				$counts   = $stats->get_counts( $uid );
				$vid_desc = Coywolf_CVM_Block::video_description( $uid );
				$desc     = '' !== $vid_desc ? $vid_desc : $description;
				$xml     .= "\t\t<video:video>\n";
				$xml     .= "\t\t\t<video:thumbnail_loc>" . esc_url( $this->cloudflare->thumbnail_url( $uid ) ) . "</video:thumbnail_loc>\n";
				$xml     .= "\t\t\t<video:title>" . esc_xml( $title ) . "</video:title>\n";
				$xml     .= "\t\t\t<video:description>" . esc_xml( $desc ) . "</video:description>\n";
				$xml   .= "\t\t\t<video:player_loc>" . esc_url( $this->cloudflare->iframe_url( $uid ) ) . "</video:player_loc>\n";
				if ( $counts['plays'] > 0 ) {
					$xml .= "\t\t\t<video:view_count>" . (int) $counts['plays'] . "</video:view_count>\n";
				}
				$xml .= "\t\t</video:video>\n";
			}

			$xml .= "\t</url>\n";
		}

		$xml .= '</urlset>' . "\n";
		return $xml;
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
