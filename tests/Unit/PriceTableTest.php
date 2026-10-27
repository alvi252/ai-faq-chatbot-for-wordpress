<?php

declare(strict_types=1);

namespace AIFAQ\Tests\Unit;

use AIFAQ\Core\Usage\PriceTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PriceTable::class)]
final class PriceTableTest extends TestCase
{
    private const TABLE = <<<'TXT'
        # model = input, output (USD per 1M tokens)
        gpt-4.1-mini = 0.40, 1.60
        gpt-4.1 = 2, 8   # inline comment

        claude-opus-5-5 = 4.00 , 20.00
        not a valid line
        claude-haiku-5-5 = 0.10, cheap
        TXT;

    public function testParsesLinesAndReportsInvalidOnes(): void
    {
        $table = PriceTable::parse(self::TABLE);

        self::assertSame(['input' => 0.4, 'output' => 1.6], $table->price_for('gpt-4.1-mini'));
        self::assertSame(['input' => 2.0, 'output' => 8.0], $table->price_for('gpt-4.1'));
        self::assertSame(['input' => 4.0, 'output' => 20.0], $table->price_for('claude-opus-5-5'));
        self::assertSame([6, 7], $table->invalid_lines());
        self::assertCount(3, $table->all());
    }

    public function testDatedSnapshotUsesLongestMatchingFamily(): void
    {
        $table = PriceTable::parse(self::TABLE);

        self::assertSame(['input' => 0.4, 'output' => 1.6], $table->price_for('gpt-4.1-mini-2025-04-14'), 'Not the gpt-4.1 price.');
        self::assertSame(['input' => 2.0, 'output' => 8.0], $table->price_for('GPT-4.1-2025-04-14'));
        self::assertNull($table->price_for('gpt-4.10'), 'A prefix must end at a dash.');
        self::assertNull($table->price_for('claude-sonnet-5-5'));
    }

    public function testEstimatesCost(): void
    {
        $table = PriceTable::parse(self::TABLE);

        // 812 * 0.40 / 1M + 240 * 1.60 / 1M = 0.0003248 + 0.000384
        self::assertSame(0.0007088, $table->estimate('gpt-4.1-mini-2025-04-14', 812, 240));
        // 900 * 4 / 1M + 1500 * 20 / 1M = 0.0036 + 0.03
        self::assertSame(0.0336, $table->estimate('claude-opus-5-5', 900, 1500));
        self::assertSame(0.0, $table->estimate('gpt-4.1', 0, 0));
        self::assertNull($table->estimate('unknown-model', 100, 100));
    }

    public function testEmptyTable(): void
    {
        $table = PriceTable::parse('');

        self::assertSame([], $table->all());
        self::assertSame([], $table->invalid_lines());
        self::assertNull($table->estimate('gpt-4.1-mini', 1, 1));
    }
}
