<?php
/**
 * OpenAI Responses API.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Provider;

use AIFAQ\Core\Answer\AnswerPrompt;
use AIFAQ\Core\Http\HttpRequest;
use AIFAQ\Core\Http\HttpResponse;
use AIFAQ\Core\Http\TransportException;
use AIFAQ\Core\Http\TransportInterface;

defined( 'ABSPATH' ) || exit;

/**
 * POST /v1/responses with a strict JSON-schema text format.
 */
final class OpenAiResponses extends AbstractProvider {

	public const ENDPOINT = 'https://api.openai.com/v1/responses';

	/**
	 * Constructor.
	 *
	 * @param TransportInterface $transport HTTP transport.
	 * @param string             $api_key   API key.
	 * @param string             $model     Model ID.
	 * @param int                $timeout   Timeout in seconds.
	 * @param string             $endpoint  API URL (overridable for proxies and tests).
	 */
	public function __construct(
		private TransportInterface $transport,
		private string $api_key,
		private string $model,
		private int $timeout = 30,
		private string $endpoint = self::ENDPOINT
	) {
	}

	/**
	 * Model ID.
	 *
	 * @return string
	 */
	public function model(): string {
		return $this->model;
	}

	/**
	 * Sends the prompt and returns the answer text with its usage.
	 *
	 * @param AnswerPrompt $prompt Prompt.
	 * @return ProviderResult
	 * @throws ProviderException When the call failed or the answer is unusable.
	 */
	public function complete( AnswerPrompt $prompt ): ProviderResult {
		try {
			$response = $this->transport->send( $this->build_request( $prompt ) );
		} catch ( TransportException $e ) {
			$error = $e->is_timeout() ? ProviderException::timeout( $e->getMessage() ) : ProviderException::network( $e->getMessage() );
			throw $error;
		}
		return $this->parse_response( $response );
	}

	/**
	 * Builds the HTTP request.
	 *
	 * @param AnswerPrompt $prompt Prompt.
	 * @return HttpRequest
	 */
	public function build_request( AnswerPrompt $prompt ): HttpRequest {
		$body = array(
			'model'             => $this->model,
			'instructions'      => $prompt->system,
			'input'             => $prompt->user,
			'text'              => array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => AnswerPrompt::SCHEMA_NAME,
					'schema' => AnswerPrompt::schema(),
					'strict' => true,
				),
			),
			// Headroom for reasoning models, which spend output tokens before the answer.
			'max_output_tokens' => $prompt->max_output_tokens * 4,
			// Visitor questions and site content are not kept on the provider side for later retrieval.
			'store'             => false,
		);

		return new HttpRequest(
			$this->endpoint,
			array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			),
			self::encode( $body ),
			$this->timeout
		);
	}

	/**
	 * Reads the HTTP response.
	 *
	 * @param HttpResponse $response Response, any status.
	 * @return ProviderResult
	 * @throws ProviderException When the call failed or the response has no usable text.
	 */
	public function parse_response( HttpResponse $response ): ProviderResult {
		$data = self::decode( $response );

		$text    = '';
		$refusal = '';
		foreach ( (array) ( $data['output'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) || 'message' !== ( $item['type'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $item['content'] ?? array() ) as $part ) {
				if ( ! is_array( $part ) ) {
					continue;
				}
				if ( 'output_text' === ( $part['type'] ?? '' ) ) {
					$text .= (string) ( $part['text'] ?? '' );
				} elseif ( 'refusal' === ( $part['type'] ?? '' ) ) {
					$refusal .= (string) ( $part['refusal'] ?? '' );
				}
			}
		}

		$usage  = is_array( $data['usage'] ?? null ) ? $data['usage'] : array();
		$result = new ProviderResult(
			$text,
			(string) ( $data['model'] ?? $this->model ),
			self::int( $usage, 'input_tokens' ),
			self::int( $usage, 'output_tokens' ),
			(string) ( $data['id'] ?? '' )
		);

		$status = (string) ( $data['status'] ?? '' );
		if ( 'incomplete' === $status ) {
			$reason = (string) ( $data['incomplete_details']['reason'] ?? 'unknown' );
			$error  = 'content_filter' === $reason ? ProviderException::refused( $reason ) : ProviderException::truncated( $reason );
			throw $error->with_usage( $result );
		}
		if ( 'failed' === $status ) {
			throw ProviderException::invalid_response( (string) ( $data['error']['message'] ?? 'status failed' ) )->with_usage( $result );
		}
		if ( '' === $text && '' !== $refusal ) {
			throw ProviderException::refused( $refusal )->with_usage( $result );
		}
		if ( '' === $text ) {
			throw ProviderException::invalid_response( 'no output text' )->with_usage( $result );
		}

		return $result;
	}
}
