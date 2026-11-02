# El corte

Cómo se pasan los datos del sistema viejo —Laravel 6 sobre MongoDB— a éste. Es el mismo
procedimiento para el ensayo en local y para el día del corte; donde cambian, está dicho.

Medido entero el **2026-11-01**, sobre el backup de ese día: **46 minutos** desde el
backup hasta tener el panel andando con los datos.

Se borra con el corte, junto con `app/Etl`, `config/etl.php` y el servicio `mongo` de
`compose.yaml`.

---

## 0. Antes de empezar: el backup y el tar se toman JUNTOS

No es prolijidad. Las imágenes de las postulaciones **no están en ningún backup de la
base** —son archivos en el servidor, DATA-9— y la base guarda la *ruta* a cada una. Si el
tar es anterior al backup, toda postulación creada en el medio apunta a un archivo que el
tar no tiene, y **la carga se detiene a mitad de camino**.

Pasó en este ensayo: el tar era del 24/09 y el backup del 01/11. De las 1.725 rutas que la
base reclamaba faltaban **39**, de 13 postulaciones, todas creadas después del tar.

En producción, los dos seguidos:

```bash
php artisan db:backup
tar czf postulaciones-$(date +%F).tgz -C storage/app public/postulaciones
```

---

## 1. Restaurar el backup en el Mongo del ensayo

⚠️ **Nunca contra producción.** El servicio está en el perfil `etl`, así que se levanta a
mano:

```bash
sail --profile etl up -d mongo
```

El backup es un *archive* de `mongodump` comprimido: no hace falta —ni sirve—
descomprimirlo. El `drop` previo importa, o quedan colecciones de la copia anterior que el
backup nuevo ya no trae:

```bash
docker compose exec -T mongo mongosh --quiet \
    --eval 'db.getSiblingDB("mensajes").dropDatabase()'

docker compose exec -T mongo mongorestore --archive --gzip \
    --nsInclude='mensajes.*' --numParallelCollections=4 \
    < mensajes-2026-11-01-190303.gz
```

**Medido:** 5.479.640 documentos, 0 fallidos, **5m45s**.

---

## 2. Desempaquetar las imágenes

⚠️ **Sólo las rutas que la base reclama.** El tar trae todo lo que hay en el servidor,
incluidas las imágenes de postulaciones ya borradas: en el del 01/11 sobraban **63
archivos sin dueño, y son DNI de personas**. No se desempaquetan.

Las entradas cuelgan de `public/`, así que se quita ese nivel:

```bash
tar xzf postulaciones-2026-11-01.tgz -C storage/app/etl-origen \
    --strip-components=1 --no-same-owner -T lista-de-miembros.txt
```

La lista sale de la base: las cuatro columnas de imagen de `postulaciones_cadetes`
—`foto`, `dni_frente`, `dni_dorso`, `boleta_de_servicio`— cuando el valor **no** empieza
con `data:`. Los que empiezan así son base64 adentro del documento y no necesitan archivo.

**Medido:** 1.725 rutas reclamadas y 1.251 valores en base64; extraer de un tar de 4,5 GB
tarda **4m48s**, y queda 1,4 GB en disco.

Antes de seguir, comprobar que no falte ninguna: si falta una sola, la carga frena.

---

## 3. El informe, que es la puerta

```bash
sail artisan etl:migrar
```

No escribe nada. Imprime las 18 colecciones con sus cuentas y después dos listas:

- **«datos que el esquema rechaza»** → bloqueantes. Con uno solo, la carga no corre.
- **«datos que el esquema acepta y están mal»** → advertencias. Cargan igual.

⚠️ **Los bloqueantes se corrigen en el SISTEMA VIEJO**, en Corrección de datos. El corte
vuelve a cargar con el destino vacío y el ensayo se repite hasta el día del corte: lo que
se corrija de este lado se borra en la carga siguiente. Lo único que sobrevive es Mongo.

**Medido:** cero bloqueantes, 17 segundos. Advertencias: 5 fechas de nacimiento imposibles
en cadetes y 10 en postulaciones.

---

## 4. La carga

```bash
sail artisan etl:migrar --aplicar --reiniciar
```

`--reiniciar` vacía las 18 tablas, el mapa de ids y las imágenes ya escritas, y **pide
confirmación**. La primera vez el destino está vacío y la opción no hace falta.

⚠️ **La confirmación necesita una terminal de verdad.** `confirm()` de Laravel Prompts lee
la terminal en crudo: por una tubería, o sin TTY, devuelve el valor por defecto —que es
«no»— y el comando contesta «No se modificó nada» sin hacer nada. Si hay que correrlo
desde un script, se vacía el destino aparte y se usa `--aplicar` solo, que no pregunta.

Carga en el orden que dictan las claves foráneas, compara filas escritas contra documentos
en origen y **frena en la primera que no coincida**. Al final cierra el nudo circular
`users ↔ clientes` y mueve las dos secuencias.

⚠️ **Las secuencias no son un detalle.** `pedidos_numero_seq` y `clientes_numero_seq`
quedan en el máximo migrado. Sin ese paso, el primer pedido que cargue la oficina después
del corte choca con uno que ya existe.

**Medido: 36 minutos**, todas las tablas coincidiendo. Las dos que mandan:

| tabla | filas | tiempo |
|---|---:|---:|
| `logs_estados_pedidos` | 3.677.210 | 20m52s |
| `pedidos` | 969.539 | 12m10s |
| `actividades_cadetes` | 399.865 | 1m04s |
| `postulacion_documentos` | 2.976 | 2m15s |
| todo lo demás | 39.464 | menos de 1m |

Al terminar quedaron 2 usuarios con su cliente restringido, el próximo pedido es el
957.326 y el próximo cliente el 1.000.270.

---

## 5. Las imágenes al disco definitivo

```bash
sail artisan etl:archivos --aplicar     # días antes, sube todo
sail artisan etl:archivos --verificar   # en la ventana, comprueba
```

⚠️ **En local no hace nada, y está bien.** `ETL_DISCO` y `ETL_DISCO_CARGA` son los dos
`local`, así que origen y destino son el mismo disco y el comando lo dice: las imágenes ya
quedaron escritas por la carga. En producción el destino es el bucket y son dos pasos:
subir los 4.509 archivos con anticipación —fuera de la ventana— y dentro de la ventana
subir sólo lo que cambió y verificar. `--verificar` sale con código 1 si falta un archivo.

**Medido en local:** 4.492 archivos, 2,3 GB, escritos durante la carga.

---

## 6. Comprobar que quedó bien

Cuatro consultas que valen más que mirar totales, porque un total puede coincidir con las
relaciones rotas:

```sql
select count(*) from pedidos p left join clientes c on c.id = p.cliente_id
    where p.cliente_id is not null and c.id is null;
select count(*) from pedidos p left join cadetes k on k.id = p.cadete_id
    where p.cadete_id is not null and k.id is null;
select count(*) from cadetes k left join users u on u.id = k.user_id where u.id is null;
select count(*) from postulacion_documentos d
    left join postulaciones p on p.id = d.postulacion_id where p.id is null;
```

Las cuatro tienen que dar **0**. Y las secuencias, que el último valor sea el máximo de la
tabla:

```sql
select last_value from pedidos_numero_seq;   select max(numero) from pedidos;
select last_value from clientes_numero_seq;  select max(numero) from clientes;
```

**Medido:** las cuatro en 0, las dos secuencias exactas.

---

## 7. Entrar, para probar las aplicaciones

⚠️ **Las contraseñas migran.** Viajan como hash de bcrypt desde Mongo, así que cada usuario
real conserva la suya —y nadie más la sabe—. Para entrar en local hay dos caminos.

Una cuenta aparte, que no toca el legajo de nadie:

```php
$rol = App\Models\Rol::query()->where('rol', 'admin')->firstOrFail();

App\Models\User::query()->updateOrCreate(
    ['email' => 'local@mensajes.test'],
    ['name' => 'Admin local', 'rol_id' => $rol->id,
     'password' => bcrypt('la-que-quieras'), 'email_verified_at' => now()],
);
```

O una contraseña conocida sobre un usuario migrado, que es lo que sirve para probar como
un comercio concreto, con sus pedidos y sus restricciones:

```php
App\Models\User::query()->where('email', 'el@que.sea')
    ->update(['password' => bcrypt('la-que-quieras')]);
```

**Lo que hay para probar, migrado:** 1 admin, 7 empleados, 2 comercios restringidos, 293
cadetes y 7.311 cuentas de la app de clientes.

⚠️ Después de entrar, Fortify manda a `/panel` —`config/fortify.php`—, no a la raíz, que
es la landing.

---

## Las trampas que encontró el ensayo

Vale la pena leerlas antes del corte: las tres aparecieron corriéndolo, no leyéndolo.

**Una variable declarada y vacía en `.env` no toma el valor por defecto.** `env('X', 'd')`
devuelve la cadena vacía cuando la clave existe. `ETL_ARCHIVOS=` vacía dejaba la ruta en
`/postulaciones/…`, desde la raíz del sistema de archivos, y la carga frenaba diciendo que
faltaba un archivo **que sí estaba**. `config/etl.php` ahora usa `?:`. Antes había pasado
lo mismo con `ETL_ORIGEN`, que el ejemplo declaraba con un nombre que el config no leía.

**El tar de imágenes envejece.** Ver el paso 0.

**Si se regenera Wayfinder a mano, con `--with-form`.** El plugin de Vite usa
`formVariants: true`; `php artisan wayfinder:generate` sin esa opción reescribe los
módulos sin las variantes de formulario, y las pantallas que usan `store.form()` —la de
ingreso, entre otras— quedan en blanco con `store.form is not a function`. Pasó en este
ensayo al limpiar rutas borradas.
