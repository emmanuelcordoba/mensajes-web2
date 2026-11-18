<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cuándo el cadete dijo por última vez dónde estaba.
     *
     * ⚠️ **La tabla guardaba el dónde y no el cuándo**, y ése era el agujero: el mapa
     * pintaba igual al cadete parado en una esquina hace tres minutos y al que dejó de
     * mandar hace 57 días. Había uno así —móvil 1754, última novedad el 21/09/2026— y
     * desde la oficina se veía trabajando. «No llega la ubicación» y «está quieto» eran
     * la misma pantalla, y sin esta columna no hay forma de distinguirlas ni de medir si
     * la app está mandando.
     *
     * ⚠️ **Arranca en null para todos**, y eso es correcto: de las posiciones que ya
     * están guardadas no sabemos la fecha, y suponerle `now()` sería inventar que son
     * frescas, que es justo el error que esto viene a arreglar. Los null se muestran como
     * viejos hasta que cada teléfono manda la primera.
     *
     * `timestampTz` para que coincida con `timestampsTz()` del resto de la tabla.
     */
    public function up(): void
    {
        Schema::table('cadetes', function (Blueprint $tabla): void {
            $tabla->timestampTz('ubicacion_actualizada_en')->nullable()->after('ubicacion_lon');
        });
    }

    public function down(): void
    {
        Schema::table('cadetes', function (Blueprint $tabla): void {
            $tabla->dropColumn('ubicacion_actualizada_en');
        });
    }
};
