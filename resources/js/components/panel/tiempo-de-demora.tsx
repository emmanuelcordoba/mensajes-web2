import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

/*
| El tiempo de demora, en la barra de arriba.
|
| Es una fila de `configuraciones` que la oficina toca varias veces por día —cuando hay
| demora, cuando se normaliza—, y por eso el sistema viejo la puso en la barra en lugar de
| hacer abrir una pantalla. Acá igual.
|
| ⚠️ No se dibuja para quien no puede cambiarlo: el servidor manda null y acá no hay nada
| que esconder con CSS. Ver HandleInertiaRequests::tiempoDeDemora().
*/

export function TiempoDeDemora() {
    const { tiempoDeDemora } = usePage().props;
    // El valor que se ve, para que el selector no vuelva al anterior mientras se guarda.
    const [elegido, setElegido] = useState(tiempoDeDemora?.valor ?? '');

    if (tiempoDeDemora === null) {
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
