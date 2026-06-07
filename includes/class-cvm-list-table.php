<?php
/**
 * All Videos list table.
 *
 * Lists the Cloudflare Stream library joined with local play/like counts and
 * post/page usage. Search is delegated to the Cloudflare API; pagination is
 * handled in PHP over the fetched page.
 *
 * @package CoywolfVideoManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The All Videos table.
 */
class Coywolf_CVM_List_Table extends WP_List_Table {

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
	 * Settings.
	 *
	 * @var Coywolf_CVM_Settings
	 */
	private $settings;

	/**
	 * Last API error, if any.
	 *
	 * @var WP_Error|null
	 */
	private $error = null;

	/**
	 * Constructor.
	 *
	 * @param Coywolf_CVM_Cloudflare $cloudflare API client.
	 * @param Coywolf_CVM_Stats      $stats      Stats store.
	 * @param Coywolf_CVM_Index      $index      Usage index.
	 * @param Coywolf_CVM_Settings   $settings   Settings.
	 */
	public function __construct( Coywolf_CVM_Cloudflare $cloudflare, Coywolf_CVM_Stats $stats, Coywolf_CVM_Index $index, Coywolf_CVM_Settings $settings ) {
		parent::__construct(
			array(
				'singular' => 'video',
				'plural'   => 'videos',
				'ajax'     => false,
			)
		);
		$this->cloudflare = $cloudflare;
		$this->stats      = $stats;
		$this->index      = $index;
		$this->settings   = $settings;
	}

	/**
	 * Column definitions.
	 *
	 * @return array
	 */
	public function get_columns() {
		$columns = array(
			'cb'     => '<input type="checkbox" />',
			'name'   => __( 'Name', 'coywolf-video-manager' ),
			'uid'    => __( 'Video ID', 'coywolf-video-manager' ),
			'status' => __( 'Status', 'coywolf-video-manager' ),
			'plays'  => __( 'Plays', 'coywolf-video-manager' ),
			'likes'  => __( 'Likes', 'coywolf-video-manager' ),
			'posts'  => __( 'Posts', 'coywolf-video-manager' ),
			'pages'  => __( 'Pages', 'coywolf-video-manager' ),
		);
		if ( $this->settings->get( 'analytics_enabled' ) ) {
			$columns['minutes'] = __( 'Minutes viewed', 'coywolf-video-manager' );
		}
		return $columns;
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'name'  => array( 'name', false ),
			'plays' => array( 'plays', false ),
			'likes' => array( 'likes', false ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'coywolf-video-manager' ) );
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="uids[]" value="%s" />', esc_attr( $item['uid'] ) );
	}

	/**
	 * Name column with row actions.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_name( $item ) {
		$edit_url = add_query_arg(
			array(
				'page'   => Coywolf_CVM_Admin::PAGE,
				'action' => 'edit',
				'uid'    => $item['uid'],
			),
			admin_url( 'admin.php' )
		);

		$name = '' !== $item['name'] ? $item['name'] : __( '(untitled)', 'coywolf-video-manager' );

		$embed = sprintf(
			'<iframe src="%s" loading="lazy" style="border:none;width:100%%;aspect-ratio:16/9;" allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;" allowfullscreen="true"></iframe>',
			esc_url( $this->cloudflare->iframe_url( $item['uid'] ) )
		);

		$actions = array(
			'edit'  => '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'coywolf-video-manager' ) . '</a>',
			'embed' => '<a href="#" class="coywolf-cvm-copy-embed" data-embed="' . esc_attr( $embed ) . '">' . esc_html__( 'Copy embed', 'coywolf-video-manager' ) . '</a>',
			'trash' => '<a href="' . esc_url( $this->delete_url( $item['uid'] ) ) . '" class="coywolf-cvm-delete">' . esc_html__( 'Delete', 'coywolf-video-manager' ) . '</a>',
		);

		return '<strong><a class="row-title" href="' . esc_url( $edit_url ) . '">' . esc_html( $name ) . '</a></strong>' . $this->row_actions( $actions );
	}

	/**
	 * Single-delete URL (nonce-protected).
	 *
	 * @param string $uid Video UID.
	 * @return string
	 */
	private function delete_url( $uid ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'   => Coywolf_CVM_Admin::PAGE,
					'action' => 'delete',
					'uid'    => $uid,
				),
				admin_url( 'admin.php' )
			),
			'coywolf_cvm_delete_' . $uid
		);
	}

	/**
	 * Video ID column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_uid( $item ) {
		return '<code>' . esc_html( $item['uid'] ) . '</code>';
	}

	/**
	 * Status column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_status( $item ) {
		if ( $item['ready'] ) {
			return '<span style="color:#1a7f37;">' . esc_html__( 'Ready', 'coywolf-video-manager' ) . '</span>';
		}
		$state = '' !== $item['state'] ? $item['state'] : __( 'processing', 'coywolf-video-manager' );
		return '<span style="color:#996800;">' . esc_html( ucfirst( $state ) ) . '</span>';
	}

	/**
	 * Plays column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_plays( $item ) {
		return esc_html( number_format_i18n( $item['plays'] ) );
	}

	/**
	 * Likes column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_likes( $item ) {
		return esc_html( number_format_i18n( $item['likes'] ) );
	}

	/**
	 * Minutes-viewed column (analytics).
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_minutes( $item ) {
		return isset( $item['minutes'] ) ? esc_html( number_format_i18n( $item['minutes'] ) ) : '—';
	}

	/**
	 * Posts usage column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_posts( $item ) {
		return $this->usage_cell( (int) $item['posts'], 'post', $item['uid'] );
	}

	/**
	 * Pages usage column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_pages( $item ) {
		return $this->usage_cell( (int) $item['pages'], 'page', $item['uid'] );
	}

	/**
	 * Render a usage count as a link to the filtered post-type list, or "0".
	 *
	 * @param int    $count     Usage count.
	 * @param string $post_type Post type.
	 * @param string $uid       Video UID.
	 * @return string
	 */
	private function usage_cell( $count, $post_type, $uid ) {
		if ( $count < 1 ) {
			return '0';
		}
		$url = add_query_arg(
			array(
				'post_type'         => $post_type,
				'coywolf_cvm_video' => $uid,
			),
			admin_url( 'edit.php' )
		);
		return '<a href="' . esc_url( $url ) . '">' . esc_html( number_format_i18n( $count ) ) . '</a>';
	}

	/**
	 * Fallback column renderer.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}

	/**
	 * Message when there are no videos.
	 */
	public function no_items() {
		if ( is_wp_error( $this->error ) ) {
			echo esc_html( $this->error->get_error_message() );
			return;
		}
		esc_html_e( 'No videos found.', 'coywolf-video-manager' );
	}

	/**
	 * Fetch + assemble the rows.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$force  = isset( $_REQUEST['cvm_refresh'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$videos = $this->cloudflare->list_videos(
			array(
				'search' => $search,
				'force'  => $force,
			)
		);
		if ( is_wp_error( $videos ) ) {
			$this->error = $videos;
			$this->items = array();
			return;
		}

		$rows = array();
		$uids = array();
		foreach ( $videos as $video ) {
			if ( empty( $video['uid'] ) ) {
				continue;
			}
			$uid    = (string) $video['uid'];
			$uids[] = $uid;
			$rows[] = array(
				'uid'   => $uid,
				'name'  => isset( $video['meta']['name'] ) ? (string) $video['meta']['name'] : '',
				'ready' => ! empty( $video['readyToStream'] ),
				'state' => isset( $video['status']['state'] ) ? (string) $video['status']['state'] : '',
				'plays' => 0,
				'likes' => 0,
				'posts' => 0,
				'pages' => 0,
			);
		}

		$counts = $this->stats->get_counts_map( $uids );
		$usage  = $this->index->usage_map( $uids );
		foreach ( $rows as &$row ) {
			if ( isset( $counts[ $row['uid'] ] ) ) {
				$row['plays'] = (int) $counts[ $row['uid'] ]['plays'];
				$row['likes'] = (int) $counts[ $row['uid'] ]['likes'];
			}
			if ( isset( $usage[ $row['uid'] ] ) ) {
				$row['posts'] = (int) $usage[ $row['uid'] ]['posts'];
				$row['pages'] = (int) $usage[ $row['uid'] ]['pages'];
			}
		}
		unset( $row );

		if ( $this->settings->get( 'analytics_enabled' ) ) {
			$minutes = $this->cloudflare->minutes_viewed_map();
			foreach ( $rows as &$row ) {
				$row['minutes'] = isset( $minutes[ $row['uid'] ] ) ? (int) $minutes[ $row['uid'] ] : 0;
			}
			unset( $row );
		}

		// Sort.
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order   = ( isset( $_REQUEST['order'] ) && 'desc' === strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) ) ? 'desc' : 'asc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $orderby, array( 'name', 'plays', 'likes' ), true ) ) {
			usort(
				$rows,
				static function ( $a, $b ) use ( $orderby ) {
					if ( 'name' === $orderby ) {
						return strcasecmp( $a['name'], $b['name'] );
					}
					return $a[ $orderby ] <=> $b[ $orderby ];
				}
			);
			if ( 'desc' === $order ) {
				$rows = array_reverse( $rows );
			}
		}

		// Paginate in PHP.
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$total        = count( $rows );
		$this->items  = array_slice( $rows, ( $current_page - 1 ) * $per_page, $per_page );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}
}
