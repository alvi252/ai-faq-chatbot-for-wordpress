<?php

declare(strict_types=1);

namespace AIFAQ\Tests\Unit;

use AIFAQ\Core\Content\Chunker;
use AIFAQ\Core\Content\HtmlText;
use AIFAQ\Core\Content\Passage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HtmlText::class)]
#[CoversClass(Chunker::class)]
#[CoversClass(Passage::class)]
final class ContentTest extends TestCase
{
    public function testSplitsAtHeadingsAndKeepsTheIntro(): void
    {
        $html = '<p>We ship from Lisbon.</p><h2>Delivery times</h2><p>Portugal: 1&ndash;2 days.</p><ul><li>Spain: 2&nbsp;days</li><li>France: 3 days</li></ul><h3 class="x">Costs</h3><p>Free over &euro;49.</p>';

        $sections = HtmlText::sections($html);

        self::assertSame(['', 'Delivery times', 'Costs'], array_column($sections, 'heading'));
        self::assertSame('We ship from Lisbon.', $sections[0]['text']);
        self::assertSame("Portugal: 1–2 days.\n- Spain: 2 days\n- France: 3 days", $sections[1]['text']);
        self::assertSame('Free over €49.', $sections[2]['text']);
    }

    public function testFaqDetailsBecomeOneSectionPerQuestion(): void
    {
        $html = '<!-- wp:details --><details><summary>Can I pay by bank transfer?</summary><p>Yes. We hold the order for 7 days.</p></details><!-- /wp:details -->'
            . '<details><summary><strong>Do you ship to Norway?</strong></summary><p>No, only to the EU.</p></details>';

        $sections = HtmlText::sections($html);

        self::assertSame(['Can I pay by bank transfer?', 'Do you ship to Norway?'], array_column($sections, 'heading'));
        self::assertSame('No, only to the EU.', $sections[1]['text']);
    }

    public function testScriptsStylesAndCommentsAreDropped(): void
    {
        $html = '<style>.a{color:red}</style><p>Visible</p><script>alert("x")</script><!-- secret note --><noscript>Enable JS</noscript>';

        self::assertSame('Visible', HtmlText::text($html));
    }

    public function testTablesKeepTheirCellsOnOneLine(): void
    {
        $html = '<table><tr><th>Country</th><th>Days</th></tr><tr><td>Germany</td><td>3-5</td></tr></table>';

        self::assertSame("Country | Days\nGermany | 3-5", HtmlText::text($html));
    }

    public function testShortTextWithoutAHeadingIsSkippedButShortFaqAnswersAreKept(): void
    {
        $passages = Chunker::passages(7, 'FAQ', 'https://shop.test/faq/', '<p>Read more</p><h4>Open on Sundays?</h4><p>No.</p>');

        self::assertCount(1, $passages);
        self::assertSame('Open on Sundays?', $passages[0]->heading);
        self::assertSame('No.', $passages[0]->text);
        self::assertSame("FAQ > Open on Sundays?\nNo.", $passages[0]->embedding_input());
    }

    public function testLongSectionsAreCutAtLineBreaksWithinTheLimit(): void
    {
        $line = str_repeat('word ', 100); // 500 characters
        $html = '<h2>Long</h2><p>' . $line . '</p><p>' . $line . '</p><p>' . $line . '</p>';

        $passages = Chunker::passages(1, 'Page', 'https://shop.test/page/', $html);

        self::assertCount(2, $passages);
        self::assertSame([0, 1], array_map(static fn (Passage $p): int => $p->position, $passages));
        foreach ($passages as $passage) {
            self::assertLessThanOrEqual(Chunker::MAX_CHARS, mb_strlen($passage->text));
            self::assertSame('Long', $passage->heading);
        }
    }

    public function testAVeryLongLineIsCutAtSentenceEnds(): void
    {
        $sentence = str_repeat('a', 700) . '.';
        $pieces = Chunker::split($sentence . ' ' . $sentence);

        self::assertSame([$sentence, $sentence], $pieces);
    }
}
