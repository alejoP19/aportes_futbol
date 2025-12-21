<?php
header("Content-Type: application/json");

require_once __DIR__ . "/../../conexion.php";
require_once __DIR__ . "/../../config/env.php";

/* =====================================================
   PHPMailer
===================================================== */
require_once __DIR__ . "/../../libs/PHPMailer/src/Exception.php";
require_once __DIR__ . "/../../libs/PHPMailer/src/PHPMailer.php";
require_once __DIR__ . "/../../libs/PHPMailer/src/SMTP.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* =====================================================
   INPUT
===================================================== */
$email = trim(strtolower($_POST['email'] ?? ''));

if ($email === '') {
    echo json_encode([
        "ok" => false,
        "msg" => "Email requerido"
    ]);
    exit;
}

/* =====================================================
   VERIFICAR USUARIO
===================================================== */
$stmt = $conexion->prepare("
    SELECT id
    FROM usuarios
    WHERE LOWER(TRIM(email)) = ?
    LIMIT 1
");
$stmt->bind_param("s", $email);
$stmt->execute();
$res = $stmt->get_result();

/*
  Respuesta genérica por seguridad
*/
if (!$res || $res->num_rows === 0) {
    echo json_encode([
        "ok" => true,
        "msg" => "Si el correo existe, recibirás un enlace para cambiar tu contraseña"
    ]);
    exit;
}

/* =====================================================
   GENERAR TOKEN
===================================================== */
$token  = bin2hex(random_bytes(32));
$expira = date("Y-m-d H:i:s", time() + 3600);

/* Guardar token */
$upd = $conexion->prepare("
    UPDATE usuarios
    SET reset_token = ?, reset_expira = ?
    WHERE LOWER(TRIM(email)) = ?
    LIMIT 1
");
$upd->bind_param("sss", $token, $expira, $email);
$upd->execute();

/* =====================================================
   LINK DE RECUPERACIÓN
===================================================== */
$link = BASE_URL . "/backend/auth/cambiar_password.php?token=" . urlencode($token);

/* =====================================================
   ENVIAR CORREO (LOCAL Y PRODUCCIÓN)
===================================================== */
try {

    $mail = new PHPMailer(true);
    $mail->CharSet = "UTF-8";

    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = SMTP_SECURE;
    $mail->Port       = SMTP_PORT;

    $mail->setFrom(FROM_EMAIL, FROM_NAME);
    $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->Subject = "Recuperación de contraseña";

    $mail->Body = "
        <div style='font-family:Arial,sans-serif;line-height:1.6'>
            <h2>Recuperación de contraseña</h2>
            <p>Solicitaste restablecer tu contraseña.</p>
            <p>
                <a href='$link'
                   style='display:inline-block;
                          padding:12px 18px;
                          background:#28a745;
                          color:#ffffff;
                          text-decoration:none;
                          border-radius:6px'>
                    Cambiar contraseña
                </a>
            </p>
            <p>Este enlace expira en <strong>1 hora</strong>.</p>
            <p>Si no fuiste tú, ignora este mensaje.</p>
        </div>
    ";

    $mail->AltBody = "Cambia tu contraseña aquí: $link (expira en 1 hora)";

    $mail->send();

    echo json_encode([
        "ok" => true,
        "msg" => "Si el correo existe, recibirás un enlace para cambiar tu contraseña"
    ]);
    exit;

} catch (Exception $e) {

    echo json_encode([
        "ok" => false,
        "msg" => "No se pudo enviar el correo. Intenta más tarde."
    ]);
    exit;
}
