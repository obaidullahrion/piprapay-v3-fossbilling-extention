<?php

class Payment_Adapter_piprapayUSD extends Payment_AdapterAbstract implements \FOSSBilling\InjectionAwareInterface
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

        $this->config['api_url'] = preg_replace('/^http:\/\//i', 'https://', $this->config['api_url']);

        if (!isset($this->config['currency'])) {
            $this->config['currency'] = 'USD';
        }
    }

    public static function getConfig()
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions'     => false,
            'description'                => 'Accept payments via PipraPay',
            'logo'                       => [
                'logo'   => 'piprapay/dollar.png',
                'height' => '50px',
                'width'  => '50px',
            ],
            'form' => [
                'api_key' => [
                    'text', [
                        'label'    => 'API key:',
                        'required' => true,
                    ],
                ],
                'api_url' => [
                    'text', [
                        'label'    => 'API URL (HTTPS only):',
                        'required' => true,
                        'value'    => 'https://checkout.webfuran.com',
                    ],
                ],
                'currency' => [
                    'text', [
                        'label'    => 'Currency (BDT/USD):',
                        'required' => true,
                        'value'    => 'USD',
                    ],
                ],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        $invoice    = $api_admin->invoice_get(['id' => $invoice_id]);
        $data       = $this->preparePaymentData($invoice);
        $paymentUrl = $this->createCharge($data);

        return $this->generatePaymentForm($paymentUrl);
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        $this->logInfo('processTransaction called', [
            'id'          => $id,
            'gateway_id'  => $gateway_id,
            'invoice_id'  => $data['invoice_id'] ?? null,
            'get'         => $data['get'] ?? [],
            'post'        => $data['post'] ?? [],
            'raw_preview' => substr($data['http_raw_post_data'] ?? '', 0, 200),
        ]);

        // STEP 1: Parse IPN data
        $ipn = $this->parseIpnData($data);

        if (!$ipn || empty($ipn['pp_id'])) {
            $this->logError('Invalid IPN: missing pp_id', [
                'get'  => $data['get'] ?? [],
                'post' => $data['post'] ?? [],
                'raw'  => substr($data['http_raw_post_data'] ?? '', 0, 500),
            ]);
            throw new Payment_Exception('Invalid IPN request: missing pp_id (transaction reference)');
        }

        $pp_id = $ipn['pp_id'];
        $this->logInfo('IPN parsed successfully', ['pp_id' => $pp_id]);

        // STEP 2: Verify payment with PipraPay API
        $payment = $this->verifyPayment($pp_id);
        $this->logInfo('Payment verified with API', ['status' => $payment['status'] ?? 'unknown']);

        // STEP 3: Validate payment status
        if (($payment['status'] ?? '') !== 'completed') {
            $this->logError('Payment not completed', ['pp_id' => $pp_id, 'status' => $payment['status'] ?? 'unknown']);
            throw new Payment_Exception('Payment not completed. Status: ' . ($payment['status'] ?? 'unknown'));
        }

        // STEP 4: Resolve invoice ID
        $invoiceId = $this->resolveInvoiceId($data, $ipn, $payment);

        if (empty($invoiceId)) {
            $this->logError('Cannot determine invoice ID', [
                'data_invoice_id'  => $data['invoice_id'] ?? null,
                'ipn_invoice_id'   => $ipn['invoice_id'] ?? null,
                'payment_metadata' => $payment['metadata'] ?? null,
            ]);
            throw new Payment_Exception('Cannot determine invoice ID from payment data');
        }

        $this->logInfo('Invoice ID resolved', ['invoice_id' => $invoiceId]);

        // STEP 5: Load models
        $invoice     = $this->di['db']->getExistingModelById('Invoice',     (int) $invoiceId, 'Invoice not found');
        $transaction = $this->di['db']->getExistingModelById('Transaction', $id,              'Transaction not found');

        $this->logInfo('Models loaded', [
            'invoice_id'         => $invoice->id,
            'invoice_total'      => $invoice->total,
            'invoice_status'     => $invoice->status,
            'invoice_type'       => $invoice->type,
            'transaction_id'     => $transaction->id,
            'transaction_status' => $transaction->status,
        ]);

        // STEP 6: IDEMPOTENCY — atomic lock on pp_id
        // ipn.php always creates a NEW transaction row per call, so two calls
        // (webhook + return URL) get different $id values and a per-row check
        // won't stop the duplicate. We lock on pp_id instead.
        $this->ensureLockTableExists();

        $locked = $this->acquirePaymentLock($pp_id, $id);
        if (!$locked) {
            $this->logInfo('Duplicate IPN suppressed — pp_id already processed', [
                'pp_id'  => $pp_id,
                'txn_id' => $id,
            ]);
            return true;
        }

        // STEP 7: Get payment amount (straight USD — no conversion)
        $paymentAmount = $this->getPaymentAmount($payment, $invoice);
        $this->logInfo('Payment amount calculated', ['amount' => $paymentAmount]);

        // STEP 8: Update transaction record
        $tx_data = [
            'id'         => $id,
            'invoice_id' => $invoice->id,
            'txn_status' => $payment['status'],
            'txn_id'     => $payment['transaction_id'] ?? $pp_id,
            'amount'     => $paymentAmount,
            'currency'   => $invoice->currency ?? 'USD',
            'type'       => $payment['gateway'] ?? 'piprapay',
            'status'     => 'complete',
        ];

        $transactionService = $this->di['mod_service']('Invoice', 'Transaction');
        $transactionService->update($transaction, $tx_data);
        $this->logInfo('Transaction updated', ['txn_id' => $id]);

        // STEP 9: Credit client wallet
        $description = sprintf(
            'PipraPay Payment | Gateway: %s | pp_id: %s | txn: %s',
            $payment['gateway'] ?? 'unknown',
            $pp_id,
            $payment['transaction_id'] ?? 'N/A'
        );

        $client        = $this->di['db']->getExistingModelById('Client', $invoice->client_id, 'Client not found');
        $clientService = $this->di['mod_service']('client');
        $clientService->addFunds($client, $paymentAmount, $description);
        $this->logInfo('Funds added to client', ['client_id' => $client->id, 'amount' => $paymentAmount]);

        // STEP 10: Pay invoice with credits — but ONLY for service invoices.
        // Add-funds invoices have invoice_item.type = 'deposit'.
        // If we call payInvoiceWithCredits on a deposit invoice, the wallet
        // credit is immediately consumed and the balance stays at 0.
        $invoiceItem = $this->di['db']->findOne('InvoiceItem', 'invoice_id = ?', [$invoice->id]);
        $isDeposit   = ($invoiceItem && $invoiceItem->type === 'deposit');

        if (!$isDeposit) {
            $invoiceService = $this->di['mod_service']('Invoice');
            $invoiceService->payInvoiceWithCredits($invoice);
            $invoiceService->doBatchPayWithCredits(['client_id' => $invoice->client_id]);
            $this->logInfo('Invoice paid with credits', ['invoice_id' => $invoice->id]);
        } else {
            // Deposit invoice — mark as paid directly without consuming wallet credits.
            // $charge = false skips any debit/order-activation logic.
            $invoiceService = $this->di['mod_service']('Invoice');
            $invoiceService->markAsPaid($invoice, false);
            $this->logInfo('Deposit invoice marked as paid, balance retained', ['invoice_id' => $invoice->id]);
        }

        $this->logInfo('Payment processed successfully', [
            'invoice_id'  => $invoiceId,
            'is_deposit'  => $isDeposit,
            'pp_id'       => $pp_id,
            'amount'      => $paymentAmount,
            'currency'    => 'USD',
        ]);

        return true;
    }

    // -------------------------------------------------------------------------
    // PP_ID lock helpers
    // -------------------------------------------------------------------------

    private function ensureLockTableExists(): void
    {
        $this->di['db']->exec(
            'CREATE TABLE IF NOT EXISTS `piprapay_processed_payments` (
                `pp_id`      VARCHAR(255) NOT NULL,
                `txn_id`     INT          NOT NULL,
                `created_at` DATETIME     NOT NULL,
                PRIMARY KEY (`pp_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function acquirePaymentLock(string $pp_id, int $txn_id): bool
    {
        try {
            $this->di['db']->exec(
                'INSERT INTO `piprapay_processed_payments` (`pp_id`, `txn_id`, `created_at`) VALUES (?, ?, ?)',
                [$pp_id, $txn_id, date('Y-m-d H:i:s')]
            );
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // IPN parsing
    // -------------------------------------------------------------------------

    private function parseIpnData($data): array|false
    {
        $raw = $data['http_raw_post_data'] ?? '';
        if (!empty($raw)) {
            $ipn = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($ipn) && !empty($ipn['pp_id'])) {
                if (empty($ipn['invoice_id'])) {
                    $ipn['invoice_id'] = $data['invoice_id'] ?? null;
                }
                return $ipn;
            }
        }

        $get = $data['get'] ?? [];
        if (!empty($get['pp_id'])) {
            return [
                'pp_id'      => $get['pp_id'],
                'invoice_id' => $data['invoice_id'] ?? $get['invoice_id'] ?? null,
                'status'     => $get['status'] ?? $get['pp_status'] ?? null,
            ];
        }
        if (!empty($get['transaction_ref'])) {
            return [
                'pp_id'      => $get['transaction_ref'],
                'invoice_id' => $data['invoice_id'] ?? $get['invoice_id'] ?? null,
                'status'     => $get['status'] ?? $get['pp_status'] ?? null,
            ];
        }

        $post = $data['post'] ?? [];
        if (!empty($post['pp_id'])) {
            return [
                'pp_id'      => $post['pp_id'],
                'invoice_id' => $data['invoice_id'] ?? $post['invoice_id'] ?? null,
                'status'     => $post['status'] ?? null,
            ];
        }
        if (!empty($post['transaction_ref'])) {
            return [
                'pp_id'      => $post['transaction_ref'],
                'invoice_id' => $data['invoice_id'] ?? $post['invoice_id'] ?? null,
                'status'     => $post['status'] ?? null,
            ];
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Invoice ID resolution
    // -------------------------------------------------------------------------

    private function resolveInvoiceId($data, $ipn, $payment): ?int
    {
        if (!empty($data['invoice_id'])) {
            return (int) $data['invoice_id'];
        }
        if (!empty($ipn['invoice_id'])) {
            return (int) $ipn['invoice_id'];
        }

        $metadata = $payment['metadata'] ?? null;
        if (is_array($metadata) && !empty($metadata)) {
            if (!empty($metadata['invoice_id'])) {
                return (int) $metadata['invoice_id'];
            }
            if (!empty($metadata['invoiceid'])) {
                return (int) $metadata['invoiceid'];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Amount
    // -------------------------------------------------------------------------

    private function getPaymentAmount(array $payment, $invoice): float
    {
        if (!empty($payment['amount'])) {
            return round((float) $payment['amount'], 2);
        }

        return round((float) $invoice->total, 2);
    }

    // -------------------------------------------------------------------------
    // Payment data preparation
    // -------------------------------------------------------------------------

    private function preparePaymentData($invoice): array
    {
        $client   = $invoice['client'];
        $amount   = $invoice['total'];
        $currency = $this->config['currency'];

        $webhookUrl = $this->config['notify_url'];
        $separator  = (strpos($webhookUrl, '?') !== false) ? '&' : '?';
        $webhookUrl = $webhookUrl . $separator . 'invoice_id=' . $invoice['id'];

        $returnUrl = $this->config['thank_you_url'] ?? $this->config['redirect_url'] ?? null;

        if (empty($returnUrl) && $this->di && isset($this->di['tools'])) {
            $returnUrl = $this->di['tools']->url('invoice/' . $invoice['id']);
        }

        if (empty($returnUrl)) {
            $notifyUrl = $this->config['notify_url'];
            $parsedUrl = parse_url($notifyUrl);
            $baseUrl   = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? '');
            if (isset($parsedUrl['port'])) {
                $baseUrl .= ':' . $parsedUrl['port'];
            }
            $returnUrl = $baseUrl . '/invoice/' . $invoice['id'];
        }

        return [
            'full_name'     => trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? '')),
            'email_address' => $client['email'] ?? '',
            'mobile_number' => !empty($client['phone']) ? $client['phone'] : '+8801000000000',
            'amount'        => (string) round($amount, 2),
            'currency'      => $currency,
            'metadata'      => [
                'invoice_id' => (string) $invoice['id'],
                'invoiceid'  => (string) $invoice['id'],
            ],
            'return_url'    => $returnUrl,
            'return_type'   => 'GET',
            'cancel_url'    => $this->config['cancel_url'] ?? '',
            'webhook_url'   => $webhookUrl,
        ];
    }

    // -------------------------------------------------------------------------
    // Payment form & API helpers
    // -------------------------------------------------------------------------

    private function generatePaymentForm($paymentUrl): string
    {
        $paymentUrl = preg_replace('/^http:\/\//i', 'https://', $paymentUrl);
        $safe = htmlspecialchars($paymentUrl, ENT_QUOTES, 'UTF-8');

        $form  = '<form action="' . $safe . '" method="GET" id="payment_form">';
        $form .= '<input class="bb-button bb-button-submit" type="submit" value="Pay with PipraPay" id="payment_button"/>';
        $form .= '</form>';

        if (!empty($this->config['auto_redirect'])) {
            $form .= '<script>document.getElementById("payment_form").submit();</script>';
        }

        return $form;
    }

    private function createCharge($data): string
    {
        $url      = rtrim($this->config['api_url'], '/') . '/api/checkout/redirect';
        $response = $this->makeApiRequest($url, $data);

        if (!empty($response['pp_url'])) {
            return preg_replace('/^http:\/\//i', 'https://', $response['pp_url']);
        }

        $errorMsg = $response['error']['message'] ?? $response['message'] ?? 'Unknown error';
        $this->logError('Failed to create payment', ['response' => $response]);
        throw new Payment_Exception('Failed to create payment: ' . $errorMsg);
    }

    private function verifyPayment($pp_id): array
    {
        $url      = rtrim($this->config['api_url'], '/') . '/api/verify-payment';
        $response = $this->makeApiRequest($url, ['pp_id' => $pp_id]);

        if (isset($response['status'])) {
            return $response;
        }

        $errorMsg = $response['error']['message'] ?? $response['message'] ?? 'Unknown error';
        $this->logError('Failed to verify payment', ['pp_id' => $pp_id, 'response' => $response]);
        throw new Payment_Exception('Failed to verify payment: ' . $errorMsg);
    }

    private function makeApiRequest($url, $data): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'MHS-PIPRAPAY-API-KEY: ' . $this->config['api_key'],
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
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

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------

    private function logError(string $message, array $context = []): void
    {
        if ($this->di && isset($this->di['logger'])) {
            $this->di['logger']->error('[PipraPay] ' . $message, $context);
        }
    }

    private function logInfo(string $message, array $context = []): void
    {
        if ($this->di && isset($this->di['logger'])) {
            $this->di['logger']->info('[PipraPay] ' . $message, $context);
        }
    }
}