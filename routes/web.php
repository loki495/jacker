<?php

declare(strict_types=1);

use App\Http\Controllers\AgentFileController;
use App\Http\Controllers\CoverLetterPdfController;
use App\Http\Controllers\CsvExportController;
use App\Http\Controllers\MaterialPdfController;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::home')->name('home');
Route::livewire('/opportunities/create', 'pages::editor')->name('opportunities.create');
Route::livewire('/opportunities/{opportunity}/edit', 'pages::editor')->name('opportunities.edit');
Route::livewire('/opportunities/{opportunity}', 'pages::detail')->name('opportunities.show');
Route::livewire('/opportunities/{opportunity}/questions', 'pages::answers')->name('opportunities.questions');
Route::get('/opportunities/{opportunity}/cover-letter.pdf', CoverLetterPdfController::class)->name('opportunities.cover-letter');
Route::get('/opportunities/{opportunity}/application/{file}', AgentFileController::class)->whereIn('file', ['resume', 'letter'])->name('opportunities.application-file');
Route::get('/opportunities/{opportunity}/materials/{kind}/{version}', MaterialPdfController::class)->whereIn('kind', ['resume', 'letter'])->whereIn('version', ['generated', 'edited', 'general'])->name('opportunities.material');
Route::livewire('/questions', 'pages::questions')->name('questions');

Route::livewire('/queue', 'pages::queue')->name('queue');
Route::redirect('/link-checks', '/queue?kind=links');
Route::livewire('/job-finder', 'pages::job-finder')->name('job-finder');
Route::redirect('/scoring', '/queue?kind=grading');
Route::livewire('/settings', 'pages::settings')->name('settings');
Route::livewire('/import', 'pages::import')->name('import');
Route::get('/export', CsvExportController::class)->name('export');
