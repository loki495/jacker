<?php

declare(strict_types=1);

use App\Http\Controllers\CsvExportController;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::home')->name('home');
Route::livewire('/opportunities/create', 'pages::editor')->name('opportunities.create');
Route::livewire('/opportunities/{opportunity}/edit', 'pages::editor')->name('opportunities.edit');
Route::livewire('/opportunities/{opportunity}', 'pages::detail')->name('opportunities.show');

Route::livewire('/link-checks', 'pages::link-checks')->name('link-checks');
Route::livewire('/import', 'pages::import')->name('import');
Route::get('/export', CsvExportController::class)->name('export');
