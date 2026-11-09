<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles (admin, editor, viewer) and which spreadsheets a non-admin user can work with.
     * Users that exist already were created as admins, so they stay admins.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('viewer')->after('email');
        });

        DB::table('users')->update(['role' => 'admin']);

        Schema::create('spreadsheet_user', function (Blueprint $table) {
            $table->foreignId('spreadsheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['spreadsheet_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spreadsheet_user');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
