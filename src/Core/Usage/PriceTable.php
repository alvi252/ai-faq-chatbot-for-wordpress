<?php
/**
 * Token prices for cost estimates.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * Prices per million tokens, edited by the site owner as text:
 *
 *     # model = input, output   (USD per 1M tokens)
 *     gpt-4.1-mini = 0.40, 1.60
 *
 * The result is an estimate: providers change prices, and some bill extras
 * (cached input, long context) that the table does not model.
 */
final class PriceTable {

	/**
	 * Model => [input, output] per million tokens.
	 *
	 * @var array<string, array{input: float, output: float}>
	 */
	private array $prices = array();

	/**
	 * Line numbers that could not be read.
	 *
	 * @var int[]
	 */
	private array $invalid_lines = array();

	/**
	 * Parses the text format.
	 *
	 * @param string $text Price table text.
	 * @return self
	 */
	public static function parse( string $text ): self {
		$table = new self();
		$lines = preg_split( '/\R/', $text );

		foreach ( false === $lines ? array() : $lines as $index => $line ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line ) {
				continue;
			}
			if ( ! preg_match( '/^([A-Za-z0-9._:\/-]+)\s*=\s*(\d+(?:\.\d+)?)\s*,\s*(\d+(?:\.\d+)?)$/', $line, $m ) ) {
				$table->invalid_lines[] = $index + 1;
				continue;
			}
			$table->prices[ strtolower( $m[1] ) ] = array(
				'input'  => (float) $m[2],
				'output' => (float) $m[3],
			);
		}

		return $table;
	}

	/**
	 * Returns the prices for a model: exact match first, then the longest
	 * listed prefix, so a dated snapshot ("gpt-4.1-mini-2025-04-14") uses the
	 * price of its family ("gpt-4.1-mini").
	 *
	 * @param string $model Model ID as reported by the provider.
	 * @return array{input: float, output: float}|null
	 */
	public function price_for( string $model ): ?array {
		$model = strtolower( trim( $model ) );
		if ( isset( $this->prices[ $model ] ) ) {
			return $this->prices[ $model ];
		}

		$best = null;
		foreach ( array_keys( $this->prices ) as $listed ) {
			$prefix = (string) $listed;
			if ( str_starts_with( $model, $prefix . '-' ) && ( null === $best || strlen( $prefix ) > strlen( $best ) ) ) {
				$best = $prefix;
			}
		}
		return null === $best ? null : $this->prices[ $best ];
	}

	/**
	 * Estimates the cost of one call in the table's currency.
	 *
	 * @param string $model         Model ID.
	 * @param int    $input_tokens  Input tokens.
	 * @param int    $output_tokens Output tokens.
	 * @return float|null Null when the model has no price.
	 */
	public function estimate( string $model, int $input_tokens, int $output_tokens ): ?float {
		$price = $this->price_for( $model );
		if ( null === $price ) {
			return null;
		}
		return round( ( $input_tokens * $price['input'] + $output_tokens * $price['output'] ) / 1000000, 9 );
	}

	/**
	 * Returns the listed models and prices.
	 *
	 * @return array<string, array{input: float, output: float}>
	 */
	public function all(): array {
		return $this->prices;
	}

	/**
	 * Returns line numbers that could not be read.
	 *
	 * @return int[]
	 */
	public function invalid_lines(): array {
		return $this->invalid_lines;
	}
}
