<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name')->nullable();
            $table->string('agreement_number')->nullable();
            $table->string('grant_amount')->nullable();
            $table->string('effective_date')->nullable();
            $table->string('expiry_date')->nullable();
            $table->string('template_date')->nullable();
            $table->json('assistance_listings')->nullable();

            $table->string('recipient_name')->nullable();
            $table->string('recipient_street_address')->nullable();
            $table->string('recipient_city_state_zip')->nullable();
            $table->string('recipient_attention')->nullable();
            $table->string('recipient_telephone')->nullable();
            $table->string('recipient_email')->nullable();

            $table->string('company_name')->nullable();
            $table->string('company_division')->nullable();
            $table->string('company_office')->nullable();
            $table->string('company_street_address')->nullable();
            $table->string('company_city_state_zip')->nullable();
            $table->string('company_grant_administrator')->nullable();
            $table->string('company_telephone')->nullable();
            $table->string('company_email')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
