# Gauteng Order

Restaurant ordering demo with a Node.js/SQLite backend and an alternative Apache/PHP/MySQL setup. The app does not need Visual Studio Code to run.

## Run with Node.js

Install Node.js LTS, then open a terminal in this folder and run:

```sh
npm install
npm start
```

Open `http://localhost:3000`. On Windows, double-click `start-restaurant.bat`. On macOS or Linux, run `sh start-restaurant.sh` from this folder.

The server prints a local-network URL when one is available. Open that URL on another device connected to the same Wi-Fi. If it cannot connect, allow Node.js through the computer's private-network firewall. Do not expose the demo server to the public internet.

## Run with XAMPP

Use the PHP/MySQL version when you want Apache and phpMyAdmin:

1. Copy the contents of this project folder directly into XAMPP's `htdocs` directory so `/api` and `/restaurant-site` resolve from the site root.
2. Start Apache and MySQL in XAMPP.
3. Import `php/schema.sql` using `http://localhost/phpmyadmin`.
4. Open `http://localhost/`.

The API is routed by the root `.htaccess`, and the PHP database settings are in `php/db.php`. XAMPP must have `mod_rewrite` enabled and `.htaccess` overrides allowed. Full setup and LAN instructions are in [XAMPP_MANUAL.md](XAMPP_MANUAL.md).

## Demo behavior and access

- Checkout simulates a payment; it does not charge a real card.
- QR images use an online QR image service.
- The pickup dashboard demo login is username `4321`, password `4321`. Change these before using the dashboard beyond a private demo. In Node, set `STAFF_USERNAME` and `STAFF_PASSWORD`; in XAMPP, set the same PHP environment variables or update the defaults in `api.php`.
- Orders can be collected within two hours after the requested pickup time. The refund window follows, and order records are automatically removed after four hours while the server is active (Node) or when API requests reach the PHP backend (XAMPP).
- The Node version stores data in `data.sqlite`. The XAMPP version stores data in MySQL database `restaurant_distributor`.


4321