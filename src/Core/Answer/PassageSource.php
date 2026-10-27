<?php
/**
 * Where indexed passages come from.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Answer;

use AIFAQ\Core\Content\Passage;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin reads passages from its database table; tests pass an array.
 */
interface PassageSource {

	/**
	 * All passages embedded with the given model and vector length.
	 *
	 * @param string $model      Embedding model.
	 * @param int    $dimensions Vector length (0 = model default).
	 * @return Passage[] Passages with vectors.
	 */
	public function passages( string $model, int $dimensions ): array;
}
