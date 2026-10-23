# XAMPP installation

This setup runs the existing website with Apache, PHP, and MySQL. Visual Studio Code is not required after the project files are installed. The PHP API is in `api.php`, database settings are in `php/db.php`, and the importable database is `php/schema.sql`.

## Requirements

- XAMPP with Apache, MySQL, PHP MySQLi, and `mod_rewrite` enabled
- A browser on the computer or a device connected to the same local network

## Install on Windows

1. Copy the contents of this project folder directly into XAMPP's `htdocs` folder, for example `C:\xampp\htdocs\`. Keep the files and folders together, including `.htaccess`, `api.php`, `php`, and `restaurant-site`. This makes the site's `/api` and `/restaurant-site` URLs work as configured.
2. Open the XAMPP Control Panel and start **Apache** and **MySQL**.
3. Open `http://localhost/phpmyadmin`.
4. Select **Import**, choose `C:\xampp\htdocs\php\schema.sql`, and select **Import** at the bottom of the page. The script creates and seeds the `restaurant_distributor` database.
5. Open `http://localhost/`.

If you prefer to keep the project in a subfolder, configure an Apache virtual host with that project folder as its document root, then use the virtual host URL. Opening it under a subfolder URL without a virtual host will break the site's root-relative links.

## Database settings

XAMPP's usual local defaults are already configured: database `restaurant_distributor`, user `root`, and an empty password. If your MySQL account differs, edit the values in `php/db.php` or set `DB_HOST`, `DB_USER`, `DB_PASS`, and `DB_NAME` in the PHP environment.

Do not expose phpMyAdmin or a default/root database account to the public internet. This setup is intended for local development and trusted private networks.

## Open from a phone or another computer

1. Connect the phone/computer and the XAMPP computer to the same Wi-Fi network.
2. Find the XAMPP computer's local IPv4 address. On Windows, run `ipconfig` and look for **IPv4 Address** under the active Wi-Fi adapter.
3. On the other device, open `http://COMPUTER-IP/`, replacing `COMPUTER-IP` with that address, for example `http://192.168.1.25/`.
4. If the page does not load, allow Apache through the Windows firewall on **Private networks**. Do not open the port to public networks.

QR and order links use the current request's host, so links generated from the phone-accessible site point back to the computer instead of to `localhost` on the phone.

## Troubleshooting

- **API returns 404:** confirm Apache's `mod_rewrite` module is enabled and that `.htaccess` overrides are allowed for `htdocs`. Restart Apache after changing its configuration.
- **Database connection failed:** ensure MySQL is running, import `php/schema.sql`, and check credentials in `php/db.php`.
- **Apache will not start:** another program may be using port 80 or 443. Stop that service or change Apache's port, then use that port in the website URL.
- **Other devices cannot connect:** confirm both devices are on the same network and allow Apache through the private-network firewall. Guest Wi-Fi may block device-to-device traffic.
- **QR image is blank:** this PHP setup requests QR image generation from `api.qrserver.com`; the server/device needs internet access for that image.

The Node.js option remains available too. See `README.md`; it does not require XAMPP or Visual Studio Code.
