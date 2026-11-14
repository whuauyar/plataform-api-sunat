<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ubigeos INEI/SUNAT para los selects dependientes región → provincia → distrito.
 * Se pueblan desde public/data_ubi.txt vía UbigeoSeeder (php artisan db:seed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ubigeo_regiones', function (Blueprint $table) {
            $table->char('id', 2)->primary();      // "15"
            $table->string('nombre', 120);          // "Lima"
        });

        Schema::create('ubigeo_provincias', function (Blueprint $table) {
            $table->char('id', 4)->primary();       // "1501"
            $table->string('nombre', 120);          // "Lima"
            $table->char('region_id', 2)->index();  // "15"
        });

        Schema::create('ubigeo_distritos', function (Blueprint $table) {
            $table->char('id', 6)->primary();          // "150101" (ubigeo SUNAT)
            $table->string('nombre', 120);             // "Lima"
            $table->char('provincia_id', 4)->index();  // "1501"
            $table->char('region_id', 2)->index();     // "15"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ubigeo_distritos');
        Schema::dropIfExists('ubigeo_provincias');
        Schema::dropIfExists('ubigeo_regiones');
    }
};
