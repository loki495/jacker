<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the job tracker homepage', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('Job tracker');
});

it('rejects requests for unapproved hosts', function (): void {
    $this->get('http://untrusted.invalid/')->assertStatus(400);
});

it('returns a handled not-found response', function (): void {
    $this->get('/missing')->assertNotFound();
});
