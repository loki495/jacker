<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interview_questions', function (Blueprint $table): void {
            $table->id();
            $table->string('stage')->index();
            $table->text('question');
            $table->json('options')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
        Schema::create('question_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('interview_question_id')->constrained()->cascadeOnDelete();
            $table->text('answer');
            $table->timestamps();
            $table->unique(['opportunity_id', 'interview_question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_answers');
        Schema::dropIfExists('interview_questions');
    }
};
