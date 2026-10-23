<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond($data, $status = 200) {
  http_response_code($status);
  echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function readJsonBody() {
  $body = json_decode(file_get_contents('php://input'), true);
  return is_array($body) ? $body : [];
}

function utcDate($timestamp = null) {
  return gmdate('Y-m-d H:i:s', $timestamp ?? time());
}

function isoDate($value) {
  if (!$value) return null;
  return gmdate('Y-m-d\TH:i:s\Z', strtotime($value . ' UTC'));
}

function expireOrders($db) {
  $now = utcDate();
  $stmt = $db->prepare("UPDATE orders SET status = 'expired' WHERE status = 'pending' AND expires_at <= ?");
  $stmt->bind_param('s', $now);
  $stmt->execute();
  $stmt->close();

  $stmt = $db->prepare('DELETE FROM orders WHERE purge_at <= ?');
  $stmt->bind_param('s', $now);
  $stmt->execute();
  $stmt->close();
}

function staffOnly() {
  if (empty($_SESSION['restaurant_staff'])) {
    respond(['error' => 'Staff authorization required'], 401);
  }
}

function orderResponse($row) {
  $row['total'] = (float)$row['total'];
  $row['paid'] = (int)$row['paid'];
  foreach (['pickup_time', 'created_at', 'expires_at', 'picked_up_at', 'refunded_at', 'purge_at'] as $field) {
    if (array_key_exists($field, $row)) $row[$field] = isoDate($row[$field]);
  }
  return $row;
}

function orderPageUrl($orderId) {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api.php')), '/');
  if ($base === '') $base = '';
  return $scheme . '://' . $host . $base . '/restaurant-site/order.html?id=' . (int)$orderId;
}

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
session_start();

try {
  $db = require __DIR__ . '/php/db.php';
} catch (Throwable $error) {
  error_log('Restaurant database connection failed: ' . $error->getMessage());
  respond(['error' => 'Database connection failed. Check MySQL and php/db.php settings.'], 500);
}

$method = $_SERVER['REQUEST_METHOD'];
$path = trim($_GET['path'] ?? '', '/');
$parts = $path === '' ? [] : explode('/', $path);
expireOrders($db);

if ($path === 'restaurants' && $method === 'GET') {
  $result = $db->query('SELECT id, name, address, menu_json FROM restaurants ORDER BY id');
  $restaurants = [];
  while ($row = $result->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['menu'] = json_decode($row['menu_json'], true) ?: [];
    unset($row['menu_json']);
    $restaurants[] = $row;
  }
  respond($restaurants);
}

if ($path === 'order' && $method === 'POST') {
  $body = readJsonBody();
  $restaurantId = (int)($body['restaurantId'] ?? 0);
  $customerName = trim((string)($body['customerName'] ?? ''));
  $phone = trim((string)($body['phone'] ?? ''));
  $items = $body['items'] ?? null;
  if ($restaurantId < 1 || $customerName === '' || !is_array($items) || count($items) < 1) {
    respond(['error' => 'Choose a food item and enter your name'], 400);
  }

  $stmt = $db->prepare('SELECT menu_json FROM restaurants WHERE id = ?');
  $stmt->bind_param('i', $restaurantId);
  $stmt->execute();
  $restaurant = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$restaurant) respond(['error' => 'Restaurant not found'], 404);

  $menu = json_decode($restaurant['menu_json'], true) ?: [];
  $cleanItems = [];
  $total = 0.0;
  foreach ($items as $item) {
    $itemId = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT);
    $quantity = filter_var($item['qty'] ?? null, FILTER_VALIDATE_INT);
    if (!$itemId || !$quantity || $quantity < 1 || $quantity > 99) {
      respond(['error' => 'Invalid order item or quantity'], 400);
    }
    $menuItem = null;
    foreach ($menu as $candidate) {
      if ((int)$candidate['id'] === $itemId) { $menuItem = $candidate; break; }
    }
    if (!$menuItem) respond(['error' => 'One of the selected menu items is no longer available'], 400);
    $total += (float)$menuItem['price'] * $quantity;
    $cleanItems[] = ['id' => $itemId, 'qty' => $quantity];
  }

  $pickupMinutes = max(5, min(240, (int)($body['pickupInMinutes'] ?? 20)));
  $pickupTimestamp = time() + ($pickupMinutes * 60);
  $pickupTime = utcDate($pickupTimestamp);
  $createdAt = utcDate();
  $expiresAt = utcDate($pickupTimestamp + 2 * 60 * 60);
  $purgeAt = utcDate($pickupTimestamp + 4 * 60 * 60);
  $itemsJson = json_encode($cleanItems);
  $status = 'pending';
  $stmt = $db->prepare('INSERT INTO orders (restaurant_id, customer_name, phone, items_json, total, pickup_time, created_at, status, expires_at, purge_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
  $stmt->bind_param('isssdsssss', $restaurantId, $customerName, $phone, $itemsJson, $total, $pickupTime, $createdAt, $status, $expiresAt, $purgeAt);
  $stmt->execute();
  $orderId = $stmt->insert_id;
  $stmt->close();
  respond(['orderId' => $orderId, 'total' => $total, 'pickupTime' => isoDate($pickupTime), 'expiresAt' => isoDate($expiresAt), 'orderUrl' => orderPageUrl($orderId)], 201);
}

if (preg_match('#^order/(\d+)$#', $path, $match) && $method === 'GET') {
  $id = (int)$match[1];
  $stmt = $db->prepare('SELECT o.*, r.menu_json FROM orders o JOIN restaurants r ON r.id = o.restaurant_id WHERE o.id = ?');
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$row) respond(['error' => 'Order not found'], 404);
  $menu = json_decode($row['menu_json'], true) ?: [];
  $row['items'] = json_decode($row['items_json'], true) ?: [];
  foreach ($row['items'] as &$item) {
    foreach ($menu as $menuItem) {
      if ((int)$menuItem['id'] === (int)$item['id']) { $item['name'] = $menuItem['name']; break; }
    }
  }
  unset($row['menu_json']);
  respond(orderResponse($row));
}

if (preg_match('#^order/(\d+)/pay$#', $path, $match) && $method === 'POST') {
  $id = (int)$match[1];
  $stmt = $db->prepare('UPDATE orders SET paid = 1 WHERE id = ?');
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $changed = $stmt->affected_rows;
  $stmt->close();
  $check = $db->prepare('SELECT id FROM orders WHERE id = ?');
  $check->bind_param('i', $id);
  $check->execute();
  $exists = (bool)$check->get_result()->fetch_assoc();
  $check->close();
  if (!$exists) respond(['error' => 'Order not found'], 404);
  respond(['ok' => true, 'changed' => $changed > 0]);
}

if ($path === 'refund' && $method === 'POST') {
  $body = readJsonBody();
  $id = (int)($body['orderId'] ?? 0);
  $name = trim((string)($body['customerName'] ?? ''));
  $phone = trim((string)($body['phone'] ?? ''));
  if ($id < 1 || $name === '') respond(['error' => 'Order number and customer name are required'], 400);
  $stmt = $db->prepare('SELECT id, customer_name, phone, total, status, expires_at FROM orders WHERE id = ?');
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $order = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$order) respond(['error' => 'Order not found'], 404);
  if (mb_strtolower($order['customer_name']) !== mb_strtolower($name) || ($order['phone'] !== '' && $order['phone'] !== $phone)) {
    respond(['error' => 'The ordering information does not match this order'], 403);
  }
  $expiry = strtotime($order['expires_at'] . ' UTC');
  if ($order['status'] !== 'expired') respond(['error' => 'Refunds are available only for expired, uncollected orders'], 409);
  if (time() < $expiry + 3600) respond(['error' => 'This order becomes refundable after the three-hour mark'], 409);
  if (time() >= $expiry + 7200) respond(['error' => 'The four-hour refund window has closed'], 410);
  $stmt = $db->prepare('DELETE FROM orders WHERE id = ? AND status = ?');
  $expired = 'expired';
  $stmt->bind_param('is', $id, $expired);
  $stmt->execute();
  $stmt->close();
  respond(['ok' => true, 'orderId' => $id, 'refundAmount' => (float)$order['total'], 'message' => 'Refund recorded and order removed']);
}

if ($path === 'staff/login' && $method === 'POST') {
  $body = readJsonBody();
  $username = getenv('STAFF_USERNAME') ?: '4321';
  $password = getenv('STAFF_PASSWORD') ?: '4321';
  if (!hash_equals($username, (string)($body['username'] ?? '')) || !hash_equals($password, (string)($body['password'] ?? ''))) {
    respond(['error' => 'Invalid username or password'], 401);
  }
  session_regenerate_id(true);
  $_SESSION['restaurant_staff'] = true;
  respond(['ok' => true]);
}

if ($path === 'staff/logout' && $method === 'POST') {
  $_SESSION = [];
  session_destroy();
  respond(['ok' => true]);
}

if ($path === 'pickup/orders' && $method === 'GET') {
  staffOnly();
  $restaurantId = (int)($_GET['restaurantId'] ?? 0);
  if ($restaurantId > 0) {
    $stmt = $db->prepare('SELECT * FROM orders WHERE restaurant_id = ? AND id BETWEEN 10 AND 100 ORDER BY created_at DESC');
    $stmt->bind_param('i', $restaurantId);
  } else {
    $stmt = $db->prepare('SELECT * FROM orders WHERE id BETWEEN 10 AND 100 ORDER BY created_at DESC');
  }
  $stmt->execute();
  $result = $stmt->get_result();
  $orders = [];
  while ($row = $result->fetch_assoc()) {
    $row['items'] = json_decode($row['items_json'], true) ?: [];
    $orders[] = orderResponse($row);
  }
  $stmt->close();
  respond($orders);
}

if (preg_match('#^pickup/order/(\d+)/complete$#', $path, $match) && $method === 'POST') {
  staffOnly();
  $id = (int)$match[1];
  $pickedUpAt = utcDate();
  $purgeAt = utcDate(time() + 4 * 60 * 60);
  $pending = 'pending';
  $stmt = $db->prepare("UPDATE orders SET status = 'picked_up', picked_up_at = ?, purge_at = ? WHERE id = ? AND status = ?");
  $stmt->bind_param('ssis', $pickedUpAt, $purgeAt, $id, $pending);
  $stmt->execute();
  $changed = $stmt->affected_rows;
  $stmt->close();
  if (!$changed) respond(['error' => 'Order is expired, already collected, or not found'], 409);
  respond(['ok' => true, 'status' => 'picked_up']);
}

if (preg_match('#^pickup/order/(\d+)/refund$#', $path, $match) && $method === 'POST') {
  staffOnly();
  $id = (int)$match[1];
  if ($id < 10 || $id > 100) respond(['error' => 'Refund actions are available for orders 10 through 100'], 400);
  $stmt = $db->prepare('SELECT total, status, expires_at FROM orders WHERE id = ?');
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $order = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$order) respond(['error' => 'Order not found'], 404);
  $expiry = strtotime($order['expires_at'] . ' UTC');
  if ($order['status'] !== 'expired' || time() < $expiry + 3600 || time() >= $expiry + 7200) {
    respond(['error' => 'This order is no longer eligible for a refund'], 409);
  }
  $stmt = $db->prepare('DELETE FROM orders WHERE id = ? AND status = ?');
  $expired = 'expired';
  $stmt->bind_param('is', $id, $expired);
  $stmt->execute();
  $changed = $stmt->affected_rows;
  $stmt->close();
  if (!$changed) respond(['error' => 'Order was already changed'], 409);
  respond(['ok' => true, 'orderId' => $id, 'refundAmount' => (float)$order['total'], 'message' => 'Order refunded and removed']);
}

if (in_array($path, ['qrcode', 'qrcode.jpg'], true) && $method === 'GET') {
  $url = (string)($_GET['url'] ?? '');
  if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
    respond(['error' => 'A valid HTTP or HTTPS URL is required'], 400);
  }
  $query = http_build_query(['size' => '900x900', 'format' => 'png', 'ecc' => 'H', 'data' => $url]);
  header('Content-Type: image/png');
  header('Location: https://api.qrserver.com/v1/create-qr-code/?' . $query, true, 302);
  exit;
}

respond(['error' => 'API route not found'], 404);