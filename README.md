# Bitlane for WordPress and WooCommerce

Fast, typo-tolerant search for your WooCommerce store or WordPress site, from [Bitlane](https://bitlane.co). Click **Connect**, approve the site in Bitlane, and Bitlane creates the search engine, an index for each kind of content, and the search widget, then keeps them in sync as your site changes.

- Products, pages, posts and custom post types, updated whenever you add, edit or remove them.
- With WPML or Polylang, each language gets its own search, in its own language.
- The search widget on your site.
- Additions to the cart, paid orders and refunds reported for Bitlane's conversion reports, following your visitors' consent through the WP Consent API.

## Requirements

- WordPress 6.6 or later
- PHP 8.2 or later
- WooCommerce 9.0 or later, for products

## Install

Download [bitlane.zip](https://cdn.bitlane.co/wordpress/bitlane.zip) and upload it in WordPress under **Plugins → Add New → Upload Plugin**, then activate it.

On a site managed with Composer, such as [Bedrock](https://roots.io/bedrock/):

```bash
composer require bitlane/wordpress
```

The plugin goes in your plugins folder as `bitlane` (through `composer/installers`); activate it under **Plugins**, or with `wp plugin activate bitlane`.

Then open **Bitlane** in the admin menu, click **Connect**, sign in to Bitlane (or create an account), approve the site, and choose what to search.

## Update

New versions arrive under **Dashboard → Updates** like any other plugin's. On a site managed with Composer, run `composer update bitlane/wordpress` instead. Your connection, settings and sync stay.

## Help

Questions and bugs: open an issue in [this repository](https://github.com/bitlane-co/wordpress/issues), or write to us from [bitlane.co](https://bitlane.co). This repository holds each release as it ships; the plugin is developed elsewhere, so pull requests can't be merged here: describe the change in an issue instead.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html).
