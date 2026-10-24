<?php

declare(strict_types=1);

use Pest\Mutate\Support\EnumCaseValueTimeout;

it('uses the configured time limit', function (): void {
    expect(EnumCaseValueTimeout::seconds(7, 600, 8))->toBe(600)
        ->and(EnumCaseValueTimeout::seconds(7, 3, 1))->toBe(3);
});

it('uses the time limit of a serial initial test run', function (): void {
    expect(EnumCaseValueTimeout::seconds(7, null, 1))->toBe(7)
        ->and(EnumCaseValueTimeout::seconds(7, null, 0))->toBe(7);
});

it('multiplies the time limit of a parallel initial test run by its number of processes', function (): void {
    expect(EnumCaseValueTimeout::seconds(7, null, 8))->toBe(56);
});
