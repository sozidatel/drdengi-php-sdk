<?php

declare(strict_types=1);

namespace Soz\Drebedengi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soz\Drebedengi\Exception\UnexpectedResponseException;
use Soz\Drebedengi\Support\DrebedengiNormalizer;

final class DrebedengiNormalizerTest extends TestCase
{
    public function testStringRejectsNonScalarValue(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('got array');

        DrebedengiNormalizer::string(['unexpected']);
    }

    public function testTextPreservesWhitespaceWhileStringTrimsIt(): void
    {
        self::assertSame('  value  ', DrebedengiNormalizer::text('  value  '));
        self::assertSame('value', DrebedengiNormalizer::string('  value  '));
        self::assertSame('  value  ', DrebedengiNormalizer::nullableText('  value  '));
        self::assertNull(DrebedengiNormalizer::nullableText(null));
    }

    public function testNullableIdAcceptsNullAndRejectsOtherNonScalarValues(): void
    {
        self::assertNull(DrebedengiNormalizer::nullableId(null));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('got stdClass');

        DrebedengiNormalizer::nullableId(new \stdClass());
    }

    public function testNullableStringPreservesNullAndRejectsOtherNonScalarValues(): void
    {
        self::assertNull(DrebedengiNormalizer::nullableString(null));
        self::assertSame('value', DrebedengiNormalizer::nullableString(' value '));

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('got array');

        DrebedengiNormalizer::nullableString(['unexpected']);
    }

    public function testBoolRejectsNonScalarValue(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('got array');

        DrebedengiNormalizer::bool(['unexpected']);
    }

    public function testBoolAcceptsOnlyKnownSoapTokens(): void
    {
        foreach ([true, 1, '1', 'true', 'TRUE', 't', 'yes', 'y'] as $value) {
            self::assertTrue(DrebedengiNormalizer::bool($value));
        }
        foreach ([false, 0, '0', 'false', 'FALSE', 'f', 'no', 'n', ''] as $value) {
            self::assertFalse(DrebedengiNormalizer::bool($value));
        }
    }

    public function testBoolRejectsUnknownScalarToken(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('Unexpected Drebedengi SOAP boolean token');

        DrebedengiNormalizer::bool('2');
    }

    public function testBoolRejectsUnknownIntegerToken(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('boolean token 0 or 1');

        DrebedengiNormalizer::bool(42);
    }

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

    public function testRejectsListItemWithNumericFieldNames(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('use string field names');

        DrebedengiNormalizer::listOfArrays([['unexpected']]);
    }
}
