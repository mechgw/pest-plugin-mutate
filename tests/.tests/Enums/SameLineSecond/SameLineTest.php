<?php

declare(strict_types=1);

use Tests\Fixtures\Enums\Generated\SameLine;

// the enum has two cases on the same line, which the code style does not allow in the repository
require_once getenv('PEST_MUTATE_GENERATED_ENUMS').'/SameLine.php';

it('checks the second case', function (): void {
    expect(SameLine::Second->value)->toBe('SECOND');
});
