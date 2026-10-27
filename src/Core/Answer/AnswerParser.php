<?php
/**
 * Reads the model's answer.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Answer;

use AIFAQ\Core\Search\SearchHit;

defined( 'ABSPATH' ) || exit;

/**
 * Parses the JSON answer and checks its citations against the passages that
 * were actually sent. A citation of a passage that does not exist is removed;
 * citations are renumbered 1..n in order of first use, so the visitor sees
 * [1], [2] under the answer.
 */
final class AnswerParser {

	/**
	 * Parses the model's text.
	 *
	 * @param string      $text JSON text from the model.
	 * @param SearchHit[] $hits Passages sent, numbered from 1 in this order.
	 * @return array{answerable: bool, answer: string, sources: SearchHit[]}
	 */
	public static function parse( string $text, array $hits ): array {
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) && preg_match( '/\{.*\}/s', $text, $match ) ) {
			$data = json_decode( $match[0], true );
		}
		if ( ! is_array( $data ) || ! isset( $data['answer'] ) || ! is_string( $data['answer'] ) ) {
			return array(
				'answerable' => false,
				'answer'     => '',
				'sources'    => array(),
			);
		}

		$answerable = true === ( $data['answerable'] ?? false );
		if ( ! $answerable ) {
			return array(
				'answerable' => false,
				'answer'     => trim( $data['answer'] ),
				'sources'    => array(),
			);
		}

		list( $answer, $sources ) = self::citations( $data['answer'], array_values( $hits ) );
		return array(
			'answerable' => true,
			'answer'     => $answer,
			'sources'    => $sources,
		);
	}

	/**
	 * Keeps valid [n] markers, renumbered by first use; drops the rest.
	 *
	 * @param string      $answer Answer with [n] markers.
	 * @param SearchHit[] $hits   Passages, numbered from 1.
	 * @return array{0: string, 1: SearchHit[]}
	 */
	public static function citations( string $answer, array $hits ): array {
		// "[1, 3]" and "[1; 3]" become "[1][3]".
		$answer = (string) preg_replace_callback(
			'/\[\s*(\d+(?:\s*[,;]\s*\d+)+)\s*\]/',
			static fn( array $m ): string => implode( '', array_map( static fn( string $n ): string => '[' . trim( $n ) . ']', preg_split( '/[,;]/', $m[1] ) ) ),
			$answer
		);

		$numbers = array();
		$sources = array();
		$answer  = (string) preg_replace_callback(
			'/\[(\d+)\]/',
			static function ( array $m ) use ( $hits, &$numbers, &$sources ): string {
				$original = (int) $m[1];
				if ( $original < 1 || $original > count( $hits ) ) {
					return '';
				}
				if ( ! isset( $numbers[ $original ] ) ) {
					$sources[]            = $hits[ $original - 1 ];
					$numbers[ $original ] = count( $sources );
				}
				return '[' . $numbers[ $original ] . ']';
			},
			$answer
		);

		$answer = (string) preg_replace( '/[ \t]+([.,;:!?])/', '$1', $answer );
		$answer = trim( (string) preg_replace( '/[ \t]{2,}/', ' ', $answer ) );
		return array( $answer, $sources );
	}
}
