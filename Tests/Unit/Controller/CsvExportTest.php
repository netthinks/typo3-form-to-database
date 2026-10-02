<?php

declare(strict_types=1);

namespace LiquidLight\FormToDatabase\Test\Unit\Controller;

use LiquidLight\FormToDatabase\Controller\FormResultsController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CSV export of form results: visitor input must stay text in a spreadsheet
 * (no formula injection) and must not shift cell boundaries.
 */
final class CsvExportTest extends TestCase
{
    /**
     * @param list<string> $cells
     */
    private function line(array $cells, string $delimiter = ','): string
    {
        $controller = (new \ReflectionClass(FormResultsController::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod($controller, 'csvLine'))->invoke($controller, $cells, $delimiter);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function formulaPrefixes(): array
    {
        return [
            'equals'        => ['=1+1', "'=1+1"],
            'plus'          => ['+1+1', "'+1+1"],
            'minus'         => ['-1+1', "'-1+1"],
            'at'            => ['@SUM(1,1)', "'@SUM(1,1)"],
            'leading tab'   => ["\t=1+1", "'\t=1+1"],
            'percent'       => ['%27', "'%27"],
            'phone number keeps its plus' => ['+49 7721 909593', "'+49 7721 909593"],
        ];
    }

    #[Test]
    #[DataProvider('formulaPrefixes')]
    public function formulaPrefixesAreNeutralised(string $value, string $expectedCell): void
    {
        $cells = str_getcsv($this->line([$value]), ',', '"', '\\');

        self::assertSame([$expectedCell], $cells);
    }

    #[Test]
    public function quotesDelimitersAndLineBreaksKeepCellBoundaries(): void
    {
        $values = ['Text mit "Anführungszeichen"', 'a,b;c', "Zeile 1\nZeile 2", 'Umlaute äöü ß', 'normaler Text'];

        $line = $this->line($values);

        self::assertStringContainsString('"Text mit ""Anführungszeichen"""', $line, 'quotes are doubled, not backslash-escaped');
        self::assertSame($values, str_getcsv($line, ',', '"', '\\'));
    }

    #[Test]
    public function semicolonDelimiterIsHonoured(): void
    {
        self::assertSame(['a,b', 'c'], str_getcsv($this->line(['a,b', 'c'], ';'), ';', '"', '\\'));
    }

    #[Test]
    public function lineHasNoTrailingLineBreak(): void
    {
        self::assertStringEndsNotWith("\n", $this->line(['x']));
    }
}
