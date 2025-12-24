<p align="center">
  <img src="view/frontend/web/images/abnLogo.svg" width="128" height="128"/>
</p>
<h1 align="center">Achteraf betalen with ABN AMRO payment plugin for Magento 2.3.3 and higher</h1>
Sell to your business customers in one click. The payment Module simplifies B2B shopping, making it easy and safe for Merchants to offer invoices as payment method.

### Benefits for Merchants

The payment plugin allows you to offer a seamless Buy now, Pay later option for your business customers which will enhance the buyer journey and reduce the manual work related to doing business with other Businesses.

We will:

- Run an instant credit check on your customers
- Allow you to enable a B2B Guest Checkout - increases conversion by up to 36%
- Offer customers flexible invoice payment terms from 14 to 90 days
- Automatically issue an invoice - already integrated with PEPPOL e-invoicing network
- Handle partial capture and refunds in a click
- You get paid instantly on fulfilment of an order

Completely remove any credit risk - if the customer doesn't repay, it's our problem, and not yours.

### Benefit for customers

**Achteraf betalen with ABN AMRO** offers your business customers the option to pay with a frictionless invoice solution that will send the invoice directly to their accountant through electronic invoicing.

- Total flexibility on repayment terms - customers can choose to repay on any timescale they like
- Instantly checkout without any burdensome onboarding
- PDF + Electronic invoicing using the [PEPPOL](https://peppol.eu/) framework - invoices flow straight to the ERP

To make the integration process as easy as possible for you, we offer an easy integration using this Magento® 2 Plugin. Before you start up the installation process, we recommend that you make a backup of your webshop files, as well as the database.

### Installation via Zip file

    ```bash
    unzip magento-abn-plugin -d app/code/ABN/Gateway
    ```

4. Once completed run the Magento® module enable command:

    ```bash
    php bin/magento module:enable ABN_Gateway
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
