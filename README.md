<p align="center">
  <img src="view/frontend/web/images/logo.svg" width="128" height="128"/>
</p>
<h1 align="center">Two payment plugin for Magento 2.3.3 and higher</h1>
Sell to your business customers in one click. The Two payment Module simplifies B2B shopping, making it easy and safe for Merchants to offer invoices as payment method.

### Benefits for Merchants

Two allows you to offer a seamless Buy now, Pay later option for your business customers which will enhance the buyer journey and reduce the manual work related to doing business with other Businesses.

Two will:

- Run an instant credit check on your customers
- Allow you to enable a B2B Guest Checkout - increases conversion by up to 36%
- Offer customers flexible invoice payment terms from 14 to 90 days
- Automatically issue an invoice - already integrated with PEPPOL e-invoicing network
- Handle partial capture and refunds in a click
- You get paid instantly on fulfilment of an order

Completely remove any credit risk - if the customer doesn't repay, it's Two's problem, and not yours.

### Benefit for customers

**Two Buy now, Pay later** offers your business customers the option to pay with a frictionless invoice solution that will send the invoice directly to their accountant through electronic invoicing.

- Total flexibility on repayment terms - customers can choose to repay on any timescale they like
- Instantly checkout without any burdensome onboarding
- PDF + Electronic invoicing using the [PEPPOL](https://peppol.eu/) framework - invoices flow straight to the ERP

To make the integration process as easy as possible for you, Two.inc offers an easy integration using this Magento® 2 Plugin. Before you start up the installation process, we recommend that you make a backup of your webshop files, as well as the database.

### Installation using Composer

Magento® 2 use the Composer to manage the module package and the library. Composer is a dependency manager for PHP. Composer declare the libraries your project depends on and it will manage (install/update) them for you.

Check if your server has composer installed by running the following command:

```bash
composer –v
```

If your server doesn’t have composer installed, you can easily install it by using this manual: <https://getcomposer.org/doc/00-intro.md>

Step-by-step to install the Magento® 2 extension through Composer:

1. Connect to your server running Magento® 2 using SSH or other method (make sure you have access to the command line).
2. Locate your Magento® 2 project root.
3. Install the Magento® 2 extension through composer and wait till it's completed:

    ```bash
    composer require two-inc/magento2
    ```

4. Once completed run the Magento® module enable command:

    ```bash
    php bin/magento module:enable Two_Gateway
    ```

5. After that run the Magento® upgrade and clean the caches:

    ```bash
    php bin/magento setup:upgrade
    php bin/magento cache:flush
    ```

6. If Magento® is running in production mode you also need to redeploy the static content:

    ```bash
    php bin/magento setup:static-content:deploy
    ```

7. After the installation: Go to your Magento® admin portal and open ‘Stores’ > ‘Configuration’ > Sales > Payment Method > TWO

## Development by Magmodules

A Dutch Magento® Only Agency dedicated to the development of extensions for Magento and Shopware. All our extensions are coded by our own team and our support team is always there to help you out.

[Visit Magmodules.eu](https://www.magmodules.eu/)

## Links

[Setup Guide](https://docs.two.inc/developer-portal/plugins/magento)
