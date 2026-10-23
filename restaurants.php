<?php
header('Content-Type: application/json');

$DB_HOST = '127.0.0.1';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'restaurant_distributor';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo json_encode(['error' => $mysqli->connect_error]);
  exit;
}

$res = $mysqli->query('SELECT id,name,address,menu_json FROM restaurants');
$out = [];
while ($row = $res->fetch_assoc()) {
  $row['menu'] = json_decode($row['menu_json'], true);
  unset($row['menu_json']);
  $out[] = $row;
}

echo json_encode($out);

$mysqli->close();

?>
