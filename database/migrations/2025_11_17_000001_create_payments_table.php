<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            $table->string('plan_key');          // e.g. researcher-upgrade
            $table->string('razorpay_order_id')->unique();
            $table->string('razorpay_payment_id')->nullable();
            $table->string('razorpay_signature')->nullable();

            $table->unsignedBigInteger('amount'); // in paise
            $table->string('currency', 10)->default('INR');

            $table->enum('status', ['created', 'paid', 'failed', 'cancelled'])->default('created');
            $table->json('meta')->nullable();    // for any extra data

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
