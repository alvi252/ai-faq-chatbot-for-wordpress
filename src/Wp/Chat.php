<?php
/**
 * One question, with the site's guards.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Wp;

use AIFAQ\Core\Answer\AnswerResult;
use AIFAQ\Core\Guard\RateLimiter;
use AIFAQ\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Checks the question and the limits, answers, logs, and shapes the reply for
 * the chat window. Used by the REST endpoint, the admin test box and WP-CLI.
 */
final class Chat {

	public const MAX_LENGTH = 500;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings  Settings.
	 * @param Services    $services  Services.
	 * @param CallLog     $calls     Call log.
	 * @param QuestionLog $questions Question log.
	 * @param RateLimiter $limiter   Per-visitor limit.
	 */
	public function __construct(
		private Settings $settings,
		private Services $services,
		private CallLog $calls,
		private QuestionLog $questions,
		private RateLimiter $limiter
	) {
	}

	/**
	 * Answers a question.
	 *
	 * @param string      $question    Question as typed.
	 * @param string|null $visitor_key Hashed visitor key for the rate limit; null for admin and WP-CLI.
	 * @param bool        $log         Store the question in the question log.
	 * @return array{http: int, body: array<string, mixed>, result: AnswerResult|null}
	 */
	public function ask( string $question, ?string $visitor_key, bool $log ): array {
		$question = trim( (string) preg_replace( '/\s+/u', ' ', $question ) );
		if ( '' === $question || mb_strlen( $question ) > self::MAX_LENGTH ) {
			return self::reply(
				400,
				'invalid',
				/* translators: %d: maximum number of characters. */
				sprintf( __( 'Please type a question of up to %d characters.', 'ai-faq-chatbot' ), self::MAX_LENGTH )
			);
		}
		if ( ! $this->settings->is_configured() ) {
			return self::reply( 503, 'unavailable', __( 'The chat is not available right now. Please contact us instead.', 'ai-faq-chatbot' ) );
		}
		if ( null !== $visitor_key && ! $this->limiter->allow( $visitor_key, time() ) ) {
			return self::reply( 429, 'rate_limited', __( 'You have asked a lot of questions in a short time. Please try again in an hour, or contact us.', 'ai-faq-chatbot' ) );
		}
		// A model without a price would never count towards the budget: pause instead of running unlimited.
		if ( array() !== $this->settings->unpriced_models() ) {
			return self::reply( 503, 'unavailable', __( 'The chat is not available right now. Please contact us instead.', 'ai-faq-chatbot' ) );
		}
		$budget = (float) $this->settings->get( 'daily_budget' );
		if ( $budget > 0 && $this->calls->spent_today() >= $budget ) {
			return self::reply( 503, 'unavailable', __( 'The chat is resting for today. Please contact us instead.', 'ai-faq-chatbot' ) );
		}

		$result = $this->services->answerer()->ask( $question );
		$this->calls->record( $result->calls, 'question' );
		if ( $log && ! empty( $this->settings->get( 'log_questions' ) ) ) {
			$this->questions->record( $question, $result );
		}

		if ( AnswerResult::ERROR === $result->status ) {
			$reply           = self::reply( 502, 'error', __( 'Sorry, I could not answer just now. Please try again in a minute, or contact us.', 'ai-faq-chatbot' ) );
			$reply['result'] = $result;
			return $reply;
		}

		$sources = array();
		foreach ( $result->sources as $index => $hit ) {
			$sources[] = array(
				'n'       => $index + 1,
				'title'   => $hit->passage->title,
				'heading' => $hit->passage->heading,
				'url'     => $hit->passage->url,
			);
		}

		return array(
			'http'   => 200,
			'body'   => array(
				'status'      => $result->status,
				'answer'      => $result->answer,
				'sources'     => $sources,
				'contact_url' => AnswerResult::REFUSED === $result->status ? (string) $this->settings->get( 'contact_url' ) : '',
			),
			'result' => $result,
		);
	}

	/**
	 * A reply without an answer.
	 *
	 * @param int    $http    HTTP status.
	 * @param string $status  Status.
	 * @param string $message Message for the visitor.
	 * @return array{http: int, body: array<string, mixed>, result: null}
	 */
	private static function reply( int $http, string $status, string $message ): array {
		return array(
			'http'   => $http,
			'body'   => array(
				'status'      => $status,
				'answer'      => $message,
				'sources'     => array(),
				'contact_url' => '',
			),
			'result' => null,
		);
	}
}
