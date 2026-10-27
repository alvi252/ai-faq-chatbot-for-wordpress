<?php

declare(strict_types=1);

namespace AIFAQ\Tests\Support;

use AIFAQ\Core\Content\Passage;

/**
 * Response bodies in the shape of the OpenAI API, and small vectors that make
 * search results predictable.
 */
final class OpenAi
{
    /**
     * Body of POST /v1/embeddings.
     *
     * @param array<int, float[]> $vectors
     * @return array<string, mixed>
     */
    public static function embeddings(array $vectors, int $tokens = 12, string $model = 'text-embedding-3-small'): array
    {
        $data = [];
        foreach (array_values($vectors) as $index => $vector) {
            $data[] = ['object' => 'embedding', 'index' => $index, 'embedding' => $vector];
        }

        return ['object' => 'list', 'data' => $data, 'model' => $model, 'usage' => ['prompt_tokens' => $tokens, 'total_tokens' => $tokens]];
    }

    /**
     * Body of POST /v1/responses with one output text.
     *
     * @return array<string, mixed>
     */
    public static function response(string $text, int $input = 900, int $output = 60, string $model = 'gpt-4.1-mini-2025-04-14'): array
    {
        return [
            'id' => 'resp_test',
            'object' => 'response',
            'status' => 'completed',
            'model' => $model,
            'output' => [[
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]],
            ]],
            'usage' => ['input_tokens' => $input, 'output_tokens' => $output, 'total_tokens' => $input + $output],
        ];
    }

    /**
     * JSON answer as the model writes it.
     */
    public static function answer(bool $answerable, string $answer): string
    {
        return (string) json_encode(['answerable' => $answerable, 'answer' => $answer]);
    }

    /**
     * Unit vector pointing mostly along one axis of a 4-dimensional space,
     * so the dot product of two such vectors is high on the same axis and low otherwise.
     *
     * @return float[]
     */
    public static function axis(int $axis, float $leak = 0.1): array
    {
        $vector = array_fill(0, 4, $leak);
        $vector[$axis] = 1.0;
        $norm = sqrt(array_sum(array_map(static fn (float $x): float => $x * $x, $vector)));

        return array_map(static fn (float $x): float => $x / $norm, $vector);
    }

    public static function passage(int $postId, string $title, string $heading, string $text, int $axis, int $id = 0): Passage
    {
        return new Passage($postId, $title, 'https://shop.test/' . strtolower(str_replace(' ', '-', $title)) . '/', $heading, $text, 0, self::axis($axis), $id);
    }
}
