<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'sso_subject')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            // The identity provider's `sub` for accounts that signed in through single sign-on.
            $table->string('sso_subject')->nullable()->index()->after('password');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'sso_subject')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('sso_subject'));
        }
    }
};
