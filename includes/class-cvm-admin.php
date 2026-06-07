<?php
/**
 * Admin screens for Coywolf Video Manager.
 *
 * Registers the Videos menu and its subpages (All Videos, Edit Video, Upload
 * Video, Settings) and enqueues admin assets on the plugin's own screens. The
 * Settings screen is delegated to the Settings module; the video screens are
 * built out in later phases.
 *
 * @package CoywolfVideoManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI controller.
 */
class Coywolf_CVM_Admin {

	/**
	 * Top-level menu slug (also the All Videos screen).
	 */
	const PAGE = 'coywolf-video-manager';

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
	 * Settings module.
	 *
	 * @var Coywolf_CVM_Settings
	 */
	private $settings;

	/**
	 * Registered submenu hook suffixes, for asset scoping.
	 *
	 * @var array
	 */
	private $hooks = array();

	/**
	 * Constructor.
	 *
	 * @param Coywolf_CVM_Cloudflare $cloudflare API client.
	 * @param Coywolf_CVM_Stats      $stats      Stats store.
	 * @param Coywolf_CVM_Index      $index      Usage index.
	 * @param Coywolf_CVM_Settings   $settings   Settings.
	 */
	public function __construct( Coywolf_CVM_Cloudflare $cloudflare, Coywolf_CVM_Stats $stats, Coywolf_CVM_Index $index, Coywolf_CVM_Settings $settings ) {
		$this->cloudflare = $cloudflare;
		$this->stats      = $stats;
		$this->index      = $index;
		$this->settings   = $settings;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the Videos menu and its subpages.
	 */
	public function register_menu() {
		$cap = Coywolf_Video_Manager::CAPABILITY;

		$this->hooks['list'] = add_menu_page(
			__( 'Coywolf Video Manager', 'coywolf-video-manager' ),
			__( 'Videos', 'coywolf-video-manager' ),
			$cap,
			self::PAGE,
			array( $this, 'render_videos_page' ),
			'dashicons-video-alt3',
			26
		);

		$this->hooks['list_sub'] = add_submenu_page(
			self::PAGE,
			__( 'All Videos', 'coywolf-video-manager' ),
			__( 'All Videos', 'coywolf-video-manager' ),
			$cap,
			self::PAGE,
			array( $this, 'render_videos_page' )
		);

		$this->hooks['upload'] = add_submenu_page(
			self::PAGE,
			__( 'Upload Video', 'coywolf-video-manager' ),
			__( 'Upload Video', 'coywolf-video-manager' ),
			$cap,
			'coywolf-video-manager-upload',
			array( $this, 'render_upload_page' )
		);

		$this->hooks['settings'] = add_submenu_page(
			self::PAGE,
			__( 'Settings', 'coywolf-video-manager' ),
			__( 'Settings', 'coywolf-video-manager' ),
			$cap,
			Coywolf_CVM_Settings::PAGE,
			array( $this->settings, 'render_page' )
		);

		// Process delete / bulk-delete before the page renders so redirects work.
		add_action( 'load-' . $this->hooks['list'], array( $this, 'handle_list_actions' ) );
	}

	/**
	 * Enqueue admin assets on the plugin's own screens.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, $this->hooks, true ) ) {
			return;
		}
		wp_enqueue_style( 'coywolf-cvm-admin', COYWOLF_CVM_URL . 'css/admin.css', array(), Coywolf_Video_Manager::VERSION );
		wp_enqueue_script( 'coywolf-cvm-admin', COYWOLF_CVM_URL . 'js/admin.js', array( 'wp-api-fetch', 'wp-element', 'wp-i18n' ), Coywolf_Video_Manager::VERSION, true );
		wp_add_inline_script(
			'coywolf-cvm-admin',
			'window.coywolfCVM = ' . wp_json_encode(
				array(
					'restUrl'    => esc_url_raw( rest_url( 'coywolf-cvm/v1' ) ),
					'nonce'      => wp_create_nonce( 'wp_rest' ),
					'pageUrl'    => admin_url( 'admin.php?page=' . self::PAGE ),
					'i18n'       => array(
						'copied'         => __( 'Embed code copied to the clipboard.', 'coywolf-video-manager' ),
						'confirmDelete'  => __( 'Delete this video from Cloudflare? This cannot be undone.', 'coywolf-video-manager' ),
						'saved'          => __( 'Saved.', 'coywolf-video-manager' ),
						'noCaptions'     => __( 'No captions yet.', 'coywolf-video-manager' ),
						'remove'         => __( 'Remove', 'coywolf-video-manager' ),
						'generating'     => __( 'Generating captions — this can take a few minutes.', 'coywolf-video-manager' ),
						'pickFile'       => __( 'Choose a .vtt file first.', 'coywolf-video-manager' ),
						'pickVideo'      => __( 'Choose a video file first.', 'coywolf-video-manager' ),
						'preparing'      => __( 'Preparing upload…', 'coywolf-video-manager' ),
						'processing'     => __( 'Uploaded. Cloudflare is processing the video…', 'coywolf-video-manager' ),
						'uploadFailed'   => __( 'Upload failed.', 'coywolf-video-manager' ),
						'stillProcessing' => __( 'Still processing — check All Videos shortly.', 'coywolf-video-manager' ),
						'ready'          => __( 'Ready!', 'coywolf-video-manager' ),
						'editNow'        => __( 'Edit this video', 'coywolf-video-manager' ),
						'processError'   => __( 'Cloudflare could not process this video.', 'coywolf-video-manager' ),
					),
				)
			) . ';',
			'before'
		);

		// The Settings screen uses the bundled jscolorpicker + a live preview.
		if ( isset( $this->hooks['settings'] ) && $hook === $this->hooks['settings'] && $this->cloudflare->is_configured() ) {
			wp_enqueue_style( 'coywolf-cvm-jscolorpicker', COYWOLF_CVM_URL . 'vendor/jscolorpicker/colorpicker.min.css', array(), '1.1.0' );
			wp_enqueue_script( 'coywolf-cvm-jscolorpicker', COYWOLF_CVM_URL . 'vendor/jscolorpicker/colorpicker.iife.min.js', array(), '1.1.0', true );
			wp_enqueue_style( 'coywolf-cvm-view' ); // the preview reuses the front-end styles.
			wp_enqueue_script( 'coywolf-cvm-settings', COYWOLF_CVM_URL . 'js/settings.js', array( 'coywolf-cvm-jscolorpicker' ), Coywolf_Video_Manager::VERSION, true );

			$d = Coywolf_CVM_Settings::defaults();
			wp_add_inline_script(
				'coywolf-cvm-settings',
				'window.coywolfCVMSettings = ' . wp_json_encode(
					array(
						'option'   => Coywolf_CVM_Settings::OPTION,
						'defaults' => array(
							'title_color'  => $d['title_color'],
							'title_size'   => $d['title_size'],
							'title_weight' => $d['title_weight'],
							'title_align'  => $d['title_align'],
							'like_color'   => $d['like_color'],
							'like_bg'      => $d['like_bg'],
							'meta_color'   => $d['meta_color'],
							'meta_size'    => $d['meta_size'],
						),
					)
				) . ';',
				'before'
			);
		}
	}

	/**
	 * Handle single + bulk delete from the list table.
	 */
	public function handle_list_actions() {
		if ( ! current_user_can( Coywolf_Video_Manager::CAPABILITY ) ) {
			return;
		}

		// Bulk delete.
		if ( isset( $_REQUEST['uids'] ) && is_array( $_REQUEST['uids'] ) ) {
			check_admin_referer( 'bulk-videos' );
			if ( 'delete' !== $this->current_bulk_action() ) {
				return;
			}
			$uids    = array_map( 'sanitize_text_field', wp_unslash( $_REQUEST['uids'] ) );
			$deleted = 0;
			foreach ( $uids as $uid ) {
				if ( '' === $uid ) {
					continue;
				}
				$result = $this->cloudflare->delete_video( $uid );
				if ( ! is_wp_error( $result ) ) {
					++$deleted;
				}
			}
			$this->redirect_list( array( 'coywolf_cvm_deleted' => $deleted ) );
		}

		// Single delete.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( 'delete' === $action && ! empty( $_GET['uid'] ) ) {
			$uid = sanitize_text_field( wp_unslash( $_GET['uid'] ) );
			check_admin_referer( 'coywolf_cvm_delete_' . $uid );
			$result = $this->cloudflare->delete_video( $uid );
			$this->redirect_list( array( 'coywolf_cvm_deleted' => is_wp_error( $result ) ? 0 : 1 ) );
		}
	}

	/**
	 * Resolve the chosen bulk action from either select.
	 *
	 * @return string
	 */
	private function current_bulk_action() {
		// The nonce is verified by check_admin_referer( 'bulk-videos' ) in the
		// caller before this runs.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '-1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '-1' === $action && isset( $_REQUEST['action2'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action = sanitize_key( wp_unslash( $_REQUEST['action2'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return $action;
	}

	/**
	 * Redirect back to the list with query args, then exit.
	 *
	 * @param array $args Query args to add.
	 */
	private function redirect_list( $args ) {
		$url = add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * All Videos screen — dispatches to the Edit screen when ?action=edit is set
	 * (Edit is reached from the list table, so it needs no menu item of its own).
	 */
	public function render_videos_page() {
		if ( ! current_user_can( Coywolf_Video_Manager::CAPABILITY ) ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'edit' === $action ) {
			$this->render_edit_page();
			return;
		}

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'All Videos', 'coywolf-video-manager' ) . '</h1>';
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=coywolf-video-manager-upload' ) ) . '" class="page-title-action">' . esc_html__( 'Upload Video', 'coywolf-video-manager' ) . '</a>';
		echo ' <a href="' . esc_url( add_query_arg( array( 'page' => self::PAGE, 'cvm_refresh' => 1 ), admin_url( 'admin.php' ) ) ) . '" class="page-title-action">' . esc_html__( 'Refresh', 'coywolf-video-manager' ) . '</a>';
		echo '<hr class="wp-header-end" />';

		if ( ! $this->cloudflare->is_configured() ) {
			echo '<div class="notice notice-warning"><p>' . wp_kses_post(
				sprintf(
					/* translators: %s: settings page URL. */
					__( 'Connect your Cloudflare account in <a href="%s">Settings</a> to manage videos.', 'coywolf-video-manager' ),
					esc_url( $this->settings->page_url() )
				)
			) . '</p></div></div>';
			return;
		}

		$this->render_action_notice();

		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once COYWOLF_CVM_PATH . 'includes/class-cvm-list-table.php';
		$table = new Coywolf_CVM_List_Table( $this->cloudflare, $this->stats, $this->index, $this->settings );
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />';
		$table->search_box( __( 'Search videos', 'coywolf-video-manager' ), 'coywolf-cvm-video' );
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Show a notice after a delete action.
	 */
	private function render_action_notice() {
		if ( ! isset( $_GET['coywolf_cvm_deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$count = (int) $_GET['coywolf_cvm_deleted']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
			sprintf(
				/* translators: %d: number of videos deleted. */
				_n( '%d video deleted.', '%d videos deleted.', $count, 'coywolf-video-manager' ),
				$count
			)
		) . '</p></div>';
	}

	/**
	 * Upload Video screen — a form driven by admin.js (direct upload to Cloudflare).
	 */
	public function render_upload_page() {
		if ( ! current_user_can( Coywolf_Video_Manager::CAPABILITY ) ) {
			return;
		}
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Upload Video', 'coywolf-video-manager' ) . '</h1>';
		if ( ! $this->cloudflare->is_configured() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Connect your Cloudflare account first.', 'coywolf-video-manager' ) . '</p></div></div>';
			return;
		}
		?>
		<div class="coywolf-cvm-uploader" data-list-url="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>">
			<div class="coywolf-cvm-edit-field">
				<label for="cvm-up-file"><?php esc_html_e( 'Video file', 'coywolf-video-manager' ); ?></label>
				<input type="file" id="cvm-up-file" accept="video/*" />
				<p class="description"><?php esc_html_e( 'Up to 200 MB upload directly here. Larger files can be added from the Cloudflare dashboard.', 'coywolf-video-manager' ); ?></p>
			</div>
			<div class="coywolf-cvm-edit-field">
				<label for="cvm-up-name"><?php esc_html_e( 'Name', 'coywolf-video-manager' ); ?></label>
				<input type="text" id="cvm-up-name" class="regular-text" />
			</div>
			<div class="coywolf-cvm-edit-field">
				<label for="cvm-up-creator"><?php esc_html_e( 'Creator', 'coywolf-video-manager' ); ?></label>
				<input type="text" id="cvm-up-creator" class="regular-text" />
			</div>
			<div class="coywolf-cvm-edit-field">
				<label for="cvm-up-origins"><?php esc_html_e( 'Allowed origins (one per line)', 'coywolf-video-manager' ); ?></label>
				<textarea id="cvm-up-origins" class="large-text" rows="3" placeholder="example.com"></textarea>
			</div>
			<div class="coywolf-cvm-edit-field">
				<label><input type="checkbox" id="cvm-up-signed" /> <?php esc_html_e( 'Require signed URLs (private video)', 'coywolf-video-manager' ); ?></label>
			</div>
			<p>
				<button type="button" class="button button-primary" id="cvm-up-start"><?php esc_html_e( 'Upload to Cloudflare', 'coywolf-video-manager' ); ?></button>
			</p>
			<div class="coywolf-cvm-progress"><div class="coywolf-cvm-progress-bar"></div></div>
			<div class="coywolf-cvm-upload-status" role="status" aria-live="polite"></div>
		</div>
		<?php
		echo '</div>';
	}

	/**
	 * Edit Video screen.
	 */
	public function render_edit_page() {
		if ( ! current_user_can( Coywolf_Video_Manager::CAPABILITY ) ) {
			return;
		}
		$uid = isset( $_GET['uid'] ) ? sanitize_text_field( wp_unslash( $_GET['uid'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Edit Video', 'coywolf-video-manager' ) . '</h1>';
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '" class="page-title-action">' . esc_html__( 'Back to All Videos', 'coywolf-video-manager' ) . '</a>';
		echo '<hr class="wp-header-end" />';

		if ( '' === $uid ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'No video specified.', 'coywolf-video-manager' ) . '</p></div></div>';
			return;
		}

		$video = $this->cloudflare->get_video( $uid );
		if ( is_wp_error( $video ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $video->get_error_message() ) . '</p></div></div>';
			return;
		}

		$name     = isset( $video['meta']['name'] ) ? (string) $video['meta']['name'] : '';
		$creator  = isset( $video['creator'] ) ? (string) $video['creator'] : '';
		$origins  = isset( $video['allowedOrigins'] ) && is_array( $video['allowedOrigins'] ) ? implode( "\n", $video['allowedOrigins'] ) : '';
		$signed   = ! empty( $video['requireSignedURLs'] );
		$duration = isset( $video['duration'] ) ? (float) $video['duration'] : 0;
		$pct      = isset( $video['thumbnailTimestampPct'] ) ? (float) $video['thumbnailTimestampPct'] : 0;
		$thumb    = isset( $video['thumbnail'] ) ? (string) $video['thumbnail'] : '';
		$pos_time = $duration > 0 ? round( $pct * $duration ) : 0;
		?>
		<div class="coywolf-cvm-edit" data-uid="<?php echo esc_attr( $uid ); ?>" data-duration="<?php echo esc_attr( $duration ); ?>">
			<div class="coywolf-cvm-edit-field">
				<label for="cvm-name"><?php esc_html_e( 'Name', 'coywolf-video-manager' ); ?></label>
				<input type="text" id="cvm-name" class="regular-text" value="<?php echo esc_attr( $name ); ?>" />
			</div>
			<div class="coywolf-cvm-edit-field">
				<label for="cvm-creator"><?php esc_html_e( 'Creator', 'coywolf-video-manager' ); ?></label>
				<input type="text" id="cvm-creator" class="regular-text" value="<?php echo esc_attr( $creator ); ?>" />
			</div>
			<div class="coywolf-cvm-edit-field">
				<label for="cvm-origins"><?php esc_html_e( 'Allowed origins (one per line, blank = any)', 'coywolf-video-manager' ); ?></label>
				<textarea id="cvm-origins" class="large-text" rows="3"><?php echo esc_textarea( $origins ); ?></textarea>
			</div>
			<div class="coywolf-cvm-edit-field">
				<label><input type="checkbox" id="cvm-signed" <?php checked( $signed ); ?> /> <?php esc_html_e( 'Require signed URLs (private video)', 'coywolf-video-manager' ); ?></label>
			</div>
			<div class="coywolf-cvm-edit-field coywolf-cvm-poster-preview">
				<label for="cvm-poster-time"><?php esc_html_e( 'Poster timestamp (seconds)', 'coywolf-video-manager' ); ?></label>
				<input type="range" id="cvm-poster-time" min="0" max="<?php echo esc_attr( $duration > 0 ? (int) ceil( $duration ) : 600 ); ?>" value="<?php echo esc_attr( $pos_time ); ?>" />
				<output id="cvm-poster-time-out"><?php echo esc_html( $pos_time ); ?>s</output>
				<div><img id="cvm-poster-img" src="<?php echo esc_url( $thumb ); ?>" alt="" /></div>
			</div>
			<p>
				<button type="button" class="button button-primary" id="cvm-save"><?php esc_html_e( 'Save changes', 'coywolf-video-manager' ); ?></button>
				<span class="coywolf-cvm-save-status" role="status" aria-live="polite"></span>
			</p>

			<h2><?php esc_html_e( 'Captions', 'coywolf-video-manager' ); ?></h2>
			<ul class="coywolf-cvm-captions-list"></ul>
			<div class="coywolf-cvm-edit-field">
				<label for="cvm-cap-lang"><?php esc_html_e( 'Language code (BCP-47, e.g. en, es, fr)', 'coywolf-video-manager' ); ?></label>
				<input type="text" id="cvm-cap-lang" value="en" size="6" />
			</div>
			<p>
				<input type="file" id="cvm-cap-file" accept=".vtt,text/vtt" />
				<button type="button" class="button" id="cvm-cap-upload"><?php esc_html_e( 'Upload VTT', 'coywolf-video-manager' ); ?></button>
				<button type="button" class="button" id="cvm-cap-generate"><?php esc_html_e( 'Auto-generate', 'coywolf-video-manager' ); ?></button>
				<span class="coywolf-cvm-cap-status" role="status" aria-live="polite"></span>
			</p>
		</div>
		<?php
		echo '</div>';
	}
}
