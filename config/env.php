
<?php
/**
 * ==========================================
 * ENTORNO
 * ==========================================
 * true  = desarrollo (localhost)
 * false = producción (Hostinger)
 */
define("APP_DEV", true);


/**
 * ==========================================
 * URL BASE
 * ==========================================
 */
if (APP_DEV) {
    define("BASE_URL", "http://localhost/aportes_futbol");
} else {
    define("BASE_URL", "https://aportesfutbol.alejoideas.com");
}


/**
 * =========================================
 * SMTP
 * ==========================================
 */
if (APP_DEV) {

    // Gmail (LOCAL)
    define("SMTP_HOST", "smtp.gmail.com");
    define("SMTP_PORT", 587);
    define("SMTP_USER", "alberth.hass77@gmail.com");      // 👈 TU GMAIL
    define("SMTP_PASS", "zros gwhi fbrw exbu");         // 👈 APP PASSWORD
  

    define("SMTP_SECURE", "tls");

} else {

    // Hostinger (PRODUCCIÓN)
    define("SMTP_HOST", "smtp.hostinger.com");
    define("SMTP_PORT", 587);
    define("SMTP_USER", "aportesfutbol25@alejoideas.com");
    define("SMTP_PASS", "********");
    define("SMTP_SECURE", "tls");
}


/**
 * ==========================================
 * REMITENTE
 * ==========================================
 */
define("FROM_EMAIL", SMTP_USER);
define("FROM_NAME", "Aportes Fútbol");

   
    


  