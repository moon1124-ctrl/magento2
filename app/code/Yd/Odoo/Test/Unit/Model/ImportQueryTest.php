<?php

declare(strict_types=1);

namespace Yd\Odoo\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Yd\Odoo\Model\ImportQuery;

class ImportQueryTest extends TestCase
{
    public function testSingleSkuDomainDoesNotUseTheCursor(): void
    {
        $query = new ImportQuery();

        self::assertSame(
            [['default_code', '=', 'ABC']],
            $query->domain(['write_date' => '2020-01-01 00:00:00', 'id' => 9], 'ABC')
        );
    }

    public function testCursorDomainKeepsProductsSharingAWriteDate(): void
    {
        $domain = (new ImportQuery())->domain(['write_date' => '2020-01-01 00:00:00', 'id' => 4], null);

        self::assertSame([
            '&',
            ['default_code', '!=', false],
            '|',
            ['write_date', '>', '2020-01-01 00:00:00'],
            '&',
            ['write_date', '=', '2020-01-01 00:00:00'],
            ['id', '>', 4],
        ], $domain);
    }

    public function testRowsAreOrderedByWriteDateThenId(): void
    {
        $sorted = (new ImportQuery())->sort([
            ['id' => 3, 'write_date' => '2020-01-03 00:00:00'],
            ['id' => 2, 'write_date' => '2020-01-01 00:00:00'],
            'ignore',
            ['id' => 1, 'write_date' => '2020-01-01 00:00:00'],
        ]);

        self::assertSame([1, 2, 3], array_column($sorted, 'id'));
    }
}
