<?php

declare(strict_types=1);

namespace Tests\Fixtures\Enums;

use Tests\Fixtures\Enums\Status as PublicationStatus;

class PublicationLabel
{
    // a class constant is never covered
    public const PublicationStatus DEFAULT_STATUS = PublicationStatus::Draft;

    public static function draft(): string
    {
        return PublicationStatus::Draft
            ->value;
    }

    public static function fromValue(string $value): ?PublicationStatus
    {
        return PublicationStatus::tryFrom($value);
    }

    public static function unknown(): string
    {
        return 'unknown';
    }
}
