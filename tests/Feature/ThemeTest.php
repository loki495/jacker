<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('offers a light, dark and system theme switch without forcing light mode', function (): void {
    $html = $this->get('/')->assertOk()->getContent();
    expect($html)->toContain('flux.appearance')
        ->toContain(__('jobs.appearance_light'))->toContain(__('jobs.appearance_dark'))->toContain(__('jobs.appearance_system'))
        ->not->toMatch('/<html[^>]*class="light"/');
});
