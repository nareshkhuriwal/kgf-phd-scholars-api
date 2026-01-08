<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ✅ Skip if table already exists
        if (Schema::hasTable('authored_paper_comments')) {
            return;
        }
        Schema::create('authored_paper_comments', function (Blueprint $table) {
            $table->id();

            // FK to authored_papers
            $table->foreignId('authored_paper_id')
                  ->constrained('authored_papers')
                  ->cascadeOnDelete();

            // Comment author (supervisor / admin / author)
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            // For threaded replies
            $table->foreignId('parent_id')
                  ->nullable()
                  ->constrained('authored_paper_comments')
                  ->cascadeOnDelete();

            // Comment body (HTML from CKEditor)
            $table->longText('body');

            $table->timestamps();

            // Helpful indexes
            $table->index(['authored_paper_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authored_paper_comments');
    }
};
