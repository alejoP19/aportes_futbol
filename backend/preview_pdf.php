<?php
// preview_pdf.php - previsualización rápida del PDF en HTML
include __DIR__ . "/../conexion.php";

// obtener mes y año (igual que en export_pdf.php)
$mes = isset($_GET['mes']) ? intval($_GET['mes']) : intval(date("n"));
$anio = isset($_GET['anio']) ? intval($_GET['anio']) : intval(date("Y"));

$_GET['mes'] = $mes;
$_GET['anio'] = $anio;

// capturar HTML del reporte
ob_start();
include __DIR__ . "/reporte_mes.php";
$html = ob_get_clean();

// ruta al logo
$logoPath =  '../assets/img/reliquias_logo.jpg';
$logoWidth = 80;
$logoY = 25;
$titulo = "Reporte Mensual - " . date("F Y");
$fecha = date("Y-m-d");

// Previsualización: mostramos todo en navegador
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Preview PDF - <?= htmlspecialchars($mesName ?? date("F")) ?> <?= $anio ?></title>
<style>
<?php echo file_get_contents(__DIR__ . "/reporte_pdf.css"); ?>
/* ajustes para simular encabezado de PDF */
.header-logo { text-align: center; margin-top: <?= $logoY ?>px; }
.header-logo img { width: <?= $logoWidth ?>px; }
.header-title { text-align: center; margin-top: 10px; font-size: 18px; font-weight: bold; }
.header-date { position: absolute; top: <?= $logoY + 5 ?>px; right: 20px; font-size: 12px; }
.page { border: 1px solid #ccc; padding: 20px; margin: 20px auto; width: 800px; min-height: 1100px; position: relative; }
.footer { position: absolute; bottom: 20px; width: 100%; text-align: center; font-size: 12px; }
.footer-left { position: absolute; left: 20px; bottom: 20px; font-size: 12px; }
</style>
</head>
<body>

<div class="page">
    <div class="header-logo">
        <?php if(file_exists($logoPath)): ?>
            <img src="<?= $logoPath ?>" alt="Logo">
        <?php else: ?>
            <div style="color:red;">Logo no encontrado</div>
        <?php endif; ?>
    </div>
    <div class="header-title"><?= htmlspecialchars($titulo) ?></div>
    <div class="header-date"><?= htmlspecialchars($fecha) ?></div>

    <hr style="margin: 20px 0;">

    <!-- contenido real del reporte -->
    <?= $html ?>

    <div class="footer">
        Página X de Y
    </div>
    <div class="footer-left">
        Sistema de Aportes - Fútbol
    </div>
</div>

</body>
</html>
