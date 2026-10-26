<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrape_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 32);
            $table->string('status', 16)->default('queued')->index();
            $table->unsignedSmallInteger('pages_total')->default(0);
            $table->unsignedSmallInteger('pages_done')->default(0);
            $table->unsignedInteger('seen')->default(0);
            $table->unsignedInteger('added')->default(0);
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('found_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scrape_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 32);
            $table->string('status', 16)->default('new')->index();
            $table->string('url', 2048);
            $table->string('normalized_url', 2048)->unique();
            $table->string('title')->nullable();
            $table->string('company')->nullable();
            $table->text('location')->nullable();
            $table->string('work_arrangement', 16)->nullable();
            $table->string('employment_type', 16)->nullable();
            $table->decimal('salary_min', 12, 2)->nullable();
            $table->decimal('salary_max', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('pay_period', 16)->nullable();
            $table->text('salary_notes')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('found_jobs');
        Schema::dropIfExists('scrape_runs');
    }
};
