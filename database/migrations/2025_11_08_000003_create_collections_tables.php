<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // ✅ Skip if table already exists
        if (Schema::hasTable('collections')) {
            return;
        }
        Schema::create('collections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->text('description')->nullable();
            $t->enum('purpose', ['ROL','Thesis','Survey','Misc'])->default('ROL');
            $t->enum('status', ['draft','active','archived'])->default('active');
            $t->timestamps();
        });

        Schema::create('collection_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $t->foreignId('paper_id')->constrained()->cascadeOnDelete();
            $t->longText('notes_html')->nullable();
            $t->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $t->integer('position')->nullable();
            $t->timestamps();
            $t->unique(['collection_id','paper_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('collection_items');
        Schema::dropIfExists('collections');
    }
};
