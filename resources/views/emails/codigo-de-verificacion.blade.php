{{--
    El mail del código de verificación del registro.

    Misma plantilla que la del código de recuperación, con el texto del sistema viejo y sin
    el `@import` a fonts.googleapis.com, que ningún cliente de correo aplica y que le cuenta
    a Google quién abrió el mail.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Código de verificación</title>
</head>
<body style="margin:0; padding:24px; background:#f4f4f5;
             font-family:'Roboto Condensed', Helvetica, Arial, sans-serif; color:#18181b;">
    <div style="max-width:480px; margin:0 auto; background:#ffffff; border-radius:8px; padding:32px;">
        <p style="margin:0 0 16px;">Hola {{ $nombre }},</p>

        <p style="margin:0 0 24px;">
            Para verificar tu correo, ingresá el siguiente código en la aplicación de
            Mensajes:
        </p>

        <p style="margin:0 0 24px; font-size:32px; font-weight:700; letter-spacing:6px;
                  text-align:center; color:#18181b;">{{ $codigo }}</p>

        <p style="margin:0 0 16px;">Gracias,</p>
        <p style="margin:0;">Mensajes Cadetería</p>
    </div>
</body>
</html>
