<?php
include __DIR__ . "/../../conexion.php";

$mes = isset($_GET['mes']) ? intval($_GET['mes']) : intval(date("n"));
$anio = isset($_GET['anio']) ? intval($_GET['anio']) : intval(date("Y"));

if ($mes < 1 || $mes > 12) $mes = intval(date("n"));
if ($anio < 1900) $anio = intval(date("Y"));

$_GET['mes'] = $mes;
$_GET['anio'] = $anio;

ob_start();
include __DIR__ . "/reporte_mes.php";
$html = ob_get_clean();

require_once __DIR__ . "/../../dompdf_2-0-3/dompdf/vendor/autoload.php";
use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set("isRemoteEnabled", true);
$options->set("isHtml5ParserEnabled", true);
$options->set("defaultFont", "DejaVu Sans");

$dompdf = new Dompdf($options);

$cssPath = __DIR__ . "/reporte_pdf.css";
$css = file_get_contents($cssPath);

$html = "<style>" . $css . "</style>" . $html;
$dompdf->loadHtml($html);
$dompdf->setPaper("A4", "portrait");
$dompdf->render();

$canvas = $dompdf->get_canvas();
$logoPath = __DIR__ . "/../../assets/img/reliquias_logo.jpg";
$logoWidth = 80;
$logoY = 105;
$meses = [
    1=>"Enero",2=>"Febrero",3=>"Marzo",4=>"Abril",5=>"Mayo",6=>"Junio",
    7=>"Julio",8=>"Agosto",9=>"Septiembre",10=>"Octubre",11=>"Noviembre",12=>"Diciembre"
];
$mesName = $meses[$mes];

if (!file_exists($logoPath)) {
    $logoPath = __DIR__ . "/../../assets/img/default_logo.png";
}

$canvas->page_script(function($pageNumber, $pageCount, $canvas, $fontMetrics) use ($logoPath, $logoWidth, $logoY, $mesName, $anio) {
    $w = $canvas->get_width();
    $h = $canvas->get_height();
    if (file_exists($logoPath)) {
        $canvas->image($logoPath, ($w - $logoWidth)/2, $logoY, $logoWidth, null);
    }
    $canvas->text($w/2 - 135, $logoY + 10, "Reporte Mensual - " . $mesName . " " . $anio, null, 14);
    $canvas->text($w - 100, $logoY - 70, date("Y-m-d"), null, 11);
    $canvas->page_text($w/2 - 50, $h - 30, "Página {PAGE_NUM} de {PAGE_COUNT}", null, 10, array(0,0,0));
    $canvas->page_text(20, $h - 30, "Aportes - Reliquias Del Fútbol", null, 9, array(0,0,0));
});

$fileName = "Reporte_{$mesName}_{$anio}.pdf";
$dompdf->stream($fileName, ["Attachment" => true]);
exit;
?>
