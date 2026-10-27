<?php

declare(strict_types=1);

namespace AIFAQ\Tests\Unit;

use AIFAQ\Core\Search\Bm25;
use AIFAQ\Core\Search\HybridSearch;
use AIFAQ\Core\Search\SearchHit;
use AIFAQ\Core\Search\SearchResult;
use AIFAQ\Core\Search\Tokenizer;
use AIFAQ\Core\Search\Vectors;
use AIFAQ\Tests\Support\OpenAi;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Tokenizer::class)]
#[CoversClass(Bm25::class)]
#[CoversClass(Vectors::class)]
#[CoversClass(HybridSearch::class)]
#[CoversClass(SearchHit::class)]
#[CoversClass(SearchResult::class)]
final class SearchTest extends TestCase
{
    public function testTokensDropFunctionWordsAndFoldPlurals(): void
    {
        self::assertSame(['delivery', 'time', 'parcel'], Tokenizer::tokens('What are the Delivery times for parcels?'));
        self::assertSame(['policy', 'glass'], Tokenizer::tokens('policies, glass'));
        self::assertSame(['combien', 'temp', 'retourner', 'article'], Tokenizer::tokens('Combien de temps pour retourner un article ?'));
    }

    public function testBm25PrefersTheDocumentWithTheRareTerm(): void
    {
        $bm25 = new Bm25([['shipping', 'germany'], ['shipping', 'portugal'], ['return', 'refund']]);

        $scores = $bm25->scores(['germany']);

        self::assertGreaterThan(0, $scores[0]);
        self::assertSame(0.0, $scores[1]);
        self::assertSame(0.0, $scores[2]);
    }

    public function testVectorsSurviveTheDatabaseRoundTrip(): void
    {
        $vector = OpenAi::axis(2);
        $packed = Vectors::pack($vector);

        self::assertSame(16, strlen($packed));
        self::assertEqualsWithDelta($vector, Vectors::unpack($packed), 1e-6);
        self::assertSame([], Vectors::unpack('abc'));
        self::assertEqualsWithDelta(1.0, Vectors::dot($vector, $vector), 1e-6);
    }

    public function testReciprocalRankFusionRewardsItemsHighInBothLists(): void
    {
        $scores = HybridSearch::reciprocal_rank_fusion([[7, 3], [3, 9]]);

        arsort($scores);
        self::assertSame(3, array_key_first($scores));
    }

    public function testNothingPassesTheGateWhenTheQuestionIsUnrelated(): void
    {
        $passages = [OpenAi::passage(1, 'Shipping', 'Germany', 'Germany 3-5 days', 0), OpenAi::passage(2, 'Returns', '', '30 days', 1)];

        $result = (new HybridSearch(5, 0.5))->search(OpenAi::axis(3), 'pet insurance', $passages);

        self::assertSame([], $result->hits);
        self::assertLessThan(0.5, $result->best_similarity);
    }

    public function testKeywordsReRankButCannotPullInUnrelatedPassages(): void
    {
        $passages = [
            OpenAi::passage(1, 'Shipping', 'Portugal', 'Portugal 1-2 days', 0),
            OpenAi::passage(2, 'Shipping', 'Germany', 'Germany 3-5 days', 0),
            OpenAi::passage(3, 'Blog', 'Germany trip', 'Our team visited Germany', 3),
        ];
        // Both shipping passages are equally close to the question; the word "Germany" lifts passage 2.
        $question = OpenAi::axis(0);

        $result = (new HybridSearch(5, 0.5))->search($question, 'delivery to Germany', $passages);

        self::assertSame([2, 1], array_map(static fn (SearchHit $h): int => $h->passage->post_id, $result->hits));
        self::assertSame(1, $result->hits[0]->keyword_rank);
    }

    public function testPassagesWithoutVectorsAreIgnored(): void
    {
        $without = new \AIFAQ\Core\Content\Passage(9, 'Draft', 'https://shop.test/draft/', '', 'no vector yet');

        $result = (new HybridSearch())->search(OpenAi::axis(0), 'draft', [$without]);

        self::assertSame([], $result->hits);
        self::assertSame(0.0, $result->best_similarity);
    }
}
