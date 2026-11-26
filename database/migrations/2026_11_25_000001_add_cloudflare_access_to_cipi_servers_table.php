<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A server's API behind Cloudflare Access (cipi zt access enable --api, 4.8.0):
 * the service token the panel sends with every call, its secret encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cipi_servers') || Schema::hasColumn('cipi_servers', 'cf_access_client_id')) {
            return;
        }

        Schema::table('cipi_servers', function (Blueprint $table) {
            $table->string('cf_access_client_id', 128)->nullable()->after('token');
            $table->text('cf_access_client_secret')->nullable()->after('cf_access_client_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cipi_servers') || ! Schema::hasColumn('cipi_servers', 'cf_access_client_id')) {
            return;
        }

        Schema::table('cipi_servers', function (Blueprint $table) {
            $table->dropColumn(['cf_access_client_id', 'cf_access_client_secret']);
        });
    }
};
