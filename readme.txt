=== Bike Sense Stock ===
Contributors: bikesense
Requires at least: 6.9
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later

Private stock taking for the Bike Sense shop. Open /stock/ on a phone or a computer.

== Description ==

Search by part number, scan a barcode, and record goods in, goods out, counts, and transfers.
Pretoria is the first location. Counted parts can be published to WooCommerce.
Workshop job cards book parts and sundries out of stock, log labour, show job costing, and can become a WooCommerce order.
Cursor and the other site bots can read stock and jobs. They cannot change them.

== Installation ==

1. Upload the plugin zip in wp-admin under Plugins → Add New → Upload Plugin.
2. Activate Bike Sense Stock.
3. Open Stock in the WooCommerce menu, or go to /stock/ and log in.
4. Email intake is off until wp-config.php defines BS_STOCK_INTAKE_SECRET as a random string of at least 32 characters. Put the same value in the Apps Script property INTAKE_SECRET. The script to paste is intake/client-intake.gs. It reads the Gmail label Client Intake and opens a workshop job. It does not change stock. Do not commit the secret.

== Changelog ==

= 1.2.0 =
* Client Intake email opens an open workshop job at POST /wp-json/bikesense-stock/v1/intake. The shared secret stays in wp-config.php or the environment, and a repeated Gmail message does not open a second job.

= 1.1.0 =
* Workshop job cards with parts, stocked sundries (decimal quantities), labour per mechanic, costing, a plain-English summary, read-only bot abilities, and conversion to a pending WooCommerce order that does not reduce stock again.

= 1.0.0 =
* Stock ledger, /stock/ app, shop publishing, and read-only bot abilities.
