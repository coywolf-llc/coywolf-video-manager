<?php
/**
 * Documentation screen: renders the bundled readme.md inside wp-admin.
 *
 * A small, self-contained Markdown→HTML converter covering exactly the syntax
 * used in readme.md (headings, ordered/unordered lists, GFM tables, fenced code,
 * bold/italic, inline code, links). The leading logo image and the wporg-strip
 * HTML comments are dropped. Every text node and URL is escaped on output — the
 * source is the plugin's own readme, but we escape regardless for correctness.
 *
 * @package CoywolfVideoManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the in-plugin Documentation page from readme.md.
 */
final class Coywolf_CVM_Docs {

	/**
	 * Output the Documentation admin page.
	 */
	public static function render_page() {
		echo '<div class="wrap coywolf-cvm-docs">';

		$markdown = self::read_readme();
		if ( '' === $markdown ) {
			echo '<h1>' . esc_html__( 'Documentation', 'coywolf-video-manager' ) . '</h1>';
			echo '<p>' . esc_html__( 'The documentation could not be loaded.', 'coywolf-video-manager' ) . '</p>';
			echo '</div>';
			return;
		}

		// to_html() emits only a known HTML subset and escapes every text node + URL.
		echo self::to_html( $markdown ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	/**
	 * Read the bundled readme.md via the WP filesystem API.
	 *
	 * @return string Raw Markdown, or '' if unreadable.
	 */
	private static function read_readme() {
		$file = COYWOLF_CVM_PATH . 'readme.md';

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;

		if ( $wp_filesystem && $wp_filesystem->exists( $file ) ) {
			return (string) $wp_filesystem->get_contents( $file );
		}
		return '';
	}

	/**
	 * Convert the readme Markdown subset to a safe HTML string.
	 *
	 * @param string $md Markdown source.
	 * @return string HTML.
	 */
	public static function to_html( $md ) {
		$md = str_replace( array( "\r\n", "\r" ), "\n", (string) $md );
		// Drop HTML comments (wporg-strip markers) and the leading logo <img>.
		$md = (string) preg_replace( '/<!--.*?-->/s', '', $md );
		$md = (string) preg_replace( '/^[ \t]*<img\b[^>]*>[ \t]*$/mi', '', $md );

		$lines = explode( "\n", $md );
		$n     = count( $lines );
		$out   = '';
		$i     = 0;

		while ( $i < $n ) {
			$line = $lines[ $i ];

			// Fenced code block: ``` optionally followed by a language.
			if ( preg_match( '/^```\s*([A-Za-z0-9_-]*)\s*$/', $line, $m ) ) {
				$lang = $m[1];
				++$i;
				$buf = array();
				while ( $i < $n && ! preg_match( '/^```\s*$/', $lines[ $i ] ) ) {
					$buf[] = $lines[ $i ];
					++$i;
				}
				++$i; // Skip the closing fence.
				$cls  = '' !== $lang ? ' class="language-' . esc_attr( $lang ) . '"' : '';
				$out .= '<pre class="coywolf-cvm-docs-code"><code' . $cls . '>' . esc_html( implode( "\n", $buf ) ) . '</code></pre>';
				continue;
			}

			// Blank line.
			if ( '' === trim( $line ) ) {
				++$i;
				continue;
			}

			// ATX heading.
			if ( preg_match( '/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m ) ) {
				$level = strlen( $m[1] );
				$out  .= '<h' . $level . '>' . self::inline( $m[2] ) . '</h' . $level . '>';
				++$i;
				continue;
			}

			// GFM table: a row with a pipe followed by a dash/pipe/colon separator.
			if ( false !== strpos( $line, '|' ) && isset( $lines[ $i + 1 ] )
				&& preg_match( '/^[\s:\-|]+$/', $lines[ $i + 1 ] ) && false !== strpos( $lines[ $i + 1 ], '-' ) ) {
				$head = self::table_cells( $line );
				$i   += 2; // Skip header + separator.
				$body = array();
				while ( $i < $n && '' !== trim( $lines[ $i ] ) && false !== strpos( $lines[ $i ], '|' ) ) {
					$body[] = self::table_cells( $lines[ $i ] );
					++$i;
				}
				$out .= '<table class="coywolf-cvm-docs-table widefat striped"><thead><tr>';
				foreach ( $head as $cell ) {
					$out .= '<th>' . self::inline( $cell ) . '</th>';
				}
				$out .= '</tr></thead><tbody>';
				foreach ( $body as $row ) {
					$out .= '<tr>';
					foreach ( $row as $cell ) {
						$out .= '<td>' . self::inline( $cell ) . '</td>';
					}
					$out .= '</tr>';
				}
				$out .= '</tbody></table>';
				continue;
			}

			// Unordered list.
			if ( preg_match( '/^[-*+]\s+/', $line ) ) {
				$out .= '<ul>';
				while ( $i < $n && preg_match( '/^[-*+]\s+(.*)$/', $lines[ $i ], $m ) ) {
					$out .= '<li>' . self::inline( $m[1] ) . '</li>';
					++$i;
				}
				$out .= '</ul>';
				continue;
			}

			// Ordered list.
			if ( preg_match( '/^\d+\.\s+/', $line ) ) {
				$out .= '<ol>';
				while ( $i < $n && preg_match( '/^\d+\.\s+(.*)$/', $lines[ $i ], $m ) ) {
					$out .= '<li>' . self::inline( $m[1] ) . '</li>';
					++$i;
				}
				$out .= '</ol>';
				continue;
			}

			// Paragraph: gather consecutive lines until a blank line or a block start.
			$buf = array();
			while ( $i < $n && '' !== trim( $lines[ $i ] )
				&& ! preg_match( '~^(#{1,6}\s|```|[-*+]\s|\d+\.\s)~', $lines[ $i ] ) ) {
				$buf[] = trim( $lines[ $i ] );
				++$i;
			}
			if ( ! empty( $buf ) ) {
				$out .= '<p>' . self::inline( implode( ' ', $buf ) ) . '</p>';
			}
		}

		return $out;
	}

	/**
	 * Split a Markdown table row into trimmed cell strings.
	 *
	 * @param string $line Table row.
	 * @return string[]
	 */
	private static function table_cells( $line ) {
		$line  = trim( $line );
		$line  = (string) preg_replace( '/^\||\|$/', '', $line );
		$cells = explode( '|', $line );
		return array_map( 'trim', $cells );
	}

	/**
	 * Convert inline Markdown (code spans, links, bold, italic) to escaped HTML.
	 *
	 * Code spans and links are pulled out to placeholders first so their contents
	 * aren't re-parsed; remaining text is escaped, then emphasis is applied.
	 *
	 * @param string $text Raw inline Markdown.
	 * @return string Escaped HTML.
	 */
	private static function inline( $text ) {
		$store = array();

		$text = (string) preg_replace_callback(
			'/`([^`]+)`/',
			static function ( $m ) use ( &$store ) {
				$key           = "\x02" . count( $store ) . "\x03";
				$store[ $key ] = '<code>' . esc_html( $m[1] ) . '</code>';
				return $key;
			},
			$text
		);

		$text = (string) preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			static function ( $m ) use ( &$store ) {
				$key           = "\x02" . count( $store ) . "\x03";
				$store[ $key ] = '<a href="' . esc_url( $m[2] ) . '" target="_blank" rel="noopener noreferrer">' . self::emphasis( esc_html( $m[1] ) ) . '</a>';
				return $key;
			},
			$text
		);

		$text = self::emphasis( esc_html( $text ) );

		if ( ! empty( $store ) ) {
			$text = strtr( $text, $store );
		}
		return $text;
	}

	/**
	 * Apply bold/italic emphasis to already-escaped text.
	 *
	 * @param string $escaped Escaped text.
	 * @return string
	 */
	private static function emphasis( $escaped ) {
		$escaped = (string) preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped );
		$escaped = (string) preg_replace( '/(?<!\*)\*([^*\s][^*]*)\*(?!\*)/', '<em>$1</em>', $escaped );
		return $escaped;
	}
}
