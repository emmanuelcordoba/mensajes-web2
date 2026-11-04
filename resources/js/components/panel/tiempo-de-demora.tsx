import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

/*
| El tiempo de demora, en la barra de arriba.
|
| Es una fila de `configuraciones` que la oficina toca varias veces por día —cuando hay
| demora, cuando se normaliza—, y por eso el sistema viejo la puso en la barra en lugar de
| hacer abrir una pantalla. Acá igual.
|
| ⚠️ **SE VE SÓLO EN LA PORTADA DEL PANEL**, y es lo que hace el sistema viejo: la barra
| de allá envuelve el selector en un `@isset($tiempo_de_demora)`, y la única pantalla que
| define esa variable es `PanelController`. Acá lo mismo, por el mismo camino: la prop la
| manda esa pantalla y no las props compartidas, así que en el resto del panel llega
| `undefined` y no hay nada que dibujar. Ver InicioController::tiempoDeDemora().
|
| ⚠️ No se dibuja tampoco para quien no puede cambiarlo: el servidor manda null y acá no
| hay nada que esconder con CSS.
*/

export function TiempoDeDemora() {
    const { tiempoDeDemora } = usePage().props;
    // El valor que se ve, para que el selector no vuelva al anterior mientras se guarda.
    const [elegido, setElegido] = useState(tiempoDeDemora?.valor ?? '');

    // `undefined` es «esta pantalla no lo lleva» y null es «no te corresponde». Las dos
    // terminan igual acá, pero son dos cosas distintas y conviene que se lean distinto.
    if (tiempoDeDemora === null || tiempoDeDemora === undefined) {
        return null;
    }

    return (
        <label className="flex items-center gap-2 text-sm">
            <span className="text-muted-foreground hidden sm:inline">Demora</span>
            <select
                value={elegido}
                onChange={(e) => {
                    const valor = e.target.value;
                    setElegido(valor);

                    router.patch(
                        `/panel/configuraciones/${tiempoDeDemora.id}`,
                        { valor },
                        { preserveScroll: true, preserveState: true },
                    );
                }}
                className="border-input bg-background h-8 rounded-md border px-2 text-sm"
                aria-label="Tiempo de demora"
            >
                {/* Vacío es «sin indicar», que es un estado válido y no un error. */}
                <option value="">Sin indicar</option>
                {tiempoDeDemora.opciones.map((opcion) => (
                    <option key={opcion} value={opcion}>
                        {opcion}
                    </option>
                ))}
            </select>
        </label>
    );
}
