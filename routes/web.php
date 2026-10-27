<?php

declare(strict_types=1);

use App\Http\Controllers\CsvExportController;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::home')->name('home');
Route::livewire('/opportunities/create', 'pages::editor')->name('opportunities.create');
Route::livewire('/opportunities/{opportunity}/edit', 'pages::editor')->name('opportunities.edit');
Route::livewire('/opportunities/{opportunity}', 'pages::detail')->name('opportunities.show');
Route::livewire('/opportunities/{opportunity}/questions', 'pages::answers')->name('opportunities.questions');
Route::livewire('/questions', 'pages::questions')->name('questions');

Route::livewire('/queue', 'pages::queue')->name('queue');
Route::redirect('/link-checks', '/queue?kind=links');
Route::livewire('/job-finder', 'pages::job-finder')->name('job-finder');
Route::redirect('/scoring', '/queue?kind=grading');
Route::livewire('/settings', 'pages::settings')->name('settings');
Route::livewire('/import', 'pages::import')->name('import');
Route::get('/export', CsvExportController::class)->name('export');
