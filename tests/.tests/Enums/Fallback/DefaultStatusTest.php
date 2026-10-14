<?php

declare(strict_types=1);

use Tests\Fixtures\Enums\PublicationLabel;

it('checks a value without referencing its case', function (): void {
    expect(PublicationLabel::DEFAULT_STATUS->value)->toBe('draft');
});
