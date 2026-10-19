<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table): void {
            $table->id();
            $table->string('company');
            $table->string('title')->nullable();
            $table->string('company_url', 2048)->nullable();
            $table->string('application_url', 2048)->nullable();
            $table->string('normalized_application_url', 2048)->nullable()->unique();
            $table->string('source')->nullable();
            $table->decimal('salary_min', 12, 2)->nullable();
            $table->decimal('salary_max', 12, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('pay_period')->default('unspecified');
            foreach (['salary_notes', 'benefits', 'hours', 'location', 'remote_restrictions', 'responsibilities', 'requirements', 'contact', 'notes', 'skill_match_notes', 'desirability_notes'] as $field) {
                $table->text($field)->nullable();
            }
            $table->string('employment_type')->default('unspecified');
            $table->string('work_arrangement')->default('unspecified');
            $table->string('status')->default('saved')->index();
            $table->boolean('has_applied')->default(false);
            $table->date('applied_at')->nullable();
            $table->date('deadline')->nullable();
            $table->date('follow_up_at')->nullable()->index();
            $table->unsignedTinyInteger('skill_match')->nullable();
            $table->unsignedTinyInteger('desirability')->nullable();
            $table->timestamp('archived_at')->nullable()->index();
            $table->json('extra_fields')->nullable();
            $table->timestamps();
        });
        Schema::create('opportunity_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_events');
        Schema::dropIfExists('opportunities');
    }
};
