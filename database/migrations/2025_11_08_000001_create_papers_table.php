<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // ✅ Skip if table already exists
        if (Schema::hasTable('papers')) {
            return;
        }
        Schema::create('papers', function (Blueprint $t) {
            $t->id();
            $t->string('paper_code')->nullable();                 // "Paper ID"
            $t->string('title')->nullable();
            $t->string('authors')->nullable();
            $t->string('doi')->nullable();
            $t->string('year')->nullable();
            $t->string('category')->nullable();
            $t->string('journal')->nullable();                    // Name of Journal/Conference
            $t->string('issn_isbn')->nullable();
            $t->string('publisher')->nullable();
            $t->string('place')->nullable();                      // Place of Conference
            $t->string('volume')->nullable();
            $t->string('issue')->nullable();
            $t->string('page_no')->nullable();
            $t->string('area')->nullable();                       // Area / Sub Area
            $t->text('key_issue')->nullable();

            // Rich text HTML fields
            $t->longText('review_html')->nullable();              // Literature Review
            $t->longText('solution_method_html')->nullable();     // Solution Approach / Methodology used
            $t->longText('related_work_html')->nullable();
            $t->longText('input_params_html')->nullable();
            $t->longText('hw_sw_html')->nullable();               // Hardware / Software / Technology Used
            $t->longText('results_html')->nullable();
            $t->longText('advantages_html')->nullable();          // Key advantages
            $t->longText('limitations_html')->nullable();
            $t->longText('remarks_html')->nullable();

            $t->json('meta')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('papers');
    }
};
