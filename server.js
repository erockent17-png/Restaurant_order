const express = require('express');
const bodyParser = require('body-parser');
const QRCode = require('qrcode');
const path = require('path');
const sqlite3 = require('sqlite3').verbose();
const cors = require('cors');
const sharp = require('sharp');
const crypto = require('crypto');
const os = require('os');

const app = express();
const PORT = process.env.PORT || 3000;
const STAFF_USERNAME = process.env.STAFF_USERNAME || '4321';
const STAFF_PASSWORD = process.env.STAFF_PASSWORD || '4321';
const staffSessions = new Set();
const COLLECTION_WINDOW_MS = 2 * 60 * 60 * 1000;
const REFUND_DELAY_MS = 60 * 60 * 1000;
const REFUND_WINDOW_MS = 60 * 60 * 1000;
const ORDER_RETENTION_MS = 4 * 60 * 60 * 1000;

app.use(cors());
app.use(bodyParser.json());
const siteDirectory = path.join(__dirname, 'restaurant-site');
app.get('/', (req, res) => {
  res.sendFile(path.join(__dirname, 'Main.linking.website.html'));
});

app.get('/Main.linking.website.html', (req, res) => {
  res.sendFile(path.join(__dirname, 'Main.linking.website.html'));
});

app.get('/staff-login.html', (req, res) => {
  res.sendFile(path.join(__dirname, 'staff-login.html'));
});

function isStaffAuthenticated(req) {
  const token = req.headers.cookie?.match(/(?:^|; )staff_session=([^;]+)/)?.[1];
  return Boolean(token && staffSessions.has(token));
}

function requireStaff(req, res, next) {
  if (isStaffAuthenticated(req)) return next();
  if (req.path.endsWith('pawn-pickup-dashboard.html')) return res.redirect('/staff-login.html');
  res.status(401).json({ error: 'Staff authorization required' });
}

app.post('/api/staff/login', (req, res) => {
  const username = String(req.body.username || '');
  const password = String(req.body.password || '');
  if (username !== STAFF_USERNAME || password !== STAFF_PASSWORD) {
    return res.status(401).json({ error: 'Invalid username or password' });
  }
  const token = crypto.randomBytes(24).toString('hex');
  staffSessions.add(token);
  res.setHeader('Set-Cookie', `staff_session=${token}; HttpOnly; SameSite=Lax; Path=/`);
  res.json({ ok: true });
});

app.post('/api/staff/logout', (req, res) => {
  const token = req.headers.cookie?.match(/(?:^|; )staff_session=([^;]+)/)?.[1];
  if (token) staffSessions.delete(token);
  res.setHeader('Set-Cookie', 'staff_session=; HttpOnly; SameSite=Lax; Path=/; Max-Age=0');
  res.json({ ok: true });
});

app.get('/restaurant-site/pawn-pickup-dashboard.html', requireStaff, (req, res) => {
  res.sendFile(path.join(siteDirectory, 'pawn-pickup-dashboard.html'));
});

app.use('/restaurant-site', express.static(siteDirectory));
app.use(express.static(siteDirectory));

const DB_FILE = path.join(__dirname, 'data.sqlite');
const db = new sqlite3.Database(DB_FILE);

function expireOrders(callback) {
  const now = new Date().toISOString();
  db.serialize(() => {
    db.run("UPDATE orders SET status = 'expired' WHERE status = 'pending' AND expires_at <= ?", [now]);
    db.run(
      "DELETE FROM orders WHERE purge_at IS NOT NULL AND purge_at <= ?",
      [now],
      callback || (() => {})
    );
  });
}

setInterval(() => expireOrders(), 60 * 1000);

// Initialize DB
db.serialize(() => {
  db.run(
    `CREATE TABLE IF NOT EXISTS restaurants (
      id INTEGER PRIMARY KEY,
      name TEXT,
      address TEXT,
      menu_json TEXT
    )`
  );

  db.run(
    `CREATE TABLE IF NOT EXISTS orders (
      id INTEGER PRIMARY KEY,
      restaurant_id INTEGER,
      customer_name TEXT,
      phone TEXT,
      items_json TEXT,
      total REAL,
      pickup_time TEXT,
      paid INTEGER DEFAULT 0,
      created_at TEXT,
      status TEXT DEFAULT 'pending',
      expires_at TEXT,
      picked_up_at TEXT,
      refunded_at TEXT,
      purge_at TEXT
    )`
  );

  db.run("ALTER TABLE orders ADD COLUMN status TEXT DEFAULT 'pending'", () => {});
  db.run('ALTER TABLE orders ADD COLUMN expires_at TEXT', () => {});
  db.run('ALTER TABLE orders ADD COLUMN picked_up_at TEXT', () => {});
  db.run('ALTER TABLE orders ADD COLUMN refunded_at TEXT', () => {});
  db.run('ALTER TABLE orders ADD COLUMN purge_at TEXT', () => {});
  db.run("UPDATE orders SET status = 'pending' WHERE status IS NULL OR status = ''", () => {});
  db.run("UPDATE orders SET expires_at = strftime('%Y-%m-%dT%H:%M:%fZ', datetime(pickup_time, '+2 hours')) WHERE expires_at IS NULL", () => {});
  db.run("UPDATE orders SET purge_at = strftime('%Y-%m-%dT%H:%M:%fZ', datetime(pickup_time, '+4 hours')) WHERE purge_at IS NULL", () => {});

  // Seed restaurants if empty
  db.get('SELECT COUNT(*) as c FROM restaurants', (err, row) => {
    if (err) return console.error(err);
    if (row.c === 0) {
      const sample = [
        {
          name: 'Sunrise Diner',
          address: '123 Main St',
          menu: [
            { id: 1, name: 'Pancake Plate', price: 7.5 },
            { id: 2, name: 'Bacon & Eggs', price: 8.5 }
          ]
        },
        {
          name: 'Noodle Stall',
          address: 'Market Alley',
          menu: [
            { id: 1, name: 'Chicken Noodle', price: 6.0 },
            { id: 2, name: 'Beef Noodle', price: 7.0 }
          ]
        }
      ];

      const stmt = db.prepare('INSERT INTO restaurants (name,address,menu_json) VALUES (?,?,?)');
      sample.forEach(r => stmt.run(r.name, r.address, JSON.stringify(r.menu)));
      stmt.finalize();
      console.log('Seeded sample restaurants');
    }
  });

  db.get('SELECT id FROM restaurants WHERE name = ?', ['Pawn Pick Up Order'], (err, row) => {
    if (err) return console.error(err);
    if (row) return;
    const menu = [
      { id: 1, name: 'Pawn Pick Up Burger', price: 65 },
      { id: 2, name: 'Golden Chicken Box', price: 75 },
      { id: 3, name: 'Loaded Chips', price: 35 },
      { id: 4, name: 'Cold Drink', price: 20 }
    ];
    db.run(
      'INSERT INTO restaurants (name,address,menu_json) VALUES (?,?,?)',
      ['Pawn Pick Up Order', 'Gauteng Food Market', JSON.stringify(menu)],
      err => {
        if (err) console.error(err);
        else console.log('Added Pawn Pick Up Order restaurant');
      }
    );
  });
});

// APIs
app.get('/api/restaurants', (req, res) => {
  db.all('SELECT id, name, address, menu_json FROM restaurants', (err, rows) => {
    if (err) return res.status(500).json({ error: err.message });
    const result = rows.map(r => ({ id: r.id, name: r.name, address: r.address, menu: JSON.parse(r.menu_json) }));
    res.json(result);
  });
});

app.post('/api/order', (req, res) => {
  const { restaurantId, items, customerName, phone, pickupInMinutes } = req.body;
  const validItems = Array.isArray(items) && items.length > 0 && items.every(item => Number.isInteger(item.id) && Number.isInteger(item.qty) && item.qty > 0);
  if (!restaurantId || !validItems || !String(customerName).trim()) return res.status(400).json({ error: 'Choose a food item and enter your name' });

  db.get('SELECT menu_json FROM restaurants WHERE id = ?', [restaurantId], (err, row) => {
    if (err) return res.status(500).json({ error: err.message });
    if (!row) return res.status(404).json({ error: 'Restaurant not found' });

    const menu = JSON.parse(row.menu_json);
    let total = 0;
    const hasUnknownItem = items.some(it => !menu.some(item => item.id === it.id));
    if (hasUnknownItem) return res.status(400).json({ error: 'One of the selected menu items is no longer available' });

    items.forEach(it => {
      const m = menu.find(x => x.id === it.id);
      total += m.price * it.qty;
    });

    const pickupMinutes = Math.max(5, parseInt(pickupInMinutes || 20, 10) || 20);
    const pickupTimeDate = new Date(Date.now() + pickupMinutes * 60000);
    const pickupTime = pickupTimeDate.toISOString();
    const expiresAt = new Date(pickupTimeDate.getTime() + COLLECTION_WINDOW_MS).toISOString();
    const purgeAt = new Date(pickupTimeDate.getTime() + ORDER_RETENTION_MS).toISOString();
    const now = new Date().toISOString();

    const stmt = db.prepare('INSERT INTO orders (restaurant_id,customer_name,phone,items_json,total,pickup_time,created_at,status,expires_at,purge_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
    stmt.run(restaurantId, customerName, phone || '', JSON.stringify(items), total, pickupTime, now, 'pending', expiresAt, purgeAt, function (err) {
      if (err) return res.status(500).json({ error: err.message });
      const orderId = this.lastID;
      const orderUrl = `${req.protocol}://${req.get('host')}/order.html?id=${orderId}`;
      res.json({ orderId, total, pickupTime, expiresAt, orderUrl });
    });
  });
});

app.get('/api/order/:id', (req, res) => {
  const id = req.params.id;
  expireOrders(() => db.get('SELECT orders.*, restaurants.menu_json FROM orders JOIN restaurants ON restaurants.id = orders.restaurant_id WHERE orders.id = ?', [id], (err, row) => {
    if (err) return res.status(500).json({ error: err.message });
    if (!row) return res.status(404).json({ error: 'Order not found' });
    const menu = JSON.parse(row.menu_json);
    row.items = JSON.parse(row.items_json).map(item => ({
      ...item,
      name: menu.find(menuItem => menuItem.id === item.id)?.name || 'Menu item'
    }));
    delete row.menu_json;
    res.json(row);
  }));
});

app.get('/api/pickup/orders', requireStaff, (req, res) => {
  const restaurantId = Number(req.query.restaurantId);
  expireOrders(() => {
    const query = restaurantId
      ? 'SELECT * FROM orders WHERE restaurant_id = ? AND id BETWEEN 10 AND 100 ORDER BY created_at DESC'
      : 'SELECT * FROM orders WHERE id BETWEEN 10 AND 100 ORDER BY created_at DESC';
    const params = restaurantId ? [restaurantId] : [];
    db.all(query, params, (err, rows) => {
      if (err) return res.status(500).json({ error: err.message });
      res.json(rows.map(row => ({ ...row, items: JSON.parse(row.items_json) })));
    });
  });
});

app.post('/api/pickup/order/:id/complete', requireStaff, (req, res) => {
  const id = req.params.id;
  expireOrders(() => db.run(
    "UPDATE orders SET status = 'picked_up', picked_up_at = ?, purge_at = ? WHERE id = ? AND status = 'pending'",
    [new Date().toISOString(), new Date(Date.now() + ORDER_RETENTION_MS).toISOString(), id],
    function (err) {
      if (err) return res.status(500).json({ error: err.message });
      if (this.changes === 0) return res.status(409).json({ error: 'Order is expired, already collected, or not found' });
      res.json({ ok: true, status: 'picked_up' });
    }
  ));
});

app.post('/api/pickup/order/:id/refund', requireStaff, (req, res) => {
  const id = Number(req.params.id);
  if (!Number.isInteger(id) || id < 10 || id > 100) {
    return res.status(400).json({ error: 'Refund actions are available for orders 10 through 100' });
  }

  expireOrders(() => db.get(
    'SELECT id, total, status, expires_at FROM orders WHERE id = ?',
    [id],
    (err, order) => {
      if (err) return res.status(500).json({ error: err.message });
      if (!order) return res.status(404).json({ error: 'Order not found' });
      const expiry = new Date(order.expires_at).getTime();
      const canRefund = order.status === 'expired'
        && Date.now() >= expiry + REFUND_DELAY_MS
        && Date.now() < expiry + REFUND_DELAY_MS + REFUND_WINDOW_MS;
      if (!canRefund) {
        return res.status(409).json({ error: 'This order is no longer eligible for a refund' });
      }
      db.run('DELETE FROM orders WHERE id = ? AND status IN (?, ?)', [id, 'pending', 'expired'], function (deleteErr) {
        if (deleteErr) return res.status(500).json({ error: deleteErr.message });
        if (this.changes === 0) return res.status(409).json({ error: 'Order was already changed' });
        res.json({ ok: true, orderId: id, refundAmount: order.total, message: 'Order refunded and removed' });
      });
    }
  ));
});

app.post('/api/refund', (req, res) => {
  const { orderId, customerName, phone } = req.body;
  if (!orderId || !String(customerName).trim()) return res.status(400).json({ error: 'Order number and customer name are required' });
  expireOrders(() => db.get('SELECT * FROM orders WHERE id = ?', [orderId], (err, order) => {
    if (err) return res.status(500).json({ error: err.message });
    if (!order) return res.status(404).json({ error: 'Order not found' });
    if (order.customer_name.trim().toLowerCase() !== String(customerName).trim().toLowerCase() || (order.phone && order.phone !== String(phone || '').trim())) {
      return res.status(403).json({ error: 'The ordering information does not match this order' });
    }
    if (order.status !== 'expired') return res.status(409).json({ error: 'Refunds are available only for expired, uncollected orders' });
    const refundStart = new Date(new Date(order.expires_at).getTime() + REFUND_DELAY_MS);
    const refundDeadline = new Date(refundStart.getTime() + REFUND_WINDOW_MS);
    if (Date.now() < refundStart.getTime()) return res.status(409).json({ error: 'This order becomes refundable after the three-hour mark' });
    if (Date.now() > refundDeadline.getTime()) return res.status(410).json({ error: 'The four-hour refund window has closed' });
    db.run("DELETE FROM orders WHERE id = ? AND status = 'expired'", [orderId], function (deleteErr) {
      if (deleteErr) return res.status(500).json({ error: deleteErr.message });
      if (this.changes === 0) return res.status(409).json({ error: 'Order was already changed' });
      res.json({ ok: true, orderId, refundAmount: order.total, message: 'Refund recorded and order removed' });
    });
  }));
});

app.post('/api/order/:id/pay', (req, res) => {
  const id = req.params.id;
  db.run('UPDATE orders SET paid = 1 WHERE id = ?', [id], function (err) {
    if (err) return res.status(500).json({ error: err.message });
    if (this.changes === 0) return res.status(404).json({ error: 'Order not found' });
    res.json({ ok: true });
  });
});

app.get('/api/qrcode', (req, res) => {
  const url = req.query.url;
  if (!url) return res.status(400).send('Missing url');
  res.setHeader('Content-Type', 'image/png');
  QRCode.toFileStream(res, url, { type: 'png' });
});

app.get('/api/qrcode.jpg', async (req, res) => {
  const url = req.query.url;
  if (!url) return res.status(400).send('Missing url');

  try {
    const qr = await QRCode.toBuffer(url, {
      type: 'png',
      width: 900,
      margin: 2,
      errorCorrectionLevel: 'H',
      color: { dark: '#111111', light: '#ffffff' }
    });
    const logo = await sharp(path.join(siteDirectory, 'gauteng-order-logo.svg'))
      .resize(165, 165, { fit: 'contain', background: '#ffffff' })
      .png()
      .toBuffer();
    const image = await sharp(qr)
      .composite([{ input: Buffer.from('<svg width="205" height="205"><rect width="205" height="205" rx="18" fill="white"/></svg>'), gravity: 'centre' }, { input: logo, gravity: 'centre' }])
      .jpeg({ quality: 95 })
      .toBuffer();

    res.set('Content-Type', 'image/jpeg');
    if (req.query.download === '1') {
      res.set('Content-Disposition', 'attachment; filename="gauteng-order-qr.jpg"');
    }
    res.send(image);
  } catch (error) {
    console.error('QR JPG generation failed:', error);
    res.status(500).send('QR code could not be generated');
  }
});

app.listen(PORT, '0.0.0.0', () => {
  const interfaces = Object.values(os.networkInterfaces()).flat();
  const lanAddress = interfaces.find(address => address && address.family === 'IPv4' && !address.internal)?.address;
  console.log(`Server running on http://localhost:${PORT}`);
  if (lanAddress) console.log(`Other devices on your Wi-Fi can open http://${lanAddress}:${PORT}`);
});
