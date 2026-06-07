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
		$primary  = isset( $attributes['primaryColor'] ) ? (string) $attributes['primaryColor'] : '';

		// Aspect ratio (height/width) and upload date come from the block, with a
		// cached API lookup as a fallback for older blocks or when signing.
		$aspect     = isset( $attributes['aspectRatio'] ) ? (float) $attributes['aspectRatio'] : 0;
		$uploaded   = isset( $attributes['uploaded'] ) ? (string) $attributes['uploaded'] : '';
		$signing_on = $this->settings->get( 'signed_urls_enabled' ) && $this->cloudflare->has_signing_key();

		if ( $aspect <= 0 || '' === $uploaded || $signing_on ) {
			$meta = $this->video_meta( $uid );
			if ( $aspect <= 0 && $meta['width'] > 0 && $meta['height'] > 0 ) {
				$aspect = $meta['height'] / $meta['width'];
			}
			if ( '' === $uploaded && '' !== $meta['created'] ) {
				$uploaded = $meta['created'];
			}
			$signed_required = $signing_on && $meta['signed'];
		} else {
			$signed_required = false;
		}
		if ( $aspect <= 0 ) {
			$aspect = 0.5625; // 16:9 fallback.
		}
		$pct = round( $aspect * 100, 4 );

		$playback_id = $this->playback_id( $uid, $signed_required );
		$poster      = $this->poster_url( $attributes, $playback_id );

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
		if ( $is_oss ) {
			$this->enqueue_player( $cfg['player'] );
		}
		$mode = ( $is_oss ? 'oss-' : '' ) . ( $cfg['lazy'] ? 'lazy' : 'inline' );

		$player = $is_oss
			? $this->oss_markup( $hls_url, $poster, $name, $cfg, $pct, $maxwidth )
			: $this->iframe_markup( $iframe_url, $name, $cfg, $pct, $maxwidth, $poster );

		$wrapper = get_block_wrapper_attributes(
			array(
				'class'       => 'coywolf-cvm',
				'data-uid'    => $uid,
				'data-player' => $cfg['player'],
				'data-mode'   => $mode,
			)
		);

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
		$like  = $cfg['enableLikes'] ? $this->like_button( $uid, (int) $counts['likes'], $cfg['showLikeCount'] ) : '';
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
	 * @return string
	 */
	private function like_button( $uid, $likes, $show_count ) {
		$count_class = 'coywolf-cvm-like-count' . ( $likes < 1 ? ' is-empty' : '' );
		$count_html  = $show_count
			? '<span class="' . esc_attr( $count_class ) . '">' . esc_html( $likes > 0 ? number_format_i18n( $likes ) : '' ) . '</span>'
			: '';

		return sprintf(
			'<button type="button" class="coywolf-cvm-like" data-uid="%1$s" aria-pressed="false"><span class="screen-reader-text">%2$s</span><svg class="coywolf-cvm-thumb" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z"/></svg>%3$s</button>',
			esc_attr( $uid ),
			esc_html__( 'Like this video', 'coywolf-video-manager' ),
			$count_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
		);
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
		$cfg = array( 'player' => $this->settings->get( 'player' ) );
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
	 * Resolve the playback id — UID, or a signed token for private videos.
	 *
	 * @param string $uid    Video UID.
	 * @param bool   $signed Whether this video requires a signed URL.
	 * @return string
	 */
	private function playback_id( $uid, $signed ) {
		if ( ! $signed ) {
			return $uid;
		}
		$token = $this->cloudflare->sign_token( $uid );
		return is_wp_error( $token ) ? $uid : $token;
	}

	/**
	 * Cached per-video metadata (dimensions, created date, signed flag) to avoid
	 * an API call on every render.
	 *
	 * @param string $uid Video UID.
	 * @return array { width, height, created, signed }.
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
			'signed'  => false,
		);
		$video = $this->cloudflare->get_video( $uid );
		if ( ! is_wp_error( $video ) ) {
			$meta['width']   = isset( $video['input']['width'] ) ? (int) $video['input']['width'] : 0;
			$meta['height']  = isset( $video['input']['height'] ) ? (int) $video['input']['height'] : 0;
			$meta['created'] = isset( $video['created'] ) ? (string) $video['created'] : '';
			$meta['signed']  = ! empty( $video['requireSignedURLs'] );
		}
		set_transient( $cache, $meta, HOUR_IN_SECONDS );
		return $meta;
	}

	/**
	 * Resolve the poster URL — a custom image, or a large (>=1200px) Cloudflare
	 * thumbnail at the chosen timestamp.
	 *
	 * @param array  $attributes  Block attributes.
	 * @param string $playback_id Playback id.
	 * @return string
	 */
	private function poster_url( $attributes, $playback_id ) {
		$mode = isset( $attributes['posterMode'] ) ? $attributes['posterMode'] : 'timestamp';
		if ( 'media' === $mode && ! empty( $attributes['posterUrl'] ) ) {
			return esc_url_raw( $attributes['posterUrl'] );
		}
		$time = isset( $attributes['posterTime'] ) ? max( 0, (float) $attributes['posterTime'] ) : 0;
		return $this->cloudflare->thumbnail_url(
			$playback_id,
			array(
				'time'  => $time . 's',
				'width' => self::POSTER_WIDTH,
			)
		);
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
