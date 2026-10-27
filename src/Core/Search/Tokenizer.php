<?php
/**
 * Word tokens for keyword search.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Lowercase word tokens without common function words, with light plural
 * folding ("parcels" and "parcel" match). Ported from our docs-assistant-rag.
 */
final class Tokenizer {

	// English, then French and German function words for visitors who ask in those languages.
	private const STOPWORDS = 'a an and are as at be by can do does for from has have how i if in is it its me my of on or our so '
		. 'that the their them then there this to us was we what when where which who why will with you your '
		. 'le la les un une des du de et est que qui pour par sur dans au aux ce je mon ma mes vous votre nous '
		. 'der die das und ist ich sie ein eine zu';

	/**
	 * Tokenizes text.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function tokens( string $text ): array {
		static $stop = null;
		if ( null === $stop ) {
			$stop = array_fill_keys( explode( ' ', self::STOPWORDS ), true );
		}

		preg_match_all( '/[\p{L}\p{N}_]+/u', mb_strtolower( $text ), $matches );
		$tokens = array();
		foreach ( $matches[0] as $raw ) {
			if ( isset( $stop[ $raw ] ) || mb_strlen( $raw ) < 2 ) {
				continue;
			}
			$length = mb_strlen( $raw );
			if ( $length > 4 && str_ends_with( $raw, 'ies' ) ) {
				$raw = mb_substr( $raw, 0, -3 ) . 'y';
			} elseif ( $length > 3 && str_ends_with( $raw, 's' ) && ! str_ends_with( $raw, 'ss' ) ) {
				$raw = mb_substr( $raw, 0, -1 );
			}
			$tokens[] = $raw;
		}
		return $tokens;
	}
}
