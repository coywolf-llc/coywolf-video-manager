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
		add_action( 'admin_post_coywolf_cvm_cleanup_orphans', array( $this, 'handle_cleanup_orphans' ) );
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

		$this->hooks['docs'] = add_submenu_page(
			self::PAGE,
			__( 'Documentation', 'coywolf-video-manager' ),
			__( 'Documentation', 'coywolf-video-manager' ),
			$cap,
			'coywolf-video-manager-docs',
			array( 'Coywolf_CVM_Docs', 'render_page' )
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
		// The Edit Video screen (under the list hook) uses the media library for posters.
		if ( isset( $this->hooks['list'] ) && $hook === $this->hooks['list'] ) {
			wp_enqueue_media();
		}
		// The Upload Video screen sends files to Cloudflare with the shared
		// resumable-upload client (also used by the block's in-editor uploader).
		Coywolf_CVM_Block::register_upload_script();
		wp_enqueue_script( 'coywolf-cvm-admin', COYWOLF_CVM_URL . 'js/admin.js', array( 'wp-api-fetch', 'wp-element', 'wp-i18n', 'coywolf-cvm-upload' ), Coywolf_Video_Manager::VERSION, true );
		wp_add_inline_script(
			'coywolf-cvm-admin',
			'window.coywolfCVM = ' . wp_json_encode(
				array(
					'restUrl'    => esc_url_raw( rest_url( 'coywolf-cvm/v1' ) ),
					'nonce'      => wp_create_nonce( 'wp_rest' ),
					'pageUrl'    => admin_url( 'admin.php?page=' . self::PAGE ),
					'i18n'       => array(
						'copiedId'       => __( 'Copied!', 'coywolf-video-manager' ),
						'mediaTitle'     => __( 'Select poster image', 'coywolf-video-manager' ),
						'mediaButton'    => __( 'Use image', 'coywolf-video-manager' ),
						'confirmDelete'  => __( 'Delete this video from Cloudflare? This cannot be undone, and its block is removed from any post or page that uses it.', 'coywolf-video-manager' ),
						'confirmOrphans' => __( 'Remove the missing videos from those posts and delete their leftover local data? This cannot be undone.', 'coywolf-video-manager' ),
						'deleteConfirm'  => __( 'Delete', 'coywolf-video-manager' ),
						'deleting'       => __( 'Deleting…', 'coywolf-video-manager' ),
						'saved'          => __( 'Saved.', 'coywolf-video-manager' ),
						'noCaptions'     => __( 'No captions yet.', 'coywolf-video-manager' ),
						'remove'         => __( 'Remove', 'coywolf-video-manager' ),
						'captionRemoved' => __( 'Caption removed.', 'coywolf-video-manager' ),
						'generating'     => __( 'Generating captions — this can take a few minutes.', 'coywolf-video-manager' ),
						'pickFile'       => __( 'Choose a .vtt file first.', 'coywolf-video-manager' ),
						'pickVideo'      => __( 'Choose a video file first.', 'coywolf-video-manager' ),
						'preparing'      => __( 'Preparing upload…', 'coywolf-video-manager' ),
						'uploading'      => __( 'Uploading…', 'coywolf-video-manager' ),
						'retrying'       => __( 'Connection hiccup — resuming upload…', 'coywolf-video-manager' ),
						'processing'     => __( 'Uploaded. Cloudflare is processing the video…', 'coywolf-video-manager' ),
						'uploadFailed'   => __( 'Upload failed.', 'coywolf-video-manager' ),
						'stillProcessing' => __( 'Still processing — check All Videos shortly.', 'coywolf-video-manager' ),
						'mp4Enable'      => __( 'Enable MP4 download', 'coywolf-video-manager' ),
						'mp4Preparing'   => __( 'Preparing MP4…', 'coywolf-video-manager' ),
						'mp4Failed'      => __( 'Could not prepare the MP4.', 'coywolf-video-manager' ),
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
							'desc_weight'  => $d['desc_weight'],
							'align'        => $d['align'],
							'meta_align'   => $d['meta_align'],
							'like_icon'    => $d['like_icon'],
							'like_color'        => $d['like_color'],
							'like_bg'           => $d['like_bg'],
							'like_active_color' => $d['like_active_color'],
							'like_active_bg'    => $d['like_active_bg'],
							'meta_color'   => $d['meta_color'],
							'meta_size'    => $d['meta_size'],
							'radius'       => $d['radius'],
							'border_enabled' => $d['border_enabled'],
							'border_width'   => $d['border_width'],
							'border_color'   => $d['border_color'],
						),
						'icons'    => array(
							'heart'  => Coywolf_CVM_Block::thumb_svg( 'heart' ),
							'thumbs' => Coywolf_CVM_Block::thumb_svg( 'thumbs' ),
							'star'   => Coywolf_CVM_Block::thumb_svg( 'star' ),
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

		// Bulk actions (add tags / delete).
		if ( isset( $_REQUEST['uids'] ) && is_array( $_REQUEST['uids'] ) ) {
			check_admin_referer( 'bulk-videos' );
			$bulk = $this->current_bulk_action();
			$uids = array_map( 'sanitize_text_field', wp_unslash( $_REQUEST['uids'] ) );

			// Bulk add tags to the selected videos.
			if ( 'cvm_add_tags' === $bulk ) {
				$raw_tags = isset( $_REQUEST['cvm_bulk_tags'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['cvm_bulk_tags'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$tags     = Coywolf_CVM_Cloudflare::parse_tags( $raw_tags );
				$tagged   = 0;
				if ( ! empty( $tags ) ) {
					foreach ( $uids as $uid ) {
						if ( '' === $uid ) {
							continue;
						}
						$result = $this->cloudflare->add_tags( $uid, $tags );
						if ( ! is_wp_error( $result ) ) {
							++$tagged;
						}
					}
				}
				$this->redirect_list( array( 'coywolf_cvm_tagged' => $tagged ) );
			}

			if ( 'delete' !== $bulk ) {
				return;
			}
			$deleted = 0;
			foreach ( $uids as $uid ) {
				if ( '' === $uid ) {
					continue;
				}
				$result = $this->cloudflare->delete_video( $uid );
				if ( ! is_wp_error( $result ) ) {
					Coywolf_Video_Manager::instance()->purge_video( $uid );
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
			if ( ! is_wp_error( $result ) ) {
				Coywolf_Video_Manager::instance()->purge_video( $uid );
			}
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
		// With the webhook enabled, Cloudflare pings this site as videos finish
		// processing, so the manual cache-busting Refresh is redundant (other
		// dashboard-side edits self-heal within the five-minute list cache).
		$coywolf_cvm_webhook = get_option( 'coywolf_cvm_webhook', array() );
		if ( ! is_array( $coywolf_cvm_webhook ) || empty( $coywolf_cvm_webhook['secret'] ) ) {
			echo ' <a href="' . esc_url( add_query_arg( array( 'page' => self::PAGE, 'cvm_refresh' => 1 ), admin_url( 'admin.php' ) ) ) . '" class="page-title-action">' . esc_html__( 'Refresh', 'coywolf-video-manager' ) . '</a>';
		}
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

		// Sticky search + filter: persist per-user until reset (empty submit),
		// and restore when arriving without them (e.g. via the menu).
		$this->sticky_request( 's', 'coywolf_cvm_search' );
		$this->sticky_request( 'cvm_filter', 'coywolf_cvm_filter' );

		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once COYWOLF_CVM_PATH . 'includes/class-cvm-list-table.php';
		$table = new Coywolf_CVM_List_Table( $this->cloudflare, $this->stats, $this->index );
		$table->prepare_items();
		$this->render_orphans_notice( $table->orphaned_uids() );

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />';
		$table->search_box( __( 'Search videos', 'coywolf-video-manager' ), 'coywolf-cvm-video' );
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Make a request param sticky per user: store it when present (clear on empty
	 * submit), and restore it into the request when absent.
	 *
	 * @param string $key      Request key.
	 * @param string $meta_key User-meta key.
	 */
	private function sticky_request( $key, $meta_key ) {
		$user = get_current_user_id();
		if ( isset( $_REQUEST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$value = sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( '' === $value ) {
				delete_user_meta( $user, $meta_key );
			} else {
				update_user_meta( $user, $meta_key, $value );
			}
			return;
		}
		$value = (string) get_user_meta( $user, $meta_key, true );
		if ( '' !== $value ) {
			$_REQUEST[ $key ] = $value;
			$_GET[ $key ]     = $value;
		}
	}

	/**
	 * Show a notice after a save or delete action (redirected from the Edit screen).
	 */
	private function render_action_notice() {
		if ( isset( $_GET['coywolf_cvm_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Video updated.', 'coywolf-video-manager' ) . '</p></div>';
			return;
		}
		if ( isset( $_GET['coywolf_cvm_tagged'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tagged = (int) $_GET['coywolf_cvm_tagged']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %d: number of videos tagged. */
					_n( 'Tags added to %d video.', 'Tags added to %d videos.', $tagged, 'coywolf-video-manager' ),
					$tagged
				)
			) . '</p></div>';
			return;
		}
		if ( isset( $_GET['coywolf_cvm_cleanup_failed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not verify the video list with Cloudflare, so nothing was removed. Try again in a moment.', 'coywolf-video-manager' ) . '</p></div>';
			return;
		}
		if ( isset( $_GET['coywolf_cvm_cleaned'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$cleaned = (int) $_GET['coywolf_cvm_cleaned']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %d: number of videos cleaned up. */
					_n( '%d missing video removed from posts and cleaned up.', '%d missing videos removed from posts and cleaned up.', $cleaned, 'coywolf-video-manager' ),
					$cleaned
				)
			) . '</p></div>';
			return;
		}
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
		<?php $this->render_storage_usage(); ?>
		<div class="coywolf-cvm-uploader" data-list-url="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>">
			<div class="coywolf-cvm-edit-field">
				<label for="cvm-up-file"><?php esc_html_e( 'Video file', 'coywolf-video-manager' ); ?></label>
				<input type="file" id="cvm-up-file" accept="video/*" />
				<p class="description"><?php esc_html_e( 'Large files are supported — the upload is sent in chunks and resumes after connection hiccups.', 'coywolf-video-manager' ); ?></p>
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
				<label for="cvm-up-tags"><?php esc_html_e( 'Tags', 'coywolf-video-manager' ); ?></label>
				<input type="text" id="cvm-up-tags" class="regular-text" />
				<p class="description"><?php esc_html_e( 'Comma-separated. Added on top of any default tags from Settings.', 'coywolf-video-manager' ); ?></p>
			</div>
			<div class="coywolf-cvm-edit-field">
				<label for="cvm-up-origins"><?php esc_html_e( 'Allowed origins (one per line)', 'coywolf-video-manager' ); ?></label>
				<textarea id="cvm-up-origins" class="large-text" rows="3" placeholder="example.com"></textarea>
			</div>
			<p>
				<button type="button" class="button button-primary" id="cvm-up-start"><?php esc_html_e( 'Upload to Cloudflare', 'coywolf-video-manager' ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'Cancel', 'coywolf-video-manager' ); ?></a>
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
		$tags     = implode( ', ', Coywolf_CVM_Cloudflare::video_tags( $video ) );
		$creator  = isset( $video['creator'] ) ? (string) $video['creator'] : '';
		$origins  = isset( $video['allowedOrigins'] ) && is_array( $video['allowedOrigins'] ) ? implode( "\n", $video['allowedOrigins'] ) : '';
		$duration = isset( $video['duration'] ) ? (float) $video['duration'] : 0;
		$pct      = isset( $video['thumbnailTimestampPct'] ) ? (float) $video['thumbnailTimestampPct'] : 0;

		// Per-video poster (timestamp or custom image), saved on this page.
		$poster      = Coywolf_CVM_Block::video_poster( $uid );
		$poster_mode = ( $poster && isset( $poster['mode'] ) ) ? $poster['mode'] : 'timestamp';
		$poster_time = ( $poster && 'timestamp' === $poster_mode && isset( $poster['time'] ) )
			? (float) $poster['time']
			: ( $duration > 0 ? round( $pct * $duration ) : 0 );
		$poster_img_id  = ( $poster && isset( $poster['image_id'] ) ) ? (int) $poster['image_id'] : 0;
		$poster_img_url = ( $poster && isset( $poster['image_url'] ) ) ? (string) $poster['image_url'] : '';
		// Preview with an explicit time — Cloudflare's bare default poster
		// ($video['thumbnail']) can 400 while regenerating after a save.
		$preview_src = ( 'image' === $poster_mode && '' !== $poster_img_url )
			? $poster_img_url
			: $this->cloudflare->thumbnail_url(
				$uid,
				array(
					'time'  => $poster_time . 's',
					'width' => 1200,
				)
			);
		$back_url    = admin_url( 'admin.php?page=' . self::PAGE );
		$video_desc  = Coywolf_CVM_Block::video_description( $uid );

		// Recommended custom-poster size: 1200px wide, height matching the video.
		$vid_w = isset( $video['input']['width'] ) ? (int) $video['input']['width'] : 0;
		$vid_h = isset( $video['input']['height'] ) ? (int) $video['input']['height'] : 0;
		$rec_h = ( $vid_w > 0 && $vid_h > 0 ) ? (int) round( 1200 * $vid_h / $vid_w ) : 675;
		?>
		<div class="coywolf-cvm-edit" data-uid="<?php echo esc_attr( $uid ); ?>" data-duration="<?php echo esc_attr( $duration ); ?>" data-list-url="<?php echo esc_url( $back_url ); ?>">
			<table class="form-table" role="presentation"><tbody>
				<tr>
					<th scope="row"><label for="cvm-name"><?php esc_html_e( 'Name', 'coywolf-video-manager' ); ?></label></th>
					<td><input type="text" id="cvm-name" class="regular-text" value="<?php echo esc_attr( $name ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cvm-tags"><?php esc_html_e( 'Tags', 'coywolf-video-manager' ); ?></label></th>
					<td>
						<input type="text" id="cvm-tags" class="regular-text" value="<?php echo esc_attr( $tags ); ?>" />
						<p class="description"><?php esc_html_e( 'Comma-separated. Tags let you filter the All Videos list (search with #tag).', 'coywolf-video-manager' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cvm-description"><?php esc_html_e( 'Description', 'coywolf-video-manager' ); ?></label></th>
					<td>
						<textarea id="cvm-description" class="large-text" rows="4"><?php echo esc_textarea( $video_desc ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Used in the video schema markup and the XML sitemap.', 'coywolf-video-manager' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cvm-creator"><?php esc_html_e( 'Creator', 'coywolf-video-manager' ); ?></label></th>
					<td><input type="text" id="cvm-creator" class="regular-text" value="<?php echo esc_attr( $creator ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cvm-origins"><?php esc_html_e( 'Allowed origins', 'coywolf-video-manager' ); ?></label></th>
					<td>
						<textarea id="cvm-origins" class="large-text" rows="3"><?php echo esc_textarea( $origins ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One per line; blank = any.', 'coywolf-video-manager' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Poster', 'coywolf-video-manager' ); ?></th>
					<td>
						<p>
							<label><input type="radio" name="cvm-poster-mode" value="timestamp" <?php checked( 'image' !== $poster_mode ); ?> /> <?php esc_html_e( 'Use a timestamp', 'coywolf-video-manager' ); ?></label><br />
							<label><input type="radio" name="cvm-poster-mode" value="image" <?php checked( 'image' === $poster_mode ); ?> /> <?php esc_html_e( 'Use a custom image', 'coywolf-video-manager' ); ?></label>
						</p>
						<div class="cvm-poster-timestamp"<?php echo 'image' === $poster_mode ? ' style="display:none;"' : ''; ?>>
							<input type="range" id="cvm-poster-time" aria-label="<?php esc_attr_e( 'Poster timestamp (seconds)', 'coywolf-video-manager' ); ?>" min="0" max="<?php echo esc_attr( $duration > 0 ? (int) ceil( $duration ) : 600 ); ?>" value="<?php echo esc_attr( $poster_time ); ?>" />
							<input type="text" id="cvm-poster-time-text" class="coywolf-cvm-poster-time-text" aria-label="<?php esc_attr_e( 'Poster timestamp (hours:minutes:seconds)', 'coywolf-video-manager' ); ?>" value="<?php echo esc_attr( self::format_timestamp( $poster_time ) ); ?>" />
						</div>
						<div class="cvm-poster-image"<?php echo 'image' !== $poster_mode ? ' style="display:none;"' : ''; ?>>
							<button type="button" class="button" id="cvm-poster-pick"><?php esc_html_e( 'Select / upload image', 'coywolf-video-manager' ); ?></button>
							<input type="hidden" id="cvm-poster-image-id" value="<?php echo esc_attr( $poster_img_id ); ?>" />
							<input type="hidden" id="cvm-poster-image-url" value="<?php echo esc_attr( $poster_img_url ); ?>" />
							<p class="description">
								<?php
								printf(
									/* translators: %1$d: recommended width in px, %2$d: recommended height in px. */
									esc_html__( 'Recommended size: %1$d × %2$dpx (matches this video’s dimensions).', 'coywolf-video-manager' ),
									1200,
									(int) $rec_h
								);
								?>
							</p>
						</div>
						<div class="coywolf-cvm-poster-preview"><img id="cvm-poster-img" src="<?php echo esc_url( $preview_src ); ?>" alt="" /></div>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'MP4 download', 'coywolf-video-manager' ); ?></th>
					<td>
						<div id="cvm-mp4" class="coywolf-cvm-mp4" role="status" aria-live="polite"><span class="spinner is-active" style="float:none;margin:0;"></span></div>
						<p class="description"><?php esc_html_e( 'Generates a downloadable MP4 of this video. Once ready, it is also used as the direct video URL in the schema markup and the XML sitemap. Each download counts toward Cloudflare minutes delivered.', 'coywolf-video-manager' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Video ID', 'coywolf-video-manager' ); ?></th>
					<td>
						<span class="coywolf-cvm-copy-id" data-id="<?php echo esc_attr( $uid ); ?>" role="button" tabindex="0" title="<?php esc_attr_e( 'Click to copy', 'coywolf-video-manager' ); ?>">
							<code><?php echo esc_html( $uid ); ?></code>
							<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
							<span class="coywolf-cvm-copy-hint" aria-live="polite"><?php esc_html_e( 'click to copy', 'coywolf-video-manager' ); ?></span>
						</span>
					</td>
				</tr>
			</tbody></table>

			<h2><?php esc_html_e( 'Captions', 'coywolf-video-manager' ); ?></h2>
			<ul class="coywolf-cvm-captions-list" aria-live="polite" aria-relevant="additions text" aria-label="<?php esc_attr_e( 'Caption tracks', 'coywolf-video-manager' ); ?>"></ul>
			<table class="form-table" role="presentation"><tbody>
				<tr>
					<th scope="row"><label for="cvm-cap-lang"><?php esc_html_e( 'Add captions', 'coywolf-video-manager' ); ?></label></th>
					<td>
						<input type="text" id="cvm-cap-lang" value="en" size="6" />
						<input type="file" id="cvm-cap-file" accept=".vtt,text/vtt" />
						<button type="button" class="button" id="cvm-cap-upload"><?php esc_html_e( 'Upload VTT', 'coywolf-video-manager' ); ?></button>
						<button type="button" class="button" id="cvm-cap-generate"><?php esc_html_e( 'Auto-generate', 'coywolf-video-manager' ); ?></button>
						<span class="coywolf-cvm-cap-status" role="status" aria-live="polite"></span>
						<p class="description"><?php esc_html_e( 'Language code is BCP-47, e.g. en, es, fr.', 'coywolf-video-manager' ); ?></p>
					</td>
				</tr>
			</tbody></table>

			<div class="coywolf-cvm-edit-actions">
				<div class="coywolf-cvm-edit-actions-left">
					<button type="button" class="button button-primary" id="cvm-save"><?php esc_html_e( 'Save changes', 'coywolf-video-manager' ); ?></button>
					<a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Cancel', 'coywolf-video-manager' ); ?></a>
					<span class="coywolf-cvm-save-status" role="status" aria-live="polite"></span>
				</div>
				<button type="button" class="button coywolf-cvm-delete-btn" id="cvm-delete"><?php esc_html_e( 'Delete video', 'coywolf-video-manager' ); ?></button>
			</div>

			<div class="coywolf-cvm-modal-overlay" id="cvm-delete-modal" hidden>
				<div class="coywolf-cvm-modal" role="dialog" aria-modal="true" aria-labelledby="cvm-delete-modal-title">
					<h2 id="cvm-delete-modal-title"><?php esc_html_e( 'Delete this video?', 'coywolf-video-manager' ); ?></h2>
					<p><?php esc_html_e( 'Are you sure you want to delete this video? It will be permanently removed from Cloudflare, and its block will be removed from any post or page that includes it. This cannot be undone.', 'coywolf-video-manager' ); ?></p>
					<div class="coywolf-cvm-modal-actions">
						<button type="button" class="button" id="cvm-delete-cancel"><?php esc_html_e( 'Cancel', 'coywolf-video-manager' ); ?></button>
						<button type="button" class="button coywolf-cvm-delete-btn" id="cvm-delete-confirm"><?php esc_html_e( 'Delete', 'coywolf-video-manager' ); ?></button>
					</div>
				</div>
			</div>
		</div>
		<?php
		echo '</div>';
	}

	/**
	 * Warn about videos that are still embedded in content but no longer
	 * exist on Cloudflare (deleted in its dashboard, so no webhook fired and
	 * no purge ran). Cleanup only happens on explicit confirmation.
	 *
	 * @param string[] $orphans Missing-but-embedded video UIDs.
	 */
	private function render_orphans_notice( $orphans ) {
		if ( empty( $orphans ) ) {
			return;
		}

		echo '<div class="notice notice-warning">';
		echo '<p><strong>' . esc_html(
			sprintf(
				/* translators: %d: number of videos. */
				_n( '%d missing video', '%d missing videos', count( $orphans ), 'coywolf-video-manager' ),
				count( $orphans )
			)
		) . '</strong> — ' . esc_html__( 'still embedded in content but no longer on Cloudflare Stream (deleted in its dashboard?). Visitors see an empty player on the pages below.', 'coywolf-video-manager' ) . '</p>';

		echo '<ul style="list-style:disc;margin-left:2em;">';
		foreach ( array_slice( $orphans, 0, 5 ) as $uid ) {
			$links = array();
			foreach ( array_slice( $this->index->posts_for( $uid ), 0, 3 ) as $post_id ) {
				$title   = get_the_title( $post_id );
				$links[] = '<a href="' . esc_url( (string) get_edit_post_link( $post_id ) ) . '">' . esc_html( '' !== $title ? $title : '#' . $post_id ) . '</a>';
			}
			echo '<li><code>' . esc_html( $uid ) . '</code>' . ( $links ? ' — ' . wp_kses_post( implode( ', ', $links ) ) : '' ) . '</li>';
		}
		if ( count( $orphans ) > 5 ) {
			echo '<li>' . esc_html(
				sprintf(
					/* translators: %d: number of additional videos. */
					_n( '…and %d more.', '…and %d more.', count( $orphans ) - 5, 'coywolf-video-manager' ),
					count( $orphans ) - 5
				)
			) . '</li>';
		}
		echo '</ul>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="coywolf-cvm-orphans-form">';
		echo '<input type="hidden" name="action" value="coywolf_cvm_cleanup_orphans" />';
		wp_nonce_field( 'coywolf_cvm_cleanup_orphans' );
		echo '<p>';
		echo '<button type="submit" class="button">' . esc_html__( 'Remove missing videos from posts', 'coywolf-video-manager' ) . '</button> ';
		echo '<span class="description">' . esc_html__( 'Removes their blocks from the posts above and deletes the leftover local data. The list is re-checked against Cloudflare first. This cannot be undone.', 'coywolf-video-manager' ) . '</span>';
		echo '</p>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Confirmed orphan cleanup: re-verify against a fresh, complete Cloudflare
	 * list, then run the full purge (block removal + local data) for each
	 * video that is genuinely gone.
	 */
	public function handle_cleanup_orphans() {
		if ( ! current_user_can( Coywolf_Video_Manager::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'coywolf-video-manager' ) );
		}
		check_admin_referer( 'coywolf_cvm_cleanup_orphans' );

		$videos = $this->cloudflare->list_videos( array( 'force' => true ) );
		if ( is_wp_error( $videos ) || count( $videos ) >= 1000 ) {
			// Can't trust the comparison; never purge on uncertain data.
			$this->redirect_list( array( 'coywolf_cvm_cleanup_failed' => 1 ) );
		}

		$cloud = array();
		foreach ( $videos as $video ) {
			if ( ! empty( $video['uid'] ) ) {
				$cloud[ (string) $video['uid'] ] = true;
			}
		}

		$cleaned = 0;
		foreach ( $this->index->embedded_uids() as $uid ) {
			if ( ! isset( $cloud[ $uid ] ) ) {
				Coywolf_Video_Manager::instance()->purge_video( $uid );
				++$cleaned;
			}
		}

		$this->redirect_list( array( 'coywolf_cvm_cleaned' => $cleaned ) );
	}

	/**
	 * Account storage summary for the Upload screen ("X of Y minutes used").
	 * Silent when the lookup fails — uploading works fine without it.
	 */
	private function render_storage_usage() {
		$usage = $this->cloudflare->storage_usage();
		if ( is_wp_error( $usage ) ) {
			return;
		}
		$used  = (int) $usage['totalStorageMinutes'];
		$limit = (int) $usage['totalStorageMinutesLimit'];
		$count = (int) $usage['videoCount'];

		/* translators: %s: number of videos. */
		$videos_text = sprintf( _n( '%s video', '%s videos', $count, 'coywolf-video-manager' ), number_format_i18n( $count ) );
		?>
		<div class="coywolf-cvm-storage">
			<strong><?php esc_html_e( 'Cloudflare Stream storage', 'coywolf-video-manager' ); ?></strong>
			<p class="description">
			<?php
			if ( $limit > 0 ) {
				printf(
					/* translators: 1: minutes used, 2: minutes included in the plan, 3: "N videos". */
					esc_html__( '%1$s of %2$s minutes used · %3$s', 'coywolf-video-manager' ),
					esc_html( number_format_i18n( $used ) ),
					esc_html( number_format_i18n( $limit ) ),
					esc_html( $videos_text )
				);
			} else {
				printf(
					/* translators: 1: minutes stored, 2: "N videos". */
					esc_html__( '%1$s minutes stored · %2$s', 'coywolf-video-manager' ),
					esc_html( number_format_i18n( $used ) ),
					esc_html( $videos_text )
				);
			}
			?>
			</p>
			<?php if ( $limit > 0 ) : ?>
				<?php $pct = min( 100, (int) round( 100 * $used / max( 1, $limit ) ) ); ?>
				<div class="coywolf-cvm-storage-bar" aria-hidden="true"><span style="width:<?php echo esc_attr( $pct ); ?>%;"></span></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Format a timestamp in seconds as m:ss or h:mm:ss (e.g. 266 -> "4:26",
	 * 3735 -> "1:02:15"). Mirrored by formatTime() in admin.js and block.js.
	 *
	 * @param float $seconds Seconds.
	 * @return string
	 */
	private static function format_timestamp( $seconds ) {
		$total = max( 0, (int) floor( (float) $seconds ) );
		$h     = (int) floor( $total / 3600 );
		$m     = (int) floor( ( $total % 3600 ) / 60 );
		$s     = $total % 60;
		if ( $h > 0 ) {
			return sprintf( '%d:%02d:%02d', $h, $m, $s );
		}
		return sprintf( '%d:%02d', $m, $s );
	}
}
