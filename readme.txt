=== PingoLine Order Confirmation Call ===
Requires at least: 6.0
Requires PHP: 7.4
WC requires at least: 7.0
Stable tag: 1.3.0
License: GPLv2 or later

Automatic phone confirmation for WooCommerce orders through PingoLine.

== Description ==

When a customer places an order, PingoLine calls them: press 1 to confirm, press 2 to cancel.
Unanswered calls are retried. The final result updates the order status and adds an order note.

== Installation ==

1. Plugins > Add New > Upload Plugin, choose pingoline-order-confirm.zip, activate.
2. WooCommerce > PingoLine Order Call:
   * API URL and API token: from the PingoLine portal, Developer page.
   * Copy the webhook URL shown on this page into the PingoLine portal (Developer > Webhook URL),
     then paste the webhook signing secret from the portal back here.
3. Click "Test connection".
4. Place a Cash on Delivery test order.

== Order statuses ==

* Pressed 1: status set to "When confirmed" (default Processing).
* Pressed 2: status set to "When cancelled" (default Cancelled).
* No answer / no key pressed: an order note only; the status is left for your team.
