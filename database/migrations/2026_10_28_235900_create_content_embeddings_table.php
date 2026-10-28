<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_embeddings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('source_hash', 64);
            $table->string('provider');
            $table->string('model');
            $table->unsignedInteger('dimensions');
            $table->json('vector');
            $table->timestamps();
            $table->unique(['opportunity_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_embeddings');
    }
};
