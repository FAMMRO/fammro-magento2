# FAMMRO for Magento 2

Chat window, order status and returns for Adobe Commerce, Magento Open Source and Mage-OS, connected to
[FAMMRO](https://fammro.com) with one click.

## Requirements

- Magento 2.4.4 or later (or Mage-OS), PHP 8.1 to 8.5 with the `sodium` extension
- Shop address on https
- A FAMMRO account

## Install

```bash
composer config repositories.fammro vcs https://github.com/FAMMRO/fammro-magento2
composer require fammro/module-integration
bin/magento setup:upgrade
```

In production mode also run `bin/magento setup:di:compile` and `bin/magento setup:static-content:deploy`.

## Connect

In the Magento admin open **FAMMRO** and click **Connect with FAMMRO**. Approve the shop in the FAMMRO window. The
module then:

- stores the public widget key, so the chat window appears on every page (only after cookie consent)
- creates the integration **FAMMRO** under System > Integrations with exactly three permissions: view orders, add
  order comments and All Stores (for the connection test). FAMMRO uses it for order status and returns.

The shop key for signed requests never leaves the shop and is stored encrypted. Nothing is sent to FAMMRO before you
click Connect.

## Remove

```bash
bin/magento module:uninstall Fammro_Integration
```

This deletes the integration (and with it FAMMRO's access) and every `fammro_integration/*` setting.

## Support

support@fammro.com
