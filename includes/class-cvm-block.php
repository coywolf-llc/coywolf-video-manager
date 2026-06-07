<?php
/**
 * The coywolf/video editor block.
 *
 * A dynamic block: the editor stores attributes (and a static link fallback in
 * save()), while the front end is server-rendered here — a responsive Cloudflare
 * Stream embed (or an open-source player) wrapped in a <figure>, with the video
 * name in a <figcaption>, a YouTube-style like/views/date row, and VideoObject
 * schema. The block only registers once Cloudflare credentials are connected.
 *
 * @package CoywolfVideoManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the video block.
 */
class Coywolf_CVM_Block {

	/**
	 * Poster / schema thumbnail width requested from Cloudflare (px).
	 */
	const POSTER_WIDTH = 1200;

	/**
	 * Cloudflare client.
	 *
	 * @var Coywolf_CVM_Cloudflare
	 */
	private $cloudflare;

	/**
	 * Settings.
	 *
	 * @var Coywolf_CVM_Settings
	 */
	private $settings;

	/**
	 * Stats store.
	 *
	 * @var Coywolf_CVM_Stats
	 */
	private $stats;

	/**
	 * Maps inherit-able block attributes to their settings keys.
	 *
	 * @var array
	 */
	private $inherit_map = array(
		'controls'      => 'controls',
		'autoplay'      => 'autoplay',
		'loop'          => 'loop',
		'preload'       => 'preload',
		'mute'          => 'mute',
		'lazy'          => 'lazy',
		'showPlays'     => 'plays_enabled',
		'playsInSchema' => 'plays_in_schema',
		'enableLikes'   => 'likes_enabled',
		'showLikeCount' => 'likes_show_count',
	);

	/**
	 * Constructor.
	 *
	 * @param Coywolf_CVM_Cloudflare $cloudflare API client.
	 * @param Coywolf_CVM_Settings   $settings   Settings.
	 * @param Coywolf_CVM_Stats      $stats      Stats store.
	 */
	public function __construct( Coywolf_CVM_Cloudflare $cloudflare, Coywolf_CVM_Settings $settings, Coywolf_CVM_Stats $stats ) {
		$this->cloudflare = $cloudflare;
		$this->settings   = $settings;
		$this->stats      = $stats;

		add_action( 'init', array( $this, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front' ) );
	}

	/**
	 * Enqueue the front-end style/script in the document head when a singular
	 * post/page contains the block. (block.json's style/viewScript hints don't
	 * reliably fire for dynamic blocks, so we load them explicitly.)
	 */
	public function enqueue_front() {
		if ( ! $this->cloudflare->is_configured() ) {
			return;
		}
		if ( is_singular() && has_block( 'coywolf/video' ) ) {
			wp_enqueue_style( 'coywolf-cvm-view' );
			wp_enqueue_script( 'coywolf-cvm-view' );
		}
	}

	/**
	 * Register the block (only when connected) and its assets.
	 */
	public function register() {
		if ( ! $this->cloudflare->is_configured() ) {
			return;
		}

		wp_register_script(
			'coywolf-cvm-block',
			COYWOLF_CVM_URL . 'js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-server-side-render' ),
			Coywolf_Video_Manager::VERSION,
			true
		);
		wp_set_script_translations( 'coywolf-cvm-block', 'coywolf-video-manager' );
		wp_register_style( 'coywolf-cvm-block-editor', COYWOLF_CVM_URL . 'css/block-editor.css', array(), Coywolf_Video_Manager::VERSION );

		wp_register_script( 'coywolf-cvm-view', COYWOLF_CVM_URL . 'js/view.js', array(), Coywolf_Video_Manager::VERSION, true );
		wp_register_style( 'coywolf-cvm-view', COYWOLF_CVM_URL . 'css/view.css', array(), Coywolf_Video_Manager::VERSION );

		// Appearance overrides from Settings, as CSS custom properties.
		$custom_css = $this->custom_css();
		if ( '' !== $custom_css ) {
			wp_add_inline_style( 'coywolf-cvm-view', $custom_css );
		}

		// Bundled open-source players (enqueued only when the block uses them).
		wp_register_script( 'coywolf-cvm-hls', COYWOLF_CVM_URL . 'vendor/hls/hls.min.js', array(), '1.5.17', true );
		wp_register_script( 'coywolf-cvm-plyr', COYWOLF_CVM_URL . 'vendor/plyr/plyr.min.js', array(), '3.7.8', true );
		wp_register_style( 'coywolf-cvm-plyr', COYWOLF_CVM_URL . 'vendor/plyr/plyr.css', array(), '3.7.8' );
		wp_register_script( 'coywolf-cvm-videojs', COYWOLF_CVM_URL . 'vendor/videojs/video.min.js', array(), '8.17.4', true );
		wp_register_style( 'coywolf-cvm-videojs', COYWOLF_CVM_URL . 'vendor/videojs/video-js.min.css', array(), '8.17.4' );

		wp_add_inline_script(
			'coywolf-cvm-block',
			'window.coywolfCVMBlock = ' . wp_json_encode( $this->editor_data() ) . ';',
			'before'
		);
		wp_add_inline_script(
			'coywolf-cvm-view',
			'window.coywolfCVMView = ' . wp_json_encode(
				array(
					'restUrl' => esc_url_raw( rest_url( 'coywolf-cvm/v1' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'i18n'    => array(
						'view'  => __( 'view', 'coywolf-video-manager' ),
						'views' => __( 'views', 'coywolf-video-manager' ),
					),
				)
			) . ';',
			'before'
		);

		register_block_type(
			COYWOLF_CVM_PATH,
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * Data passed to the editor script.
	 *
	 * @return array
	 */
	private function editor_data() {
		return array(
			'restUrl'  => esc_url_raw( rest_url( 'coywolf-cvm/v1' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'defaults' => $this->settings->all(),
		);
	}

	/**
	 * Build the appearance CSS custom properties from Settings. Values are
	 * already sanitized (hex colors, enum weight/align, integer sizes), so they
	 * are safe to inline; only set vars are emitted so view.css fallbacks apply
	 * otherwise.
	 *
	 * @return string
	 */
	private function custom_css() {
		$defaults = Coywolf_CVM_Settings::defaults();
		$vars     = array();

		// Colors: only when changed from the default, so a chosen color scheme
		// isn't clobbered by emitting the default values.
		$colors = array(
			'title_color' => '--cvm-title-color',
			'like_color'  => '--cvm-like-color',
			'meta_color'  => '--cvm-meta-color',
		);
		foreach ( $colors as $key => $var ) {
			$value = (string) $this->settings->get( $key );
			if ( '' !== $value && $value !== (string) $defaults[ $key ] ) {
				$vars[ $var ] = $value;
			}
		}

		// Like background: an explicitly empty value means "no background".
		$like_bg = (string) $this->settings->get( 'like_bg' );
		if ( '' === $like_bg ) {
			$vars['--cvm-like-bg'] = 'transparent';
		} elseif ( $like_bg !== (string) $defaults['like_bg'] ) {
			$vars['--cvm-like-bg'] = $like_bg;
		}

		$weight = (string) $this->settings->get( 'title_weight' );
		if ( $weight !== (string) $defaults['title_weight'] ) {
			$vars['--cvm-title-weight'] = $weight;
		}
		$align = (string) $this->settings->get( 'align' );
		if ( $align !== (string) $defaults['align'] ) {
			$vars['--cvm-align'] = $align;
		}

		$title_size = $this->settings->get_size( 'title_size' );
		if ( abs( $title_size - (float) $defaults['title_size'] ) > 0.001 ) {
			$vars['--cvm-title-size'] = $this->rem( $title_size );
		}
		$meta_size = $this->settings->get_size( 'meta_size' );
		if ( abs( $meta_size - (float) $defaults['meta_size'] ) > 0.001 ) {
			$vars['--cvm-meta-size'] = $this->rem( $meta_size );
		}

		if ( empty( $vars ) ) {
			return '';
		}
		$css = '.coywolf-cvm{';
		foreach ( $vars as $name => $value ) {
			$css .= $name . ':' . $value . ';';
		}
		return $css . '}';
	}

	/**
	 * Format a float as a trimmed rem value (1.15 → "1.15rem", 2.0 → "2rem").
	 *
	 * @param float $value rem value.
	 * @return string
	 */
	private function rem( $value ) {
		return rtrim( rtrim( number_format( (float) $value, 2, '.', '' ), '0' ), '.' ) . 'rem';
	}

	/* --------------------------------------------------------------------- *
	 * Front-end render
	 * --------------------------------------------------------------------- */

	/**
	 * Render the block on the front end.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render( $attributes ) {
		$uid = isset( $attributes['videoId'] ) ? (string) $attributes['videoId'] : '';
		if ( '' === $uid ) {
			return '';
		}

		$cfg      = $this->resolve( $attributes );
		$name     = isset( $attributes['videoName'] ) && '' !== $attributes['videoName'] ? (string) $attributes['videoName'] : get_the_title();
		$duration = isset( $attributes['duration'] ) ? (float) $attributes['duration'] : 0;
		$start    = isset( $attributes['startTime'] ) ? max( 0, (int) $attributes['startTime'] ) : 0;
		// Play-button (accent) color: per-block override, else the Settings default.
		$primary = isset( $attributes['primaryColor'] ) && '' !== $attributes['primaryColor']
			? (string) $attributes['primaryColor']
			: (string) $this->settings->get( 'player_color' );

		// Aspect ratio (height/width) and upload date come from the block, with a
		// cached API lookup as a fallback for older blocks.
		$aspect   = isset( $attributes['aspectRatio'] ) ? (float) $attributes['aspectRatio'] : 0;
		$uploaded = isset( $attributes['uploaded'] ) ? (string) $attributes['uploaded'] : '';

		if ( $aspect <= 0 || '' === $uploaded ) {
			$meta = $this->video_meta( $uid );
			if ( $aspect <= 0 && $meta['width'] > 0 && $meta['height'] > 0 ) {
				$aspect = $meta['height'] / $meta['width'];
			}
			if ( '' === $uploaded && '' !== $meta['created'] ) {
				$uploaded = $meta['created'];
			}
		}
		if ( $aspect <= 0 ) {
			$aspect = 0.5625; // 16:9 fallback.
		}
		$pct = round( $aspect * 100, 4 );

		$playback_id = $uid;
		$poster      = $this->poster_url( $attributes, $playback_id, $uid );

		// Cloudflare iframe params.
		$params = array( 'preload' => $cfg['preload'] );
		if ( $cfg['autoplay'] ) {
			$params['autoplay'] = 'true';
			$params['muted']    = 'true'; // browsers require muted autoplay.
		} elseif ( $cfg['mute'] ) {
			$params['muted'] = 'true';
		}
		if ( $cfg['loop'] ) {
			$params['loop'] = 'true';
		}
		if ( ! $cfg['controls'] ) {
			$params['controls'] = 'false';
		}
		if ( $start > 0 ) {
			$params['startTime'] = $start . 's';
		}
		if ( '' !== $primary ) {
			$params['primaryColor'] = $primary;
		}
		if ( '' !== $poster ) {
			$params['poster'] = $poster;
		}
		$params['title'] = $name;

		$iframe_url = $this->cloudflare->iframe_url( $playback_id, $params );
		$hls_url    = $this->cloudflare->hls_url( $playback_id );
		$counts     = $this->stats->get_counts( $uid );

		$maxwidth = ( 'maxwidth' === ( isset( $attributes['sizeMode'] ) ? $attributes['sizeMode'] : 'responsive' ) )
			? max( 100, (int) ( isset( $attributes['maxWidth'] ) ? $attributes['maxWidth'] : 800 ) )
			: 0;

		$is_oss = ( 'cloudflare' !== $cfg['player'] );
		// Fallback enqueue (covers non-singular placements / widgets too).
		wp_enqueue_style( 'coywolf-cvm-view' );
		wp_enqueue_script( 'coywolf-cvm-view' );
		if ( $is_oss ) {
			$this->enqueue_player( $cfg['player'] );
		}
		$mode = ( $is_oss ? 'oss-' : '' ) . ( $cfg['lazy'] ? 'lazy' : 'inline' );

		$player = $is_oss
			? $this->oss_markup( $hls_url, $poster, $name, $cfg, $pct, $maxwidth )
			: $this->iframe_markup( $iframe_url, $name, $cfg, $pct, $maxwidth, $poster );

		$classes = 'coywolf-cvm';
		$scheme  = (string) $this->settings->get( 'color_scheme' );
		if ( in_array( $scheme, array( 'auto', 'light', 'dark' ), true ) ) {
			$classes .= ' coywolf-cvm-scheme-' . $scheme;
		}

		$attrs = array(
			'class'       => $classes,
			'data-uid'    => $uid,
			'data-player' => $cfg['player'],
			'data-mode'   => $mode,
		);
		// Per-block alignment override (else the Settings default applies).
		if ( isset( $attributes['contentAlign'] ) && in_array( $attributes['contentAlign'], array( 'left', 'center', 'right' ), true ) ) {
			$attrs['style'] = '--cvm-align:' . $attributes['contentAlign'] . ';';
		}

		$wrapper = get_block_wrapper_attributes( $attrs );

		ob_start();
		?>
		<figure <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php echo $player; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo $this->caption_markup( $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo $this->meta_markup( $uid, $cfg, $counts, $uploaded ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</figure>
		<?php echo $this->schema_markup( $uid, $name, $poster, $duration, $cfg, $counts, $iframe_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Cloudflare iframe embed, responsive via a padding-top wrapper (matches the
	 * Cloudflare dashboard embed), optionally capped by a max-width.
	 *
	 * @param string $iframe_url Iframe URL.
	 * @param string $name       Video name.
	 * @param array  $cfg        Resolved config.
	 * @param float  $pct        padding-top percentage (height/width * 100).
	 * @param int    $maxwidth   Max width in px, or 0 for full width.
	 * @param string $poster     Poster URL.
	 * @return string
	 */
	private function iframe_markup( $iframe_url, $name, $cfg, $pct, $maxwidth, $poster ) {
		$allow = 'accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;';
		$abs   = 'border:none;position:absolute;top:0;left:0;height:100%;width:100%;';

		if ( $cfg['lazy'] ) {
			$img   = '' !== $poster
				? '<img class="coywolf-cvm-poster" src="' . esc_url( $poster ) . '" alt="" loading="lazy" style="' . $abs . 'object-fit:cover;" />'
				: '';
			$inner = $img . '<iframe data-src="' . esc_url( $iframe_url ) . '" loading="lazy" title="' . esc_attr( $name ) . '" style="' . $abs . '" allow="' . esc_attr( $allow ) . '" allowfullscreen="true"></iframe>';
		} else {
			$inner = '<iframe src="' . esc_url( $iframe_url ) . '" loading="lazy" title="' . esc_attr( $name ) . '" style="' . $abs . '" allow="' . esc_attr( $allow ) . '" allowfullscreen="true"></iframe>';
		}

		$frame = '<div class="coywolf-cvm-frame" style="position:relative;padding-top:' . esc_attr( $pct ) . '%;">' . $inner . '</div>';

		if ( $maxwidth > 0 ) {
			return '<div class="coywolf-cvm-bound" style="margin:0 auto;max-width:' . (int) $maxwidth . 'px;">' . $frame . '</div>';
		}
		return $frame;
	}

	/**
	 * Open-source player markup: a <video> fed the HLS manifest by view.js.
	 *
	 * @param string $hls_url  HLS manifest URL.
	 * @param string $poster   Poster URL.
	 * @param string $name     Video name.
	 * @param array  $cfg      Resolved config.
	 * @param float  $pct      padding-top percentage (used for aspect-ratio).
	 * @param int    $maxwidth Max width in px, or 0 for full width.
	 * @return string
	 */
	private function oss_markup( $hls_url, $poster, $name, $cfg, $pct, $maxwidth ) {
		$classes = 'coywolf-cvm-video';
		if ( 'videojs' === $cfg['player'] ) {
			$classes .= ' video-js';
		}
		$video  = '<video class="' . esc_attr( $classes ) . '" playsinline';
		$video .= $cfg['controls'] ? ' controls' : '';
		$video .= $cfg['loop'] ? ' loop' : '';
		$video .= ( $cfg['autoplay'] || $cfg['mute'] ) ? ' muted' : '';
		$video .= $cfg['autoplay'] ? ' autoplay' : '';
		$video .= ' preload="' . esc_attr( $cfg['preload'] ) . '"';
		if ( '' !== $poster ) {
			$video .= ' poster="' . esc_url( $poster ) . '"';
		}
		$video .= ' data-hls="' . esc_url( $hls_url ) . '"';
		$video .= ' title="' . esc_attr( $name ) . '"';
		$video .= ' style="width:100%;aspect-ratio:100 / ' . esc_attr( $pct ) . ';"';
		$video .= '></video>';

		if ( $maxwidth > 0 ) {
			return '<div class="coywolf-cvm-bound" style="margin:0 auto;max-width:' . (int) $maxwidth . 'px;">' . $video . '</div>';
		}
		return $video;
	}

	/**
	 * Enqueue the bundled assets for an open-source player.
	 *
	 * @param string $player plyr|videojs.
	 */
	private function enqueue_player( $player ) {
		if ( 'plyr' === $player ) {
			wp_enqueue_style( 'coywolf-cvm-plyr' );
			wp_enqueue_script( 'coywolf-cvm-hls' );
			wp_enqueue_script( 'coywolf-cvm-plyr' );
		} elseif ( 'videojs' === $player ) {
			wp_enqueue_style( 'coywolf-cvm-videojs' );
			wp_enqueue_script( 'coywolf-cvm-videojs' );
		}
	}

	/**
	 * The video-name figcaption (unique class so it overrides image figcaptions).
	 *
	 * @param string $name Video name.
	 * @return string
	 */
	private function caption_markup( $name ) {
		if ( '' === $name ) {
			return '';
		}
		return '<figcaption class="coywolf-cvm-title">' . esc_html( $name ) . '</figcaption>';
	}

	/**
	 * The like / views / upload-date row.
	 *
	 * @param string $uid      Video UID.
	 * @param array  $cfg      Resolved config.
	 * @param array  $counts   { plays, likes }.
	 * @param string $uploaded Cloudflare created timestamp (ISO-8601).
	 * @return string
	 */
	private function meta_markup( $uid, $cfg, $counts, $uploaded ) {
		$like  = $cfg['enableLikes'] ? $this->like_button( $uid, (int) $counts['likes'], $cfg['showLikeCount'], $cfg['like_icon'] ) : '';
		$views = '';
		$date  = '';

		if ( $cfg['showPlays'] ) {
			$plays       = (int) $counts['plays'];
			$views_class = 'coywolf-cvm-views' . ( $plays < 1 ? ' is-empty' : '' );
			$views_text  = $plays > 0
				? sprintf(
					/* translators: %s: number of views. */
					_n( '%s view', '%s views', $plays, 'coywolf-video-manager' ),
					number_format_i18n( $plays )
				)
				: '';
			$views = '<span class="' . esc_attr( $views_class ) . '">' . esc_html( $views_text ) . '</span>';
		}

		if ( '' !== $uploaded ) {
			$ts = strtotime( $uploaded );
			if ( $ts ) {
				$date = sprintf(
					'<span class="coywolf-cvm-date">%s</span>',
					esc_html(
						sprintf(
							/* translators: %s: human-readable time difference, e.g. "7 months". */
							__( '%s ago', 'coywolf-video-manager' ),
							human_time_diff( $ts )
						)
					)
				);
			}
		}

		if ( '' === $like && '' === $views && '' === $date ) {
			return '';
		}
		return '<div class="coywolf-cvm-meta">' . $like . $views . $date . '</div>';
	}

	/**
	 * The YouTube-style like button (thumbs-up + count; count hidden when zero).
	 *
	 * @param string $uid        Video UID.
	 * @param int    $likes      Like count.
	 * @param bool   $show_count Whether to show the count at all.
	 * @param string $icon       heart|thumbs|star.
	 * @return string
	 */
	private function like_button( $uid, $likes, $show_count, $icon ) {
		$count_class = 'coywolf-cvm-like-count' . ( $likes < 1 ? ' is-empty' : '' );
		$count_html  = $show_count
			? '<span class="' . esc_attr( $count_class ) . '">' . esc_html( $likes > 0 ? number_format_i18n( $likes ) : '' ) . '</span>'
			: '';

		return sprintf(
			'<button type="button" class="coywolf-cvm-like" data-uid="%1$s" aria-pressed="false"><span class="screen-reader-text">%2$s</span>%3$s%4$s</button>',
			esc_attr( $uid ),
			esc_html__( 'Like this video', 'coywolf-video-manager' ),
			self::thumb_svg( $icon ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup.
			$count_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
		);
	}

	/**
	 * The outline thumbs-up SVG (CSS fills it solid when the button is liked).
	 * Shared by the block and the settings preview.
	 *
	 * @return string
	 */
	public static function thumb_svg( $icon = 'heart' ) {
		$paths = array(
			'heart'  => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.29 1.51 4.04 3 5.5l7 7Z"/>',
			'thumbs' => '<path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/>',
			'star'   => '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>',
		);
		$path = isset( $paths[ $icon ] ) ? $paths[ $icon ] : $paths['heart'];
		return '<svg class="coywolf-cvm-thumb" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
	}

	/**
	 * Build the VideoObject JSON-LD.
	 *
	 * @param string $uid        Video UID.
	 * @param string $name       Name.
	 * @param string $poster     Poster URL.
	 * @param float  $duration   Duration in seconds.
	 * @param array  $cfg        Resolved config.
	 * @param array  $counts     { plays, likes }.
	 * @param string $iframe_url Embed URL.
	 * @return string
	 */
	private function schema_markup( $uid, $name, $poster, $duration, $cfg, $counts, $iframe_url ) {
		$thumbnail = '' !== $poster
			? $poster
			: $this->cloudflare->thumbnail_url( $uid, array( 'width' => self::POSTER_WIDTH ) );

		$schema = array(
			'@context'     => 'https://schema.org',
			'@type'        => 'VideoObject',
			'name'         => $name,
			'description'  => $this->description( $name ),
			'thumbnailUrl' => array( $thumbnail ),
			'uploadDate'   => get_the_date( 'c' ),
			'embedUrl'     => $iframe_url,
			'contentUrl'   => $this->cloudflare->watch_url( $uid ),
		);
		if ( $duration > 0 ) {
			$schema['duration'] = $this->iso8601_duration( $duration );
		}

		$interaction = array();
		if ( $cfg['showPlays'] && $cfg['playsInSchema'] ) {
			$interaction[] = array(
				'@type'                => 'InteractionCounter',
				'interactionType'      => array( '@type' => 'WatchAction' ),
				'userInteractionCount' => (int) $counts['plays'],
			);
		}
		if ( $cfg['enableLikes'] ) {
			$interaction[] = array(
				'@type'                => 'InteractionCounter',
				'interactionType'      => array( '@type' => 'LikeAction' ),
				'userInteractionCount' => (int) $counts['likes'],
			);
		}
		if ( ! empty( $interaction ) ) {
			$schema['interactionStatistic'] = $interaction;
		}

		return '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
	}

	/* --------------------------------------------------------------------- *
	 * Helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Resolve effective config: per-block override or settings default.
	 *
	 * @param array $attributes Block attributes.
	 * @return array
	 */
	private function resolve( $attributes ) {
		$cfg = array(
			'player'    => $this->settings->get( 'player' ),
			'like_icon' => $this->settings->get( 'like_icon' ),
		);
		foreach ( $this->inherit_map as $attr => $setting_key ) {
			if ( array_key_exists( $attr, $attributes ) && null !== $attributes[ $attr ] ) {
				$cfg[ $attr ] = $attributes[ $attr ];
			} else {
				$cfg[ $attr ] = $this->settings->get( $setting_key );
			}
		}
		return $cfg;
	}

	/**
	 * Cached per-video metadata (dimensions, created date) to avoid an API call
	 * on every render.
	 *
	 * @param string $uid Video UID.
	 * @return array { width, height, created }.
	 */
	private function video_meta( $uid ) {
		$cache  = 'coywolf_cvm_meta_' . md5( $uid );
		$cached = get_transient( $cache );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$meta  = array(
			'width'   => 0,
			'height'  => 0,
			'created' => '',
		);
		$video = $this->cloudflare->get_video( $uid );
		if ( ! is_wp_error( $video ) ) {
			$meta['width']   = isset( $video['input']['width'] ) ? (int) $video['input']['width'] : 0;
			$meta['height']  = isset( $video['input']['height'] ) ? (int) $video['input']['height'] : 0;
			$meta['created'] = isset( $video['created'] ) ? (string) $video['created'] : '';
		}
		set_transient( $cache, $meta, HOUR_IN_SECONDS );
		return $meta;
	}

	/**
	 * The per-video default poster set on the Edit Video page, or null.
	 *
	 * @param string $uid Video UID.
	 * @return array|null { mode, time, image_id, image_url }.
	 */
	public static function video_poster( $uid ) {
		$all = get_option( 'coywolf_cvm_posters', array() );
		return ( is_array( $all ) && isset( $all[ $uid ] ) && is_array( $all[ $uid ] ) ) ? $all[ $uid ] : null;
	}

	/**
	 * Resolve the poster URL: a per-block image, a per-block timestamp, the
	 * per-video default (image or timestamp), or a large (>=1200px) thumbnail.
	 *
	 * @param array  $attributes  Block attributes.
	 * @param string $playback_id Playback id.
	 * @param string $uid         Video UID.
	 * @return string
	 */
	private function poster_url( $attributes, $playback_id, $uid ) {
		$mode = isset( $attributes['posterMode'] ) ? $attributes['posterMode'] : 'timestamp';
		if ( 'media' === $mode && ! empty( $attributes['posterUrl'] ) ) {
			return esc_url_raw( $attributes['posterUrl'] );
		}
		$btime = isset( $attributes['posterTime'] ) ? max( 0, (float) $attributes['posterTime'] ) : 0;
		if ( $btime > 0 ) {
			return $this->cloudflare->thumbnail_url( $playback_id, array( 'time' => $btime . 's', 'width' => self::POSTER_WIDTH ) );
		}

		$video_poster = self::video_poster( $uid );
		if ( $video_poster ) {
			$vp_mode = isset( $video_poster['mode'] ) ? $video_poster['mode'] : '';
			if ( 'image' === $vp_mode && ! empty( $video_poster['image_url'] ) ) {
				return esc_url_raw( $video_poster['image_url'] );
			}
			if ( 'timestamp' === $vp_mode ) {
				$t = isset( $video_poster['time'] ) ? max( 0, (float) $video_poster['time'] ) : 0;
				return $this->cloudflare->thumbnail_url( $playback_id, array( 'time' => $t . 's', 'width' => self::POSTER_WIDTH ) );
			}
		}

		return $this->cloudflare->thumbnail_url( $playback_id, array( 'time' => '0s', 'width' => self::POSTER_WIDTH ) );
	}

	/**
	 * A schema description, falling back to the video name.
	 *
	 * @param string $name Video name.
	 * @return string
	 */
	private function description( $name ) {
		$excerpt = has_excerpt() ? get_the_excerpt() : '';
		$excerpt = trim( wp_strip_all_tags( $excerpt ) );
		return '' !== $excerpt ? $excerpt : $name;
	}

	/**
	 * Convert seconds to an ISO-8601 duration.
	 *
	 * @param float $seconds Seconds.
	 * @return string
	 */
	private function iso8601_duration( $seconds ) {
		$seconds = (int) round( $seconds );
		$h       = (int) floor( $seconds / 3600 );
		$m       = (int) floor( ( $seconds % 3600 ) / 60 );
		$s       = $seconds % 60;
		$out     = 'PT';
		if ( $h ) {
			$out .= $h . 'H';
		}
		if ( $m ) {
			$out .= $m . 'M';
		}
		if ( $s || ( ! $h && ! $m ) ) {
			$out .= $s . 'S';
		}
		return $out;
	}
}
