# PipraPay FOSSBilling Extension

[![GitHub Repo stars](https://img.shields.io/github/stars/obaidullahrion/piprapay-v3-fossbilling-extention?style=social)](https://github.com/obaidullahrion/piprapay-v3-fossbilling-extention)
[![GitHub forks](https://img.shields.io/github/forks/obaidullahrion/piprapay-v3-fossbilling-extention?style=social)](https://github.com/obaidullahrion/piprapay-v3-fossbilling-extention)
[![GitHub watchers](https://img.shields.io/github/watchers/obaidullahrion/piprapay-v3-fossbilling-extention?style=social)](https://github.com/obaidullahrion/piprapay-v3-fossbilling-extention)
[![GitHub license](https://img.shields.io/github/license/obaidullahrion/piprapay-v3-fossbilling-extention)](https://github.com/obaidullahrion/piprapay-v3-fossbilling-extention/blob/main/LICENSE)
[![GitHub release](https://img.shields.io/github/v/release/obaidullahrion/piprapay-v3-fossbilling-extention)](https://github.com/obaidullahrion/piprapay-v3-fossbilling-extention/releases)

![View Count](https://api.countapi.xyz/hits/obaidullahrion/piprapay-v3-fossbilling-extention)

## Overview

PipraPay is a comprehensive payment gateway extension for FOSSBilling that supports both USD and BDT currencies. This extension allows you to accept payments through PipraPay's secure payment processing system.

## Features

- **Dual Currency Support**: Accept payments in both USD and BDT
- **Secure Payment Processing**: Industry-standard security measures
- **Webhook Integration**: Real-time payment notifications
- **Easy Installation**: Simple upload and extract process
- **FOSSBilling Compatible**: Fully integrated with FOSSBilling 2.x
- **Automatic Exchange Rate**: Real-time USD to BDT conversion

## Installation

### Step 1: Download and Upload
1. Download the latest release from [GitHub Releases](https://github.com/obaidullahrion/piprapay-v3-fossbilling-extention/releases)
2. Upload the zip file to your FOSSBilling installation
3. Navigate to `library/payment/adapter` directory

### Step 2: Extract Files
Extract the zip file in the `library/payment/adapter` directory. The file structure will be:

```
library/payment/adapter/
├── piprapay.php
├── piprapayBDT.php
└── piprapay/
    ├── dollar.png
    └── taka.png
```

### Step 3: Configure in FOSSBilling
1. Log in to your FOSSBilling admin panel
2. Go to **Settings** → **Payment Gateways**
3. Enable both PipraPay USD and PipraPay BDT gateways
4. Configure API settings for each gateway

## Configuration

### PipraPay USD Gateway
- **Currency**: USD
- **Logo**: dollar.png
- **Payment Method**: Binance, Stripe, etc.

### PipraPay BDT Gateway
- **Currency**: BDT
- **Logo**: taka.png
- **Payment Method**: bKash, Nagad, etc.

## Setup in PipraPay Admin

### Currency Configuration
- **USD Gateway**: Configure with Binance, Stripe, and other USD payment methods
- **BDT Gateway**: Configure with bKash, Nagad, and other BDT payment methods

### API Configuration
1. Log in to your PipraPay admin panel
2. Navigate to **API Settings**
3. Generate API keys for both USD and BDT gateways
4. Copy the API keys to your FOSSBilling configuration

## Usage

### For USD Payments
1. Select PipraPay USD as payment method
2. Complete payment using supported USD methods
3. Payment will be processed in USD

### For BDT Payments
1. Select PipraPay BDT as payment method
2. Complete payment using supported BDT methods
3. Payment will be processed in BDT with automatic USD conversion

## Security Features

- **HTTPS Only**: All API communications use HTTPS
- **API Key Authentication**: Secure API key-based authentication
- **Webhook Verification**: All webhook notifications are verified
- **Idempotency Checks**: Prevents duplicate payment processing
- **SSL Certificate Verification**: Ensures secure connections

## Support

For any help or support, please contact:

- **Email**: support@webfuran.com
- **Website**: https://piprapay.com
- **GitHub Issues**: [Create an Issue](https://github.com/obaidullahrion/piprapay-v3-fossbilling-extention/issues)

## Contribution

### Contributors
1. Obaidullahrion
2. [Your Name Here]
3. [Your Name Here]

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Changelog

### v3.0.2 (Latest)
- Initial stable release
- Basic payment processing
- USD and BDT currency support
- Simple webhook integration

### v3.0.1
- Minor bug fixes
- Documentation improvements
- Security enhancements

### v3.0.0
- Initial development release
- Basic functionality
- Proof of concept

## File Structure

```
piprapay-v3-fossbilling-extention/
├── LICENSE
├── README.md
├── piprapay.php          # Main USD payment gateway
├── piprapayBDT.php       # BDT payment gateway
└── piprapay/
    ├── dollar.png        # USD logo
    └── taka.png          # BDT logo
```

## Requirements

- FOSSBilling 2.x or higher
- PHP 7.4 or higher
- cURL extension enabled
- HTTPS enabled server
- Valid PipraPay API credentials

## Troubleshooting

### Common Issues
1. **Payment not processing**: Check API key and URL configuration
2. **Webhook not working**: Verify webhook URL and SSL certificate
3. **Currency conversion issues**: Check exchange rate API connectivity

### Debug Mode
To enable debug mode, add the following to your configuration:
```php
'debug' => true,
```

## Disclaimer

This extension is provided as-is without warranty. Use at your own risk. Always test in a staging environment before deploying to production.

---

## Contributions
Anyone is open to contribute and open PR.
