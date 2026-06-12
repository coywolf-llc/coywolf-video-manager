<?php
/**
 * All Videos list table.
 *
 * Lists the Cloudflare Stream library joined with local play/like counts and
 * post/page usage. Search (name or ID) and the Plays/Likes/Posts/Pages filter
 * are applied locally over the fetched, cached library; pagination in PHP.
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
	 * Last API error, if any.
	 *
	 * @var WP_Error|null
	 */
	private $error = null;

	/**
	 * UIDs embedded in content but missing from Cloudflare (see prepare_items).
	 *
	 * @var string[]
	 */
	private $orphans = array();

	/**
	 * UIDs embedded in content but missing from Cloudflare. Empty when the
	 * fetch failed or was too large to trust.
	 *
	 * @return string[]
	 */
	public function orphaned_uids() {
		return $this->orphans;
	}

	/**
	 * Constructor.
	 *
	 * @param Coywolf_CVM_Cloudflare $cloudflare API client.
	 * @param Coywolf_CVM_Stats      $stats      Stats store.
	 * @param Coywolf_CVM_Index      $index      Usage index.
	 */
	public function __construct( Coywolf_CVM_Cloudflare $cloudflare, Coywolf_CVM_Stats $stats, Coywolf_CVM_Index $index ) {
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
	}

	/**
	 * Column definitions.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'        => '<input type="checkbox" />',
			'thumbnail' => __( 'Thumbnail', 'coywolf-video-manager' ),
			'name'      => __( 'Name', 'coywolf-video-manager' ),
			'created'   => __( 'Created', 'coywolf-video-manager' ),
			'plays'     => __( 'Plays', 'coywolf-video-manager' ),
			'likes'     => __( 'Likes', 'coywolf-video-manager' ),
			'posts'     => __( 'Posts', 'coywolf-video-manager' ),
			'pages'     => __( 'Pages', 'coywolf-video-manager' ),
		);
	}

	/**
	 * Keep Name the primary column (row actions, responsive toggle) now that
	 * Thumbnail comes first. Core's default falls back to the first non-cb
	 * column on screens without registered column headers, like this one.
	 *
	 * @return string
	 */
	protected function get_primary_column_name() {
		return 'name';
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'name'    => array( 'name', false ),
			// True: the default view is already sorted by this, descending.
			'created' => array( 'created', true ),
			'plays'   => array( 'plays', false ),
			'likes'   => array( 'likes', false ),
			'posts'   => array( 'posts', false ),
			'pages'   => array( 'pages', false ),
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
	 * Plays/Likes/Posts/Pages filter dropdown, right of the bulk actions.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$current = isset( $_REQUEST['cvm_filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['cvm_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$options = array(
			''      => __( 'All videos', 'coywolf-video-manager' ),
			'plays' => __( 'Has plays', 'coywolf-video-manager' ),
			'likes' => __( 'Has likes', 'coywolf-video-manager' ),
			'posts' => __( 'On posts', 'coywolf-video-manager' ),
			'pages' => __( 'On pages', 'coywolf-video-manager' ),
		);
		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="coywolf-cvm-filter">' . esc_html__( 'Filter videos', 'coywolf-video-manager' ) . '</label>';
		echo '<select name="cvm_filter" id="coywolf-cvm-filter" class="coywolf-cvm-filter">';
		foreach ( $options as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
		echo '</div>';
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
		$edit_url = $this->edit_url( $item['uid'] );

		$name    = '' !== $item['name'] ? $item['name'] : __( '(untitled)', 'coywolf-video-manager' );
		$actions = array(
			'edit'  => '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'coywolf-video-manager' ) . '</a>',
			'trash' => '<a href="' . esc_url( $this->delete_url( $item['uid'] ) ) . '" class="coywolf-cvm-delete">' . esc_html__( 'Delete', 'coywolf-video-manager' ) . '</a>',
		);

		return '<strong><a class="row-title" href="' . esc_url( $edit_url ) . '">' . esc_html( $name ) . '</a></strong>' . $this->row_actions( $actions );
	}

	/**
	 * Thumbnail column: the video's poster frame, linked to the Edit Video page.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_thumbnail( $item ) {
		if ( '' === $item['thumbnail'] ) {
			return '&#8212;';
		}
		// The stored poster (custom image or explicit timestamp), never
		// Cloudflare's bare default poster — it can 400 while regenerating.
		$src = Coywolf_CVM_Block::poster_thumbnail_url( $item['uid'], $this->cloudflare, array( 'width' => 240 ) );
		return '<a href="' . esc_url( $this->edit_url( $item['uid'] ) ) . '"><img class="coywolf-cvm-list-thumb" src="' . esc_url( $src ) . '" alt="" loading="lazy" /></a>';
	}

	/**
	 * Edit Video page URL.
	 *
	 * @param string $uid Video UID.
	 * @return string
	 */
	private function edit_url( $uid ) {
		return add_query_arg(
			array(
				'page'   => Coywolf_CVM_Admin::PAGE,
				'action' => 'edit',
				'uid'    => $uid,
			),
			admin_url( 'admin.php' )
		);
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
	 * Created column: Cloudflare upload time, in the site timezone and format.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_created( $item ) {
		if ( empty( $item['created_ts'] ) ) {
			return '&#8212;';
		}
		return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['created_ts'] ) );
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

		$force = isset( $_REQUEST['cvm_refresh'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Always show fresh data on the list screen (lightly throttled, so
		// rapid sorting/paging rides the cache). The webhook covers uploads
		// finishing, but Cloudflare sends no event for dashboard-side deletes
		// or renames — without this they would linger for the five-minute
		// list cache.
		if ( ! $force && false === get_transient( 'coywolf_cvm_list_fresh' ) ) {
			$force = true;
		}
		if ( $force ) {
			set_transient( 'coywolf_cvm_list_fresh', 1, 15 );
		}

		$videos = $this->cloudflare->list_videos( array( 'force' => $force ) );
		if ( is_wp_error( $videos ) ) {
			$this->error = $videos;
			$this->items = array();
			return;
		}

		// Embedded-but-missing detection: videos still referenced by posts or
		// pages but no longer on Cloudflare (deleted in its dashboard). Only
		// computed from a complete, successful fetch — an API error or a
		// truncated page of 1,000 must never make a video look deleted.
		$this->orphans = array();
		if ( count( $videos ) < 1000 ) {
			$cloud = array();
			foreach ( $videos as $video ) {
				if ( ! empty( $video['uid'] ) ) {
					$cloud[ (string) $video['uid'] ] = true;
				}
			}
			foreach ( $this->index->embedded_uids() as $embedded ) {
				if ( ! isset( $cloud[ $embedded ] ) ) {
					$this->orphans[] = $embedded;
				}
			}
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
				'uid'        => $uid,
				'name'       => isset( $video['meta']['name'] ) ? (string) $video['meta']['name'] : '',
				'thumbnail'  => isset( $video['thumbnail'] ) ? (string) $video['thumbnail'] : '',
				'created_ts' => isset( $video['created'] ) ? (int) strtotime( (string) $video['created'] ) : 0,
				'plays'      => 0,
				'likes'      => 0,
				'posts'      => 0,
				'pages'      => 0,
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

		// Search by name OR video ID.
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $search ) {
			$needle = strtolower( $search );
			$rows   = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $needle ) {
						return false !== strpos( strtolower( $row['name'] ), $needle )
							|| false !== strpos( strtolower( $row['uid'] ), $needle );
					}
				)
			);
		}

		// Filter by Plays/Likes/Posts/Pages > 0.
		$filter = isset( $_REQUEST['cvm_filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['cvm_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $filter, array( 'plays', 'likes', 'posts', 'pages' ), true ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $filter ) {
						return (int) $row[ $filter ] > 0;
					}
				)
			);
		}

		// Sort. Default: newest first by Cloudflare creation time.
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order   = isset( $_REQUEST['order'] ) ? strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $orderby, array( 'name', 'created', 'plays', 'likes', 'posts', 'pages' ), true ) ) {
			$orderby = 'created';
			if ( '' === $order ) {
				$order = 'desc';
			}
		}
		if ( ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
			$order = 'asc';
		}
		usort(
			$rows,
			static function ( $a, $b ) use ( $orderby ) {
				if ( 'name' === $orderby ) {
					return strcasecmp( $a['name'], $b['name'] );
				}
				if ( 'created' === $orderby ) {
					return $a['created_ts'] <=> $b['created_ts'];
				}
				return $a[ $orderby ] <=> $b[ $orderby ];
			}
		);
		if ( 'desc' === $order ) {
			$rows = array_reverse( $rows );
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
