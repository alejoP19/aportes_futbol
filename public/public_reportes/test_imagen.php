<?php
$path = __DIR__ . "/../../assets/img/reliquias_logo.jpg";

if (!file_exists($path)) {
    echo "NO EXISTE";
    exit;
}

$info = getimagesize($path);
var_dump($info);

echo "<hr>";
echo "mime: " . ($info['mime'] ?? 'desconocido');
?>
