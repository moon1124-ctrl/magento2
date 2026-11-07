<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Octocub\UpcCopy\Model\CheckDigit;
use Octocub\UpcCopy\Model\Generator;
use PHPUnit\Framework\TestCase;

class GeneratorTest extends TestCase
{
    public function testFirstNumberIsElevenDataDigitsPlusTheCheckDigit(): void
    {
        $rows = [];
        $log = [];
        $generator = $this->generator('036000', $rows, $log);

        $code = $generator->next();

        self::assertSame(CheckDigit::append('03600000001'), $code);
        self::assertSame(12, strlen($code));
        self::assertSame(11, strlen(substr($code, 0, -1)));
        self::assertTrue(CheckDigit::isValid($code));
        self::assertSame(2, $rows['036000']);
        self::assertSame(['begin', 'lock', 'insert', 'lock', 'update', 'commit'], $log);
    }

    public function testKnownUpcAVectorUsesSerial29145(): void
    {
        $rows = ['036000' => 29145];
        $log = [];
        $generator = $this->generator('036000', $rows, $log);

        self::assertSame('036000291452', $generator->next());
        self::assertSame(29146, $rows['036000']);
        self::assertNotContains('rollback', $log);
    }

    public function testSequentialCallsAdvanceTheLockedCounter(): void
    {
        $rows = [];
        $log = [];
        $generator = $this->generator('036000', $rows, $log);

        $first = $generator->next();
        $second = $generator->next();

        self::assertSame(CheckDigit::append('03600000001'), $first);
        self::assertSame(CheckDigit::append('03600000002'), $second);
        self::assertNotSame($first, $second);
        self::assertSame(3, $rows['036000']);
    }

    public function testTenDigitPrefixLeavesOneSequenceDigit(): void
    {
        $rows = ['1234567890' => 7];
        $log = [];
        $generator = $this->generator('1234567890', $rows, $log);

        $code = $generator->next();

        self::assertSame(CheckDigit::append('12345678907'), $code);
        self::assertSame(12, strlen($code));
        self::assertSame(8, $rows['1234567890']);
    }

    public function testDashesInThePrefixAreIgnored(): void
    {
        $rows = [];
        $log = [];
        $generator = $this->generator('036-000', $rows, $log);

        self::assertSame(CheckDigit::append('03600000001'), $generator->next());
    }

    public function testElevenDigitPrefixDoesNotOpenATransaction(): void
    {
        $rows = [];
        $log = [];
        $generator = $this->generator('12345678901', $rows, $log);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('6 to 10');
        try {
            $generator->next();
        } finally {
            self::assertSame([], $log);
        }
    }

    public function testEmptyPrefixDoesNotOpenATransaction(): void
    {
        $rows = [];
        $log = [];
        $generator = $this->generator('', $rows, $log);

        $this->expectException(LocalizedException::class);
        try {
            $generator->next();
        } finally {
            self::assertSame([], $log);
        }
    }

    public function testExhaustedSequenceRollsBackWithoutAdvancing(): void
    {
        $rows = ['036000' => 100000];
        $log = [];
        $generator = $this->generator('036000', $rows, $log);

        try {
            $generator->next();
            self::fail('An exhausted sequence must not issue a code.');
        } catch (LocalizedException $exception) {
            self::assertStringContainsString('exhausted', $exception->getMessage());
        }
        self::assertSame(100000, $rows['036000']);
        self::assertContains('rollback', $log);
        self::assertNotContains('update', $log);
    }

    public function testFailedReservationRollsTheInsertBack(): void
    {
        $rows = [];
        $log = [];
        $generator = $this->generator('036000', $rows, $log, static fn (array $bind): int => 0);

        try {
            $generator->next();
            self::fail('A reservation that updates no row must fail.');
        } catch (LocalizedException $exception) {
            self::assertStringContainsString('could not be reserved', $exception->getMessage());
        }
        self::assertArrayNotHasKey('036000', $rows);
        self::assertContains('rollback', $log);
    }

    public function testDuplicateInsertUsesTheRowLockedByTheOtherTransaction(): void
    {
        $rows = [];
        $log = [];
        $generator = $this->generator('036000', $rows, $log, null, static function (array $bind) use (&$rows): void {
            $rows[$bind['prefix']] = 4;
            throw new \RuntimeException('duplicate');
        });

        self::assertSame(CheckDigit::append('03600000004'), $generator->next());
        self::assertSame(5, $rows['036000']);
        self::assertSame(['begin', 'lock', 'insert', 'lock', 'update', 'commit'], $log);
    }

    /**
     * @param array<string, int> $rows
     * @param string[] $log
     * @param \Closure(array): int|null $update
     * @param \Closure(array): void|null $insert
     */
    private function generator(
        string $prefix,
        array &$rows,
        array &$log,
        ?\Closure $update = null,
        ?\Closure $insert = null
    ): Generator {
        $locked = false;
        $snapshot = [];
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn ($ident): string => (string) $ident);
        $connection->method('forUpdate')->willReturnCallback(static fn (string $sql): string => $sql . ' FOR UPDATE');
        $connection->method('beginTransaction')->willReturnCallback(function () use (&$rows, &$log, &$locked, &$snapshot) {
            if ($locked) {
                throw new \RuntimeException('sequence row is still locked');
            }
            $snapshot = $rows;
            $log[] = 'begin';
        });
        $connection->method('commit')->willReturnCallback(function () use (&$rows, &$log, &$locked, &$snapshot) {
            $snapshot = $rows;
            $locked = false;
            $log[] = 'commit';
        });
        $connection->method('rollBack')->willReturnCallback(function () use (&$rows, &$log, &$locked, &$snapshot) {
            $rows = $snapshot;
            $locked = false;
            $log[] = 'rollback';
        });
        $connection->method('fetchOne')->willReturnCallback(
            function ($sql, $bind = []) use (&$rows, &$log, &$locked) {
                if (!str_contains((string) $sql, 'FOR UPDATE')) {
                    throw new \RuntimeException('Sequence read did not lock the row.');
                }
                $locked = true;
                $log[] = 'lock';

                return $rows[$bind[0]] ?? false;
            }
        );
        $connection->method('insert')->willReturnCallback(
            function ($table, array $bind) use (&$rows, &$log, $insert): int {
                $log[] = 'insert';
                if ($insert !== null) {
                    $insert($bind);

                    return 1;
                }
                if (isset($rows[$bind['prefix']])) {
                    throw new \RuntimeException('duplicate');
                }
                $rows[$bind['prefix']] = $bind['next_number'];

                return 1;
            }
        );
        $connection->method('update')->willReturnCallback(
            function ($table, array $bind, $where = '') use (&$rows, &$log, $update): int {
                $log[] = 'update';
                if ($update !== null) {
                    return $update($bind);
                }
                $key = $where['prefix = ?'] ?? null;
                if ($key === null || !isset($rows[$key])) {
                    return 0;
                }
                $rows[$key] = $bind['next_number'];

                return 1;
            }
        );

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturn('octocub_upc_sequence');
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn($prefix);

        return new Generator($config, $resource);
    }
}
