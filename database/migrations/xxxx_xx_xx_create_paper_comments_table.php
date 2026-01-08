<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // ✅ Skip if table already exists
        if (Schema::hasTable('paper_comments')) {
            return;
        }
        Schema::create('paper_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('paper_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('paper_comments')->cascadeOnDelete();
            $t->text('body');
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('paper_comments'); }
};
