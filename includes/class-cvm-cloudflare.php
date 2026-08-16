<?php
/**
 * Cloudflare Stream API client.
 *
 * Stateless wrapper around the Cloudflare Stream REST API (and the GraphQL
 * analytics endpoint). Holds no per-request state beyond the cached video list
 * and the derived customer code. The API token never leaves the server.
 *
 * @package CoywolfVideoManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Talks to Cloudflare Stream on behalf of the plugin.
 */
class Coywolf_CVM_Cloudflare {

	/**
	 * Cloudflare API v4 base URL.
	 */
	const API_BASE = 'https://api.cloudflare.com/client/v4';

	/**
	 * How long the video list is cached, in seconds.
	 */
	const LIST_TTL = 300;

	/**
	 * Name of the wp-config.php constant / environment variable for the API token.
	 */
	const TOKEN_CONST = 'COYWOLF_CVM_API_TOKEN';

	/**
	 * Name of the wp-config.php constant / environment variable for the account ID.
	 */
	const ACCOUNT_CONST = 'COYWOLF_CVM_ACCOUNT_ID';

	/**
	 * Per-request cache of the resolved playback customer code.
	 *
	 * @var string|null
	 */
	private $customer_code_cache = null;

	/* --------------------------------------------------------------------- *
	 * Credentials
	 * --------------------------------------------------------------------- */

	/**
	 * Get the API token. Resolution order, mirroring Coywolf SEO's AI keys:
	 * the saved option wins, then the {@see TOKEN_CONST} wp-config constant,
	 * then the same-named environment variable.
	 *
	 * @return string
	 */
	public function get_token() {
		return $this->resolve( 'coywolf_cvm_api_token', self::TOKEN_CONST );
	}

	/**
	 * Get the Cloudflare account ID. Same resolution order as {@see get_token()}.
	 *
	 * @return string
	 */
	public function get_account_id() {
		return $this->resolve( 'coywolf_cvm_account_id', self::ACCOUNT_CONST );
	}

	/**
	 * Resolve a credential: saved option, else the wp-config constant, else the
	 * same-named environment variable.
	 *
	 * @param string $option     Option name holding the saved value.
	 * @param string $const_name Constant / environment-variable name.
	 * @return string
	 */
	private function resolve( $option, $const_name ) {
		$saved = (string) get_option( $option, '' );
		if ( '' !== $saved ) {
			return $saved;
		}
		if ( defined( $const_name ) && '' !== (string) constant( $const_name ) ) {
			return (string) constant( $const_name );
		}
		$env = getenv( $const_name );
		return ( is_string( $env ) && '' !== $env ) ? $env : '';
	}

	/**
	 * Where the API token comes from, for the Settings status row.
	 *
	 * @return array{source:string,saved:bool,constant:bool,env:bool}
	 */
	public function token_status() {
		return $this->credential_status( 'coywolf_cvm_api_token', self::TOKEN_CONST );
	}

	/**
	 * Where the account ID comes from, for the Settings status row.
	 *
	 * @return array{source:string,saved:bool,constant:bool,env:bool}
	 */
	public function account_status() {
		return $this->credential_status( 'coywolf_cvm_account_id', self::ACCOUNT_CONST );
	}

	/**
	 * Describe a credential's source (option > constant > env), matching the
	 * shape Coywolf SEO uses for its AI key status row.
	 *
	 * @param string $option     Option name holding the saved value.
	 * @param string $const_name Constant / environment-variable name.
	 * @return array{source:string,saved:bool,constant:bool,env:bool}
	 */
	private function credential_status( $option, $const_name ) {
		$saved    = '' !== (string) get_option( $option, '' );
		$constant = defined( $const_name ) && '' !== (string) constant( $const_name );
		$env_val  = getenv( $const_name );
		$env      = is_string( $env_val ) && '' !== $env_val;

		if ( $saved ) {
			$source = 'saved';
		} elseif ( $constant ) {
			$source = 'constant';
		} elseif ( $env ) {
			$source = 'env';
		} else {
			$source = '';
		}

		return array(
			'source'   => $source,
			'saved'    => $saved,
			'constant' => $constant,
			'env'      => $env,
		);
	}

	/**
	 * Whether both credentials are present.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->get_token() && '' !== $this->get_account_id();
	}

	/* --------------------------------------------------------------------- *
	 * Core request wrapper
	 * --------------------------------------------------------------------- */

	/**
	 * Build a Stream endpoint URL for the configured account.
	 *
	 * @param string $suffix Path/query appended after `/stream`.
	 * @return string
	 */
	private function stream_url( $suffix = '' ) {
		return self::API_BASE . '/accounts/' . rawurlencode( $this->get_account_id() ) . '/stream' . $suffix;
	}

	/**
	 * Perform an authenticated request and unwrap Cloudflare's response envelope.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    Absolute URL.
	 * @param array  $args   Optional: body (array → JSON, or string with raw_body),
	 *                       headers, timeout.
	 * @return array|WP_Error Decoded response array, or WP_Error on failure.
	 */
	private function request( $method, $url, $args = array() ) {
		$token = $this->get_token();
		if ( '' === $token ) {
			return new WP_Error( 'coywolf_cvm_no_token', __( 'No Cloudflare API token is configured.', 'coywolf-video-manager' ) );
		}

		$request = array(
			'method'  => $method,
			'timeout' => isset( $args['timeout'] ) ? (int) $args['timeout'] : 15,
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
		);

		if ( ! empty( $args['headers'] ) && is_array( $args['headers'] ) ) {
			$request['headers'] = array_merge( $request['headers'], $args['headers'] );
		}

		if ( isset( $args['body'] ) ) {
			if ( is_array( $args['body'] ) && empty( $args['raw_body'] ) ) {
				$request['headers']['Content-Type'] = 'application/json';
				$request['body']                    = wp_json_encode( $args['body'] );
			} else {
				$request['body'] = $args['body'];
			}
		}

		$response = wp_remote_request( $url, $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		// JSON endpoints return a { success, errors, result } envelope.
		if ( is_array( $data ) && array_key_exists( 'success', $data ) ) {
			if ( $data['success'] ) {
				return $data;
			}
			return new WP_Error(
				'coywolf_cvm_api_error',
				$this->error_message( $data ),
				array(
					'status' => $code,
					'errors' => isset( $data['errors'] ) ? $data['errors'] : array(),
				)
			);
		}

		if ( $code >= 200 && $code < 300 ) {
			return is_array( $data ) ? $data : array();
		}

		return new WP_Error(
			'coywolf_cvm_http_error',
			/* translators: %d: HTTP status code. */
			sprintf( __( 'Cloudflare returned an unexpected response (HTTP %d).', 'coywolf-video-manager' ), $code ),
			array( 'status' => $code )
		);
	}

	/**
	 * Flatten Cloudflare's errors array into a readable message.
	 *
	 * @param array $data Decoded response.
	 * @return string
	 */
	private function error_message( $data ) {
		$messages = array();
		if ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
			foreach ( $data['errors'] as $error ) {
				if ( isset( $error['message'] ) ) {
					$messages[] = $error['message'];
				}
			}
		}
		if ( empty( $messages ) ) {
			return __( 'Cloudflare rejected the request.', 'coywolf-video-manager' );
		}
		return implode( ' ', $messages );
	}

	/**
	 * Build a multipart/form-data request (used for caption uploads).
	 *
	 * @param string $method        HTTP method.
	 * @param string $url           Absolute URL.
	 * @param array  $fields        Plain form fields (name => value).
	 * @param string $file_field    Name of the file field.
	 * @param string $file_name     Filename to report.
	 * @param string $file_contents Raw file bytes.
	 * @param string $file_type     MIME type.
	 * @return array|WP_Error
	 */
	private function multipart_request( $method, $url, $fields, $file_field, $file_name, $file_contents, $file_type ) {
		$boundary = 'cvm' . wp_generate_password( 24, false );
		$eol      = "\r\n";
		$body     = '';
		foreach ( $fields as $name => $value ) {
			$body .= '--' . $boundary . $eol;
			$body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
			$body .= $value . $eol;
		}
		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="' . $file_field . '"; filename="' . $file_name . '"' . $eol;
		$body .= 'Content-Type: ' . $file_type . $eol . $eol;
		$body .= $file_contents . $eol;
		$body .= '--' . $boundary . '--' . $eol;

		return $this->request(
			$method,
			$url,
			array(
				'body'     => $body,
				'raw_body' => true,
				'timeout'  => 30,
				'headers'  => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
			)
		);
	}

	/* --------------------------------------------------------------------- *
	 * Videos
	 * --------------------------------------------------------------------- */

	/**
	 * List (and optionally search) videos. Cached for LIST_TTL unless forced.
	 *
	 * @param array $args limit, search, status, creator, force.
	 * @return array|WP_Error Array of video objects, or WP_Error.
	 */
	public function list_videos( $args = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'coywolf_cvm_not_configured', __( 'Connect a Cloudflare account first.', 'coywolf-video-manager' ) );
		}

		$args  = wp_parse_args(
			$args,
			array(
				'limit'   => 1000,
				'search'  => '',
				'status'  => '',
				'creator' => '',
				'force'   => false,
			)
		);
		$query = array( 'limit' => min( 1000, max( 1, (int) $args['limit'] ) ) );
		if ( '' !== $args['search'] ) {
			$query['search'] = $args['search'];
		}
		if ( '' !== $args['status'] ) {
			$query['status'] = $args['status'];
		}
		if ( '' !== $args['creator'] ) {
			$query['creator'] = $args['creator'];
		}

		$cache_key = 'coywolf_cvm_list_cache_' . md5( wp_json_encode( $query ) );
		if ( ! $args['force'] ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$response = $this->request( 'GET', $this->stream_url( '?' . http_build_query( $query ) ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$result = ( isset( $response['result'] ) && is_array( $response['result'] ) ) ? $response['result'] : array();
		set_transient( $cache_key, $result, self::LIST_TTL );
		$this->remember_cache_key( $cache_key );
		$this->learn_customer_code( $result );

		return $result;
	}

	/**
	 * Fetch a single video.
	 *
	 * @param string $uid Video UID.
	 * @return array|WP_Error Video object, or WP_Error.
	 */
	public function get_video( $uid ) {
		$response = $this->request( 'GET', $this->stream_url( '/' . rawurlencode( $uid ) ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return isset( $response['result'] ) ? $response['result'] : array();
	}

	/**
	 * Update a video's metadata (name, creator, allowedOrigins,
	 * requireSignedURLs, thumbnailTimestampPct, …).
	 *
	 * @param string $uid    Video UID.
	 * @param array  $fields Fields to set.
	 * @return array|WP_Error Updated video object, or WP_Error.
	 */
	public function update_video( $uid, $fields ) {
		$response = $this->request( 'POST', $this->stream_url( '/' . rawurlencode( $uid ) ), array( 'body' => $fields ) );
		$this->flush_list_cache();
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return isset( $response['result'] ) ? $response['result'] : array();
	}

	/**
	 * Delete a video.
	 *
	 * @param string $uid Video UID.
	 * @return true|WP_Error
	 */
	public function delete_video( $uid ) {
		$response = $this->request( 'DELETE', $this->stream_url( '/' . rawurlencode( $uid ) ), array( 'timeout' => 30 ) );
		$this->flush_list_cache();
		$this->flush_storage_usage();
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return true;
	}

	/**
	 * Create a one-time TUS (resumable) direct creator upload. The browser
	 * then PATCHes file chunks straight to the returned URL without ever
	 * seeing the API token. Unlike the basic direct upload, TUS has no
	 * 200 MB ceiling.
	 *
	 * @param int    $length   File size in bytes.
	 * @param array  $metadata Upload-Metadata pairs (e.g. name, maxDurationSeconds).
	 * @param string $creator  Optional creator id.
	 * @return array|WP_Error { uploadURL, uid }
	 */
	public function tus_create( $length, $metadata = array(), $creator = '' ) {
		$token = $this->get_token();
		if ( '' === $token ) {
			return new WP_Error( 'coywolf_cvm_no_token', __( 'No Cloudflare API token is configured.', 'coywolf-video-manager' ) );
		}

		$pairs = array();
		foreach ( $metadata as $key => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			// The TUS protocol requires metadata values base64-encoded.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			$pairs[] = $key . ' ' . base64_encode( (string) $value );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Tus-Resumable' => '1.0.0',
			'Upload-Length' => (string) max( 1, (int) $length ),
		);
		if ( ! empty( $pairs ) ) {
			$headers['Upload-Metadata'] = implode( ',', $pairs );
		}
		if ( '' !== $creator ) {
			$headers['Upload-Creator'] = $creator;
		}

		$response = wp_remote_post(
			$this->stream_url( '?direct_user=true' ),
			array(
				'timeout' => 30,
				'headers' => $headers,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code     = (int) wp_remote_retrieve_response_code( $response );
		$location = wp_remote_retrieve_header( $response, 'location' );
		$uid      = wp_remote_retrieve_header( $response, 'stream-media-id' );
		if ( $code < 200 || $code >= 300 || empty( $location ) ) {
			return new WP_Error(
				'coywolf_cvm_http_error',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'Cloudflare returned an unexpected response (HTTP %d).', 'coywolf-video-manager' ), $code ),
				array( 'status' => $code )
			);
		}
		$this->flush_list_cache();
		$this->flush_storage_usage();
		return array(
			'uploadURL' => is_array( $location ) ? (string) reset( $location ) : (string) $location,
			'uid'       => is_array( $uid ) ? (string) reset( $uid ) : (string) $uid,
		);
	}

	/**
	 * Account-wide Stream storage usage, cached for five minutes.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array|WP_Error { videoCount, totalStorageMinutes, totalStorageMinutesLimit }
	 */
	public function storage_usage( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( 'coywolf_cvm_storage_usage' );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$response = $this->request( 'GET', $this->stream_url( '/storage-usage' ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$result = isset( $response['result'] ) && is_array( $response['result'] ) ? $response['result'] : array();
		$usage  = array(
			'videoCount'               => isset( $result['videoCount'] ) ? (int) $result['videoCount'] : 0,
			'totalStorageMinutes'      => isset( $result['totalStorageMinutes'] ) ? (int) $result['totalStorageMinutes'] : 0,
			'totalStorageMinutesLimit' => isset( $result['totalStorageMinutesLimit'] ) ? (int) $result['totalStorageMinutesLimit'] : 0,
		);
		set_transient( 'coywolf_cvm_storage_usage', $usage, 5 * MINUTE_IN_SECONDS );
		return $usage;
	}

	/**
	 * Drop the cached storage usage (after uploads, imports, and deletes).
	 */
	public function flush_storage_usage() {
		delete_transient( 'coywolf_cvm_storage_usage' );
	}

	/**
	 * Create a one-time direct creator upload URL.
	 *
	 * @param array $opts maxDurationSeconds (required), meta, creator,
	 *                    requireSignedURLs, allowedOrigins, thumbnailTimestampPct.
	 * @return array|WP_Error { uploadURL, uid }.
	 */
	public function create_direct_upload( $opts ) {
		$opts = wp_parse_args( $opts, array( 'maxDurationSeconds' => 3600 ) );
		$response = $this->request( 'POST', $this->stream_url( '/direct_upload' ), array( 'body' => $opts ) );
		$this->flush_list_cache();
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return isset( $response['result'] ) ? $response['result'] : array();
	}

	/* --------------------------------------------------------------------- *
	 * Webhook subscription
	 * --------------------------------------------------------------------- */

	/**
	 * Subscribe the account's Stream webhook to a notification URL (Cloudflare
	 * allows one subscription per account; this replaces any existing one).
	 *
	 * @param string $notification_url Receiver URL.
	 * @return array|WP_Error Result incl. the HMAC `secret` for verification.
	 */
	public function set_webhook( $notification_url ) {
		$response = $this->request(
			'PUT',
			$this->stream_url( '/webhook' ),
			array(
				'body'    => array( 'notificationUrl' => $notification_url ),
				'timeout' => 30,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return isset( $response['result'] ) && is_array( $response['result'] ) ? $response['result'] : array();
	}

	/**
	 * Delete the account's Stream webhook subscription.
	 *
	 * @return true|WP_Error
	 */
	public function delete_webhook() {
		$response = $this->request( 'DELETE', $this->stream_url( '/webhook' ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return true;
	}

	/* --------------------------------------------------------------------- *
	 * MP4 downloads
	 * --------------------------------------------------------------------- */

	/**
	 * Ask Cloudflare to generate (or report) the downloadable MP4 for a video.
	 *
	 * @param string $uid Video UID.
	 * @return array|WP_Error|null { status, url, percent }, or null when none.
	 */
	public function enable_download( $uid ) {
		$response = $this->request( 'POST', $this->stream_url( '/' . rawurlencode( $uid ) . '/downloads' ), array( 'timeout' => 30 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return $this->normalize_download( $response );
	}

	/**
	 * Current MP4 download state for a video.
	 *
	 * @param string $uid Video UID.
	 * @return array|WP_Error|null { status, url, percent }, or null when none.
	 */
	public function get_download( $uid ) {
		$response = $this->request( 'GET', $this->stream_url( '/' . rawurlencode( $uid ) . '/downloads' ) );
		if ( is_wp_error( $response ) ) {
			$data   = $response->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
			// No download has ever been created for this video.
			if ( 404 === $status ) {
				return null;
			}
			return $response;
		}
		return $this->normalize_download( $response );
	}

	/**
	 * Remove a video's downloadable MP4.
	 *
	 * @param string $uid Video UID.
	 * @return true|WP_Error
	 */
	public function delete_download( $uid ) {
		$response = $this->request( 'DELETE', $this->stream_url( '/' . rawurlencode( $uid ) . '/downloads' ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return true;
	}

	/**
	 * Reduce Cloudflare's downloads envelope to { status, url, percent }.
	 *
	 * @param array $response Decoded response.
	 * @return array|null Null when no default download exists.
	 */
	private function normalize_download( $response ) {
		$result = isset( $response['result'] ) && is_array( $response['result'] ) ? $response['result'] : array();
		if ( empty( $result['default'] ) || ! is_array( $result['default'] ) ) {
			return null;
		}
		$download = $result['default'];
		return array(
			'status'  => isset( $download['status'] ) ? (string) $download['status'] : '',
			'url'     => isset( $download['url'] ) ? (string) $download['url'] : '',
			'percent' => isset( $download['percentComplete'] ) ? (float) $download['percentComplete'] : 0,
		);
	}

	/* --------------------------------------------------------------------- *
	 * Captions
	 * --------------------------------------------------------------------- */

	/**
	 * List a video's captions.
	 *
	 * @param string $uid Video UID.
	 * @return array|WP_Error
	 */
	public function list_captions( $uid ) {
		$response = $this->request( 'GET', $this->stream_url( '/' . rawurlencode( $uid ) . '/captions' ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return isset( $response['result'] ) && is_array( $response['result'] ) ? $response['result'] : array();
	}

	/**
	 * Upload a WebVTT caption file for a language.
	 *
	 * @param string $uid          Video UID.
	 * @param string $lang         BCP-47 language code.
	 * @param string $vtt_contents Raw VTT bytes.
	 * @return array|WP_Error
	 */
	public function upload_caption( $uid, $lang, $vtt_contents ) {
		$url = $this->stream_url( '/' . rawurlencode( $uid ) . '/captions/' . rawurlencode( $lang ) );
		$response = $this->multipart_request( 'PUT', $url, array(), 'file', $lang . '.vtt', $vtt_contents, 'text/vtt' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return isset( $response['result'] ) ? $response['result'] : array();
	}

	/**
	 * Ask Cloudflare to auto-generate captions (speech-to-text) for a language.
	 *
	 * @param string $uid  Video UID.
	 * @param string $lang BCP-47 language code.
	 * @return array|WP_Error
	 */
	public function generate_caption( $uid, $lang ) {
		$url = $this->stream_url( '/' . rawurlencode( $uid ) . '/captions/' . rawurlencode( $lang ) . '/generate' );
		$response = $this->request( 'POST', $url, array( 'timeout' => 30 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return isset( $response['result'] ) ? $response['result'] : array();
	}

	/**
	 * Download a caption's raw WebVTT through the authenticated API.
	 *
	 * @param string $uid  Video UID.
	 * @param string $lang BCP-47 language code.
	 * @return string|WP_Error Raw VTT contents.
	 */
	public function caption_vtt( $uid, $lang ) {
		$token = $this->get_token();
		if ( '' === $token ) {
			return new WP_Error( 'coywolf_cvm_no_token', __( 'No Cloudflare API token is configured.', 'coywolf-video-manager' ) );
		}
		$url      = $this->stream_url( '/' . rawurlencode( $uid ) . '/captions/' . rawurlencode( $lang ) . '/vtt' );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'coywolf_cvm_http_error',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'Cloudflare returned an unexpected response (HTTP %d).', 'coywolf-video-manager' ), $code ),
				array( 'status' => $code )
			);
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Public (unauthenticated) URL candidates where Cloudflare's delivery
	 * hostname may serve a caption's WebVTT directly. Callers must verify a
	 * candidate actually responds with VTT before using it.
	 *
	 * @param string $uid  Video UID.
	 * @param string $lang BCP-47 language code.
	 * @return string[]
	 */
	public function caption_public_urls( $uid, $lang ) {
		$base = $this->playback_base( $uid ) . '/captions/' . rawurlencode( $lang );
		return array( $base, $base . '/vtt' );
	}

	/**
	 * Delete a caption language.
	 *
	 * @param string $uid  Video UID.
	 * @param string $lang BCP-47 language code.
	 * @return true|WP_Error
	 */
	public function delete_caption( $uid, $lang ) {
		$url = $this->stream_url( '/' . rawurlencode( $uid ) . '/captions/' . rawurlencode( $lang ) );
		$response = $this->request( 'DELETE', $url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return true;
	}

	/* --------------------------------------------------------------------- *
	 * Playback URLs + customer code
	 * --------------------------------------------------------------------- */

	/**
	 * The customer code that prefixes playback hostnames, derived from the API
	 * and cached. Empty string until a ready video reveals it.
	 *
	 * @return string
	 */
	public function customer_code() {
		if ( null !== $this->customer_code_cache ) {
			return $this->customer_code_cache;
		}
		$code = (string) get_option( 'coywolf_cvm_customer_code', '' );
		// Only learn inline in wp-admin / REST / CLI; never block a public page view
		// on a remote Cloudflare call. On the front end, the URL builders already
		// fall back to videodelivery.net until a background path (admin All-Videos
		// table, REST list, sitemap) learns the code.
		if ( '' === $code && $this->is_configured()
			&& ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) ) {
			$list = $this->list_videos( array( 'limit' => 50 ) );
			if ( ! is_wp_error( $list ) ) {
				$this->learn_customer_code( $list );
			}
			$code = (string) get_option( 'coywolf_cvm_customer_code', '' );
		}
		$this->customer_code_cache = $code;
		return $code;
	}

	/**
	 * Inspect video objects for a customer-coded playback URL and cache the code.
	 *
	 * @param array $videos Video objects.
	 */
	private function learn_customer_code( $videos ) {
		if ( '' !== (string) get_option( 'coywolf_cvm_customer_code', '' ) || ! is_array( $videos ) ) {
			return;
		}
		foreach ( $videos as $video ) {
			$candidates = array(
				isset( $video['thumbnail'] ) ? $video['thumbnail'] : '',
				isset( $video['preview'] ) ? $video['preview'] : '',
				isset( $video['playback']['hls'] ) ? $video['playback']['hls'] : '',
			);
			foreach ( $candidates as $url ) {
				if ( $url && preg_match( '#//customer-([a-z0-9]+)\.cloudflarestream\.com#i', $url, $m ) ) {
					// Autoloaded: it's ~10 chars and read on every front-end
					// render to build playback URLs.
					update_option( 'coywolf_cvm_customer_code', $m[1], true );
					return;
				}
			}
		}
	}

	/**
	 * Base playback URL for a playback id (a UID, or a signed token).
	 *
	 * @param string $playback_id UID or signed token.
	 * @return string No trailing slash.
	 */
	private function playback_base( $playback_id ) {
		$code = $this->customer_code();
		if ( '' !== $code ) {
			return 'https://customer-' . $code . '.cloudflarestream.com/' . rawurlencode( $playback_id );
		}
		// Customer-code-independent fallback until a ready video reveals the code.
		return 'https://videodelivery.net/' . rawurlencode( $playback_id );
	}

	/**
	 * Iframe embed URL.
	 *
	 * @param string $playback_id UID or signed token.
	 * @param array  $params      Query params (autoplay, muted, …).
	 * @return string
	 */
	public function iframe_url( $playback_id, $params = array() ) {
		$code = $this->customer_code();
		if ( '' !== $code ) {
			$url = 'https://customer-' . $code . '.cloudflarestream.com/' . rawurlencode( $playback_id ) . '/iframe';
		} else {
			$url = 'https://iframe.videodelivery.net/' . rawurlencode( $playback_id );
		}
		$params = array_filter( $params, array( $this, 'is_present' ) );
		if ( ! empty( $params ) ) {
			$url .= '?' . http_build_query( $params );
		}
		return $url;
	}

	/**
	 * Thumbnail (poster) URL.
	 *
	 * @param string $playback_id UID or signed token.
	 * @param array  $params      time, height, width, fit.
	 * @return string
	 */
	public function thumbnail_url( $playback_id, $params = array() ) {
		$url    = $this->playback_base( $playback_id ) . '/thumbnails/thumbnail.jpg';
		$params = array_filter( $params, array( $this, 'is_present' ) );
		if ( ! empty( $params ) ) {
			$url .= '?' . http_build_query( $params );
		}
		return $url;
	}

	/**
	 * Public watch page URL (used as the block's static fallback link).
	 *
	 * @param string $playback_id UID or signed token.
	 * @return string
	 */
	public function watch_url( $playback_id ) {
		$code = $this->customer_code();
		if ( '' !== $code ) {
			return 'https://customer-' . $code . '.cloudflarestream.com/' . rawurlencode( $playback_id ) . '/watch';
		}
		return 'https://iframe.videodelivery.net/' . rawurlencode( $playback_id );
	}

	/**
	 * array_filter callback: keep values that are not null/empty-string.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function is_present( $value ) {
		return null !== $value && '' !== $value;
	}

	/* --------------------------------------------------------------------- *
	 * Connection + cache
	 * --------------------------------------------------------------------- */

	/**
	 * Verify the stored credentials with a cheap list call.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		if ( '' === $this->get_token() ) {
			return new WP_Error( 'coywolf_cvm_no_token', __( 'Enter an API token.', 'coywolf-video-manager' ) );
		}
		if ( '' === $this->get_account_id() ) {
			return new WP_Error( 'coywolf_cvm_no_account', __( 'Enter your Cloudflare Account ID.', 'coywolf-video-manager' ) );
		}
		$response = $this->request( 'GET', $this->stream_url( '?limit=1' ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return true;
	}

	/**
	 * Remember a list-cache transient key so it can be flushed later.
	 *
	 * @param string $key Transient key.
	 */
	private function remember_cache_key( $key ) {
		$keys = get_option( 'coywolf_cvm_list_keys', array() );
		if ( ! is_array( $keys ) ) {
			$keys = array();
		}
		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			update_option( 'coywolf_cvm_list_keys', $keys, false );
		}
	}

	/**
	 * Drop every cached video list.
	 */
	public function flush_list_cache() {
		$keys = get_option( 'coywolf_cvm_list_keys', array() );
		if ( is_array( $keys ) ) {
			foreach ( $keys as $key ) {
				delete_transient( $key );
			}
		}
		delete_option( 'coywolf_cvm_list_keys' );
	}
}
