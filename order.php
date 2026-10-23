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

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
  // Create order
  $data = json_decode(file_get_contents('php://input'), true);
  $restaurantId = intval($data['restaurantId'] ?? 0);
  $items = $data['items'] ?? [];
  $customerName = $mysqli->real_escape_string($data['customerName'] ?? '');
  $phone = $mysqli->real_escape_string($data['phone'] ?? '');
  $pickupInMinutes = intval($data['pickupInMinutes'] ?? 20);

  if ($restaurantId <= 0 || !$customerName) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing fields']);
    exit;
  }

  // fetch menu for pricing
  $stmt = $mysqli->prepare('SELECT menu_json FROM restaurants WHERE id = ?');
  $stmt->bind_param('i', $restaurantId);
  $stmt->execute();
  $stmt->bind_result($menu_json);
  if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'Restaurant not found']);
    exit;
  }
  $stmt->close();

  $menu = json_decode($menu_json, true);
  $total = 0.0;
  foreach ($items as $it) {
    foreach ($menu as $m) {
      if ($m['id'] == $it['id']) { $qty = intval($it['qty'] ?? 1); $total += $m['price'] * $qty; break; }
    }
  }

  $pickupTime = date('Y-m-d H:i:s', time() + $pickupInMinutes * 60);
  $items_json = $mysqli->real_escape_string(json_encode($items));

  $ins = $mysqli->prepare('INSERT INTO orders (restaurant_id,customer_name,phone,items_json,total,pickup_time) VALUES (?,?,?,?,?,?)');
  $ins->bind_param('isssds', $restaurantId, $customerName, $phone, $items_json, $total, $pickupTime);
  if (!$ins->execute()) {
    http_response_code(500);
    echo json_encode(['error' => $ins->error]);
    exit;
  }
  $orderId = $ins->insert_id;
  $ins->close();

  $orderUrl = 'http://localhost/restaurant-site/order.html?id=' . $orderId;
  echo json_encode(['orderId' => $orderId, 'total' => floatval($total), 'pickupTime' => $pickupTime, 'orderUrl' => $orderUrl]);
  exit;
}

// GET order by id
if ($method === 'GET') {
  $id = intval($_GET['id'] ?? 0);
  if ($id <= 0) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
  $stmt = $mysqli->prepare('SELECT * FROM orders WHERE id = ?');
  $stmt->bind_param('i',$id);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc();
  if (!$row) { http_response_code(404); echo json_encode(['error'=>'Order not found']); exit; }
  $row['items'] = json_decode($row['items_json'], true);
  echo json_encode($row);
  exit;
}

// PATCH/POST pay action
if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'pay') {
  $id = intval($_GET['id'] ?? 0);
  if ($id <= 0) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }
  $stmt = $mysqli->prepare('UPDATE orders SET paid = 1 WHERE id = ?');
  $stmt->bind_param('i',$id);
  $stmt->execute();
  echo json_encode(['ok'=>true]);
  exit;
}

http_response_code(405);
echo json_encode(['error'=>'Unsupported method']);

$mysqli->close();

?>
