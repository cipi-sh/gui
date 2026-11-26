<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The machine's own name (`hostname`: vmi12334), next to the name the panel
 * gives the server (production). It is what a notification says unless the
 * server was told otherwise (`cipi notification server-name`), so the panel
 * shows it wherever it lists servers. Read from the server's status.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cipi_servers') || Schema::hasColumn('cipi_servers', 'hostname')) {
            return;
        }

        Schema::table('cipi_servers', function (Blueprint $table) {
            $table->string('hostname', 253)->nullable()->after('ip');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cipi_servers') || ! Schema::hasColumn('cipi_servers', 'hostname')) {
            return;
        }

        Schema::table('cipi_servers', function (Blueprint $table) {
            $table->dropColumn('hostname');
        });
    }
};
