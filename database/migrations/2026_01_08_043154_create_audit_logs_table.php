<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Who
            $table->foreignId('user_id')->nullable()->index();
            $table->string('user_email')->nullable();

            // What
            $table->string('action'); // e.g. pdf.highlight.apply
            $table->string('entity_type')->nullable(); // Paper
            $table->unsignedBigInteger('entity_id')->nullable();

            // Request snapshot
            $table->json('payload');

            // Context
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Outcome
            $table->boolean('success')->default(true);
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
