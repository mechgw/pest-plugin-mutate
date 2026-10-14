<?php

declare(strict_types=1);

namespace Tests\Fixtures\Enums;

use PHPUnit\Framework\TestCase;

/**
 * A PHPUnit test class, to locate the code of its executed tests.
 */
final class PublicationCheck extends TestCase
{
    public function test_draft(): void
    {
        self::assertSame('draft', Status::Draft->value);
    }

    public function test_published(): void
    {
        self::assertSame('published', Status::Published->value);
    }
}
