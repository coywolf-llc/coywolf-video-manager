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
	 * Constructor.
	 *
	 * @param Coywolf_CVM_Cloudflare $cloudflare API client.
	 */
	public function __construct( Coywolf_CVM_Cloudflare $cloudflare ) {
		$this->cloudflare = $cloudflare;

		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_post_coywolf_cvm_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_coywolf_cvm_create_key', array( $this, 'handle_create_key' ) );

		foreach ( array( 'coywolf_cvm_account_id', 'coywolf_cvm_api_token' ) as $option ) {
			add_action( 'update_option_' . $option, array( $this, 'on_credentials_changed' ) );
			add_action( 'add_option_' . $option, array( $this, 'on_credentials_changed' ) );
		}
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
			'player'              => 'cloudflare',
			'controls'            => true,
			'autoplay'            => false,
			'loop'                => false,
			'preload'             => 'metadata',
			'mute'                => false,
			'lazy'                => true,
			'plays_enabled'       => true,
			'plays_in_schema'     => true,
			'likes_enabled'       => true,
			'likes_show_count'    => true,
			'lightbox_enabled'    => true,
			'sitemap_enabled'     => false,
			'analytics_enabled'   => false,
			'signed_urls_enabled' => false,
		);
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
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::defaults() );
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
		if ( ! $this->cloudflare->account_is_locked() ) {
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
		}
		if ( ! $this->cloudflare->token_is_locked() ) {
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
		}

		add_settings_section( 'coywolf_cvm_credentials', __( 'Cloudflare account', 'coywolf-video-manager' ), array( $this, 'render_credentials_intro' ), self::PAGE );
		add_settings_field( 'coywolf_cvm_account_id', __( 'Account ID', 'coywolf-video-manager' ), array( $this, 'render_account_field' ), self::PAGE, 'coywolf_cvm_credentials' );
		add_settings_field( 'coywolf_cvm_api_token', __( 'API token', 'coywolf-video-manager' ), array( $this, 'render_token_field' ), self::PAGE, 'coywolf_cvm_credentials' );

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
		add_settings_field( 'player', __( 'Video player', 'coywolf-video-manager' ), array( $this, 'render_player_field' ), self::PAGE, 'coywolf_cvm_player' );

		add_settings_section( 'coywolf_cvm_embed', __( 'Embed defaults', 'coywolf-video-manager' ), array( $this, 'render_embed_intro' ), self::PAGE );
		add_settings_field( 'embed', __( 'Default options', 'coywolf-video-manager' ), array( $this, 'render_embed_field' ), self::PAGE, 'coywolf_cvm_embed' );

		add_settings_section( 'coywolf_cvm_engagement', __( 'Plays, likes & lightbox', 'coywolf-video-manager' ), '__return_false', self::PAGE );
		add_settings_field( 'engagement', __( 'Engagement', 'coywolf-video-manager' ), array( $this, 'render_engagement_field' ), self::PAGE, 'coywolf_cvm_engagement' );

		add_settings_section( 'coywolf_cvm_advanced', __( 'Sitemap, analytics & private videos', 'coywolf-video-manager' ), '__return_false', self::PAGE );
		add_settings_field( 'advanced', __( 'Advanced', 'coywolf-video-manager' ), array( $this, 'render_advanced_field' ), self::PAGE, 'coywolf_cvm_advanced' );
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

		$players          = array( 'cloudflare', 'plyr', 'videojs' );
		$clean['player']  = ( isset( $input['player'] ) && in_array( $input['player'], $players, true ) ) ? $input['player'] : $defaults['player'];
		$preloads         = array( 'none', 'metadata', 'auto' );
		$clean['preload'] = ( isset( $input['preload'] ) && in_array( $input['preload'], $preloads, true ) ) ? $input['preload'] : $defaults['preload'];

		foreach ( array( 'controls', 'autoplay', 'loop', 'mute', 'lazy', 'plays_enabled', 'plays_in_schema', 'likes_enabled', 'likes_show_count', 'lightbox_enabled', 'sitemap_enabled', 'analytics_enabled', 'signed_urls_enabled' ) as $flag ) {
			$clean[ $flag ] = ! empty( $input[ $flag ] );
		}

		return $clean;
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
		echo '<ol>';
		echo '<li>' . wp_kses_post( __( '<strong>Account ID</strong> — find it in the right sidebar of the <a href="https://dash.cloudflare.com/" target="_blank" rel="noopener">Cloudflare dashboard</a>, or in the Stream section.', 'coywolf-video-manager' ) ) . '</li>';
		echo '<li>' . wp_kses_post( __( '<strong>API token</strong> — at <em>My Profile → API Tokens → Create Token → Custom token</em>, grant <code>Account · Stream · Edit</code> (and optionally <code>Account · Account Analytics · Read</code> for watch-time).', 'coywolf-video-manager' ) ) . '</li>';
		echo '</ol>';
		$this->render_connection_status();
	}

	/**
	 * Show the current connection status and the Test connection button.
	 */
	private function render_connection_status() {
		if ( ! $this->cloudflare->is_configured() ) {
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
		if ( $this->cloudflare->account_is_locked() ) {
			echo '<code>' . esc_html( $this->mask( $this->cloudflare->get_account_id() ) ) . '</code> ';
			echo '<span class="description">' . esc_html__( 'Set via the COYWOLF_CVM_ACCOUNT_ID constant in wp-config.php.', 'coywolf-video-manager' ) . '</span>';
			return;
		}
		printf(
			'<input type="text" class="regular-text" name="coywolf_cvm_account_id" value="%s" autocomplete="off" spellcheck="false" />',
			esc_attr( get_option( 'coywolf_cvm_account_id', '' ) )
		);
	}

	/**
	 * API token field (write-only; rendered blank when a token is stored).
	 */
	public function render_token_field() {
		if ( $this->cloudflare->token_is_locked() ) {
			echo '<code>' . esc_html__( '•••••••• (set in wp-config.php)', 'coywolf-video-manager' ) . '</code>';
			return;
		}
		$has = '' !== (string) get_option( 'coywolf_cvm_api_token', '' );
		printf(
			'<input type="password" class="regular-text" name="coywolf_cvm_api_token" value="" autocomplete="new-password" spellcheck="false" placeholder="%s" />',
			esc_attr( $has ? __( '•••••••• saved — leave blank to keep', 'coywolf-video-manager' ) : __( 'Paste your API token', 'coywolf-video-manager' ) )
		);
		echo '<p class="description">' . esc_html__( 'Stored server-side and never sent to the browser.', 'coywolf-video-manager' ) . '</p>';
	}

	/**
	 * Player choice field.
	 */
	public function render_player_field() {
		$value   = $this->get( 'player' );
		$choices = array(
			'cloudflare' => __( 'Cloudflare Stream player (default)', 'coywolf-video-manager' ),
			'plyr'       => __( 'Plyr (open source)', 'coywolf-video-manager' ),
			'videojs'    => __( 'Video.js (open source)', 'coywolf-video-manager' ),
		);
		echo '<select name="' . esc_attr( self::OPTION ) . '[player]">';
		foreach ( $choices as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $value, $key, false ), esc_html( $label ) );
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'All three play Cloudflare’s adaptive HLS. Plyr and Video.js are bundled with the plugin.', 'coywolf-video-manager' ) . '</p>';
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
	 * Plays / likes / lightbox checkboxes.
	 */
	public function render_engagement_field() {
		$this->checkbox( 'plays_enabled', __( 'Show the number of plays (and include it in schema)', 'coywolf-video-manager' ) );
		$this->checkbox( 'plays_in_schema', __( 'Include play count in VideoObject schema', 'coywolf-video-manager' ) );
		$this->checkbox( 'likes_enabled', __( 'Show a like button', 'coywolf-video-manager' ) );
		$this->checkbox( 'likes_show_count', __( 'Show the number of likes', 'coywolf-video-manager' ) );
		$this->checkbox( 'lightbox_enabled', __( 'Open videos in a lightbox on click', 'coywolf-video-manager' ) );
	}

	/**
	 * Sitemap / analytics / signed-URL controls.
	 */
	public function render_advanced_field() {
		$this->checkbox( 'sitemap_enabled', __( 'Serve a video XML sitemap', 'coywolf-video-manager' ) );
		echo '<p class="description">' . sprintf(
			/* translators: %s: sitemap URL. */
			esc_html__( 'Served at %s', 'coywolf-video-manager' ),
			'<a href="' . esc_url( home_url( '/video-sitemap.xml' ) ) . '" target="_blank" rel="noopener">' . esc_html( home_url( '/video-sitemap.xml' ) ) . '</a>'
		) . '</p>';

		$this->checkbox( 'analytics_enabled', __( 'Show a Cloudflare watch-time (minutes) column on All Videos', 'coywolf-video-manager' ) );
		echo '<p class="description">' . esc_html__( 'Requires the Account Analytics · Read permission on your API token.', 'coywolf-video-manager' ) . '</p>';

		$this->checkbox( 'signed_urls_enabled', __( 'Support private (signed-URL) videos in the block', 'coywolf-video-manager' ) );
		$this->render_signing_key_status();
	}

	/**
	 * Signing-key status + create button.
	 */
	private function render_signing_key_status() {
		if ( ! $this->get( 'signed_urls_enabled' ) ) {
			return;
		}
		if ( $this->cloudflare->has_signing_key() ) {
			echo '<p class="description" style="color:#1a7f37;">' . esc_html__( '✓ A Stream signing key is stored. Private videos will play via short-lived tokens.', 'coywolf-video-manager' ) . '</p>';
			return;
		}
		echo '<p class="description">' . esc_html__( 'No signing key yet — create one so private videos can play.', 'coywolf-video-manager' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=coywolf_cvm_create_key' ), 'coywolf_cvm_create_key' ) ) . '">' . esc_html__( 'Create signing key', 'coywolf-video-manager' ) . '</a></p>';
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

	/**
	 * Mask a secret for display (first 3 + last 3 characters).
	 *
	 * @param string $value Secret.
	 * @return string
	 */
	private function mask( $value ) {
		$len = strlen( $value );
		if ( $len <= 8 ) {
			return str_repeat( '•', max( 0, $len ) );
		}
		return substr( $value, 0, 3 ) . str_repeat( '•', 6 ) . substr( $value, -3 );
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
			} elseif ( 'key_created' === $notice ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Signing key created.', 'coywolf-video-manager' ) . '</p></div>';
			} elseif ( 'key_failed' === $notice ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not create a signing key. Check your token permissions.', 'coywolf-video-manager' ) . '</p></div>';
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
	 * Handle the Create signing key button.
	 */
	public function handle_create_key() {
		if ( ! current_user_can( Coywolf_Video_Manager::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'coywolf-video-manager' ) );
		}
		check_admin_referer( 'coywolf_cvm_create_key' );

		$result = $this->cloudflare->create_signing_key();
		$notice = is_wp_error( $result ) ? 'key_failed' : 'key_created';

		wp_safe_redirect( add_query_arg( 'coywolf_cvm_notice', $notice, $this->page_url() ) );
		exit;
	}
}
