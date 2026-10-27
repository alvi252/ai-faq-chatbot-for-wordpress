<?php
/**
 * A provider's answer.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Answer text and token usage, normalized across providers.
 */
final class ProviderResult {

	/**
	 * Constructor.
	 *
	 * @param string $text          Answer text (expected to be JSON).
	 * @param string $model         Model that produced the answer, as reported by the provider.
	 * @param int    $input_tokens  Input tokens billed.
	 * @param int    $output_tokens Output tokens billed (includes reasoning tokens where the provider counts them).
	 * @param string $request_id    Provider request or response ID, for support tickets.
	 */
	public function __construct(
		public readonly string $text,
		public readonly string $model,
		public readonly int $input_tokens,
		public readonly int $output_tokens,
		public readonly string $request_id = ''
	) {
	}
}
