import { Form, Link } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
| El formulario de una publicidad, compartido por el alta y la edición.
|
| ⚠️ La imagen es obligatoria al crear y opcional al editar: sin elegir una nueva, queda
| la que estaba. El panel viejo mandaba la imagen como base64 en un campo oculto que
| completaba el JavaScript, y si ese JS fallaba se creaba una publicidad SIN imagen —el
| validador pedía el campo de archivo pero el que se guardaba era el oculto—. Acá sube el
| archivo y lo valida el servidor.
*/

export type PublicidadEditable = {
    id: number;
    url: string;
    visible: boolean;
    imagen: string | null;
};

export default function PublicidadForm({
    publicidad,
    action,
    method,
}: {
    publicidad?: PublicidadEditable;
    action: string;
    method: 'post' | 'patch';
}) {
    return (
        <Form
            action={action}
            method={method}
            options={{ preserveScroll: true }}
            className="space-y-6"
        >
            {({ processing, errors }) => (
                <>
                    <Card className="space-y-4 p-6">
                        <div className="grid gap-2">
                            <Label htmlFor="url">A dónde lleva</Label>
                            <Input
                                id="url"
                                name="url"
                                type="url"
                                defaultValue={publicidad?.url}
                                required
                                placeholder="https://..."
                            />
                            <InputError message={errors.url} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="imagen">Imagen</Label>

                            {publicidad?.imagen != null && (
                                <div className="bg-muted mb-2 aspect-video max-w-sm overflow-hidden rounded-md">
                                    <img
                                        src={publicidad.imagen}
                                        alt=""
                                        className="h-full w-full object-cover"
                                    />
                                </div>
                            )}

                            <Input
                                id="imagen"
                                name="imagen"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                required={publicidad === undefined}
                                className="file:text-foreground"
                            />
                            <p className="text-muted-foreground text-xs">
                                JPG, PNG o WebP, hasta 4 MB.
                                {publicidad !== undefined &&
                                    ' Si no elegís una, queda la que está.'}
                            </p>
                            <InputError message={errors.imagen} />
                        </div>

                        <div className="flex items-center gap-2">
                            {/* El checkbox de shadcn no manda valor: el hidden es el que
                                viaja cuando queda sin tildar. */}
                            <input type="hidden" name="visible" value="0" />
                            <input
                                type="checkbox"
                                id="visible"
                                name="visible"
                                value="1"
                                defaultChecked={publicidad?.visible ?? true}
                                className="border-input size-4 rounded border"
                            />
                            <Label htmlFor="visible">Se muestra en la app</Label>
                        </div>
                    </Card>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={processing}>
                            Guardar
                        </Button>
                        <Button asChild variant="ghost">
                            <Link href="/panel/publicidad-app">Cancelar</Link>
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
