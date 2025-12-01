$fecha_aporte = $_POST['fecha'];
$id_aporte = $_POST['id_aporte'];

if(!puedeModificarAporte($fecha_aporte)) {
    die("No tiene permisos para eliminar este aporte.");
}

$consulta = $conexion->prepare("DELETE FROM aportes WHERE id_aporte = ? AND fecha = ?");
$consulta->bind_param("is", $id_aporte, $fecha_aporte);
$consulta->execute();
