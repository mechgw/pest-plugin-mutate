<?php

declare(strict_types=1);

use Tests\Fixtures\Enums\Status;

// a helper of another file than the test file: its references are not followed
function draftStatusValueFromAnotherFile(): string
{
    return Status::Draft->value;
}
