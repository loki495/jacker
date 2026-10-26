<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_requests', function (Blueprint $table): void {
            $table->foreignId('link_check_id')->nullable()->change();
            $table->foreignId('opportunity_id')->nullable()->after('link_check_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('opportunity_id');
            $table->dropColumn('mode');
        });
    }
};
