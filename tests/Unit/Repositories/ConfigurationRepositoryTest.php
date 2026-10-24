<?php

declare(strict_types=1);

use Pest\Mutate\Repositories\ConfigurationRepository;

it('uses the paths from the phpunit.xml if no paths are configured', function (): void {
    $repository = new ConfigurationRepository;

    expect($repository->mergedConfiguration())
        ->paths->toBeArray()
        ->paths->{0}->toEndWith('/./tests')
        ->paths->{1}->toEndWith('/./src');
});

it('passes the time limit of the run verifying the value of an enum case', function (): void {
    $repository = new ConfigurationRepository;

    expect($repository->mergedConfiguration())->enumTimeout->toBeNull();

    $repository = new ConfigurationRepository;
    $repository->testConfiguration->enumTimeout(600);

    expect($repository->mergedConfiguration())->enumTimeout->toBe(600);
});
