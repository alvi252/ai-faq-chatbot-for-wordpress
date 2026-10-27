<?php
/**
 * OpenAI embeddings API.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Provider;

use AIFAQ\Core\Http\HttpRequest;
use AIFAQ\Core\Http\HttpResponse;
use AIFAQ\Core\Http\TransportException;
use AIFAQ\Core\Http\TransportInterface;

defined( 'ABSPATH' ) || exit;

/**
 * POST /v1/embeddings. One request embeds a batch of texts; vectors come back
 * in input order, unit length (OpenAI normalizes embeddings, and they are
 * normalized again here so the dot product is the cosine similarity).
 *
 * API reference (checked 27.10.2026): each input at most 8192 tokens, at most
 * 2048 inputs and 300,000 tokens per request; `dimensions` only for the
 * text-embedding-3 models.
 */
final class OpenAiEmbeddings extends AbstractProvider {

	public const ENDPOINT = 'https://api.openai.com/v1/embeddings';

	/**
	 * Inputs per request. Well under the API limit of 2048, so one request
	 * stays far below the 300,000-token cap for page-sized passages.
	 */
	public const BATCH_SIZE = 64;

	/**
	 * Constructor.
	 *
	 * @param TransportInterface $transport  HTTP transport.
	 * @param string             $api_key    API key.
	 * @param string             $model      Model ID, e.g. text-embedding-3-small.
	 * @param int                $dimensions Vector length; 0 keeps the model default.
	 * @param int                $timeout    Timeout in seconds.
	 * @param string             $endpoint   API URL (overridable for proxies and tests).
	 */
	public function __construct(
		private TransportInterface $transport,
		private string $api_key,
		private string $model,
		private int $dimensions = 0,
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
	 * Vector length requested from the API (0 = model default).
	 *
	 * @return int
	 */
	public function dimensions(): int {
		return $this->dimensions;
	}

	/**
	 * Embeds texts, in batches of BATCH_SIZE.
	 *
	 * @param string[] $texts Texts, none empty.
	 * @return EmbeddingResult
	 * @throws ProviderException When a request fails; vectors of earlier batches are lost.
	 */
	public function embed( array $texts ): EmbeddingResult {
		$vectors = array();
		$tokens  = 0;
		$model   = $this->model;

		foreach ( array_chunk( array_values( $texts ), self::BATCH_SIZE ) as $batch ) {
			$result  = $this->embed_batch( $batch );
			$vectors = array_merge( $vectors, $result->vectors );
			$tokens += $result->tokens;
			$model   = $result->model;
		}

		return new EmbeddingResult( $vectors, $model, $tokens );
	}

	/**
	 * Builds the request for one batch.
	 *
	 * @param string[] $texts Texts.
	 * @return HttpRequest
	 */
	public function build_request( array $texts ): HttpRequest {
		$body = array(
			'model'           => $this->model,
			'input'           => array_values( $texts ),
			'encoding_format' => 'float',
		);
		if ( $this->dimensions > 0 ) {
			$body['dimensions'] = $this->dimensions;
		}

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
	 * Reads the response for one batch.
	 *
	 * @param HttpResponse $response Response, any status.
	 * @param int          $expected Number of inputs sent.
	 * @return EmbeddingResult
	 * @throws ProviderException For an error status or a body in the wrong shape.
	 */
	public function parse_response( HttpResponse $response, int $expected ): EmbeddingResult {
		$data = self::decode( $response );

		$by_index = array();
		foreach ( (array) ( $data['data'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['index'], $item['embedding'] ) || ! is_array( $item['embedding'] ) ) {
				throw ProviderException::invalid_response( 'an embedding item has no index or vector' );
			}
			$by_index[ (int) $item['index'] ] = self::normalize( $item['embedding'] );
		}

		ksort( $by_index );
		if ( count( $by_index ) !== $expected || array_keys( $by_index ) !== range( 0, $expected - 1 ) ) {
			throw ProviderException::invalid_response( sprintf( 'expected %d embeddings, got %d', $expected, count( $by_index ) ) );
		}

		$usage = is_array( $data['usage'] ?? null ) ? $data['usage'] : array();
		return new EmbeddingResult(
			array_values( $by_index ),
			(string) ( $data['model'] ?? $this->model ),
			self::int( $usage, 'total_tokens' )
		);
	}

	/**
	 * Sends one batch.
	 *
	 * @param string[] $texts Texts.
	 * @return EmbeddingResult
	 * @throws ProviderException When the request fails.
	 */
	private function embed_batch( array $texts ): EmbeddingResult {
		try {
			$response = $this->transport->send( $this->build_request( $texts ) );
		} catch ( TransportException $e ) {
			$error = $e->is_timeout() ? ProviderException::timeout( $e->getMessage() ) : ProviderException::network( $e->getMessage() );
			throw $error;
		}
		return $this->parse_response( $response, count( $texts ) );
	}

	/**
	 * Scales a vector to unit length.
	 *
	 * @param array<int, mixed> $vector Numbers.
	 * @return float[]
	 * @throws ProviderException For a zero vector.
	 */
	private static function normalize( array $vector ): array {
		$floats = array_map( 'floatval', array_values( $vector ) );
		$norm   = sqrt( array_sum( array_map( static fn( float $x ): float => $x * $x, $floats ) ) );
		if ( $norm <= 0.0 ) {
			throw ProviderException::invalid_response( 'an embedding has zero length' );
		}
		return array_map( static fn( float $x ): float => $x / $norm, $floats );
	}
}
