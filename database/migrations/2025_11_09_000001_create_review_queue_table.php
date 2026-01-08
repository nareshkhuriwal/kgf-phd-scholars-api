<?php
// database/migrations/2025_11_09_000001_create_review_queue_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // ✅ Skip if table already exists
        if (Schema::hasTable('review_queue')) {
            return;
        }
        Schema::create('review_queue', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('paper_id')->constrained('papers')->cascadeOnDelete();
            $t->timestamp('added_at')->useCurrent();
            $t->unique(['user_id', 'paper_id']);
            $t->index(['user_id', 'added_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('review_queue'); }
};
