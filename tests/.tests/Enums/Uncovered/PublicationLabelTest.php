<?php

declare(strict_types=1);

use Tests\Fixtures\Enums\PublicationLabel;

it('uses a part of the class', function (): void {
    expect(PublicationLabel::draft())->toBe('draft');
});
