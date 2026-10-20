<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('message_id', 512)->unique();
            $table->string('sender', 255)->nullable();
            $table->string('subject', 512)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->unsignedSmallInteger('links_found')->default(0);
            $table->unsignedSmallInteger('links_queued')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_messages');
    }
};
