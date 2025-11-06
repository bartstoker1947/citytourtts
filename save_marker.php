<?php
header('Content-Type: application/json');
include 'api/cnn.php';

// Ontvang gegevens
$name = preg_replace("/[^a-zA-Z0-9_-]/", "", $_POST['name'] ?? "marker");
$filename = $_POST['filename'] ?? ($name . "_" . date("Ymd_His") . ".png");
$imageData = $_POST['image'] ?? "";

// Map waar de PNG's komen
$upload_dir = "c:/xampp/htdocs/wp-content/uploads/icon_images/";

// Zorg dat de map bestaat
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Volledig pad naar het bestand
$file_path = $upload_dir . $filename;

// Base64-data strippen en decoderen
$imageData = str_replace('data:image/png;base64,', '', $imageData);
$imageData = str_replace(' ', '+', $imageData);
$decoded = base64_decode($imageData);

// Bestand opslaan
if (file_put_contents($file_path, $decoded) === false) {
    echo json_encode(["status" => "error", "message" => "Kon bestand niet opslaan"]);
    exit;
}

// URL opbouwen
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$url = "$protocol://$host/wp-content/uploads/icon_images/$filename";

// Klaar
echo json_encode(["status" => "ok", "file" => $url]);
?>
