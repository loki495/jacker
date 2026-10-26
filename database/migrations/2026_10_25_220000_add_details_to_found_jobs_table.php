<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('found_jobs', function (Blueprint $table): void {
            $table->string('company_url', 2048)->nullable();
            $table->text('remote_restrictions')->nullable();
            $table->text('requirements')->nullable();
            $table->text('notes')->nullable();
            $table->date('deadline')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('found_jobs', function (Blueprint $table): void {
            $table->dropColumn(['company_url', 'remote_restrictions', 'requirements', 'notes', 'deadline']);
        });
    }
};
