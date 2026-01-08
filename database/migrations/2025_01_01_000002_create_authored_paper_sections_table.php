<?php
// database/migrations/2025_01_01_000002_create_authored_paper_sections_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ✅ Skip if table already exists
        if (Schema::hasTable('authored_paper_sections')) {
            return;
        }
        Schema::create('authored_paper_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('authored_paper_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->string('section_key', 50);
            $table->string('section_title', 200);
            $table->longText('body_html')->nullable();
            $table->integer('position')->default(0);

            $table->timestamps();

            $table->unique(['authored_paper_id', 'section_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authored_paper_sections');
    }
};
