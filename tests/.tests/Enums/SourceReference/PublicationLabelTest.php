<?php

declare(strict_types=1);

use Tests\Fixtures\Enums\PublicationLabel;

it('uses a case through covered source code', function (): void {
    expect(PublicationLabel::draft())->toBe('draft');
});
