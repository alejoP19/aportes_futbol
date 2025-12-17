<?php
header("Content-Type: application/json");
include "../../conexion.php";

// ====== CONFIG SMTP (HOSTINGER) ======
const SMTP_HOST = "smtp.tudominio.com";   // ej: smtp.alejoideas.com
const SMTP_PORT = 587;                   // 587 TLS (recomendado) o 465 SSL
const SMTP_USER = "no-reply@tudominio.com";
const SMTP_PASS = "TU_PASSWORD_SMTP";
const FROM_EMAIL = "no-reply@tudominio.com";
const FROM_NAME  = "Aportes Fútbol";

// ====== PHPMailer (ruta donde lo subiste) ======
require_once __DIR__ . "/../../libs/PHPMailer/src/Exception.php";
require_once __DIR__ . "/../../libs/PHPMailer/src/PHPMailer.php";
require_once __DIR__ . "/../../libs/PHPMailer/src/SMTP.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ====== INPUT ======
$email = trim(strtolower($_POST['email'] ?? ''));
if ($email === '') {
  echo json_encode(["ok"=>false,"msg"=>"Email requerido"]);
  exit;
}

// (Recomendado) Responder genérico para no filtrar si existe o no
// Pero como es para admin, puedes dejarlo explícito. Yo lo dejo seguro:
$stmt = $conexion->prepare("
  SELECT id
  FROM usuarios
  WHERE LOWER(TRIM(email)) = ?
  LIMIT 1
");
$stmt->bind_param("s", $email);
$stmt->execute();
$r = $stmt->get_result();

if (!$r || $r->num_rows === 0) {
  echo json_encode(["ok"=>true,"msg"=>"Si el correo existe, se enviará el enlace."]);
  exit;
}

// ====== TOKEN ======
$token  = bin2hex(random_bytes(32));
$expira = date("Y-m-d H:i:s", time() + 3600);

// Guardar token
$u = $conexion->prepare("
  UPDATE usuarios
  SET reset_token=?, reset_expira=?
  WHERE LOWER(TRIM(email)) = ?
  LIMIT 1
");
$u->bind_param("sss", $token, $expira, $email);
$u->execute();

// Link ABSOLUTO (producción)
$link = "https://aportesfutbol.alejoideas.com/backend/auth/cambiar_password.php?token=$token";

// ====== ENVIAR EMAIL ======
try {
  $mail = new PHPMailer(true);
  $mail->CharSet = "UTF-8";

  $mail->isSMTP();
  $mail->Host = SMTP_HOST;
  $mail->SMTPAuth = true;
  $mail->Username = SMTP_USER;
  $mail->Password = SMTP_PASS;

  // TLS recomendado
  $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
  $mail->Port = SMTP_PORT;

  $mail->setFrom(FROM_EMAIL, FROM_NAME);
  $mail->addAddress($email);

  $mail->isHTML(true);
  $mail->Subject = "Recuperación de contraseña - Aportes Fútbol";

  $mail->Body = "
    <div style='font-family:Arial,sans-serif;line-height:1.5'>
      <h2>Recuperación de contraseña</h2>
      <p>Recibimos una solicitud para restablecer tu contraseña.</p>
      <p><a href='$link' style='display:inline-block;padding:12px 18px;background:#28a745;color:#fff;text-decoration:none;border-radius:6px'>
        Cambiar contraseña
      </a></p>
      <p>Si no fuiste tú, ignora este mensaje.</p>
      <p><small>Este enlace expira en 1 hora.</small></p>
    </div>
  ";

  $mail->AltBody = "Recuperación de contraseña. Abre este enlace: $link (expira en 1 hora).";

  $mail->send();

  echo json_encode(["ok"=>true,"msg"=>"Correo enviado"]);
  exit;

} catch (Exception $e) {
  // Si falla el correo, por seguridad puedes borrar token o dejarlo:
  // (yo recomiendo dejarlo para reintentar)
  echo json_encode(["ok"=>false,"msg"=>"No se pudo enviar el correo. Error SMTP."]);
  exit;
}