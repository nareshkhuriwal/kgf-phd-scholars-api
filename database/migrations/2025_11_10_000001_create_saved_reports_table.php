<?php
// database/migrations/2025_11_10_000001_create_saved_reports_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('saved_reports', function (Blueprint $t) {
      $t->id();
      $t->string('name');
      $t->string('template');           // synopsis | rol | final_thesis | presentation
      $t->string('format')->nullable(); // pdf | docx | xlsx | pptx
      $t->string('filename')->nullable();
      $t->json('filters');
      $t->json('selections');
      $t->unsignedBigInteger('created_by')->nullable();
      $t->unsignedBigInteger('updated_by')->nullable();
      $t->timestamps();
    });
  }
  public function down(): void { Schema::dropIfExists('saved_reports'); }
};
