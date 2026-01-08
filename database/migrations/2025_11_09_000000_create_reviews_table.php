<?php
// database/migrations/2025_11_09_000000_create_reviews_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // ✅ Skip if table already exists
        if (Schema::hasTable('reviews')) {
            return;
        }
        Schema::create('reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('paper_id')->constrained('papers')->cascadeOnDelete(); // your papers table
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->longText('html')->nullable();         // CKEditor HTML
            $t->string('status')->default('pending'); // pending|done
            // Handy ROL fields users may quick-edit in sidebar
            $t->text('key_issue')->nullable();
            $t->text('remarks')->nullable();
            $t->timestamps();
            $t->unique(['paper_id', 'user_id']);      // one review per user per paper
        });
    }
    public function down(): void { Schema::dropIfExists('reviews'); }
};
