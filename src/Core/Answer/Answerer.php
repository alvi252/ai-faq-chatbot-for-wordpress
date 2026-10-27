<?php
/**
 * Answers one question.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Answer;

use AIFAQ\Core\Provider\OpenAiEmbeddings;
use AIFAQ\Core\Provider\OpenAiResponses;
use AIFAQ\Core\Provider\ProviderException;
use AIFAQ\Core\Search\HybridSearch;
use AIFAQ\Core\Usage\PriceTable;

defined( 'ABSPATH' ) || exit;

/**
 * Question in, answer with sources out:
 * 1. embed the question;
 * 2. search the site's passages; nothing passes the relevance gate: refuse
 *    without calling the answer model (no cost beyond the embedding);
 * 3. ask the model with the numbered passages and a strict JSON schema;
 * 4. keep only citations of passages that were sent. The model declined (in
 *    the JSON or with an API refusal), or answered without citing anything:
 *    refuse. No source, no answer.
 */
final class Answerer {

	/**
	 * Constructor.
	 *
	 * @param OpenAiEmbeddings $embeddings Embeddings API.
	 * @param OpenAiResponses  $responses  Answer API.
	 * @param PassageSource    $source     Indexed passages.
	 * @param HybridSearch     $search     Search.
	 * @param PriceTable       $prices     Prices for cost estimates.
	 * @param string           $site_name  Site name for the instructions.
	 */
	public function __construct(
		private OpenAiEmbeddings $embeddings,
		private OpenAiResponses $responses,
		private PassageSource $source,
		private HybridSearch $search,
		private PriceTable $prices,
		private string $site_name
	) {
	}

	/**
	 * Answers a question.
	 *
	 * @param string $question Question, already trimmed and length-checked.
	 * @return AnswerResult
	 */
	public function ask( string $question ): AnswerResult {
		$started = microtime( true );
		$calls   = array();

		$passages = $this->source->passages( $this->embeddings->model(), $this->embeddings->dimensions() );
		if ( array() === $passages ) {
			return $this->result( AnswerResult::REFUSED, Refusals::message_for( $question ), array(), AnswerResult::EMPTY_INDEX, array(), 0.0, $calls, $started );
		}

		try {
			$embedded = $this->embeddings->embed( array( $question ) );
		} catch ( ProviderException $e ) {
			$calls[] = $this->failed_call( 'embedding', $this->embeddings->model(), $e );
			return $this->result( AnswerResult::ERROR, '', array(), $e->get_reason(), array(), 0.0, $calls, $started );
		}
		$calls[] = $this->call( 'embedding', $embedded->model, 'success', $embedded->tokens, 0 );

		$found = $this->search->search( $embedded->vectors[0], $question, $passages );
		if ( array() === $found->hits ) {
			return $this->result( AnswerResult::REFUSED, Refusals::message_for( $question ), array(), AnswerResult::NO_MATCH, array(), $found->best_similarity, $calls, $started );
		}

		$prompt = AnswerPrompt::build( $this->site_name, $question, $found->hits );
		try {
			$reply = $this->responses->complete( $prompt );
		} catch ( ProviderException $e ) {
			$calls[] = $this->failed_call( 'answer', $this->responses->model(), $e );
			if ( ProviderException::REFUSED === $e->get_reason() ) {
				return $this->result( AnswerResult::REFUSED, Refusals::message_for( $question ), array(), AnswerResult::MODEL_DECLINED, $found->hits, $found->best_similarity, $calls, $started );
			}
			return $this->result( AnswerResult::ERROR, '', array(), $e->get_reason(), $found->hits, $found->best_similarity, $calls, $started );
		}
		$calls[] = $this->call( 'answer', $reply->model, 'success', $reply->input_tokens, $reply->output_tokens );

		$parsed = AnswerParser::parse( $reply->text, $found->hits );
		if ( ! $parsed['answerable'] ) {
			return $this->result( AnswerResult::REFUSED, Refusals::message_for( $question ), array(), AnswerResult::MODEL_DECLINED, $found->hits, $found->best_similarity, $calls, $started );
		}
		if ( array() === $parsed['sources'] ) {
			return $this->result( AnswerResult::REFUSED, Refusals::message_for( $question ), array(), AnswerResult::NO_CITATION, $found->hits, $found->best_similarity, $calls, $started );
		}

		return $this->result( AnswerResult::ANSWERED, $parsed['answer'], $parsed['sources'], '', $found->hits, $found->best_similarity, $calls, $started );
	}

	/**
	 * Usage row for a call.
	 *
	 * @param string $kind          "embedding" or "answer".
	 * @param string $model         Model.
	 * @param string $status        "success" or "error".
	 * @param int    $input_tokens  Input tokens.
	 * @param int    $output_tokens Output tokens.
	 * @param string $error         Error reason.
	 * @return array<string, mixed>
	 */
	private function call( string $kind, string $model, string $status, int $input_tokens, int $output_tokens, string $error = '' ): array {
		return array(
			'kind'          => $kind,
			'model'         => $model,
			'status'        => $status,
			'input_tokens'  => $input_tokens,
			'output_tokens' => $output_tokens,
			'cost'          => $input_tokens + $output_tokens > 0 ? $this->prices->estimate( $model, $input_tokens, $output_tokens ) : 0.0,
			'error'         => $error,
		);
	}

	/**
	 * Usage row for a failed call; tokens count when the API answered.
	 *
	 * @param string            $kind  "embedding" or "answer".
	 * @param string            $model Model, when known.
	 * @param ProviderException $error Error.
	 * @return array<string, mixed>
	 */
	private function failed_call( string $kind, string $model, ProviderException $error ): array {
		$usage = $error->get_usage();
		return $this->call(
			$kind,
			null !== $usage && '' !== $usage->model ? $usage->model : $model,
			'error',
			null !== $usage ? $usage->input_tokens : 0,
			null !== $usage ? $usage->output_tokens : 0,
			$error->get_reason()
		);
	}

	/**
	 * Builds the result.
	 *
	 * @param string                           $status          Status.
	 * @param string                           $answer          Text for the visitor.
	 * @param array                            $sources         Cited hits.
	 * @param string                           $reason          Reason.
	 * @param array                            $retrieved       Hits sent to the model.
	 * @param float                            $best            Best similarity.
	 * @param array<int, array<string, mixed>> $calls           Calls.
	 * @param float                            $started         Start time.
	 * @return AnswerResult
	 */
	private function result( string $status, string $answer, array $sources, string $reason, array $retrieved, float $best, array $calls, float $started ): AnswerResult {
		return new AnswerResult( $status, $answer, $sources, $reason, $retrieved, $best, $calls, (int) round( ( microtime( true ) - $started ) * 1000 ) );
	}
}
