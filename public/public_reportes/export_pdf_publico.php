<?php
include __DIR__ . "/../../conexion.php";

$mes  = isset($_GET['mes'])  ? intval($_GET['mes'])  : intval(date("n"));
$anio = isset($_GET['anio']) ? intval($_GET['anio']) : intval(date("Y"));

if ($mes < 1 || $mes > 12)  $mes  = intval(date("n"));
if ($anio < 1900)          $anio = intval(date("Y"));

// para que reporte_mes.php reciba los mismos valores
$_GET['mes']  = $mes;
$_GET['anio'] = $anio;

// --- renderizar HTML del reporte ---
ob_start();
include realpath(__DIR__ . "/reporte_mes_publico.php");
$html = ob_get_clean();

// --- Dompdf ---
require_once realpath(__DIR__ . "/../../dompdf_2-0-3/dompdf/autoload.inc.php");

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set("isRemoteEnabled", true);
$options->set("isHtml5ParserEnabled", true);
$options->set("defaultFont", "DejaVu Sans");

$dompdf = new Dompdf($options);

// CSS del reporte
$cssPath =realpath(__DIR__ . "/../../backend/reportes/reporte_pdf.css");
$css     = file_get_contents($cssPath);

$html = "<style>{$css}</style>" . $html;

$dompdf->loadHtml($html);
$dompdf->setPaper("A4", "portrait");
$dompdf->render();

// ============ ENCABEZADO Y PIE EN TODAS LAS PÁGINAS ============
$canvas   = $dompdf->get_canvas();
$logoPath = realpath(__DIR__ . "/../../assets/img/reliquias_logo.jpg");

if (!file_exists($logoPath)) {
    // fallback opcional
   $logoPath = realpath(__DIR__ . "/../../assets/img/reliquias_logo.jpg");
}

$logoWidth = 80;
$logoY     = 100;  // un poco más arriba para que nunca tape la tabla

$meses = [
    1=>"Enero",2=>"Febrero",3=>"Marzo",4=>"Abril",5=>"Mayo",6=>"Junio",
    7=>"Julio",8=>"Agosto",9=>"Septiembre",10=>"Octubre",11=>"Noviembre",12=>"Diciembre"
];
$mesName = $meses[$mes];

$canvas->page_script(function($pageNumber, $pageCount, $canvas, $fontMetrics)
        use ($logoPath, $logoWidth, $logoY, $mesName, $anio) {

    $w = $canvas->get_width();
    $h = $canvas->get_height();

    // Logo centrado arriba
    if (file_exists($logoPath)) {
        $canvas->image($logoPath, ($w - $logoWidth) / 2, $logoY, $logoWidth, null);
    }

    // Título del reporte
    $canvas->text($w / 2 - 140, $logoY +15, "Reporte Mensual - {$mesName} {$anio}", null, 14);


    // Fecha en esquina superior derecha
 $canvas->text($w - 120, $logoY -65, date("Y-m-d"), null, 11);


    // Pie de página
    $canvas->page_text(20,     $h - 30, "Aportes - Reliquias Del Fútbol", null, 9, [0,0,0]);
    $canvas->page_text($w-140, $h - 30, "Página {PAGE_NUM} de {PAGE_COUNT}", null, 9, [0,0,0]);
});

// Nombre del archivo descargado
$fileName = "Reporte_{$mesName}_{$anio}.pdf";
$dompdf->stream($fileName, ["Attachment" => true]);
exit;
