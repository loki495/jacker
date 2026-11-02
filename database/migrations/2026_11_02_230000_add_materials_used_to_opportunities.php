<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->string('resume_choice', 20)->nullable();
            $table->string('letter_choice', 20)->nullable();
            $table->timestamp('materials_used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->dropColumn(['resume_choice', 'letter_choice', 'materials_used_at']);
        });
    }
};
