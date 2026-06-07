<?php
/**
 * Video XML sitemap.
 *
 * Serves /video-sitemap.xml listing every published post/page that embeds a
 * video, with a <video:video> entry per video. The rewrite rule is registered
 * unconditionally on init (so it survives); output is gated on the setting, and
 * rewrites are flushed only on activation and when the setting changes.
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
		add_action( 'template_redirect', array( $this, 'serve' ) );
		add_action( 'update_option_coywolf_cvm_settings', array( $this, 'on_settings_changed' ), 10, 2 );
	}

	/**
	 * Register the rewrite rule. Called on init and on activation.
	 */
	public static function register_rewrite() {
		add_rewrite_rule( '^video-sitemap\.xml$', 'index.php?coywolf_cvm_sitemap=1', 'top' );
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
	 * Serve the sitemap when requested and enabled.
	 */
	public function serve() {
		$requested = get_query_var( 'coywolf_cvm_sitemap' );
		if ( ! $requested && isset( $_GET['coywolf_cvm_sitemap'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$requested = '1';
		}
		if ( ! $requested ) {
			return;
		}
		if ( ! $this->settings->get( 'sitemap_enabled' ) ) {
			// Let WordPress serve a normal 404 for the URL.
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
				$counts = $stats->get_counts( $uid );
				$xml   .= "\t\t<video:video>\n";
				$xml   .= "\t\t\t<video:thumbnail_loc>" . esc_url( $this->cloudflare->thumbnail_url( $uid ) ) . "</video:thumbnail_loc>\n";
				$xml   .= "\t\t\t<video:title>" . esc_xml( $title ) . "</video:title>\n";
				$xml   .= "\t\t\t<video:description>" . esc_xml( $description ) . "</video:description>\n";
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
