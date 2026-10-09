{{--
    El mail del código de recuperación.

    ⚠️ Es el texto del sistema viejo, con dos arreglos de redacción que estaban en el
    original: «ingrese el siguiente código de en la aplicación» —sobraba un «de»— y el
    tuteo cambiando a usted en la misma frase.

    ⚠️ Y sin la hoja de estilo de Google Fonts que tenía el viejo: un `@import` a
    fonts.googleapis.com dentro de un mail no lo aplica ningún cliente de correo —todos
    bloquean los recursos remotos del CSS— y de paso le cuenta a Google quién abrió el
    mail. Las familias se piden en el `font-family`, que sí se respeta cuando están.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Código de restauración de contraseña</title>
</head>
<body style="margin:0; padding:24px; background:#f4f4f5;
             font-family:'Roboto Condensed', Helvetica, Arial, sans-serif; color:#18181b;">
    <div style="max-width:480px; margin:0 auto; background:#ffffff; border-radius:8px; padding:32px;">
        <p style="margin:0 0 16px;">Hola {{ $nombre }},</p>

        <p style="margin:0 0 24px;">
            Para restaurar tu contraseña, ingresá el siguiente código en la aplicación de
            Mensajes:
        </p>

        <p style="margin:0 0 24px; font-size:32px; font-weight:700; letter-spacing:6px;
                  text-align:center; color:#18181b;">{{ $codigo }}</p>

        <p style="margin:0 0 16px;">Gracias,</p>
        <p style="margin:0;">Mensajes Cadetería</p>
    </div>
</body>
</html>
