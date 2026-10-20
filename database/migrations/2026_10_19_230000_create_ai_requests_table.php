<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_checks', function (Blueprint $table): void {
            $table->text('page_text')->nullable();
        });

        Schema::create('ai_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('link_check_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16)->default('extract');
            $table->string('status', 16)->default('queued')->index();
            $table->string('agent', 32)->nullable();
            $table->string('model', 64)->nullable();
            $table->text('output')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
        Schema::table('link_checks', function (Blueprint $table): void {
            $table->dropColumn('page_text');
        });
    }
};
