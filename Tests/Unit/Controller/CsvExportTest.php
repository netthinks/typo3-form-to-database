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
     * RFC 4180 reading as spreadsheet programs do it: a quote is only ever
     * escaped by doubling it, a backslash has no meaning.
     *
     * @return list<string>
     */
    private function parse(string $line, string $delimiter = ','): array
    {
        return str_getcsv($line, $delimiter, '"', '');
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
            'leading CR'    => ["\r=1+1", "'\r=1+1"],
            'percent'       => ['%27', "'%27"],
            'phone number keeps its plus' => ['+49 7721 909593', "'+49 7721 909593"],
        ];
    }

    #[Test]
    #[DataProvider('formulaPrefixes')]
    public function formulaPrefixesAreNeutralised(string $value, string $expectedCell): void
    {
        $cells = $this->parse($this->line([$value]));

        self::assertSame([$expectedCell], $cells);
    }

    #[Test]
    public function quotesDelimitersAndLineBreaksKeepCellBoundaries(): void
    {
        $values = ['Text mit "Anführungszeichen"', 'a,b;c', "Zeile 1\nZeile 2", 'Umlaute äöü ß', 'normaler Text'];

        $line = $this->line($values);

        self::assertStringContainsString('"Text mit ""Anführungszeichen"""', $line, 'quotes are doubled, not backslash-escaped');
        self::assertSame($values, $this->parse($line));
    }

    #[Test]
    public function semicolonDelimiterIsHonoured(): void
    {
        self::assertSame(['a,b', 'c'], $this->parse($this->line(['a,b', 'c'], ';'), ';'));
    }

    #[Test]
    public function backslashBeforeQuoteCannotBreakOutOfItsCell(): void
    {
        $values = ['x\\",=1+1,"', 'second'];

        $cells = $this->parse($this->line($values));

        self::assertSame($values, $cells, 'exactly two unchanged cells, no extra formula cell');
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function cellBoundaryCases(): array
    {
        return [
            'backslash quote'          => [['a\\"b', 'c'], ','],
            'trailing backslash'       => [['a\\', 'b'], ','],
            'backslash quote at end'   => [['a\\"', 'b'], ','],
            'double backslash quote'   => [['a\\\\"",b', 'c'], ','],
            'comma and semicolon'      => [['a,b;c', 'd'], ','],
            'semicolon delimiter'      => [['a;b,c', 'x\\";=1+1;"', 'd'], ';'],
            'LF and CRLF'              => [["Zeile 1\nZeile 2", "a\r\nb", 'c'], ','],
            'umlauts'                  => [['Umlaute äöü ß', 'Ä"Ö'], ','],
            'plain quotes'             => [['Text mit "Anführungszeichen"', '""'], ','],
            'empty and numeric'        => [['', '42', '+4917612345678', '1.5'], ','],
        ];
    }

    /**
     * @param list<string> $values
     */
    #[Test]
    #[DataProvider('cellBoundaryCases')]
    public function cellsSurviveRfc4180Parsing(array $values, string $delimiter): void
    {
        self::assertSame($values, $this->parse($this->line($values, $delimiter), $delimiter));
    }

    #[Test]
    public function formulasAfterBreakoutAttemptStayText(): void
    {
        $values = ['=1+1', 'x\\",=1+1,"', '+1+1', '-1+1', '@SUM(1,1)', "\t=1", "\r=1"];

        $cells = $this->parse($this->line($values));

        self::assertSame(["'=1+1", 'x\\",=1+1,"', "'+1+1", "'-1+1", "'@SUM(1,1)", "'\t=1", "'\r=1"], $cells);
    }

    #[Test]
    public function outputFormatMatchesPreviousExportForHarmlessValues(): void
    {
        self::assertSame(
            '"a";42;;+4917612345678;"x y";";";1.5',
            $this->line(['a', '42', '', '+4917612345678', 'x y', ';', '1.5'], ';')
        );
    }

    #[Test]
    public function lineHasNoTrailingLineBreak(): void
    {
        self::assertStringEndsNotWith("\n", $this->line(['x']));
    }
}
