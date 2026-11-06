<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linkedin_links', function (Blueprint $table): void {
            $table->id();
            $table->string('job_id', 32)->index();
            $table->string('url', 255)->unique();
            $table->string('title', 255)->nullable();
            $table->string('company', 255)->nullable();
            $table->string('location', 255)->nullable();
            $table->json('notes')->nullable();
            $table->string('alert', 255)->nullable();
            $table->string('message_id', 512)->nullable();
            $table->string('status', 16)->default('new')->index();
            $table->foreignId('opportunity_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linkedin_links');
    }
};
