<?php
/**
 * Plugin settings.
 *
 * @package AIFAQ
 */

namespace AIFAQ;

use AIFAQ\Core\Usage\PriceTable;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitizes the settings option and resolves the API key.
 *
 * The API key: a constant in wp-config.php wins over the value saved on the
 * settings screen, so the key can be kept out of the database entirely:
 *     define( 'AIFAQ_OPENAI_API_KEY', '...' );
 * The key is only ever used on the server; it is never sent to the browser.
 */
final class Settings {

	public const OPTION       = 'aifaq_settings';
	public const GROUP        = 'aifaq_settings_group';
	public const KEY_CONSTANT = 'AIFAQ_OPENAI_API_KEY';

	public const DEFAULT_PRICES = "# model = input, output    (USD per 1M tokens, for estimates only)\n"
		. "gpt-4.1-mini = 0.40, 1.60\n"
		. "gpt-4.1 = 2.00, 8.00\n"
		. "text-embedding-3-small = 0.02, 0\n"
		. "text-embedding-3-large = 0.13, 0\n";

	/**
	 * Default values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'          => true,
			'openai_api_key'   => '',
			'chat_model'       => 'gpt-4.1-mini',
			'embedding_model'  => 'text-embedding-3-small',
			'dimensions'       => 512,
			'post_types'       => array( 'page', 'product' ),
			'exclude_ids'      => '',
			'top_k'            => 5,
			'min_similarity'   => 0.30,
			'site_name'        => '',
			'title'            => 'Ask us',
			'welcome'          => "Hi! Ask me anything about our shop. I answer from this website's pages and show you where I found it.",
			'contact_url'      => '',
			'accent'           => '#1f2a37',
			'per_visitor_hour' => 20,
			'daily_budget'     => 1.0,
			'log_questions'    => true,
			'keep_days'        => 30,
			'timeout'          => 30,
			'price_table'      => self::DEFAULT_PRICES,
		);
	}

	/**
	 * Returns all settings merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Returns one setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public function get( string $key ): mixed {
		$all = $this->all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Registers the option with the Settings API so every write is sanitized.
	 */
	public function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * The API key: wp-config constant first, then the saved setting.
	 *
	 * @return string Empty when no key is configured.
	 */
	public function api_key(): string {
		if ( defined( self::KEY_CONSTANT ) ) {
			$value = constant( self::KEY_CONSTANT );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}
		return trim( (string) $this->get( 'openai_api_key' ) );
	}

	/**
	 * Whether the key comes from wp-config.php.
	 *
	 * @return bool
	 */
	public function key_from_constant(): bool {
		return defined( self::KEY_CONSTANT ) && is_string( constant( self::KEY_CONSTANT ) ) && '' !== trim( (string) constant( self::KEY_CONSTANT ) );
	}

	/**
	 * Whether the plugin can answer.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->api_key();
	}

	/**
	 * Post types to index, limited to those that exist now.
	 *
	 * @return string[]
	 */
	public function post_types(): array {
		return array_values( array_filter( (array) $this->get( 'post_types' ), 'post_type_exists' ) );
	}

	/**
	 * Post IDs never indexed.
	 *
	 * @return int[]
	 */
	public function excluded_ids(): array {
		return array_values( array_filter( array_map( 'absint', explode( ',', (string) $this->get( 'exclude_ids' ) ) ) ) );
	}

	/**
	 * Name used in the model's instructions.
	 *
	 * @return string
	 */
	public function site_name(): string {
		$name = trim( (string) $this->get( 'site_name' ) );
		return '' !== $name ? $name : (string) get_bloginfo( 'name' );
	}

	/**
	 * Price table for cost estimates.
	 *
	 * @return PriceTable
	 */
	public function price_table(): PriceTable {
		return PriceTable::parse( (string) $this->get( 'price_table' ) );
	}

	/**
	 * Models in use that have no price while a daily budget is set. Their
	 * calls cannot be counted towards the budget, so the chat stays paused
	 * until they get a price (or the budget is set to 0).
	 *
	 * @return string[]
	 */
	public function unpriced_models(): array {
		if ( (float) $this->get( 'daily_budget' ) <= 0 ) {
			return array();
		}
		$prices = $this->price_table();
		$models = array_unique( array( (string) $this->get( 'chat_model' ), (string) $this->get( 'embedding_model' ) ) );
		return array_values( array_filter( $models, static fn( string $model ): bool => null === $prices->price_for( $model ) ) );
	}

	/**
	 * Integer setting within bounds.
	 *
	 * @param string $key Setting name.
	 * @return int
	 */
	public function int( string $key ): int {
		return (int) $this->get( $key );
	}

	/**
	 * Sanitizes submitted settings. Idempotent: WordPress runs it twice when
	 * the option is first created, and the second run gets the cleaned array.
	 *
	 * @param mixed $input Submitted value.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		$current  = $this->all();
		$defaults = self::defaults();
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$clean            = array();
		$clean['enabled'] = ! empty( $input['enabled'] );

		// The key is write-only in the form: blank keeps the saved key, the checkbox deletes it.
		// Control characters are removed to rule out header injection.
		$key = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', (string) ( $input['openai_api_key'] ?? '' ) ) );
		if ( ! empty( $input['openai_api_key_delete'] ) ) {
			$key = '';
		} elseif ( '' === $key ) {
			$key = (string) $current['openai_api_key'];
		}
		$clean['openai_api_key'] = $key;

		foreach ( array( 'chat_model', 'embedding_model' ) as $model_key ) {
			$model               = (string) preg_replace( '/[^A-Za-z0-9._:\/-]/', '', (string) ( $input[ $model_key ] ?? '' ) );
			$clean[ $model_key ] = '' !== $model ? $model : $defaults[ $model_key ];
		}

		$clean['dimensions'] = self::bounded( $input['dimensions'] ?? $defaults['dimensions'], 0, 3072, (int) $defaults['dimensions'] );
		if ( $clean['dimensions'] !== (int) $current['dimensions'] || $clean['embedding_model'] !== $current['embedding_model'] ) {
			self::notice( 'reindex', __( 'The embedding model or vector length changed. Rebuild the index on the Index tab; until then the chatbot answers "I don\'t know".', 'ai-faq-chatbot' ), 'warning' );
		}

		$types               = array_map( 'sanitize_key', array_map( 'strval', (array) ( $input['post_types'] ?? array() ) ) );
		$clean['post_types'] = array_values( array_unique( array_filter( $types ) ) );

		$parts                = preg_split( '/[\s,]+/', (string) ( $input['exclude_ids'] ?? '' ) );
		$ids                  = array_filter( array_map( 'absint', false === $parts ? array() : $parts ) );
		$clean['exclude_ids'] = implode( ',', array_unique( $ids ) );

		$clean['top_k']          = self::bounded( $input['top_k'] ?? $defaults['top_k'], 1, 10, (int) $defaults['top_k'] );
		$similarity              = is_numeric( $input['min_similarity'] ?? null ) ? (float) $input['min_similarity'] : (float) $defaults['min_similarity'];
		$clean['min_similarity'] = round( max( 0.0, min( 0.9, $similarity ) ), 2 );

		$clean['site_name']   = mb_substr( sanitize_text_field( (string) ( $input['site_name'] ?? '' ) ), 0, 100 );
		$clean['title']       = mb_substr( sanitize_text_field( (string) ( $input['title'] ?? '' ) ), 0, 60 );
		$clean['title']       = '' !== $clean['title'] ? $clean['title'] : $defaults['title'];
		$clean['welcome']     = mb_substr( sanitize_textarea_field( (string) ( $input['welcome'] ?? '' ) ), 0, 500 );
		$clean['contact_url'] = esc_url_raw( (string) ( $input['contact_url'] ?? '' ) );
		$accent               = sanitize_hex_color( (string) ( $input['accent'] ?? '' ) );
		$clean['accent']      = is_string( $accent ) && '' !== $accent ? $accent : $defaults['accent'];

		$clean['per_visitor_hour'] = self::bounded( $input['per_visitor_hour'] ?? $defaults['per_visitor_hour'], 0, 1000, (int) $defaults['per_visitor_hour'] );
		$budget                    = is_numeric( $input['daily_budget'] ?? null ) ? (float) $input['daily_budget'] : (float) $defaults['daily_budget'];
		$clean['daily_budget']     = round( max( 0.0, min( 1000.0, $budget ) ), 2 );
		$clean['log_questions']    = ! empty( $input['log_questions'] );
		$clean['keep_days']        = self::bounded( $input['keep_days'] ?? $defaults['keep_days'], 1, 365, (int) $defaults['keep_days'] );
		$clean['timeout']          = self::bounded( $input['timeout'] ?? $defaults['timeout'], 5, 120, (int) $defaults['timeout'] );

		$clean['price_table'] = sanitize_textarea_field( (string) ( $input['price_table'] ?? $defaults['price_table'] ) );
		$invalid              = PriceTable::parse( $clean['price_table'] )->invalid_lines();
		if ( array() !== $invalid ) {
			self::notice(
				'price_table',
				sprintf(
					/* translators: %s: comma-separated line numbers. */
					__( 'Some price table lines could not be read and are ignored: line %s. Use the format "model = input, output".', 'ai-faq-chatbot' ),
					implode( ', ', $invalid )
				),
				'warning'
			);
		}

		return $clean;
	}

	/**
	 * Integer within bounds, or the fallback when not numeric.
	 *
	 * @param mixed $value    Submitted value.
	 * @param int   $min      Minimum.
	 * @param int   $max      Maximum.
	 * @param int   $fallback Value used when not numeric.
	 * @return int
	 */
	private static function bounded( $value, int $min, int $max, int $fallback ): int {
		$number = is_numeric( $value ) ? (int) $value : $fallback;
		return max( $min, min( $max, $number ) );
	}

	/**
	 * Reports a validation message on the settings screen, once.
	 *
	 * @param string $code    Field the message belongs to.
	 * @param string $message Message.
	 * @param string $type    Notice type.
	 */
	private static function notice( string $code, string $message, string $type = 'error' ): void {
		if ( ! function_exists( 'add_settings_error' ) ) {
			return;
		}
		foreach ( get_settings_errors( self::OPTION ) as $existing ) {
			if ( 'aifaq_' . $code === $existing['code'] ) {
				return;
			}
		}
		add_settings_error( self::OPTION, 'aifaq_' . $code, $message, $type );
	}
}
