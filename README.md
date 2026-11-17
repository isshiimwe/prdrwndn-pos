# PRDRWNDN POS

WordPress plugin for proudrwandan.com: an in-person point of sale (POS) app, barcodes
and labels, all tied to WooCommerce stock so the website and in-person sales share one count.

## POS app (proudrwandan.com/pos)

- Installable app (PWA) for iPhone, Android and tablets. On iPhone: open the page in
  Safari, Share, Add to Home Screen. Updates reach installed copies by themselves.
- Sign in with a WordPress account that is an Administrator, Shop Manager or POS Cashier.
- Sign in with Google (optional): uses the Google setup from Site Kit's "Sign in with
  Google", or a Client ID pasted in POS settings. Add `https://proudrwandan.com/pos/` under
  Authorized redirect URIs on that Google OAuth client. Google only lets in an email that
  matches a store account with POS access; it never creates accounts. Passwords keep working.
- Scan with the phone camera, a Bluetooth/USB barcode scanner, or type a number.
- Deals apply by themselves (set them in PRDRWNDN POS > Settings > Deals in the POS).
- Charge: Cash (with change), Zelle, Cash App, Apple Pay, Other. Each sale is saved as a
  completed WooCommerce order paid "in person" and takes the items out of stock.
- Optional customer name, phone and email; with an email, WooCommerce sends the receipt.
- No signal: sales are saved on the device and upload when the connection is back. A
  sale is never saved twice.
- Today tab: today's in-person sales and totals by payment method.
- Cashiers cannot give discounts (owners and shop managers can).

## Jersey Stock (Stock tab, administrators only)

- The Izuba and Imigongo tracker: free to sell by size, pickup holds, every jersey sale,
  costs and profit. Only administrators see the tab; the server refuses everyone else.
- Jerseys sold in the POS and on the website count by themselves (POS sales as in person,
  with the payment used, the deal price and no card fee). Log only sales made elsewhere
  (TikTok Shop, WhatsApp, presale site).
- Holds: **Ring up** puts the held jersey in the POS cart; completing the sale clears the
  hold. **Paid already** marks it picked up without a POS sale.
- Website sync: each Izuba and Imigongo size on the website shows only what is free.
- **Jerseys made and prices**: change the counts when a new run arrives.
- Data is saved in WordPress options `prd_jersey_config`, `prd_jersey_sales`,
  `prd_jersey_costs`, `prd_jersey_sync` (kept if the plugin is removed).

## Barcodes and labels

- **Number barcodes (UPC)**: every product and option gets a 12-digit barcode with the
  numbers printed under the bars. Products with a real GS1 UPC use it (Black Ice,
  199874891941, typed into the UPC / GTIN box). Everything else gets an in-store number:
  4 + the product's ID + a check digit (GS1 keeps numbers starting with 4 for a
  business's own use). In-store numbers are never stored in the GTIN box, because
  Google Shopping rejects GTINs starting with 2, 02 or 04.
- **SKUs** (PRDRWNDN POS > SKUs & barcodes): suggests a short readable code for every
  product and option, for example `PRD-FR-DR` or `PRD-JR-IZ-W-XL`. It prints small on
  labels and shows in orders. Existing SKUs are never changed unless you edit them.
- **Print labels** (PRDRWNDN POS > Print labels): pick products and quantities, preview
  the label, and print on the label printer through QZ Tray. A browser print
  option works without QZ Tray.
- **Scan test**: scan any label or UPC to check it finds the right product.
- **POS Cashier role**: for helpers. They will be able to sell and count stock in the
  POS app (coming next) but cannot change prices or settings.

## Install

1. WordPress admin > Plugins > Add New Plugin > Upload Plugin.
2. Choose `prdrwndn-pos.zip`, click Install Now, then Activate.
3. Go to PRDRWNDN POS > SKUs & barcodes, check the suggested SKUs, and click Save SKUs.

## Labels

Recommended label: 2.25 x 1.25 in direct thermal. 2 x 1 in labels also work: the UPC
prints at the same bar width on both.

No small labels on hand? Choose a 4 x 6 in sheet option: 8 labels (2 x 1.5 in) or
12 labels (2 x 1 in) print on one 4 x 6 shipping label with dashed cut lines. Each
label keeps a small clear border so a slightly off cut does not touch the print.

Silent printing (no permission prompt each time): see PRDRWNDN POS > Settings.

## Releasing an update (GitHub: isshiimwe/prdrwndn-pos)

The repo root is the plugin folder. The site looks for the newest version on GitHub,
either a release or a plain version tag.

1. Change the version in **both** places in `prdrwndn-pos.php`
   (the `Version:` header and `PRDRWNDN_POS_VERSION`).
2. Commit, push, and push a tag named after the version (for example `v0.4.3`).
   A GitHub release is optional. If a release has `prdrwndn-pos.zip` attached, the site
   uses that zip; otherwise it uses GitHub's own code zip and renames the folder.
3. In WordPress: Dashboard > Updates > Check again, then update. The site also checks
   by itself every few hours.

For a private repo, add `define( 'PRDRWNDN_POS_GITHUB_TOKEN', '...' );` to wp-config.php.

## REST routes (logged-in users only)

- `GET  /wp-json/prdrwndn-pos/v1/lookup?code=400000001845` finds a product by in-store
  number, real UPC or SKU.
- `GET  /wp-json/prdrwndn-pos/v1/qz/certificate` and `POST /wp-json/prdrwndn-pos/v1/qz/sign`
  are used by QZ Tray for silent printing.
