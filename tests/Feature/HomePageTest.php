<?php

declare(strict_types=1);

it('renders the seismo placeholder', function () {
    $response = $this->get('/');

    $response->assertSuccessful();
    $response->assertSee('SEISMO', false);
});
