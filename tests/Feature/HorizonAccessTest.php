<?php

declare(strict_types=1);

it('denies horizon dashboard outside local environment', function (): void {
    expect(app()->environment('local'))->toBeFalse();

    $this->get('/horizon')->assertForbidden();
});

it('allows horizon dashboard in local environment', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    $this->get('/horizon')->assertSuccessful();
});
