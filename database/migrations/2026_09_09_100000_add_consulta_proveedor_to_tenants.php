<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Proveedor de consulta RUC/DNI que usa esta empresa: 'json' (api.json.pe)
            // o 'migo' (api.migo.pe). El token vive en `consulta_token`.
            $table->string('consulta_proveedor', 10)->default('json')->after('consulta_token');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('consulta_proveedor');
        });
    }
};
