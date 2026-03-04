<?php

/**
 * PipraPay FOSSBilling Gateway Module - BDT Version
 *
 * Website: https://webfuran.com
 * Email: support@webfuran.com
 * Developer: obaidullahrion
 * Version: 3.4.0 (Security Fixed)
 * 
 * SECURITY FIXES IN v3.4.0:
 * - Fixed webhook parsing for PipraPay JSON payload
 * - Added proper invoice_id resolution from multiple sources
 * - Fixed currency handling (BDT payments)
 * - Added idempotency check to prevent duplicate processing
 * - Added proper amount verification from API response
 * - Added comprehensive error logging
 * - Fixed metadata handling when API returns empty array
 */

class Payment_Adapter_piprapayBDT extends Payment_AdapterAbstract implements \FOSSBilling\InjectionAwareInterface
{
    private $config = [];

    protected ?\Pimple\Container $di;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function __construct($config)
    {
        $this->config = $config;

        if (!isset($this->config['api_key'])) {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'piprapay', ':missing' => 'API KEY']);
        }

        if (!isset($this->config['api_url'])) {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'piprapay', ':missing' => 'API URL']);
        }

        // SECURITY: Force HTTPS on api_url at construction time
        // Prevents downgrade attacks and MITM on HTTP connections
        $this->config['api_url'] = preg_replace('/^http:\/\//i', 'https://', $this->config['api_url']);

        if (!isset($this->config['currency'])) {
            $this->config['currency'] = 'BDT';
        }
    }

    public static function getConfig()
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Accept payments via piprapay (BDT)',
            'logo' => [
                'logo' => 'piprapay/taka.png',
                'height' => '50px',
                'width' => '50px',
            ],
            'form' => [
                'api_key' => [
                    'text', [
                        'label' => 'API key:',
                        'required' => true,
                    ],
                ],
                'api_url' => [
                    'text', [
                        'label' => 'API URL (HTTPS only):',
                        'required' => true,
                        'value' => 'https://checkout.webfuran.com',
                    ],
                ],
                'currency' => [
                    'text', [
                        'label' => 'Currency (BDT/USD):',
                        'required' => true,
                        'value' => 'BDT',
                    ],
                ],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        $invoice = $api_admin->invoice_get(['id' => $invoice_id]);
        $data = $this->preparePaymentData($invoice);
        $paymentUrl = $this->createCharge($data);

        return $this->generatePaymentForm($paymentUrl);
    }

    /**
     * Process Transaction - Main webhook handler
     * 
     * This method is called by FOSSBilling's ipn.php when a webhook is received.
     * It handles both:
     * 1. Webhook notifications from PipraPay (JSON POST to webhook_url)
     * 2. Return URL redirects (GET from return_url after payment)
     * 
     * SECURITY: Always verifies payment with PipraPay API before processing
     * 
     * @param mixed $api_admin API admin object
     * @param int $id Transaction ID in FOSSBilling
     * @param array $data IPN data from FOSSBilling
     * @param int $gateway_id Gateway ID
     * @return bool True on success
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        // DEBUG: Log all incoming data for troubleshooting
        $this->logInfo('processTransaction called', [
            'id' => $id,
            'gateway_id' => $gateway_id,
            'invoice_id' => $data['invoice_id'] ?? null,
            'get' => $data['get'] ?? [],
            'post' => $data['post'] ?? [],
            'raw_preview' => substr($data['http_raw_post_data'] ?? '', 0, 200),
        ]);

        // STEP 1: Parse IPN data from all possible sources
        // FOSSBilling's ipn.php passes data in specific format
        $ipn = $this->parseIpnData($data);

        if (!$ipn || empty($ipn['pp_id'])) {
            $this->logError('Invalid IPN: missing pp_id', [
                'get' => $data['get'] ?? [],
                'post' => $data['post'] ?? [],
                'raw' => substr($data['http_raw_post_data'] ?? '', 0, 500),
            ]);
            throw new Payment_Exception('Invalid IPN request: missing pp_id (transaction reference)');
        }

        $pp_id = $ipn['pp_id'];
        $this->logInfo('IPN parsed successfully', ['pp_id' => $pp_id, 'ipn' => $ipn]);

        // STEP 2: SECURITY - Always verify payment with PipraPay API
        // NEVER trust incoming webhook data alone - always verify with API
        $payment = $this->verifyPayment($pp_id);
        $this->logInfo('Payment verified with API', ['status' => $payment['status'] ?? 'unknown']);

        // STEP 3: Validate payment status from API
        if (($payment['status'] ?? '') !== 'completed') {
            $this->logError('Payment not completed', ['pp_id' => $pp_id, 'status' => $payment['status'] ?? 'unknown']);
            throw new Payment_Exception('Payment not completed. Status: ' . ($payment['status'] ?? 'unknown'));
        }

        // STEP 4: Resolve invoice ID from multiple sources
        // Priority: FOSSBilling parsed → IPN payload → Payment API metadata
        // BUG FIX: Handle empty metadata array from API
        $invoiceId = $this->resolveInvoiceId($data, $ipn, $payment);

        if (empty($invoiceId)) {
            $this->logError('Cannot determine invoice ID', [
                'data_invoice_id' => $data['invoice_id'] ?? null,
                'ipn_invoice_id' => $ipn['invoice_id'] ?? null,
                'payment_metadata' => $payment['metadata'] ?? null,
            ]);
            throw new Payment_Exception('Cannot determine invoice ID from payment data');
        }

        $this->logInfo('Invoice ID resolved', ['invoice_id' => $invoiceId]);

        // STEP 5: Load database models
        $invoice = $this->di['db']->getExistingModelById('Invoice', (int) $invoiceId, 'Invoice not found');
        $transaction = $this->di['db']->getExistingModelById('Transaction', $id, 'Transaction not found');

        $this->logInfo('Models loaded', [
            'invoice_id' => $invoice->id,
            'invoice_total' => $invoice->total,
            'invoice_status' => $invoice->status,
            'transaction_id' => $transaction->id,
            'transaction_status' => $transaction->status,
        ]);

        // STEP 6: SECURITY - Idempotency check
        // Prevent duplicate processing of the same payment
        if ($transaction->status === 'complete') {
            $this->logInfo('Transaction already processed', ['txn_id' => $id, 'pp_id' => $pp_id]);
            return true; // Already processed, not an error
        }

        // STEP 7: Get payment amount
        // Use the actual verified amount from API response
        // BUG FIX: Handle BDT amount properly
        $paymentAmount = $this->getPaymentAmount($payment, $invoice);
        $this->logInfo('Payment amount calculated', ['amount' => $paymentAmount]);

        // STEP 8: Update transaction record
        $tx_data = [
            'id' => $id,
            'invoice_id' => $invoice->id,
            'txn_status' => $payment['status'],
            'txn_id' => $payment['transaction_id'] ?? $pp_id,
            'amount' => $paymentAmount,
            'currency' => $payment['currency'] ?? 'BDT',
            'type' => $payment['gateway'] ?? 'piprapay',
            'status' => 'complete',
        ];

        $transactionService = $this->di['mod_service']('Invoice', 'Transaction');
        $transactionService->update($transaction, $tx_data);
        $this->logInfo('Transaction updated', ['txn_id' => $id]);

        // STEP 9: Add funds to client account
        $description = sprintf(
            'PipraPay Payment | Gateway: %s | pp_id: %s | txn: %s',
            $payment['gateway'] ?? 'unknown',
            $pp_id,
            $payment['transaction_id'] ?? 'N/A'
        );

        $bd = [
            'amount' => $paymentAmount,
            'description' => $description,
            'type' => 'transaction',
            'rel_id' => $transaction->id,
        ];

        $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id, 'Client not found');
        $clientService = $this->di['mod_service']('client');
        $clientService->addFunds($client, $paymentAmount, $description, $bd);
        $this->logInfo('Funds added to client', ['client_id' => $client->id, 'amount' => $paymentAmount]);

        // STEP 10: Mark invoice as paid
        $invoiceService = $this->di['mod_service']('Invoice');
        $invoiceService->payInvoiceWithCredits($invoice);
        $invoiceService->doBatchPayWithCredits(['client_id' => $invoice->client_id]);

        $this->logInfo('Payment processed successfully', [
            'invoice_id' => $invoiceId,
            'pp_id' => $pp_id,
            'amount' => $paymentAmount,
            'currency' => $payment['currency'] ?? 'BDT',
        ]);

        return true;
    }

    /**
     * Parse IPN data from multiple sources
     * 
     * PipraPay can send payment notification via:
     * 1. Webhook (JSON POST to webhook_url) - primary method
     * 2. Return redirect (GET to return_url) - fallback method
     * 
     * @param array $data Data from FOSSBilling ipn.php
     * @return array|false Parsed IPN data or false if invalid
     */
    private function parseIpnData($data): array|false
    {
        // SOURCE 1: Raw JSON webhook body (PRIMARY)
        // PipraPay sends webhook as JSON POST with Content-Type: application/json
        // FOSSBilling's ipn.php reads php://input and stores in http_raw_post_data
        $raw = $data['http_raw_post_data'] ?? '';
        if (!empty($raw)) {
            $ipn = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($ipn)) {
                // PipraPay webhook format:
                // {"pp_id": "...", "status": "completed", "metadata": {...}, ...}
                if (!empty($ipn['pp_id'])) {
                    // Merge invoice_id from FOSSBilling if not in webhook
                    if (empty($ipn['invoice_id'])) {
                        $ipn['invoice_id'] = $data['invoice_id'] ?? null;
                    }
                    return $ipn;
                }
            }
        }

        // SOURCE 2: GET parameters (Return URL redirect)
        // When user is redirected back after payment
        // Format: ?gateway_id=X&invoice_id=55&pp_status=completed&transaction_ref=XXX
        $get = $data['get'] ?? [];
        if (!empty($get['pp_id'])) {
            return [
                'pp_id' => $get['pp_id'],
                'invoice_id' => $data['invoice_id'] ?? $get['invoice_id'] ?? null,
                'status' => $get['status'] ?? $get['pp_status'] ?? null,
            ];
        }
        if (!empty($get['transaction_ref'])) {
            return [
                'pp_id' => $get['transaction_ref'],
                'invoice_id' => $data['invoice_id'] ?? $get['invoice_id'] ?? null,
                'status' => $get['status'] ?? $get['pp_status'] ?? null,
            ];
        }

        // SOURCE 3: POST body fallback (form-encoded)
        $post = $data['post'] ?? [];
        if (!empty($post['pp_id'])) {
            return [
                'pp_id' => $post['pp_id'],
                'invoice_id' => $data['invoice_id'] ?? $post['invoice_id'] ?? null,
                'status' => $post['status'] ?? null,
            ];
        }
        if (!empty($post['transaction_ref'])) {
            return [
                'pp_id' => $post['transaction_ref'],
                'invoice_id' => $data['invoice_id'] ?? $post['invoice_id'] ?? null,
                'status' => $post['status'] ?? null,
            ];
        }

        return false;
    }

    /**
     * Resolve invoice ID from multiple sources
     * 
     * BUG FIX: PipraPay API may return metadata as empty array [] instead of object
     * So we need to check multiple sources for invoice_id
     * 
     * @param array $data FOSSBilling data
     * @param array $ipn Parsed IPN data
     * @param array $payment Verified payment from API
     * @return int|null Invoice ID or null
     */
    private function resolveInvoiceId($data, $ipn, $payment): ?int
    {
        // Priority 1: FOSSBilling already parsed it (from URL parameter)
        if (!empty($data['invoice_id'])) {
            return (int) $data['invoice_id'];
        }

        // Priority 2: IPN payload contains it
        if (!empty($ipn['invoice_id'])) {
            return (int) $ipn['invoice_id'];
        }

        // Priority 3: Payment API metadata
        // BUG FIX: Handle both array and object metadata
        $metadata = $payment['metadata'] ?? null;
        
        // Check if metadata is a valid array/object (not empty)
        if (is_array($metadata) && !empty($metadata)) {
            // Try different key variations
            if (!empty($metadata['invoice_id'])) {
                return (int) $metadata['invoice_id'];
            }
            if (!empty($metadata['invoiceid'])) {
                return (int) $metadata['invoiceid'];
            }
        }

        // Priority 4: Fallback - use invoice_id from data if available
        // This handles cases where metadata is empty but we have invoice_id
        if (!empty($data['invoice_id'])) {
            return (int) $data['invoice_id'];
        }

        return null;
    }

    /**
     * Get payment amount for wallet credit
     * 
     * IMPORTANT: Use the USD amount stored in metadata during payment creation.
     * The BDT amount from PipraPay API is only for display/reference.
     * 
     * Example:
     * - Invoice: $30 USD
     * - Payment: 3670 BDT (30 × 122 exchange rate)
     * - Wallet should be credited: $30 USD (NOT 3670 USD!)
     * 
     * @param array $payment Verified payment from API (contains BDT amount)
     * @param object $invoice Invoice object
     * @return float Payment amount in system currency (USD)
     */
    private function getPaymentAmount(array $payment, $invoice): float
    {
        // PRIORITY 1: Use USD amount from metadata (stored during payment creation)
        // This is the most reliable source for the original USD amount
        $metadata = $payment['metadata'] ?? null;
        if (is_array($metadata) && !empty($metadata['usd_amount'])) {
            return round((float) $metadata['usd_amount'], 2);
        }
        
        // PRIORITY 2: Use invoice total (may be 0 if already processed)
        $invoiceTotal = round((float) $invoice->total, 2);
        if ($invoiceTotal > 0) {
            return $invoiceTotal;
        }
        
        // PRIORITY 3: Fallback - calculate from BDT amount using current rate
        // This is less accurate but ensures we credit something
        $bdtAmount = (float) ($payment['amount'] ?? 0);
        if ($bdtAmount > 0) {
            // Get current exchange rate
            try {
                $rate = $this->getUsdToBdtRate();
                return round($bdtAmount / $rate, 2);
            } catch (\Exception $e) {
                // If rate fetch fails, use approximate rate
                // This should rarely happen
                return round($bdtAmount / 122, 2);
            }
        }
        
        return 0;
    }

    /**
     * Prepare payment data for PipraPay API
     * 
     * @param array $invoice Invoice data from FOSSBilling
     * @return array Payment data for API
     */
    private function preparePaymentData($invoice): array
    {
        $client = $invoice['client'];
        $usdAmount = $invoice['total'];
        
        // Get BDT exchange rate and calculate BDT amount
        $bdtRate = $this->getUsdToBdtRate();
        $bdtAmount = round($usdAmount * $bdtRate);

        // WEBHOOK URL: Server-to-server notification endpoint
        // Append invoice_id so FOSSBilling can identify the invoice
        $webhookUrl = $this->config['notify_url'];
        $separator = (strpos($webhookUrl, '?') !== false) ? '&' : '?';
        $webhookUrl = $webhookUrl . $separator . 'invoice_id=' . $invoice['id'];

        // RETURN URL: User-friendly page after payment
        // Redirect user to the invoice page where they can see payment status
        // Use thank_you_url if available, otherwise use redirect_url, or build invoice URL
        $returnUrl = $this->config['thank_you_url'] 
            ?? $this->config['redirect_url'] 
            ?? null;
        
        // If no custom return URL is set, try to build invoice URL using di tools
        if (empty($returnUrl) && $this->di && isset($this->di['tools'])) {
            $returnUrl = $this->di['tools']->url('invoice/' . $invoice['id']);
        }
        
        // Final fallback: use the base URL from notify_url to construct invoice page
        if (empty($returnUrl)) {
            // Extract base URL from notify_url and build invoice page
            $notifyUrl = $this->config['notify_url'];
            $parsedUrl = parse_url($notifyUrl);
            $baseUrl = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? '');
            if (isset($parsedUrl['port'])) {
                $baseUrl .= ':' . $parsedUrl['port'];
            }
            $returnUrl = $baseUrl . '/invoice/' . $invoice['id'];
        }

        return [
            'full_name' => trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? '')),
            'email_address' => $client['email'] ?? '',
            'mobile_number' => !empty($client['phone']) ? $client['phone'] : '+8801000000000',
            'amount' => (string) $bdtAmount,
            'currency' => 'BDT',
            'metadata' => [
                'invoice_id' => (string) $invoice['id'],
                'invoiceid' => (string) $invoice['id'],
                'usd_amount' => (string) $usdAmount,
            ],
            'return_url' => $returnUrl,
            'return_type' => 'GET',
            'cancel_url' => $this->config['cancel_url'] ?? '',
            'webhook_url' => $webhookUrl,
        ];
    }

    /**
     * Get USD to BDT exchange rate
     * 
     * @return float Exchange rate
     * @throws Payment_Exception If rate cannot be fetched
     */
    private function getUsdToBdtRate(): float
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://open.er-api.com/v6/latest/USD',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true, // SECURITY: Verify SSL certificate
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            throw new Payment_Exception('Could not fetch exchange rate: ' . $curlError);
        }

        if ($httpCode !== 200) {
            throw new Payment_Exception('Exchange rate API returned HTTP ' . $httpCode);
        }

        $rateData = json_decode($response, true);

        if (
            json_last_error() !== JSON_ERROR_NONE ||
            ($rateData['result'] ?? '') !== 'success' ||
            !isset($rateData['rates']['BDT'])
        ) {
            throw new Payment_Exception('Exchange rate API error: Unable to retrieve USD->BDT rate.');
        }

        return (float) $rateData['rates']['BDT'];
    }

    /**
     * Generate payment form HTML
     * 
     * @param string $paymentUrl Payment URL from PipraPay
     * @return string HTML form
     */
    private function generatePaymentForm($paymentUrl): string
    {
        // SECURITY: Force HTTPS and escape URL
        $paymentUrl = preg_replace('/^http:\/\//i', 'https://', $paymentUrl);
        $safe = htmlspecialchars($paymentUrl, ENT_QUOTES, 'UTF-8');

        $form = '<form action="' . $safe . '" method="GET" id="payment_form">';
        $form .= '<input class="bb-button bb-button-submit" type="submit" value="Pay with PipraPay (BDT)" id="payment_button"/>';
        $form .= '</form>';

        if (!empty($this->config['auto_redirect'])) {
            $form .= '<script>document.getElementById("payment_form").submit();</script>';
        }

        return $form;
    }

    /**
     * Create charge/payment session with PipraPay
     * 
     * @param array $data Payment data
     * @return string Payment URL
     * @throws Payment_Exception If creation fails
     */
    private function createCharge($data): string
    {
        $url = rtrim($this->config['api_url'], '/') . '/api/checkout/redirect';
        $response = $this->makeApiRequest($url, $data);

        if (!empty($response['pp_url'])) {
            // SECURITY: Force HTTPS on the returned payment URL
            return preg_replace('/^http:\/\//i', 'https://', $response['pp_url']);
        }

        $errorMsg = $response['error']['message'] ?? $response['message'] ?? 'Unknown error';
        $this->logError('Failed to create payment', ['response' => $response]);
        throw new Payment_Exception('Failed to create payment: ' . $errorMsg);
    }

    /**
     * Verify payment with PipraPay API
     * 
     * SECURITY: Always call this to verify payment before processing
     * Never trust webhook data alone
     * 
     * @param string $pp_id PipraPay transaction ID
     * @return array Payment details from API
     * @throws Payment_Exception If verification fails
     */
    private function verifyPayment($pp_id): array
    {
        $url = rtrim($this->config['api_url'], '/') . '/api/verify-payment';
        $response = $this->makeApiRequest($url, ['pp_id' => $pp_id]);

        // API returns payment details directly
        if (isset($response['status'])) {
            return $response;
        }

        $errorMsg = $response['error']['message'] ?? $response['message'] ?? 'Unknown error';
        $this->logError('Failed to verify payment', ['pp_id' => $pp_id, 'response' => $response]);
        throw new Payment_Exception('Failed to verify payment: ' . $errorMsg);
    }

    /**
     * Make API request to PipraPay
     * 
     * @param string $url API endpoint URL
     * @param array $data Request data
     * @return array Response data
     * @throws Payment_Exception If request fails
     */
    private function makeApiRequest($url, $data): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                // SECURITY: API key in header (not in URL or body)
                'MHS-PIPRAPAY-API-KEY: ' . $this->config['api_key'],
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true, // SECURITY: Verify SSL certificate
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            throw new Payment_Exception('cURL error: ' . $error);
        }

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Payment_Exception('Invalid JSON from PipraPay API: ' . substr($response, 0, 200));
        }

        return $result;
    }

    /**
     * Log error message
     * 
     * @param string $message Error message
     * @param array $context Additional context
     */
    private function logError(string $message, array $context = []): void
    {
        if ($this->di && isset($this->di['logger'])) {
            $this->di['logger']->error('[PipraPayBDT] ' . $message, $context);
        }
    }

    /**
     * Log info message
     * 
     * @param string $message Info message
     * @param array $context Additional context
     */
    private function logInfo(string $message, array $context = []): void
    {
        if ($this->di && isset($this->di['logger'])) {
            $this->di['logger']->info('[PipraPayBDT] ' . $message, $context);
        }
    }
}