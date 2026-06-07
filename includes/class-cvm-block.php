<?php
/**
 * The coywolf/video editor block.
 *
 * A dynamic block: the editor stores attributes (and a static link fallback in
 * save()), while the front end is server-rendered here — player embed, poster,
 * lightbox/lazy handling, plays/likes UI, and VideoObject schema. The block only
 * registers once Cloudflare credentials are connected.
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
		'lightbox'      => 'lightbox_enabled',
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

		$cfg          = $this->resolve( $attributes );
		$name         = isset( $attributes['videoName'] ) && '' !== $attributes['videoName'] ? (string) $attributes['videoName'] : get_the_title();
		$duration     = isset( $attributes['duration'] ) ? (float) $attributes['duration'] : 0;
		$start        = isset( $attributes['startTime'] ) ? max( 0, (int) $attributes['startTime'] ) : 0;
		$primary      = isset( $attributes['primaryColor'] ) ? (string) $attributes['primaryColor'] : '';
		$playback_id  = $this->playback_id( $uid );
		$poster       = $this->poster_url( $attributes, $playback_id );

		// Cloudflare iframe params.
		$params = array(
			'preload' => $cfg['preload'],
		);
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
		$iframe_url = $this->cloudflare->iframe_url( $playback_id, $params );

		$counts = $this->stats->get_counts( $uid );

		// Sizing.
		$style = '';
		if ( 'maxwidth' === ( isset( $attributes['sizeMode'] ) ? $attributes['sizeMode'] : 'responsive' ) ) {
			$max   = isset( $attributes['maxWidth'] ) ? (int) $attributes['maxWidth'] : 800;
			$style = 'max-width:' . max( 100, $max ) . 'px;';
		}

		// Mode: lightbox always uses the Cloudflare player iframe; otherwise the
		// chosen player (Cloudflare iframe, or an open-source <video>).
		if ( $cfg['lightbox'] ) {
			$mode = 'lightbox';
		} elseif ( 'cloudflare' !== $cfg['player'] ) {
			$mode = $cfg['lazy'] ? 'oss-lazy' : 'oss-inline';
			$this->enqueue_player( $cfg['player'] );
		} else {
			$mode = $cfg['lazy'] ? 'lazy' : 'inline';
		}

		$hls_url = $this->cloudflare->hls_url( $playback_id );

		$wrapper = get_block_wrapper_attributes(
			array(
				'class' => 'coywolf-cvm',
				'style' => $style,
			)
		);

		ob_start();
		?>
		<figure <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<div class="coywolf-cvm-player coywolf-cvm-mode-<?php echo esc_attr( $mode ); ?>"
				data-uid="<?php echo esc_attr( $uid ); ?>"
				data-player="<?php echo esc_attr( $cfg['player'] ); ?>"
				data-mode="<?php echo esc_attr( $mode ); ?>"
				data-iframe="<?php echo esc_url( $iframe_url ); ?>"
				data-hls="<?php echo esc_url( $hls_url ); ?>"
				<?php echo $cfg['autoplay'] ? 'data-autoplay="1"' : ''; ?>
				<?php echo $cfg['loop'] ? 'data-loop="1"' : ''; ?>
				<?php echo $cfg['mute'] ? 'data-muted="1"' : ''; ?>
				<?php echo $cfg['controls'] ? 'data-controls="1"' : 'data-controls="0"'; ?>
			>
				<?php echo $this->player_markup( $mode, $iframe_url, $hls_url, $poster, $name, $cfg ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<?php echo $this->meta_markup( $uid, $cfg, $counts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</figure>
		<?php echo $this->schema_markup( $uid, $name, $poster, $duration, $cfg, $counts, $iframe_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Build the player markup for the chosen mode.
	 *
	 * @param string $mode       inline|lazy|lightbox|oss-inline|oss-lazy.
	 * @param string $iframe_url Cloudflare iframe URL.
	 * @param string $hls_url    HLS manifest URL (open-source players).
	 * @param string $poster     Poster URL.
	 * @param string $name       Video name.
	 * @param array  $cfg        Resolved config.
	 * @return string
	 */
	private function player_markup( $mode, $iframe_url, $hls_url, $poster, $name, $cfg ) {
		$allow = 'accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;';

		if ( 'lightbox' === $mode ) {
			$bg = '' !== $poster ? ' style="background-image:url(' . esc_url( $poster ) . ');"' : '';
			ob_start();
			?>
			<button type="button" class="coywolf-cvm-lightbox-trigger"<?php echo $bg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: video title. */ __( 'Play %s', 'coywolf-video-manager' ), $name ) ); ?>">
				<span class="coywolf-cvm-play-icon" aria-hidden="true"></span>
			</button>
			<?php
			return (string) ob_get_clean();
		}

		// Open-source players: a <video> element fed the HLS manifest by view.js.
		if ( 'oss-inline' === $mode || 'oss-lazy' === $mode ) {
			$classes = 'coywolf-cvm-video';
			if ( 'videojs' === $cfg['player'] ) {
				$classes .= ' video-js';
			}
			$html  = '<video class="' . esc_attr( $classes ) . '" playsinline';
			$html .= $cfg['controls'] ? ' controls' : '';
			$html .= $cfg['loop'] ? ' loop' : '';
			$html .= ( $cfg['autoplay'] || $cfg['mute'] ) ? ' muted' : '';
			$html .= $cfg['autoplay'] ? ' autoplay' : '';
			$html .= ' preload="' . esc_attr( $cfg['preload'] ) . '"';
			if ( '' !== $poster ) {
				$html .= ' poster="' . esc_url( $poster ) . '"';
			}
			$html .= ' data-hls="' . esc_url( $hls_url ) . '"';
			$html .= ' title="' . esc_attr( $name ) . '"></video>';
			return $html;
		}

		if ( 'lazy' === $mode ) {
			$bg = '' !== $poster ? ' style="background-image:url(' . esc_url( $poster ) . ');"' : '';
			return sprintf(
				'<div class="coywolf-cvm-lazy"%1$s><iframe data-src="%2$s" loading="lazy" title="%3$s" allow="%4$s" allowfullscreen="true"></iframe></div>',
				$bg, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above.
				esc_url( $iframe_url ),
				esc_attr( $name ),
				esc_attr( $allow )
			);
		}

		return sprintf(
			'<iframe src="%1$s" loading="lazy" title="%2$s" allow="%3$s" allowfullscreen="true"></iframe>',
			esc_url( $iframe_url ),
			esc_attr( $name ),
			esc_attr( $allow )
		);
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
	 * Build the plays/likes UI.
	 *
	 * @param string $uid    Video UID.
	 * @param array  $cfg    Resolved config.
	 * @param array  $counts { plays, likes }.
	 * @return string
	 */
	private function meta_markup( $uid, $cfg, $counts ) {
		if ( ! $cfg['showPlays'] && ! $cfg['enableLikes'] ) {
			return '';
		}
		ob_start();
		echo '<div class="coywolf-cvm-meta">';
		if ( $cfg['enableLikes'] ) {
			$show_count = $cfg['showLikeCount'] ? '' : ' coywolf-cvm-hide-count';
			printf(
				'<button type="button" class="coywolf-cvm-like%1$s" data-uid="%2$s" aria-pressed="false"><span class="coywolf-cvm-heart" aria-hidden="true"></span><span class="coywolf-cvm-like-count">%3$s</span><span class="screen-reader-text">%4$s</span></button>',
				esc_attr( $show_count ),
				esc_attr( $uid ),
				esc_html( number_format_i18n( $counts['likes'] ) ),
				esc_html__( 'Like this video', 'coywolf-video-manager' )
			);
		}
		if ( $cfg['showPlays'] ) {
			printf(
				'<span class="coywolf-cvm-plays"><span class="coywolf-cvm-plays-count">%1$s</span> %2$s</span>',
				esc_html( number_format_i18n( $counts['plays'] ) ),
				esc_html( _n( 'play', 'plays', (int) $counts['plays'], 'coywolf-video-manager' ) )
			);
		}
		echo '</div>';
		return (string) ob_get_clean();
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
		$schema = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'VideoObject',
			'name'        => $name,
			'description' => $this->description( $name ),
			'uploadDate'  => get_the_date( 'c' ),
			'embedUrl'    => $iframe_url,
			'contentUrl'  => $this->cloudflare->watch_url( $uid ),
		);
		if ( '' !== $poster ) {
			$schema['thumbnailUrl'] = array( $poster );
		} else {
			$schema['thumbnailUrl'] = array( $this->cloudflare->thumbnail_url( $uid ) );
		}
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
	 * Resolve the playback id — UID, or a signed token for private videos when
	 * signed-URL support is on and a key exists.
	 *
	 * @param string $uid Video UID.
	 * @return string
	 */
	private function playback_id( $uid ) {
		if ( ! $this->settings->get( 'signed_urls_enabled' ) || ! $this->cloudflare->has_signing_key() ) {
			return $uid;
		}
		if ( ! $this->requires_signing( $uid ) ) {
			return $uid;
		}
		$token = $this->cloudflare->sign_token( $uid );
		return is_wp_error( $token ) ? $uid : $token;
	}

	/**
	 * Whether a video requires signed URLs, cached for an hour to avoid an API
	 * call on every render.
	 *
	 * @param string $uid Video UID.
	 * @return bool
	 */
	private function requires_signing( $uid ) {
		$cache  = 'coywolf_cvm_signed_' . md5( $uid );
		$cached = get_transient( $cache );
		if ( false !== $cached ) {
			return '1' === $cached;
		}
		$video    = $this->cloudflare->get_video( $uid );
		$requires = ! is_wp_error( $video ) && ! empty( $video['requireSignedURLs'] );
		set_transient( $cache, $requires ? '1' : '0', HOUR_IN_SECONDS );
		return $requires;
	}

	/**
	 * Resolve the poster URL from the block's poster settings.
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
		return $this->cloudflare->thumbnail_url( $playback_id, array( 'time' => $time . 's' ) );
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
