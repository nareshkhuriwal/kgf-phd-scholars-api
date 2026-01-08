<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // ✅ Skip if table already exists
        if (Schema::hasTable('paper_files')) {
            return;
        }
        Schema::create('paper_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('paper_id')->constrained()->cascadeOnDelete();
            $t->string('disk')->default('public');
            $t->string('path');
            $t->string('original_name')->nullable();
            $t->string('mime')->nullable();
            $t->unsignedBigInteger('size_bytes')->default(0);
            $t->string('checksum')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('paper_files');
    }
};
