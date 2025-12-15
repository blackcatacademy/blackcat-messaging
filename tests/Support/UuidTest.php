<?php
declare(strict_types=1);

namespace BlackCat\Messaging\Tests\Support;

use BlackCat\Messaging\Support\Uuid;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function testV4IsUuid(): void
    {
        $id = Uuid::v4();
        self::assertTrue(Uuid::isUuid($id));
    }

    public function testNormalizeReturnsLowercaseUuidWhenInputIsUuid(): void
    {
        $id = strtoupper(Uuid::v4());
        self::assertTrue(Uuid::isUuid($id));
        self::assertSame(strtolower($id), Uuid::normalize($id));
    }

    public function testNormalizeIsDeterministicForSameInputAndSalt(): void
    {
        $a = Uuid::normalize('abc', 'salt');
        $b = Uuid::normalize('abc', 'salt');
        self::assertSame($a, $b);
        self::assertTrue(Uuid::isUuid($a));
    }

    public function testNormalizeVariesBySalt(): void
    {
        $a = Uuid::normalize('abc', 'salt1');
        $b = Uuid::normalize('abc', 'salt2');
        self::assertNotSame($a, $b);
    }

    public function testV5RejectsInvalidNamespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Uuid::v5('not-a-uuid', 'x');
    }
}

