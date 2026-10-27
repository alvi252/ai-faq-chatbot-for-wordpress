<?php
/**
 * Builds the API clients from the settings.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Wp;

use AIFAQ\Core\Answer\Answerer;
use AIFAQ\Core\Http\TransportInterface;
use AIFAQ\Core\Provider\OpenAiEmbeddings;
use AIFAQ\Core\Provider\OpenAiResponses;
use AIFAQ\Core\Search\HybridSearch;
use AIFAQ\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One place that turns settings into configured objects.
 */
final class Services {

	/**
	 * Constructor.
	 *
	 * @param Settings           $settings  Settings.
	 * @param PassageRepository  $passages  Passages.
	 * @param TransportInterface $transport HTTP transport.
	 */
	public function __construct(
		private Settings $settings,
		private PassageRepository $passages,
		private TransportInterface $transport
	) {
	}

	/**
	 * Embeddings client.
	 *
	 * @return OpenAiEmbeddings
	 */
	public function embeddings(): OpenAiEmbeddings {
		return new OpenAiEmbeddings(
			$this->transport,
			$this->settings->api_key(),
			(string) $this->settings->get( 'embedding_model' ),
			$this->settings->int( 'dimensions' ),
			$this->settings->int( 'timeout' )
		);
	}

	/**
	 * Question answerer.
	 *
	 * @return Answerer
	 */
	public function answerer(): Answerer {
		return new Answerer(
			$this->embeddings(),
			new OpenAiResponses( $this->transport, $this->settings->api_key(), (string) $this->settings->get( 'chat_model' ), $this->settings->int( 'timeout' ) ),
			$this->passages,
			new HybridSearch( $this->settings->int( 'top_k' ), (float) $this->settings->get( 'min_similarity' ) ),
			$this->settings->price_table(),
			$this->settings->site_name()
		);
	}

	/**
	 * Usage row in the shape the call log stores, with the estimated cost.
	 *
	 * @param string $kind          Call kind.
	 * @param string $model         Model.
	 * @param string $status        Status.
	 * @param int    $input_tokens  Input tokens.
	 * @param int    $output_tokens Output tokens.
	 * @param string $error         Error reason.
	 * @return array<string, mixed>
	 */
	public function call_row( string $kind, string $model, string $status, int $input_tokens, int $output_tokens, string $error = '' ): array {
		return array(
			'kind'          => $kind,
			'model'         => $model,
			'status'        => $status,
			'input_tokens'  => $input_tokens,
			'output_tokens' => $output_tokens,
			'cost'          => $input_tokens + $output_tokens > 0 ? $this->settings->price_table()->estimate( $model, $input_tokens, $output_tokens ) : 0.0,
			'error'         => $error,
		);
	}
}
