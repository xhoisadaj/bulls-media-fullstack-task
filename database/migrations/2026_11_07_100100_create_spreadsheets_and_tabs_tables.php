<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spreadsheets', function (Blueprint $table) {
            $table->id();
            $table->string('google_id')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_status')->nullable();
            $table->timestamps();
        });

        Schema::create('tabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spreadsheet_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('google_tab_id');
            $table->string('name');
            $table->boolean('is_active')->default(false);
            $table->boolean('is_present')->default(true);
            $table->timestamps();
            $table->unique(['spreadsheet_id', 'google_tab_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tabs');
        Schema::dropIfExists('spreadsheets');
    }
};
