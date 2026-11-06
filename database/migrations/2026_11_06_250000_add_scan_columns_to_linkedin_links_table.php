<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('linkedin_links', function (Blueprint $table): void {
            $table->text('description')->nullable();
            $table->string('apply_url', 2048)->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->string('scan_error', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('linkedin_links', function (Blueprint $table): void {
            $table->dropColumn(['description', 'apply_url', 'scanned_at', 'scan_error']);
        });
    }
};
