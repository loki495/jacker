<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('url', 2048);
            $table->string('status', 16)->default('queued')->index();
            $table->string('result', 16)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('message')->nullable();
            $table->json('filled')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::table('opportunities', function (Blueprint $table): void {
            $table->string('link_status', 16)->nullable();
            $table->timestamp('link_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->dropColumn(['link_status', 'link_checked_at']);
        });
        Schema::dropIfExists('link_checks');
    }
};
