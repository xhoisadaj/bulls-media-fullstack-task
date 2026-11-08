<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How a modified row was recognised: null = by key, "position" = system ID mode's
     * same-place pairing (always reviewed by a human).
     */
    public function up(): void
    {
        Schema::table('pending_changes', function (Blueprint $table) {
            $table->string('matched_by')->nullable()->after('sheet_row');
        });
    }

    public function down(): void
    {
        Schema::table('pending_changes', function (Blueprint $table) {
            $table->dropColumn('matched_by');
        });
    }
};
