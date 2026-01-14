<?php
// database/migrations/2025_01_01_000000_create_tags_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // ✅ Skip if table already exists
        if (Schema::hasTable('tags')) {
            return;
        }
        
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['name', 'created_by']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('tags');
    }
};
