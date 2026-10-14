<?php

declare(strict_types=1);

namespace Tests\Fixtures\Enums;

enum Status: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function isPublished(): bool
    {
        return $this === self::Published;
    }
}
