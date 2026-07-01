<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cipi_servers') || Schema::hasColumn('cipi_servers', 'ip')) {
            return;
        }

        Schema::table('cipi_servers', function (Blueprint $table) {
            $table->string('ip', 45)->nullable()->after('url');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cipi_servers') || ! Schema::hasColumn('cipi_servers', 'ip')) {
            return;
        }

        Schema::table('cipi_servers', function (Blueprint $table) {
            $table->dropColumn('ip');
        });
    }
};
