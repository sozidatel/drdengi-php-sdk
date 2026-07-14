<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Support\DrebedengiNormalizer;

final class DrebedengiNormalizerTest extends TestCase
{
    public function testAcceptsEmptyListResponse(): void
    {
        self::assertSame([], DrebedengiNormalizer::listOfArrays([]));
    }

    public function testRejectsNonArrayListResponse(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('got string');

        DrebedengiNormalizer::listOfArrays('broken response');
    }

    public function testRejectsMalformedListItem(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('key "1"');

        DrebedengiNormalizer::listOfArrays([
            ['id' => '10'],
            'broken item',
        ]);
    }

    public function testRejectsEmptyListItem(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('empty array');

        DrebedengiNormalizer::listOfArrays([[]]);
    }
}
