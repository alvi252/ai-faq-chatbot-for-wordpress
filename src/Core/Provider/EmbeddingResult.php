<?php
/**
 * Embeddings returned by the API.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Unit-length vectors in input order and the tokens billed.
 */
final class EmbeddingResult {

	/**
	 * Constructor.
	 *
	 * @param array<int, float[]> $vectors Vectors, one per input.
	 * @param string              $model   Model reported by the API.
	 * @param int                 $tokens  Tokens billed.
	 */
	public function __construct(
		public readonly array $vectors,
		public readonly string $model,
		public readonly int $tokens
	) {
	}
}
