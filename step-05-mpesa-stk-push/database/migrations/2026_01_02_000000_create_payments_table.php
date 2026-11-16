<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 12);
            $table->unsignedInteger('amount');
            $table->string('status', 16)->default('pending');
            $table->string('merchant_request_id', 64)->nullable();
            $table->string('checkout_request_id', 64)->nullable()->unique();
            $table->string('reported_receipt', 32)->nullable();
            $table->string('mpesa_receipt', 32)->nullable()->unique();
            $table->integer('result_code')->nullable();
            $table->string('result_desc', 255)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
