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
A labelled Gmail message can open a job card when the intake secret is set in wp-config.php.
Cursor and the other site bots can read stock and jobs. They cannot change them.

== Installation ==

1. Upload the plugin zip in wp-admin under Plugins → Add New → Upload Plugin.
2. Activate Bike Sense Stock.
3. Open Stock in the WooCommerce menu, or go to /stock/ and log in.

== Changelog ==

= 1.2.0 =
* Email client intake. POST /wp-json/bikesense-stock/v1/intake opens an open workshop job from a labelled Gmail message. The shared secret stays in wp-config.php (BS_STOCK_INTAKE_SECRET) and is not stored in the plugin. Stock quantities do not change.

= 1.1.0 =
* Workshop job cards with parts, stocked sundries (decimal quantities), labour per mechanic, costing, a plain-English summary, read-only bot abilities, and conversion to a pending WooCommerce order that does not reduce stock again.

= 1.0.0 =
* Stock ledger, /stock/ app, shop publishing, and read-only bot abilities.
