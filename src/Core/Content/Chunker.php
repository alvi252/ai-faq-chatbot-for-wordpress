<?php
/**
 * Page to passages.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Cuts a page's sections into passages of at most MAX_CHARS, at line breaks.
 * A long section becomes several passages with the same heading; a line longer
 * than the limit is cut at sentence ends.
 */
final class Chunker {

	public const MAX_CHARS = 1200;
	public const MIN_CHARS = 40;

	/**
	 * Cuts a page into passages.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $title   Page title.
	 * @param string $url     Page URL.
	 * @param string $html    Page HTML.
	 * @return Passage[]
	 */
	public static function passages( int $post_id, string $title, string $url, string $html ): array {
		$passages = array();
		$position = 0;

		foreach ( HtmlText::sections( $html ) as $section ) {
			foreach ( self::split( $section['text'] ) as $piece ) {
				// Very short pieces ("Read more", a lone date) carry nothing to answer from.
				if ( mb_strlen( $piece ) < self::MIN_CHARS && '' === $section['heading'] ) {
					continue;
				}
				$passages[] = new Passage( $post_id, $title, $url, $section['heading'], $piece, $position++ );
			}
		}

		return $passages;
	}

	/**
	 * Splits text into pieces of at most MAX_CHARS.
	 *
	 * @param string $text Text with line breaks.
	 * @return string[]
	 */
	public static function split( string $text ): array {
		$units = array();
		foreach ( explode( "\n", $text ) as $line ) {
			if ( mb_strlen( $line ) <= self::MAX_CHARS ) {
				$units[] = $line;
				continue;
			}
			foreach ( self::sentences( $line ) as $sentence ) {
				$units[] = $sentence;
			}
		}

		$pieces  = array();
		$current = '';
		foreach ( $units as $unit ) {
			$candidate = '' === $current ? $unit : $current . "\n" . $unit;
			if ( mb_strlen( $candidate ) <= self::MAX_CHARS ) {
				$current = $candidate;
				continue;
			}
			if ( '' !== $current ) {
				$pieces[] = $current;
			}
			$current = $unit;
		}
		if ( '' !== $current ) {
			$pieces[] = $current;
		}
		return $pieces;
	}

	/**
	 * Splits a long line at sentence ends; a sentence still too long is cut hard.
	 *
	 * @param string $line Line.
	 * @return string[]
	 */
	private static function sentences( string $line ): array {
		$sentences = preg_split( '/(?<=[.!?])\s+/u', $line );
		$sentences = false === $sentences ? array( $line ) : $sentences;
		$out       = array();
		foreach ( $sentences as $sentence ) {
			while ( mb_strlen( $sentence ) > self::MAX_CHARS ) {
				$out[]    = mb_substr( $sentence, 0, self::MAX_CHARS );
				$sentence = mb_substr( $sentence, self::MAX_CHARS );
			}
			if ( '' !== $sentence ) {
				$out[] = $sentence;
			}
		}
		return $out;
	}
}
