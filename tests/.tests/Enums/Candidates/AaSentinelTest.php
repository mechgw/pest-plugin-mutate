<?php

declare(strict_types=1);

// runs first, and records every run of all the selected tests
it('records the run', function (): void {
    file_put_contents((string) getenv('PEST_MUTATE_SENTINEL'), 'run'.PHP_EOL, FILE_APPEND);

    expect(true)->toBeTrue();
});
