<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Favicon propio por empresa (panel del cliente): icono de pestaña que se muestra
 * cuando esa empresa es la activa. El logo del sidebar del cliente reutiliza la
 * columna existente `logo_path`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('favicon_path')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('favicon_path');
        });
    }
};
