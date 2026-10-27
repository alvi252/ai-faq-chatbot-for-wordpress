<?php
/**
 * Public endpoint of the chat window.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Wp;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/aifaq/v1/ask {"question": "..."}.
 *
 * Open to visitors without an account, like a contact form. Abuse is limited
 * by the questions-per-hour limit per visitor, the daily budget and a check
 * that the request comes from this site's pages. No nonce: pages served from
 * a cache would carry an expired one and break the chat.
 */
final class RestController {

	public const NAMESPACE = 'aifaq/v1';

	/**
	 * Constructor.
	 *
	 * @param Chat $chat Chat.
	 */
	public function __construct( private Chat $chat ) {
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/ask',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'ask' ),
				'permission_callback' => array( $this, 'same_site' ),
				'args'                => array(
					'question' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Rejects requests sent from another site's pages. A request without an
	 * Origin header (a server script) is allowed; the per-visitor limit and the
	 * daily budget still apply to it.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function same_site( \WP_REST_Request $request ): bool {
		$origin = (string) $request->get_header( 'origin' );
		if ( '' === $origin ) {
			return true;
		}
		return self::bare_host( $origin ) === self::bare_host( home_url() );
	}

	/**
	 * Host without a leading "www.", so www.example.com and example.com count as one site.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function bare_host( string $url ): string {
		return (string) preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
	}

	/**
	 * Answers.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ask( \WP_REST_Request $request ): \WP_REST_Response {
		$reply    = $this->chat->ask( (string) $request->get_param( 'question' ), self::visitor_key(), true );
		$response = new \WP_REST_Response( $reply['body'], $reply['http'] );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Salted hash of the visitor's address. REMOTE_ADDR only: forwarded-for
	 * headers can be forged by the visitor. Behind a proxy or CDN, sites can
	 * supply the real address with the aifaq_visitor_ip filter.
	 *
	 * @return string
	 */
	private static function visitor_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the visitor address used for the questions-per-hour limit.
		 *
		 * @param string $ip Address from REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'aifaq_visitor_ip', $ip );
		return hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
	}
}
