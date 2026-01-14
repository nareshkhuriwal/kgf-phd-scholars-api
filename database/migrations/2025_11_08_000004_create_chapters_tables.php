<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // ✅ Skip if table already exists
        if (Schema::hasTable('chapters')) {
            return;
        }
        Schema::create('chapters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('collection_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title');
            $t->integer('order_index')->default(0);
            $t->longText('body_html')->nullable();
            $t->timestamps();
        });

        Schema::create('chapter_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('chapter_id')->constrained()->cascadeOnDelete();
            $t->foreignId('paper_id')->constrained()->cascadeOnDelete();
            $t->enum('source_field', [
                'review_html','key_issue','solution_method_html','related_work_html',
                'input_params_html','hw_sw_html','results_html','advantages_html',
                'limitations_html','remarks_html'
            ]);
            $t->longText('content_html'); // frozen copy
            $t->string('citation_style', 32)->nullable(); // "APA", "IEEE"
            $t->integer('order_index')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('chapter_items');
        Schema::dropIfExists('chapters');
    }
};
