<?php

declare(strict_types=1);

namespace AIFAQ\Tests\Unit;

use AIFAQ\Core\Answer\AnswerPrompt;
use AIFAQ\Core\Http\HttpRequest;
use AIFAQ\Core\Http\HttpResponse;
use AIFAQ\Core\Provider\AbstractProvider;
use AIFAQ\Core\Provider\EmbeddingResult;
use AIFAQ\Core\Provider\OpenAiEmbeddings;
use AIFAQ\Core\Provider\OpenAiResponses;
use AIFAQ\Core\Provider\ProviderException;
use AIFAQ\Core\Provider\ProviderResult;
use AIFAQ\Tests\Support\FakeTransport;
use AIFAQ\Tests\Support\OpenAi;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenAiEmbeddings::class)]
#[CoversClass(OpenAiResponses::class)]
#[CoversClass(AbstractProvider::class)]
#[CoversClass(ProviderException::class)]
#[CoversClass(ProviderResult::class)]
#[CoversClass(EmbeddingResult::class)]
#[CoversClass(HttpRequest::class)]
#[CoversClass(HttpResponse::class)]
final class ProviderTest extends TestCase
{
    public function testEmbeddingRequestFollowsTheApiReference(): void
    {
        $request = (new OpenAiEmbeddings(new FakeTransport(), 'test-key', 'text-embedding-3-small', 512))->build_request(['a', 'b']);
        $body = json_decode($request->body, true);

        self::assertSame('https://api.openai.com/v1/embeddings', $request->url);
        self::assertSame(['model' => 'text-embedding-3-small', 'input' => ['a', 'b'], 'encoding_format' => 'float', 'dimensions' => 512], $body);
        self::assertSame('[redacted]', $request->redacted()['headers']['Authorization']);
    }

    public function testDimensionsAreLeftOutWhenZero(): void
    {
        $request = (new OpenAiEmbeddings(new FakeTransport(), 'k', 'text-embedding-ada-002'))->build_request(['a']);

        self::assertArrayNotHasKey('dimensions', json_decode($request->body, true));
    }

    public function testEmbeddingsComeBackInInputOrderAndUnitLength(): void
    {
        $transport = (new FakeTransport())->queueJson(200, [
            'data' => [['index' => 1, 'embedding' => [0.0, 2.0]], ['index' => 0, 'embedding' => [3.0, 4.0]]],
            'model' => 'text-embedding-3-small',
            'usage' => ['total_tokens' => 7],
        ]);

        $result = (new OpenAiEmbeddings($transport, 'k', 'text-embedding-3-small'))->embed(['first', 'second']);

        self::assertEqualsWithDelta([[0.6, 0.8], [0.0, 1.0]], $result->vectors, 1e-9);
        self::assertSame(7, $result->tokens);
    }

    public function testLargeInputsAreSentInBatches(): void
    {
        $count = OpenAiEmbeddings::BATCH_SIZE + 3;
        $transport = (new FakeTransport())
            ->queueJson(200, OpenAi::embeddings(array_fill(0, OpenAiEmbeddings::BATCH_SIZE, OpenAi::axis(0)), 100))
            ->queueJson(200, OpenAi::embeddings(array_fill(0, 3, OpenAi::axis(1)), 5));

        $result = (new OpenAiEmbeddings($transport, 'k', 'text-embedding-3-small'))->embed(array_fill(0, $count, 'text'));

        self::assertCount(2, $transport->requests);
        self::assertCount($count, $result->vectors);
        self::assertSame(105, $result->tokens);
    }

    public function testAMissingEmbeddingIsAnInvalidResponse(): void
    {
        $transport = (new FakeTransport())->queueJson(200, OpenAi::embeddings([OpenAi::axis(0)]));

        try {
            (new OpenAiEmbeddings($transport, 'k', 'text-embedding-3-small'))->embed(['one', 'two']);
            self::fail('Expected an exception.');
        } catch (ProviderException $e) {
            self::assertSame(ProviderException::INVALID_RESPONSE, $e->get_reason());
        }
    }

    public function testErrorStatusesMapToReasonsAndKeysAreMasked(): void
    {
        $body = (string) json_encode(['error' => ['message' => 'Incorrect API key provided: sk-proj-abcd****wxyz.', 'type' => 'invalid_request_error']]);
        $transport = (new FakeTransport())->queue(new HttpResponse(401, $body))->queue(new HttpResponse(429, '{}', ['Retry-After' => '20']));
        $embeddings = new OpenAiEmbeddings($transport, 'k', 'text-embedding-3-small');

        try {
            $embeddings->embed(['x']);
            self::fail('Expected an exception.');
        } catch (ProviderException $e) {
            self::assertSame(ProviderException::AUTH, $e->get_reason());
            self::assertStringNotContainsString('sk-proj', $e->getMessage());
            self::assertFalse($e->is_retryable());
        }

        try {
            $embeddings->embed(['x']);
            self::fail('Expected an exception.');
        } catch (ProviderException $e) {
            self::assertSame(ProviderException::RATE_LIMITED, $e->get_reason());
            self::assertSame(20, $e->get_context()['retry_after']);
            self::assertTrue($e->is_retryable());
        }
    }

    public function testAnIncompleteAnswerKeepsItsUsage(): void
    {
        $body = OpenAi::response('{"answerable": true, "ans', 800, 400);
        $body['status'] = 'incomplete';
        $body['incomplete_details'] = ['reason' => 'max_output_tokens'];
        $transport = (new FakeTransport())->queueJson(200, $body);

        try {
            (new OpenAiResponses($transport, 'k', 'gpt-4.1-mini'))->complete(new AnswerPrompt('system', 'user'));
            self::fail('Expected an exception.');
        } catch (ProviderException $e) {
            self::assertSame(ProviderException::TRUNCATED, $e->get_reason());
            self::assertSame(400, $e->get_usage()?->output_tokens);
        }
    }

    public function testAModelRefusalPartIsReported(): void
    {
        $body = OpenAi::response('');
        $body['output'][0]['content'] = [['type' => 'refusal', 'refusal' => 'I cannot help with that.']];
        $transport = (new FakeTransport())->queueJson(200, $body);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('declined');
        (new OpenAiResponses($transport, 'k', 'gpt-4.1-mini'))->complete(new AnswerPrompt('system', 'user'));
    }

    public function testAnswerSchemaIsStrict(): void
    {
        $schema = AnswerPrompt::schema();

        self::assertFalse($schema['additionalProperties']);
        self::assertSame(['answerable', 'answer'], $schema['required']);
    }
}
