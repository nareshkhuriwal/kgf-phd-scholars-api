<?php
// database/migrations/2025_01_01_000000_create_researcher_invites_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
         // ✅ Skip if table already exists
        if (Schema::hasTable('researcher_invites')) {
            return;
        }
        
        Schema::create('researcher_invites', function (Blueprint $table) {
            $table->id();
            $table->string('researcher_email');
            $table->string('researcher_name')->nullable();
            $table->string('supervisor_name')->nullable();
            $table->string('role')->nullable();
            $table->string('allowed_domain')->nullable();
            $table->text('message')->nullable();
            $table->text('notes')->nullable();

            $table->string('invite_token')->unique();
            $table->string('status')->default('pending'); // pending, accepted, revoked, expired

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->timestamps();

            $table->index(['created_by', 'status']);
            $table->index('researcher_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('researcher_invites');
    }
};
