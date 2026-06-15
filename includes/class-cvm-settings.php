<?php
/**
 * Settings screen + stored configuration for Coywolf Video Manager.
 *
 * Gates the whole plugin behind valid Cloudflare credentials: until a token and
 * account ID test out, only the credentials section renders and the block does
 * not register. Once connected, exposes the player, embed, and feature options
 * whose values are the single source of truth for block attribute inheritance.
 *
 * @package CoywolfVideoManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin settings.
 */
class Coywolf_CVM_Settings {

	/**
	 * Settings group / option_page name.
	 */
	const GROUP = 'coywolf_cvm_settings_group';

	/**
	 * Settings screen slug (also the submenu slug).
	 */
	const PAGE = 'coywolf-video-manager-settings';

	/**
	 * Option holding the feature settings array.
	 */
	const OPTION = 'coywolf_cvm_settings';

	/**
	 * Cloudflare client.
	 *
	 * @var Coywolf_CVM_Cloudflare
	 */
	private $cloudflare;

	/**
	 * Per-request cache of the merged settings (stored values over defaults).
	 *
	 * @var array|null
	 */
	private $cache = null;

	/**
	 * Constructor.
	 *
	 * @param Coywolf_CVM_Cloudflare $cloudflare API client.
	 */
	public function __construct( Coywolf_CVM_Cloudflare $cloudflare ) {
		$this->cloudflare = $cloudflare;

		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_post_coywolf_cvm_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_coywolf_cvm_enable_webhook', array( $this, 'handle_enable_webhook' ) );
		add_action( 'admin_post_coywolf_cvm_disable_webhook', array( $this, 'handle_disable_webhook' ) );
		add_action( 'admin_post_coywolf_cvm_remove_token', array( $this, 'handle_remove_token' ) );

		// Drop the per-request settings cache when the option changes.
		add_action( 'update_option_' . self::OPTION, array( $this, 'flush_cache' ) );
		add_action( 'add_option_' . self::OPTION, array( $this, 'flush_cache' ) );

		foreach ( array( 'coywolf_cvm_account_id', 'coywolf_cvm_api_token' ) as $option ) {
			add_action( 'update_option_' . $option, array( $this, 'on_credentials_changed' ) );
			add_action( 'add_option_' . $option, array( $this, 'on_credentials_changed' ) );
			add_action( 'delete_option_' . $option, array( $this, 'on_credentials_changed' ) );
		}
	}

	/**
	 * Reset the per-request settings cache.
	 */
	public function flush_cache() {
		$this->cache = null;
	}

	/* --------------------------------------------------------------------- *
	 * Defaults + accessors
	 * --------------------------------------------------------------------- */

	/**
	 * Default feature settings. The single source of truth for block defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'player_color'        => '',
			'controls'            => true,
			'autoplay'            => false,
			'loop'                => false,
			'preload'             => 'metadata',
			'mute'                => false,
			'lazy'                => true,
			'plays_enabled'       => true,
			'likes_enabled'       => true,
			'likes_show_count'    => true,
			'show_date'           => true,
			'date_from_post'      => true,
			'sitemap_enabled'     => true,
			// Appearance.
			'color_scheme'        => 'off',
			'align'               => 'left',
			'meta_align'          => 'left',
			'title_color'         => '',
			'show_title'          => true,
			'show_desc'           => true,
			'title_size'          => 1.0,
			'title_weight'        => '700',
			'desc_weight'         => '400',
			'like_icon'           => 'heart',
			'like_color'          => '#0f0f0f',
			'like_bg'             => '#f2f2f2',
			'like_active_color'   => '',
			'like_active_bg'      => '',
			'meta_color'          => '#606060',
			'meta_size'           => 0.9,
			'radius'              => 0,
		);
	}

	/**
	 * Default font sizes (rem) for the appearance controls.
	 *
	 * @return array
	 */
	public static function size_defaults() {
		return array(
			'title_size' => 1.0,
			'meta_size'  => 0.9,
		);
	}

	/**
	 * A font-size setting in rem, falling back to the plugin default.
	 *
	 * @param string $key title_size|meta_size.
	 * @return float
	 */
	public function get_size( $key ) {
		$defaults = self::size_defaults();
		$value    = (float) $this->get( $key );
		if ( $value <= 0 ) {
			return isset( $defaults[ $key ] ) ? $defaults[ $key ] : 1.0;
		}
		return $value;
	}

	/**
	 * Seed default options on activation (without overwriting existing values).
	 */
	public static function seed_defaults() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults() );
		}
		if ( false === get_option( 'coywolf_cvm_version', false ) ) {
			add_option( 'coywolf_cvm_version', Coywolf_Video_Manager::VERSION );
		}
	}

	/**
	 * All settings merged over the defaults.
	 *
	 * @return array
	 */
	public function all() {
		if ( null !== $this->cache ) {
			return $this->cache;
		}
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$this->cache = wp_parse_args( $stored, self::defaults() );
		return $this->cache;
	}

	/**
	 * A single setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public function get( $key ) {
		$all = $this->all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Settings screen URL.
	 *
	 * @return string
	 */
	public function page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/* --------------------------------------------------------------------- *
	 * Registration
	 * --------------------------------------------------------------------- */

	/**
	 * Register settings, sections, and fields. Non-credential sections only
	 * register once the account is connected — that is the gate.
	 */
	public function register() {
		// Both credentials are always saveable. A saved value takes precedence
		// over a wp-config constant or environment variable (see
		// Coywolf_CVM_Cloudflare::get_token()), so the fields stay editable even
		// when a constant is defined — the admin can override or fall back to it.
		register_setting(
			self::GROUP,
			'coywolf_cvm_account_id',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_account_id' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);
		register_setting(
			self::GROUP,
			'coywolf_cvm_api_token',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_token' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);

		add_settings_section( 'coywolf_cvm_credentials', __( 'Cloudflare account', 'coywolf-video-manager' ), '__return_false', self::PAGE );
		add_settings_field( 'coywolf_cvm_setup', __( 'Getting started', 'coywolf-video-manager' ), array( $this, 'render_credentials_intro' ), self::PAGE, 'coywolf_cvm_credentials' );
		add_settings_field( 'coywolf_cvm_account_id', __( 'Account ID', 'coywolf-video-manager' ), array( $this, 'render_account_field' ), self::PAGE, 'coywolf_cvm_credentials' );
		add_settings_field( 'coywolf_cvm_api_token', __( 'API token', 'coywolf-video-manager' ), array( $this, 'render_token_field' ), self::PAGE, 'coywolf_cvm_credentials' );
		add_settings_field( 'coywolf_cvm_connection', __( 'Connection', 'coywolf-video-manager' ), array( $this, 'render_connection_field' ), self::PAGE, 'coywolf_cvm_credentials' );
		add_settings_field( 'coywolf_cvm_webhook', __( 'Webhook', 'coywolf-video-manager' ), array( $this, 'render_webhook_field' ), self::PAGE, 'coywolf_cvm_credentials' );

		if ( ! $this->cloudflare->is_configured() ) {
			return;
		}

		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section( 'coywolf_cvm_player', __( 'Player', 'coywolf-video-manager' ), '__return_false', self::PAGE );
		add_settings_field( 'player_color', __( 'Play button', 'coywolf-video-manager' ), array( $this, 'render_player_color_field' ), self::PAGE, 'coywolf_cvm_player' );

		add_settings_section( 'coywolf_cvm_embed', __( 'Embed defaults', 'coywolf-video-manager' ), array( $this, 'render_embed_intro' ), self::PAGE );
		add_settings_field( 'embed', __( 'Default options', 'coywolf-video-manager' ), array( $this, 'render_embed_field' ), self::PAGE, 'coywolf_cvm_embed' );

		add_settings_section( 'coywolf_cvm_engagement', __( 'Views & likes', 'coywolf-video-manager' ), '__return_false', self::PAGE );
		add_settings_field( 'engagement', __( 'Engagement', 'coywolf-video-manager' ), array( $this, 'render_engagement_field' ), self::PAGE, 'coywolf_cvm_engagement' );

		add_settings_section( 'coywolf_cvm_appearance', __( 'Appearance', 'coywolf-video-manager' ), '__return_false', self::PAGE );
		add_settings_field( 'appearance_preview', __( 'Preview', 'coywolf-video-manager' ), array( $this, 'render_appearance_preview_field' ), self::PAGE, 'coywolf_cvm_appearance' );
		add_settings_field( 'appearance_scheme', __( 'Color scheme', 'coywolf-video-manager' ), array( $this, 'render_appearance_scheme_field' ), self::PAGE, 'coywolf_cvm_appearance' );
		add_settings_field( 'appearance_align', __( 'Alignment', 'coywolf-video-manager' ), array( $this, 'render_appearance_align_field' ), self::PAGE, 'coywolf_cvm_appearance' );
		add_settings_field( 'appearance_radius', __( 'Player corner radius', 'coywolf-video-manager' ), array( $this, 'render_appearance_radius_field' ), self::PAGE, 'coywolf_cvm_appearance' );
		add_settings_field( 'appearance_title', __( 'Name & description', 'coywolf-video-manager' ), array( $this, 'render_appearance_title_field' ), self::PAGE, 'coywolf_cvm_appearance' );
		add_settings_field( 'appearance_like', __( 'Like button', 'coywolf-video-manager' ), array( $this, 'render_appearance_like_field' ), self::PAGE, 'coywolf_cvm_appearance' );
		add_settings_field( 'appearance_meta', __( 'Views & date text', 'coywolf-video-manager' ), array( $this, 'render_appearance_meta_field' ), self::PAGE, 'coywolf_cvm_appearance' );

		add_settings_section( 'coywolf_cvm_advanced', __( 'Sitemap', 'coywolf-video-manager' ), '__return_false', self::PAGE );
		add_settings_field( 'advanced', __( 'Video sitemap', 'coywolf-video-manager' ), array( $this, 'render_advanced_field' ), self::PAGE, 'coywolf_cvm_advanced' );
	}

	/* --------------------------------------------------------------------- *
	 * Sanitizers
	 * --------------------------------------------------------------------- */

	/**
	 * Sanitize the account ID.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_account_id( $value ) {
		return sanitize_text_field( is_string( $value ) ? trim( $value ) : '' );
	}

	/**
	 * Sanitize the API token. An empty submission keeps the stored token (the
	 * field is rendered blank with a placeholder so unchanged saves don't wipe it).
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_token( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return (string) get_option( 'coywolf_cvm_api_token', '' );
		}
		return sanitize_text_field( $value );
	}

	/**
	 * Sanitize the feature settings array.
	 *
	 * @param mixed $input Submitted value.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$clean    = array();

		$clean['player_color'] = $this->sanitize_hex( isset( $input['player_color'] ) ? $input['player_color'] : '' );
		$preloads         = array( 'none', 'metadata', 'auto' );
		$clean['preload'] = ( isset( $input['preload'] ) && in_array( $input['preload'], $preloads, true ) ) ? $input['preload'] : $defaults['preload'];

		foreach ( array( 'controls', 'autoplay', 'loop', 'mute', 'lazy', 'plays_enabled', 'likes_enabled', 'likes_show_count', 'show_date', 'date_from_post', 'sitemap_enabled', 'show_title', 'show_desc' ) as $flag ) {
			$clean[ $flag ] = ! empty( $input[ $flag ] );
		}

		// Appearance.
		$schemes              = array( 'off', 'auto', 'light', 'dark' );
		$clean['color_scheme'] = ( isset( $input['color_scheme'] ) && in_array( $input['color_scheme'], $schemes, true ) ) ? $input['color_scheme'] : 'off';

		$clean['title_color'] = $this->sanitize_hex( isset( $input['title_color'] ) ? $input['title_color'] : '' );
		$clean['like_color']        = $this->sanitize_hex( isset( $input['like_color'] ) ? $input['like_color'] : '' );
		$clean['like_bg']           = $this->sanitize_hex( isset( $input['like_bg'] ) ? $input['like_bg'] : '' );
		$clean['like_active_color'] = $this->sanitize_hex( isset( $input['like_active_color'] ) ? $input['like_active_color'] : '' );
		$clean['like_active_bg']    = $this->sanitize_hex( isset( $input['like_active_bg'] ) ? $input['like_active_bg'] : '' );
		$clean['meta_color']  = $this->sanitize_hex( isset( $input['meta_color'] ) ? $input['meta_color'] : '' );

		$clean['title_size'] = $this->sanitize_size( isset( $input['title_size'] ) ? $input['title_size'] : 0, $defaults['title_size'] );
		$clean['meta_size']  = $this->sanitize_size( isset( $input['meta_size'] ) ? $input['meta_size'] : 0, $defaults['meta_size'] );

		$clean['radius'] = isset( $input['radius'] ) ? max( 0, min( 48, (int) $input['radius'] ) ) : 0;

		$weights               = array( '100', '200', '300', '400', '500', '600', '700', '800', '900' );
		$clean['title_weight'] = ( isset( $input['title_weight'] ) && in_array( (string) $input['title_weight'], $weights, true ) ) ? (string) $input['title_weight'] : '700';
		$clean['desc_weight']  = ( isset( $input['desc_weight'] ) && in_array( (string) $input['desc_weight'], $weights, true ) ) ? (string) $input['desc_weight'] : '400';
		$aligns                = array( 'left', 'center', 'right' );
		$clean['align']        = ( isset( $input['align'] ) && in_array( $input['align'], $aligns, true ) ) ? $input['align'] : 'left';
		$clean['meta_align']   = ( isset( $input['meta_align'] ) && in_array( $input['meta_align'], $aligns, true ) ) ? $input['meta_align'] : 'left';
		$icons                 = array( 'heart', 'thumbs', 'star' );
		$clean['like_icon']    = ( isset( $input['like_icon'] ) && in_array( $input['like_icon'], $icons, true ) ) ? $input['like_icon'] : 'heart';

		return $clean;
	}

	/**
	 * Sanitize a font size in rem (0.3–8); falls back to the default.
	 *
	 * @param mixed $value   Submitted value.
	 * @param float $default Default rem.
	 * @return float
	 */
	private function sanitize_size( $value, $default ) {
		$value = (float) $value;
		if ( $value <= 0 ) {
			return (float) $default;
		}
		return max( 0.3, min( 8, round( $value, 2 ) ) );
	}

	/**
	 * Sanitize a hex color (#rgb or #rrggbb); empty string if invalid/blank.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	private function sanitize_hex( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		return preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $value ) ? $value : '';
	}

	/**
	 * Clear cached connection state, customer code, and the list cache when
	 * credentials change.
	 */
	public function on_credentials_changed() {
		delete_transient( 'coywolf_cvm_conn_status' );
		delete_option( 'coywolf_cvm_customer_code' );
		$this->cloudflare->flush_list_cache();
	}

	/* --------------------------------------------------------------------- *
	 * Field renderers
	 * --------------------------------------------------------------------- */

	/**
	 * Credentials section intro with token instructions.
	 */
	public function render_credentials_intro() {
		echo '<p>' . esc_html__( 'Connect your Cloudflare account to manage and embed Stream videos. Nothing else unlocks until the connection succeeds.', 'coywolf-video-manager' ) . '</p>';
		echo '<ol style="margin:0;padding-left:1.5em;">';
		echo '<li>' . wp_kses_post( __( '<strong>Account ID</strong> — find it in the right sidebar of the <a href="https://dash.cloudflare.com/" target="_blank" rel="noopener">Cloudflare dashboard</a>, or in the Stream section.', 'coywolf-video-manager' ) ) . '</li>';
		echo '<li>' . wp_kses_post( __( '<strong>API token</strong> — at <em>My Profile → API Tokens → Create Token → Custom token</em>, grant <code>Account · Stream · Edit</code> (and optionally <code>Account · Account Analytics · Read</code> for watch-time).', 'coywolf-video-manager' ) ) . '</li>';
		echo '</ol>';

		$account_const = Coywolf_CVM_Cloudflare::ACCOUNT_CONST;
		$token_const   = Coywolf_CVM_Cloudflare::TOKEN_CONST;
		$snippet       = "define( '" . $account_const . "', 'YOUR_ACCOUNT_ID' );\n";
		$snippet      .= "define( '" . $token_const . "', 'YOUR_API_TOKEN' );";

		echo '<details class="coywolf-cvm-wpconfig" style="margin-top:1em;">';
		echo '<summary>' . esc_html__( 'Add your credentials in wp-config.php instead (recommended)', 'coywolf-video-manager' ) . '</summary>';
		echo '<p>' . esc_html__( 'Keeping credentials in wp-config.php instead of the database means a database leak (for example, a stolen backup) can’t expose your API token. Add these lines to wp-config.php, anywhere above the line that reads “That’s all, stop editing! Happy publishing.”:', 'coywolf-video-manager' ) . '</p>';
		echo '<pre class="coywolf-cvm-code" style="white-space:pre-wrap;"><code>' . esc_html( $snippet ) . '</code></pre>';
		echo '<p>';
		printf(
			/* translators: 1: account-ID constant name, 2: API-token constant name. */
			esc_html__( 'The fields above take precedence, so to use the wp-config values leave a field empty (or click Remove on the token). Matching %1$s / %2$s environment variables work the same way if your host lets you set them.', 'coywolf-video-manager' ),
			'<code>' . esc_html( $account_const ) . '</code>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			'<code>' . esc_html( $token_const ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		);
		echo '</p>';
		echo '</details>';
	}

	/**
	 * Connection status + Test connection button (in the field column).
	 */
	public function render_connection_field() {
		if ( ! $this->cloudflare->is_configured() ) {
			echo '<p class="description">' . esc_html__( 'Enter and save your Account ID and API token to test the connection.', 'coywolf-video-manager' ) . '</p>';
			return;
		}
		$status = get_transient( 'coywolf_cvm_conn_status' );
		if ( false === $status ) {
			$result = $this->cloudflare->test_connection();
			$status = is_wp_error( $result ) ? $result->get_error_message() : 'ok';
			set_transient( 'coywolf_cvm_conn_status', $status, 5 * MINUTE_IN_SECONDS );
		}

		if ( 'ok' === $status ) {
			echo '<p style="color:#1a7f37;font-weight:600;">' . esc_html__( '✓ Connected to Cloudflare Stream.', 'coywolf-video-manager' ) . '</p>';
		} else {
			echo '<p style="color:#b32d2e;font-weight:600;">' . esc_html__( '✗ Connection failed:', 'coywolf-video-manager' ) . ' ' . esc_html( $status ) . '</p>';
		}

		echo '<p>';
		echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=coywolf_cvm_test_connection' ), 'coywolf_cvm_test' ) ) . '">' . esc_html__( 'Test connection', 'coywolf-video-manager' ) . '</a>';
		echo '</p>';
	}

	/**
	 * Account ID field.
	 */
	public function render_account_field() {
		printf(
			'<input type="text" class="regular-text" name="coywolf_cvm_account_id" value="%s" autocomplete="off" spellcheck="false" />',
			esc_attr( (string) get_option( 'coywolf_cvm_account_id', '' ) )
		);
		$this->render_credential_status_note( $this->cloudflare->account_status(), Coywolf_CVM_Cloudflare::ACCOUNT_CONST );
	}

	/**
	 * API token field (write-only; rendered blank when a token is stored). The
	 * field stays editable even when a wp-config constant / environment variable
	 * is set — a saved value takes precedence, and Remove clears it to fall back.
	 */
	public function render_token_field() {
		$status = $this->cloudflare->token_status();
		printf(
			'<input type="password" class="regular-text" name="coywolf_cvm_api_token" value="" autocomplete="new-password" spellcheck="false" placeholder="%s" />',
			esc_attr( $status['saved'] ? __( '•••••••• saved — leave blank to keep', 'coywolf-video-manager' ) : __( 'Paste your API token', 'coywolf-video-manager' ) )
		);
		if ( $status['saved'] ) {
			echo ' <a class="button-link button-link-delete" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=coywolf_cvm_remove_token' ), 'coywolf_cvm_remove_token' ) ) . '">' . esc_html__( 'Remove', 'coywolf-video-manager' ) . '</a>';
		}
		echo '<p class="description">' . esc_html__( 'Stored server-side and never sent to the browser.', 'coywolf-video-manager' ) . '</p>';
		$this->render_credential_status_note( $status, Coywolf_CVM_Cloudflare::TOKEN_CONST );
	}

	/**
	 * Echo a note under a credential field explaining which source is active
	 * when it is not the saved value, or that a saved value is overriding a
	 * wp-config constant / environment variable. Mirrors Coywolf SEO's AI key
	 * status copy.
	 *
	 * @param array  $status     Status array from Coywolf_CVM_Cloudflare.
	 * @param string $const_name Constant / environment-variable name.
	 */
	private function render_credential_status_note( array $status, $const_name ) {
		if ( 'saved' === $status['source'] ) {
			if ( $status['constant'] || $status['env'] ) {
				echo '<p class="description">';
				printf(
					/* translators: %s: wp-config constant name. */
					esc_html__( 'A wp-config %s is also defined, but the saved value above takes precedence. Clear this field to use it.', 'coywolf-video-manager' ),
					'<code>' . esc_html( $const_name ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				);
				echo '</p>';
			}
			return;
		}
		if ( 'constant' === $status['source'] ) {
			echo '<p class="description">';
			printf(
				/* translators: %s: wp-config constant name. */
				esc_html__( 'In use: the %s constant from wp-config.php. Enter a value above to override it.', 'coywolf-video-manager' ),
				'<code>' . esc_html( $const_name ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			);
			echo '</p>';
			return;
		}
		if ( 'env' === $status['source'] ) {
			echo '<p class="description">';
			printf(
				/* translators: %s: environment-variable name. */
				esc_html__( 'In use: the %s environment variable. Enter a value above to override it.', 'coywolf-video-manager' ),
				'<code>' . esc_html( $const_name ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			);
			echo '</p>';
		}
	}

	/**
	 * Play-button (player accent) color field.
	 */
	public function render_player_color_field() {
		$this->color_input( 'player_color', __( 'Accent color', 'coywolf-video-manager' ) );
		echo '<p class="description">' . esc_html__( 'Default color for the player’s play button and accents. Leave empty for Cloudflare’s default; can be overridden per video block.', 'coywolf-video-manager' ) . '</p>';
	}

	/**
	 * Embed defaults section intro.
	 */
	public function render_embed_intro() {
		echo '<p>' . esc_html__( 'Defaults applied to every new video block. Each one can be overridden per block when editing a post.', 'coywolf-video-manager' ) . '</p>';
	}

	/**
	 * Embed defaults checkboxes + preload select.
	 */
	public function render_embed_field() {
		$this->checkbox( 'controls', __( 'Show player controls', 'coywolf-video-manager' ) );
		$this->checkbox( 'autoplay', __( 'Autoplay', 'coywolf-video-manager' ) );
		$this->checkbox( 'loop', __( 'Loop', 'coywolf-video-manager' ) );
		$this->checkbox( 'mute', __( 'Mute', 'coywolf-video-manager' ) );
		$this->checkbox( 'lazy', __( 'Lazy-load (defer until scrolled into view)', 'coywolf-video-manager' ) );

		$value    = $this->get( 'preload' );
		$preloads = array(
			'none'     => __( 'none', 'coywolf-video-manager' ),
			'metadata' => __( 'metadata', 'coywolf-video-manager' ),
			'auto'     => __( 'auto', 'coywolf-video-manager' ),
		);
		echo '<p><label>' . esc_html__( 'Preload', 'coywolf-video-manager' ) . ' ';
		echo '<select name="' . esc_attr( self::OPTION ) . '[preload]">';
		foreach ( $preloads as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $value, $key, false ), esc_html( $label ) );
		}
		echo '</select></label></p>';
	}

	/**
	 * Views / likes checkboxes.
	 */
	public function render_engagement_field() {
		$this->checkbox( 'plays_enabled', __( 'Show the number of views', 'coywolf-video-manager' ) );
		$this->checkbox( 'likes_enabled', __( 'Show a like button', 'coywolf-video-manager' ) );
		$this->checkbox( 'likes_show_count', __( 'Show the number of likes', 'coywolf-video-manager' ) );
		$this->checkbox( 'show_date', __( 'Show the date the video was uploaded', 'coywolf-video-manager' ) );
		$this->checkbox( 'date_from_post', __( 'Use the post or page publish date for the video upload date', 'coywolf-video-manager' ) );
		echo '<p class="description">' . esc_html__( 'The view count and upload date are always included in the video schema and XML sitemap, even when hidden here. With the publish-date option on, the post or page date is used as the upload date everywhere — the row beneath the video, the schema, and the sitemap.', 'coywolf-video-manager' ) . '</p>';
	}

	/**
	 * Appearance description + live preview (rendered in the field column so it
	 * aligns with the other settings).
	 */
	public function render_appearance_preview_field() {
		echo '<p class="description">' . esc_html__( 'Style the video name, like button, and views/date shown beneath each video. Leave a color empty to inherit your theme; changes preview live here.', 'coywolf-video-manager' ) . '</p>';

		$scheme       = (string) $this->get( 'color_scheme' );
		$scheme_class = ( 'off' !== $scheme ) ? ' coywolf-cvm-scheme-' . sanitize_html_class( $scheme ) : '';

		$preview  = '<div class="coywolf-cvm-preview-stage" id="coywolf-cvm-preview-stage">';
		$preview .= '<figure class="coywolf-cvm coywolf-cvm-preview' . $scheme_class . '" id="coywolf-cvm-preview">';
		$preview .= '<div class="coywolf-cvm-frame coywolf-cvm-preview-frame"><span class="coywolf-cvm-preview-play" aria-hidden="true"></span></div>';
		$preview .= '<figcaption class="coywolf-cvm-title"><strong class="coywolf-cvm-name">' . esc_html__( 'Your video name', 'coywolf-video-manager' ) . '</strong><span class="coywolf-cvm-sep"> — </span><span class="coywolf-cvm-desc">' . esc_html__( 'A short video description.', 'coywolf-video-manager' ) . '</span></figcaption>';
		$preview .= '<div class="coywolf-cvm-meta">';
		$preview .= '<button type="button" class="coywolf-cvm-like" aria-pressed="false"><span class="screen-reader-text">' . esc_html__( 'Like', 'coywolf-video-manager' ) . '</span>' . Coywolf_CVM_Block::thumb_svg( $this->get( 'like_icon' ) ) . '<span class="coywolf-cvm-like-count">175</span></button>';
		$preview .= '<span class="coywolf-cvm-views">' . esc_html__( '23 views', 'coywolf-video-manager' ) . '</span>';
		$preview .= '<span class="coywolf-cvm-date">' . esc_html__( '7 months ago', 'coywolf-video-manager' ) . '</span>';
		$preview .= '</div></figure></div>';

		echo $preview; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts + static SVG.
	}

	/**
	 * Color scheme select.
	 */
	public function render_appearance_scheme_field() {
		$value   = (string) $this->get( 'color_scheme' );
		$schemes = array(
			'off'   => __( 'Off — use the colors below', 'coywolf-video-manager' ),
			'auto'  => __( 'Auto — follow the visitor’s system', 'coywolf-video-manager' ),
			'light' => __( 'Always light', 'coywolf-video-manager' ),
			'dark'  => __( 'Always dark', 'coywolf-video-manager' ),
		);
		echo '<select name="' . esc_attr( self::OPTION ) . '[color_scheme]" class="coywolf-cvm-scheme">';
		foreach ( $schemes as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $value, $key, false ), esc_html( $label ) );
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Light/dark adjust the like button and text colors for dark backgrounds. Any color you set below overrides the scheme.', 'coywolf-video-manager' ) . '</p>';
	}

	/**
	 * Alignment (applies to the video name and the views/likes row).
	 */
	public function render_appearance_align_field() {
		$this->align_select( 'align', __( 'Name & description', 'coywolf-video-manager' ) );
		$this->align_select( 'meta_align', __( 'Like / views / date row', 'coywolf-video-manager' ) );
		echo '<p class="description">' . esc_html__( 'Align the name & description and the like / views / date row independently. Both can be overridden per video block.', 'coywolf-video-manager' ) . '</p>';
	}

	/**
	 * Render a left/center/right alignment select bound to the OPTION array.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 */
	private function align_select( $key, $label ) {
		$value  = (string) $this->get( $key );
		$aligns = array(
			'left'   => __( 'Left', 'coywolf-video-manager' ),
			'center' => __( 'Center', 'coywolf-video-manager' ),
			'right'  => __( 'Right', 'coywolf-video-manager' ),
		);
		echo '<p><label>' . esc_html( $label ) . ' <select name="' . esc_attr( self::OPTION ) . '[' . esc_attr( $key ) . ']" class="coywolf-cvm-field" data-key="' . esc_attr( $key ) . '">';
		foreach ( $aligns as $k => $l ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $value, $k, false ), esc_html( $l ) );
		}
		echo '</select></label></p>';
	}

	/**
	 * Player corner radius (px).
	 */
	public function render_appearance_radius_field() {
		printf(
			'<p><label>%1$s <input type="number" min="0" max="48" step="1" name="%2$s[radius]" value="%3$d" class="small-text coywolf-cvm-field" data-key="radius" /> px</label></p>',
			esc_html__( 'Corner radius', 'coywolf-video-manager' ),
			esc_attr( self::OPTION ),
			(int) $this->get( 'radius' )
		);
		echo '<p class="description">' . esc_html__( 'Rounds the corners of the video player. 0 = square corners. Can be overridden per video block.', 'coywolf-video-manager' ) . '</p>';
	}

	/**
	 * Name & description (figcaption) style fields.
	 */
	public function render_appearance_title_field() {
		$this->color_input( 'title_color', __( 'Text color', 'coywolf-video-manager' ) );

		$this->toggle_input( 'show_title', __( 'Show video name', 'coywolf-video-manager' ) );
		$this->weight_select( 'title_weight', __( 'Name font weight', 'coywolf-video-manager' ) );

		$this->toggle_input( 'show_desc', __( 'Show video description', 'coywolf-video-manager' ) );
		$this->weight_select( 'desc_weight', __( 'Description font weight', 'coywolf-video-manager' ) );

		$this->size_input( 'title_size', __( 'Font size', 'coywolf-video-manager' ) );
		echo '<p class="description">' . esc_html__( 'Font size and text color apply to both the name and the description. The description text comes from the Edit Video page.', 'coywolf-video-manager' ) . '</p>';
	}

	/**
	 * Render a checkbox bound to the OPTION array, wired for the live preview.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 */
	private function toggle_input( $key, $label ) {
		printf(
			'<p><label><input type="checkbox" class="coywolf-cvm-toggle" data-key="%2$s" name="%1$s[%2$s]" value="1"%3$s /> %4$s</label></p>',
			esc_attr( self::OPTION ),
			esc_attr( $key ),
			checked( (bool) $this->get( $key ), true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Render a 100–900 font-weight select bound to the OPTION array.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 */
	private function weight_select( $key, $label ) {
		$weight = (string) $this->get( $key );
		echo '<p><label>' . esc_html( $label ) . ' <select name="' . esc_attr( self::OPTION ) . '[' . esc_attr( $key ) . ']" class="coywolf-cvm-field" data-key="' . esc_attr( $key ) . '">';
		foreach ( array( '100', '200', '300', '400', '500', '600', '700', '800', '900' ) as $w ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $w ), selected( $weight, $w, false ), esc_html( $w ) );
		}
		echo '</select></label></p>';
	}

	/**
	 * Like-button icon + color fields.
	 */
	public function render_appearance_like_field() {
		$icon  = (string) $this->get( 'like_icon' );
		$icons = array(
			'heart'  => __( 'Heart', 'coywolf-video-manager' ),
			'thumbs' => __( 'Thumbs up', 'coywolf-video-manager' ),
			'star'   => __( 'Star', 'coywolf-video-manager' ),
		);
		echo '<p><label>' . esc_html__( 'Icon', 'coywolf-video-manager' ) . ' <select name="' . esc_attr( self::OPTION ) . '[like_icon]" class="coywolf-cvm-field" data-key="like_icon">';
		foreach ( $icons as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $icon, $key, false ), esc_html( $label ) );
		}
		echo '</select></label></p>';

		$this->color_input( 'like_color', __( 'Unclicked color', 'coywolf-video-manager' ) );
		$this->color_input( 'like_bg', __( 'Unclicked background color (empty = none)', 'coywolf-video-manager' ) );
		$this->color_input( 'like_active_color', __( 'Clicked color', 'coywolf-video-manager' ) );
		$this->color_input( 'like_active_bg', __( 'Clicked background color', 'coywolf-video-manager' ) );
		echo '<p class="description">' . esc_html__( 'When unclicked, hovering previews the clicked colors; when clicked, hovering previews the unclicked colors. Leave the clicked colors empty to reuse the unclicked ones.', 'coywolf-video-manager' ) . '</p>';
	}

	/**
	 * Views / upload-date text fields.
	 */
	public function render_appearance_meta_field() {
		$this->color_input( 'meta_color', __( 'Text color', 'coywolf-video-manager' ) );
		$this->size_input( 'meta_size', __( 'Font size', 'coywolf-video-manager' ) );
	}

	/**
	 * Render a color field (a hidden value input + a jscolorpicker mount point).
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 */
	private function color_input( $key, $label ) {
		$value    = (string) $this->get( $key );
		$label_id = 'coywolf-cvm-colorlabel-' . $key;
		printf(
			'<p class="coywolf-cvm-color-field" data-key="%3$s" role="group" aria-labelledby="%5$s"><span class="coywolf-cvm-color-label" id="%5$s">%1$s</span><br /><input type="hidden" class="coywolf-cvm-color-value" name="%2$s[%3$s]" value="%4$s" /><span class="coywolf-cvm-color-mount"></span></p>',
			esc_html( $label ),
			esc_attr( self::OPTION ),
			esc_attr( $key ),
			esc_attr( $value ),
			esc_attr( $label_id )
		);
	}

	/**
	 * Render a rem font-size number input (defaults to the plugin's own size).
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 */
	private function size_input( $key, $label ) {
		printf(
			'<p><label>%1$s <input type="number" min="0.3" max="8" step="0.05" name="%2$s[%3$s]" value="%4$s" class="small-text coywolf-cvm-field" data-key="%3$s" /> rem</label></p>',
			esc_html( $label ),
			esc_attr( self::OPTION ),
			esc_attr( $key ),
			esc_attr( $this->get_size( $key ) )
		);
	}

	/**
	 * Video sitemap control.
	 */
	public function render_advanced_field() {
		$this->checkbox( 'sitemap_enabled', __( 'Serve a video XML sitemap', 'coywolf-video-manager' ) );
		$sitemap_url = home_url( '/' . Coywolf_CVM_Sitemap::SLUG );
		echo '<p class="description">' . sprintf(
			/* translators: %s: sitemap URL. */
			esc_html__( 'Served at %s', 'coywolf-video-manager' ),
			'<a href="' . esc_url( $sitemap_url ) . '" target="_blank" rel="noopener">' . esc_html( $sitemap_url ) . '</a>'
		) . '</p>';

		// Link the Robots.txt Manager to the matching distribution of this plugin
		// (the GITHUB_BUILD flag is stripped from the WordPress.org variant).
		$robots_url = defined( 'COYWOLF_CVM_GITHUB_BUILD' )
			? 'https://github.com/coywolf-llc/coywolf-robots-txt-manager'
			: 'https://wordpress.org/plugins/coywolf-robots-txt-manager/';
		echo '<p class="description">' . wp_kses(
			sprintf(
				/* translators: 1: Google Search Console link, 2: Coywolf Robots.txt Manager link. */
				__( 'Submit this URL to %1$s, and list it in your robots.txt with the %2$s.', 'coywolf-video-manager' ),
				'<a href="https://search.google.com/search-console" target="_blank" rel="noopener">' . esc_html__( 'Google Search Console', 'coywolf-video-manager' ) . '</a>',
				'<a href="' . esc_url( $robots_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Coywolf Robots.txt Manager', 'coywolf-video-manager' ) . '</a>'
			),
			array(
				'a' => array(
					'href'   => array(),
					'target' => array(),
					'rel'    => array(),
				),
			)
		) . '</p>';
	}

	/**
	 * Render a single settings checkbox bound to the OPTION array.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 */
	private function checkbox( $key, $label ) {
		printf(
			'<p><label><input type="checkbox" name="%1$s[%2$s]" value="1"%3$s /> %4$s</label></p>',
			esc_attr( self::OPTION ),
			esc_attr( $key ),
			checked( (bool) $this->get( $key ), true, false ),
			esc_html( $label )
		);
	}

	/* --------------------------------------------------------------------- *
	 * Page + actions
	 * --------------------------------------------------------------------- */

	/**
	 * Render the settings screen.
	 */
	public function render_page() {
		if ( ! current_user_can( Coywolf_Video_Manager::CAPABILITY ) ) {
			return;
		}
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Video Manager Settings', 'coywolf-video-manager' ) . '</h1>';

		if ( isset( $_GET['coywolf_cvm_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$notice = sanitize_text_field( wp_unslash( $_GET['coywolf_cvm_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'tested' === $notice ) {
				$status = get_transient( 'coywolf_cvm_conn_status' );
				$class  = ( 'ok' === $status ) ? 'notice-success' : 'notice-error';
				$text   = ( 'ok' === $status ) ? __( 'Connection successful.', 'coywolf-video-manager' ) : (string) $status;
				echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
			} elseif ( 'token_removed' === $notice ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved API token removed.', 'coywolf-video-manager' ) . '</p></div>';
			} elseif ( 'webhook' === $notice ) {
				$status = (string) get_transient( 'coywolf_cvm_webhook_status' );
				if ( 'on' === $status ) {
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Webhook enabled.', 'coywolf-video-manager' ) . '</p></div>';
				} elseif ( 'off' === $status ) {
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Webhook disabled.', 'coywolf-video-manager' ) . '</p></div>';
				} elseif ( '' !== $status ) {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $status ) . '</p></div>';
				}
			}
		}

		echo '<form action="options.php" method="post">';
		settings_fields( self::GROUP );
		do_settings_sections( self::PAGE );
		submit_button();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Handle the Test connection button.
	 */
	public function handle_test_connection() {
		if ( ! current_user_can( Coywolf_Video_Manager::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'coywolf-video-manager' ) );
		}
		check_admin_referer( 'coywolf_cvm_test' );

		$result = $this->cloudflare->test_connection();
		$status = is_wp_error( $result ) ? $result->get_error_message() : 'ok';
		set_transient( 'coywolf_cvm_conn_status', $status, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( 'coywolf_cvm_notice', 'tested', $this->page_url() ) );
		exit;
	}

	/**
	 * Handle the Remove link on the API token field: delete the saved token so
	 * the plugin falls back to a wp-config constant / environment variable (or
	 * to no token at all).
	 */
	public function handle_remove_token() {
		if ( ! current_user_can( Coywolf_Video_Manager::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'coywolf-video-manager' ) );
		}
		check_admin_referer( 'coywolf_cvm_remove_token' );

		delete_option( 'coywolf_cvm_api_token' );

		wp_safe_redirect( add_query_arg( 'coywolf_cvm_notice', 'token_removed', $this->page_url() ) );
		exit;
	}

	/**
	 * Webhook status + enable/disable button. The webhook lets Cloudflare
	 * notify this site the moment a video finishes processing (or errors).
	 */
	public function render_webhook_field() {
		if ( ! $this->cloudflare->is_configured() ) {
			echo '<p class="description">' . esc_html__( 'Connect your Cloudflare account first.', 'coywolf-video-manager' ) . '</p>';
			return;
		}

		$config  = get_option( 'coywolf_cvm_webhook', array() );
		$enabled = is_array( $config ) && ! empty( $config['secret'] );

		if ( $enabled ) {
			echo '<p>' . esc_html__( 'Enabled — Cloudflare pings this site the moment a video finishes processing, so the library, storage numbers, and caption data refresh instantly instead of waiting for polling.', 'coywolf-video-manager' ) . '</p>';
			if ( ! empty( $config['url'] ) ) {
				echo '<p><code>' . esc_html( $config['url'] ) . '</code></p>';
			}
			echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=coywolf_cvm_disable_webhook' ), 'coywolf_cvm_webhook' ) ) . '">' . esc_html__( 'Disable webhook', 'coywolf-video-manager' ) . '</a>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'Let Cloudflare notify this site when a video finishes processing, keeping the video library, storage numbers, and caption data fresh without polling. Notifications are signature-verified. Note: Cloudflare allows one Stream webhook per account, so enabling this replaces any existing subscription.', 'coywolf-video-manager' ) . '</p>';
		echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=coywolf_cvm_enable_webhook' ), 'coywolf_cvm_webhook' ) ) . '">' . esc_html__( 'Enable webhook', 'coywolf-video-manager' ) . '</a>';
	}

	/**
	 * Handle the Enable webhook button: subscribe Cloudflare to this site's
	 * receiver and store the returned signing secret.
	 */
	public function handle_enable_webhook() {
		if ( ! current_user_can( Coywolf_Video_Manager::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'coywolf-video-manager' ) );
		}
		check_admin_referer( 'coywolf_cvm_webhook' );

		$url    = rest_url( 'coywolf-cvm/v1/webhook' );
		$result = $this->cloudflare->set_webhook( $url );
		$secret = ( ! is_wp_error( $result ) && isset( $result['secret'] ) ) ? (string) $result['secret'] : '';

		if ( is_wp_error( $result ) || '' === $secret ) {
			$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Cloudflare did not return a signing secret.', 'coywolf-video-manager' );
			set_transient( 'coywolf_cvm_webhook_status', $message, 5 * MINUTE_IN_SECONDS );
		} else {
			update_option(
				'coywolf_cvm_webhook',
				array(
					'secret'  => $secret,
					'url'     => esc_url_raw( $url ),
					'enabled' => time(),
				),
				false
			);
			set_transient( 'coywolf_cvm_webhook_status', 'on', 5 * MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( add_query_arg( 'coywolf_cvm_notice', 'webhook', $this->page_url() ) );
		exit;
	}

	/**
	 * Handle the Disable webhook button: remove the Cloudflare subscription
	 * and forget the secret.
	 */
	public function handle_disable_webhook() {
		if ( ! current_user_can( Coywolf_Video_Manager::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'coywolf-video-manager' ) );
		}
		check_admin_referer( 'coywolf_cvm_webhook' );

		$result = $this->cloudflare->delete_webhook();
		if ( is_wp_error( $result ) ) {
			set_transient( 'coywolf_cvm_webhook_status', $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
		} else {
			delete_option( 'coywolf_cvm_webhook' );
			set_transient( 'coywolf_cvm_webhook_status', 'off', 5 * MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( add_query_arg( 'coywolf_cvm_notice', 'webhook', $this->page_url() ) );
		exit;
	}
}
