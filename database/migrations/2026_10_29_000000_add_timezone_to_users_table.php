<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'timezone')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            // IANA zone the panel shows every time in for this account (Settings → Profile); null = UTC.
            $table->string('timezone', 64)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'timezone')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('timezone'));
        }
    }
};
