=== PingoLine Order Confirmation Call ===
Contributors: pingolinebd, pingoline
Tags: woocommerce, order confirmation, ivr, call verification, bangladesh
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 9.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automated phone call confirmations for WooCommerce orders in Bangladesh: customer presses 1 to confirm, 2 to cancel. Powered by PingoLine.

== Description ==

PingoLine Order Confirmation Call provides automated phone call verification for WooCommerce stores in Bangladesh. It helps online merchants eliminate fraudulent Cash on Delivery (COD) orders and avoid unnecessary courier return charges.

When a customer places an order on your WooCommerce store, PingoLine immediately places an automated call to their phone. An interactive voice system asks the customer: *"Press 1 to confirm your order, or press 2 to cancel."* The response is captured in real-time, instantly updating the WooCommerce order status.

= Key Features =
* **Instant Automated Calls:** Initiates automated voice confirmation within seconds of a new order.
* **Interactive IVR:** Customer presses 1 to confirm, 2 to cancel, or 0 to speak with a support agent.
* **Status Automation:** Updates order status to Processing or Cancelled based on DTMF keypress.
* **Smart Retry:** Automatically retries unreachable or unanswered phone calls.
* **Order Notes & Audit Log:** Logs call status, attempts, and customer key presses directly inside WooCommerce order notes.
* **Cash on Delivery Filter:** Option to only trigger calls for Cash on Delivery (COD) payment methods.
* **HPOS Compatible:** Fully declared compatibility with WooCommerce High-Performance Order Storage (HPOS).

== External Services ==

This plugin connects to and relies on the external **PingoLine Cloud PBX Telephony API** (`https://pingoline.com`) in order to dial customers, run the interactive voice prompt, and report call outcomes.

* **Service Provider:** PingoLine (https://pingoline.com)
* **API Endpoint:** `https://pingoline.com/api/v1/order-calls`
* **Terms of Service:** https://pingoline.com/terms
* **Privacy Policy:** https://pingoline.com/privacy

**Data Transmitted to PingoLine:**
* Customer billing or shipping phone number (used solely to place the order confirmation call)
* Customer billing name (for personalized voice announcement)
* Order Number and Total Amount (announced to the customer during the confirmation call)
* Store callback webhook URL (used to securely send back call completion events)

This data is processed strictly for telephone order verification and is never sold, shared, or retained beyond the necessary telephony logs.

== Installation ==

1. Upload the `pingoline-order-confirm` folder to your `/wp-content/plugins/` directory, or install via **Plugins > Add New** in your WordPress admin dashboard.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to **WooCommerce > PingoLine Order Call**.
4. Enter your **API URL** (`https://pingoline.com/api/v1`) and **API Token** from your PingoLine Portal (Developer page).
5. Copy the generated Webhook URL displayed on the settings screen and add it to your PingoLine Developer portal.
6. Click **Test connection** to verify communication with PingoLine servers.

== Frequently Asked Questions ==

= Does this plugin require an active PingoLine account? =
Yes. You need an active PingoLine Cloud PBX account and sufficient call balance or an active subscription to place outbound automated calls. You can register at https://pingoline.com/register.

= Can I run confirmation calls only for Cash on Delivery (COD)? =
Yes. In the settings screen, simply keep the "Only Cash on Delivery orders" checkbox checked. Prepaid orders will not trigger automated calls.

= Is this plugin compatible with WooCommerce HPOS? =
Yes. PingoLine Order Confirmation Call declares full compatibility with WooCommerce High-Performance Order Storage (HPOS).

= Does it support BTRC approved 096 IP numbers? =
Yes. You can assign any official 096 series IP phone number allocated to your PingoLine account as your caller ID.

== Screenshots ==

1. PingoLine Order Confirmation Call configuration screen in WooCommerce settings.
2. Real-time call confirmation note added to WooCommerce order details.

== Changelog ==

= 1.3.0 =
* Security hardening: Added nonce verification and capability checks for connection testing.
* Full rebranding to PingoLine Cloud PBX.
* Added External Services disclosure and privacy links complying with WordPress.org guidelines.
* Enhanced WooCommerce HPOS compatibility.

= 1.0.0 =
* Initial public release.
