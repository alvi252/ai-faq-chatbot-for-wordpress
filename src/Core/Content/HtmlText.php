<?php
/**
 * Page HTML to sections of plain text.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Splits rendered page HTML at its headings (h2-h4) and turns each part into
 * plain text with paragraph breaks. A FAQ written as <details><summary> gets
 * one section per question, so a visitor's question can match it directly.
 *
 * Regular expressions rather than a DOM parser: page HTML from editors is
 * often not well-formed, and the plugin must not depend on the DOM extension.
 */
final class HtmlText {

	/**
	 * Splits HTML into sections.
	 *
	 * @param string $html Page HTML (shortcodes already expanded or removed).
	 * @return array<int, array{heading: string, text: string}> Sections in page order; text without a heading first.
	 */
	public static function sections( string $html ): array {
		$html = self::strip_noise( $html );
		// A FAQ entry: the summary is the question, so it becomes a heading.
		$html = (string) preg_replace( '#<summary\b[^>]*>(.*?)</summary>#is', '<h4>$1</h4>', $html );

		$parts    = preg_split( '#<h([2-4])\b[^>]*>(.*?)</h\1>#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$parts    = false === $parts ? array( $html ) : $parts;
		$sections = array();

		$intro = self::text( (string) $parts[0] );
		if ( '' !== $intro ) {
			$sections[] = array(
				'heading' => '',
				'text'    => $intro,
			);
		}
		// After the leading text, parts come in threes: level, heading HTML, body HTML.
		$count = count( $parts );
		for ( $i = 1; $i + 2 < $count; $i += 3 ) {
			$heading = self::inline( (string) ( $parts[ $i + 1 ] ?? '' ) );
			$text    = self::text( (string) ( $parts[ $i + 2 ] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			$sections[] = array(
				'heading' => $heading,
				'text'    => $text,
			);
		}

		return $sections;
	}

	/**
	 * HTML block to plain text, keeping paragraph and list structure.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function text( string $html ): string {
		$html = self::strip_noise( $html );
		$html = (string) preg_replace( '#<li\b[^>]*>#i', "\n- ", $html );
		$html = (string) preg_replace( '#<br\s*/?>#i', "\n", $html );
		$html = (string) preg_replace( '#</(td|th)>#i', ' | ', $html );
		$html = (string) preg_replace( '#</?(p|div|ul|ol|li|tr|table|thead|tbody|section|article|details|blockquote|figure|figcaption|h[1-6]|dl|dt|dd|pre)\b[^>]*>#i', "\n", $html );
		$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\u{00A0}", ' ', $text );

		$lines = array();
		$split = preg_split( '/\R/u', $text );
		foreach ( false === $split ? array() : $split as $line ) {
			$line = trim( (string) preg_replace( '/[ \t]+/u', ' ', $line ) );
			$line = trim( $line, ' |' );
			if ( '' !== $line && '-' !== $line ) {
				$lines[] = $line;
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Removes scripts, styles, embeds and comments (including block editor comments).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function strip_noise( string $html ): string {
		$html = (string) preg_replace( '#<(script|style|noscript|template|svg|iframe)\b[^>]*>.*?</\1>#is', ' ', $html );
		return (string) preg_replace( '#<!--.*?-->#s', ' ', $html );
	}

	/**
	 * Inline HTML (a heading) to one line of text.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function inline( string $html ): string {
		$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', str_replace( "\u{00A0}", ' ', $text ) ) );
	}
}
