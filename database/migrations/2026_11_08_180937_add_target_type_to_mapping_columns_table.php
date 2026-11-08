<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The database column type chosen in the mapping. Null = the sheet column's type (the recommendation).
     */
    public function up(): void
    {
        Schema::table('mapping_columns', function (Blueprint $table) {
            $table->string('target_type')->nullable()->after('target_column');
        });
    }

    public function down(): void
    {
        Schema::table('mapping_columns', function (Blueprint $table) {
            $table->dropColumn('target_type');
        });
    }
};
