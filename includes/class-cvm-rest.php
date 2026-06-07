<?php
/**
 * REST controller — namespace coywolf-cvm/v1.
 *
 * Admin routes proxy the Cloudflare client so the API token stays server-side
 * (the browser only ever talks to this REST API). Public play/like routes are
 * capability-free but throttled, deduped, and rate-limited.
 *
 * @package CoywolfVideoManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles the plugin's REST routes.
 */
class Coywolf_CVM_REST {

	/**
	 * REST namespace.
	 */
	const NS = 'coywolf-cvm/v1';

	/**
	 * Cloudflare client.
	 *
	 * @var Coywolf_CVM_Cloudflare
	 */
	private $cloudflare;

	/**
	 * Stats store.
	 *
	 * @var Coywolf_CVM_Stats
	 */
	private $stats;

	/**
	 * Usage index.
	 *
	 * @var Coywolf_CVM_Index
	 */
	private $index;

	/**
	 * Constructor.
	 *
	 * @param Coywolf_CVM_Cloudflare $cloudflare API client.
	 * @param Coywolf_CVM_Stats      $stats      Stats store.
	 * @param Coywolf_CVM_Index      $index      Usage index.
	 */
	public function __construct( Coywolf_CVM_Cloudflare $cloudflare, Coywolf_CVM_Stats $stats, Coywolf_CVM_Index $index ) {
		$this->cloudflare = $cloudflare;
		$this->stats      = $stats;
		$this->index      = $index;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all routes.
	 */
	public function register_routes() {
		$admin  = array( $this, 'admin_permission' );
		$public = '__return_true';
		$uid    = array(
			'uid' => array(
				'validate_callback' => static function ( $value ) {
					return is_string( $value ) && (bool) preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $value );
				},
			),
		);

		register_rest_route(
			self::NS,
			'/videos',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_videos' ),
				'permission_callback' => $admin,
				'args'                => array( 's' => array( 'sanitize_callback' => 'sanitize_text_field' ) ),
			)
		);

		register_rest_route(
			self::NS,
			'/videos/(?P<uid>[A-Za-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_video' ),
					'permission_callback' => $admin,
					'args'                => $uid,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_video' ),
					'permission_callback' => $admin,
					'args'                => $uid,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_video' ),
					'permission_callback' => $admin,
					'args'                => $uid,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/direct-upload',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_direct_upload' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NS,
			'/videos/(?P<uid>[A-Za-z0-9_-]+)/captions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_captions' ),
				'permission_callback' => $admin,
				'args'                => $uid,
			)
		);

		register_rest_route(
			self::NS,
			'/videos/(?P<uid>[A-Za-z0-9_-]+)/captions/(?P<lang>[A-Za-z-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_caption' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_caption' ),
					'permission_callback' => $admin,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/videos/(?P<uid>[A-Za-z0-9_-]+)/captions/(?P<lang>[A-Za-z-]+)/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate_caption' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NS,
			'/thumbnail/(?P<uid>[A-Za-z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'thumbnail' ),
				'permission_callback' => $admin,
				'args'                => $uid,
			)
		);

		register_rest_route(
			self::NS,
			'/test-connection',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_connection' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NS,
			'/play/(?P<uid>[A-Za-z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'record_play' ),
				'permission_callback' => $public,
				'args'                => $uid,
			)
		);

		register_rest_route(
			self::NS,
			'/like/(?P<uid>[A-Za-z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'toggle_like' ),
				'permission_callback' => $public,
				'args'                => $uid,
			)
		);
	}

	/**
	 * Admin permission check.
	 *
	 * @return bool
	 */
	public function admin_permission() {
		return current_user_can( Coywolf_Video_Manager::CAPABILITY );
	}

	/* --------------------------------------------------------------------- *
	 * Admin: videos
	 * --------------------------------------------------------------------- */

	/**
	 * List/search videos.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_videos( $request ) {
		$videos = $this->cloudflare->list_videos(
			array(
				'search' => (string) $request->get_param( 's' ),
				'force'  => (bool) $request->get_param( 'refresh' ),
			)
		);
		if ( is_wp_error( $videos ) ) {
			return $videos;
		}
		$out = array();
		foreach ( $videos as $video ) {
			$out[] = $this->normalize_video( $video );
		}
		return rest_ensure_response( array( 'videos' => $out ) );
	}

	/**
	 * Get a single video.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_video( $request ) {
		$video = $this->cloudflare->get_video( $request['uid'] );
		if ( is_wp_error( $video ) ) {
			return $video;
		}
		return rest_ensure_response( $this->normalize_video( $video ) );
	}

	/**
	 * Update a video.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_video( $request ) {
		$fields = array();
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		if ( isset( $params['name'] ) ) {
			$fields['meta'] = array( 'name' => sanitize_text_field( $params['name'] ) );
		}
		if ( isset( $params['creator'] ) ) {
			$fields['creator'] = sanitize_text_field( $params['creator'] );
		}
		if ( isset( $params['allowedOrigins'] ) ) {
			$origins = is_array( $params['allowedOrigins'] ) ? $params['allowedOrigins'] : preg_split( '/[\s,]+/', (string) $params['allowedOrigins'] );
			$fields['allowedOrigins'] = array_values( array_filter( array_map( 'sanitize_text_field', (array) $origins ) ) );
		}
		if ( isset( $params['thumbnailTimestampPct'] ) ) {
			$fields['thumbnailTimestampPct'] = max( 0, min( 1, (float) $params['thumbnailTimestampPct'] ) );
		}

		// Store the per-video default poster (timestamp or Media Library image).
		$this->save_poster( (string) $request['uid'], $params );

		// Store the per-video description (used in schema + the XML sitemap).
		if ( isset( $params['description'] ) ) {
			$this->save_description( (string) $request['uid'], (string) $params['description'] );
		}

		if ( empty( $fields ) ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}

		$video = $this->cloudflare->update_video( $request['uid'], $fields );
		if ( is_wp_error( $video ) ) {
			return $video;
		}
		return rest_ensure_response( $this->normalize_video( $video ) );
	}

	/**
	 * Persist the per-video default poster from the Edit Video form.
	 *
	 * @param string $uid    Video UID.
	 * @param array  $params Request params.
	 */
	private function save_poster( $uid, $params ) {
		if ( ! isset( $params['posterMode'] ) ) {
			return;
		}
		$mode  = ( 'image' === $params['posterMode'] ) ? 'image' : 'timestamp';
		$entry = array( 'mode' => $mode );
		if ( 'image' === $mode ) {
			$entry['image_id']  = isset( $params['posterImageId'] ) ? absint( $params['posterImageId'] ) : 0;
			$entry['image_url'] = isset( $params['posterImageUrl'] ) ? esc_url_raw( $params['posterImageUrl'] ) : '';
		} else {
			$entry['time'] = isset( $params['posterTime'] ) ? max( 0, (float) $params['posterTime'] ) : 0;
		}

		$all = get_option( 'coywolf_cvm_posters', array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$all[ $uid ] = $entry;
		update_option( 'coywolf_cvm_posters', $all, false );
	}

	/**
	 * Persist the per-video description from the Edit Video form.
	 *
	 * @param string $uid  Video UID.
	 * @param string $text Description text.
	 */
	private function save_description( $uid, $text ) {
		$text = sanitize_textarea_field( $text );
		$all  = get_option( 'coywolf_cvm_descriptions', array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		if ( '' === $text ) {
			unset( $all[ $uid ] );
		} else {
			$all[ $uid ] = $text;
		}
		update_option( 'coywolf_cvm_descriptions', $all, false );
	}

	/**
	 * Delete a video.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_video( $request ) {
		$uid    = (string) $request['uid'];
		$result = $this->cloudflare->delete_video( $uid );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// Remove the block from any post/page and purge local data.
		Coywolf_Video_Manager::instance()->purge_video( $uid );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Create a one-time direct upload URL.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_direct_upload( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$opts = array(
			'maxDurationSeconds' => isset( $params['maxDurationSeconds'] ) ? max( 1, (int) $params['maxDurationSeconds'] ) : 3600,
		);
		if ( ! empty( $params['name'] ) ) {
			$opts['meta'] = array( 'name' => sanitize_text_field( $params['name'] ) );
		}
		if ( ! empty( $params['creator'] ) ) {
			$opts['creator'] = sanitize_text_field( $params['creator'] );
		}
		if ( ! empty( $params['allowedOrigins'] ) ) {
			$origins = is_array( $params['allowedOrigins'] ) ? $params['allowedOrigins'] : preg_split( '/[\s,]+/', (string) $params['allowedOrigins'] );
			$opts['allowedOrigins'] = array_values( array_filter( array_map( 'sanitize_text_field', (array) $origins ) ) );
		}
		if ( isset( $params['thumbnailTimestampPct'] ) ) {
			$opts['thumbnailTimestampPct'] = max( 0, min( 1, (float) $params['thumbnailTimestampPct'] ) );
		}

		$result = $this->cloudflare->create_direct_upload( $opts );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/* --------------------------------------------------------------------- *
	 * Admin: captions / thumbnail / connection / analytics
	 * --------------------------------------------------------------------- */

	/**
	 * List captions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_captions( $request ) {
		$captions = $this->cloudflare->list_captions( $request['uid'] );
		if ( is_wp_error( $captions ) ) {
			return $captions;
		}
		return rest_ensure_response( array( 'captions' => $captions ) );
	}

	/**
	 * Upload a caption (VTT text in the `vtt` param).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_caption( $request ) {
		$vtt = (string) $request->get_param( 'vtt' );
		if ( '' === trim( $vtt ) ) {
			return new WP_Error( 'coywolf_cvm_empty_vtt', __( 'No caption content was provided.', 'coywolf-video-manager' ), array( 'status' => 400 ) );
		}
		$result = $this->cloudflare->upload_caption( $request['uid'], $this->sanitize_lang( $request['lang'] ), $vtt );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Auto-generate captions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_caption( $request ) {
		$result = $this->cloudflare->generate_caption( $request['uid'], $this->sanitize_lang( $request['lang'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Delete a caption.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_caption( $request ) {
		$result = $this->cloudflare->delete_caption( $request['uid'], $this->sanitize_lang( $request['lang'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Build a thumbnail preview URL.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function thumbnail( $request ) {
		$time   = max( 0, (float) $request->get_param( 'time' ) );
		$height = (int) $request->get_param( 'height' );
		$params = array( 'time' => $time . 's' );
		if ( $height > 0 ) {
			$params['height'] = $height;
		}
		return rest_ensure_response( array( 'url' => $this->cloudflare->thumbnail_url( $request['uid'], $params ) ) );
	}

	/**
	 * Test the Cloudflare connection.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_connection() {
		$result = $this->cloudflare->test_connection();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		delete_transient( 'coywolf_cvm_conn_status' );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* --------------------------------------------------------------------- *
	 * Public: play / like
	 * --------------------------------------------------------------------- */

	/**
	 * Record a play.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function record_play( $request ) {
		if ( $this->rate_limited() ) {
			return new WP_Error( 'coywolf_cvm_rate_limited', __( 'Too many requests.', 'coywolf-video-manager' ), array( 'status' => 429 ) );
		}
		$result = $this->stats->record_play( $request['uid'] );
		return rest_ensure_response( $result );
	}

	/**
	 * Toggle a like.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function toggle_like( $request ) {
		if ( $this->rate_limited() ) {
			return new WP_Error( 'coywolf_cvm_rate_limited', __( 'Too many requests.', 'coywolf-video-manager' ), array( 'status' => 429 ) );
		}
		$result = $this->stats->toggle_like( $request['uid'], $this->stats->voter_hash() );
		return rest_ensure_response( $result );
	}

	/* --------------------------------------------------------------------- *
	 * Helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Normalize a Cloudflare video object to the fields the UI needs.
	 *
	 * @param array $video Raw video.
	 * @return array
	 */
	private function normalize_video( $video ) {
		return array(
			'uid'                   => isset( $video['uid'] ) ? (string) $video['uid'] : '',
			'name'                  => isset( $video['meta']['name'] ) ? (string) $video['meta']['name'] : '',
			'created'               => isset( $video['created'] ) ? (string) $video['created'] : '',
			'duration'              => isset( $video['duration'] ) ? (float) $video['duration'] : 0,
			'ready'                 => ! empty( $video['readyToStream'] ),
			'state'                 => isset( $video['status']['state'] ) ? (string) $video['status']['state'] : '',
			'thumbnail'             => isset( $video['thumbnail'] ) ? (string) $video['thumbnail'] : '',
			'creator'               => isset( $video['creator'] ) ? (string) $video['creator'] : '',
			'allowedOrigins'        => isset( $video['allowedOrigins'] ) && is_array( $video['allowedOrigins'] ) ? $video['allowedOrigins'] : array(),
			'thumbnailTimestampPct' => isset( $video['thumbnailTimestampPct'] ) ? (float) $video['thumbnailTimestampPct'] : 0,
			'width'                 => isset( $video['input']['width'] ) ? (int) $video['input']['width'] : 0,
			'height'                => isset( $video['input']['height'] ) ? (int) $video['input']['height'] : 0,
		);
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

	/**
	 * Best-effort per-IP rate limit for public endpoints.
	 *
	 * @return bool True if the request should be rejected.
	 */
	private function rate_limited() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'coywolf_cvm_rl_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= 120 ) {
			return true;
		}
		set_transient( $key, $n + 1, MINUTE_IN_SECONDS );
		return false;
	}
}
