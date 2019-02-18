
<h3>Savvy Integration for Opencart</h3>

<h3>What is this repo?</h3>
This repo contains a payment plugin for WooCommerce shopping cart to support crypto currencies via Savvy. Supported currencies are Bitcoin, Bitcoin Cash, Bitcoin Gold, Ethereum, Ethereum Classic, Litecoin, Dash, Dai.

Users have the opportunity to manage the currencies they would like to accept in their settings at https://www.savvy.io

<h3>Who do we expect to use this documentation?</h3>
You’re in the right place if you’re a developer or a shop owner looking to integrate a new payment method into your shopping cart.

Attention to PayBear users: if you have PayBear plugin installed, please [read this](https://github.com/savvyio/savvy-samples/wiki/Upgrading-from-V2-to-V3)

<h3>Prerequisites</h3>
Before installing the plugin please make sure you have the latest version of Opencart installed. We support version 3.x

In order to use the plugin you will also need a Savvy API Key. Getting a key is free and easy:

 1. Sign up to https://www.savvy.io and create a personal wallet.
 2. Click the Merchant button on the left to enable merchant features.
 3. Create a merchant wallet using the existing sending password.
 4. Click Profile -> Settings -> Merchant tab
 5. Confirm the currencies you would like to accept.
 6. Your API Keys can be found below on the same page.
 
<h3>How to install</h3>

1. Make sure you have Opencart installed. We recommend running the latest version.
2. Download the latest version of the integration: https://github.com/savvyio/savvy-opencart/releases/latest
3. Open Opencart Admin - {Extensions} - {Installer}
4. Click {Upload} and select the ZIP file of the plugin
5. Click {Extensions} - {Extensions} and select {Payment} from the dropdown.
6. Find {Crypto Payments by Savvytech.com} and click {Install}
7. Once installed, click {Edit} and enter your API Keys in settings.
8. Don't forget to save the settings by clicking the Save icon at the top.
9. Test the plugin by making a test order.

<h3>How to update</h3>

1. Download the latest version of the integration: https://github.com/savvyio/savvy-opencart/releases/latest
2. Extract the package and connect to your server using SFTP Clients. Then upload the '**upload**' folder contents (and rewrite files with the old ones) to Opencart root.
3. Clear cache if needed


<h3>Get Help</h3>
Start with our <a href="https://help.savvy.io">Knowledge Base</a> and <a href="https://help.savvy.io/frequently-asked-questions">FAQ</a>.

Still have questions or need support? Log in to your Savvy account and use the live chat to talk to our team directly!
