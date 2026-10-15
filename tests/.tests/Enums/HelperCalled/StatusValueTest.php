<?php

declare(strict_types=1);

use Tests\Fixtures\Enums\Status;

function draftStatusValue(): string
{
    return Status::Draft->value;
}

function publishedStatusValue(): string
{
    return Status::Published->value;
}

it('checks the draft value through a helper', function (): void {
    expect(draftStatusValue())->toBe('draft');
});

it('checks the published value through a helper', function (): void {
    expect(publishedStatusValue())->toBe('published');
});
