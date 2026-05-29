<?php
/**
 * WORK2/backend/includes/MpesaGateway.php
 * ==========================================================
 * Enterprise M-Pesa Gateway
 * Supports:
 * - Safaricom Daraja API (Kenya)
 * - Daraja-compatible M-Pesa integrations
 * - Tanzania and Kenya phone normalization
 *
 * Features:
 * - OAuth token generation with in-memory caching
 * - STK Push initiation
 * - STK Push status query
 * - Callback verification and parsing
 * - Automatic retry on transient failures
 * - Robust error normalization
 * - Secure logging
 * - MAMP and production compatible
 *
 * Requires:
 * - WORK2/backend/config/payment_config.php
 * - PHP cURL extension
 *
 * NOTE:
 * - Replace placeholder credentials in payment_config.php
 * - Callback URLs must be publicly accessible via HTTPS
 * - Some Vodacom Tanzania implementations may differ from Safaricom Daraja
 * ==========================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/payment_config.php';

class MpesaGateway
{
    /**
     * Cached OAuth token.
     */
    private ?string $accessToken = null;

    /**
     * UNIX timestamp when token expires.
     */
    private int $tokenExpiresAt = 0;

    /**
     * Network settings.
     */
    private int $timeout = 60;
    private int $connectTimeout = 20;
    private int $maxRetries = 3;

    /**
     * Constructor.
     *
     * @throws Exception
     */
    public function __construct()
    {
        if (!defined('ENABLE_MPESA') || !ENABLE_MPESA) {
            throw new Exception('M-Pesa is disabled in payment_config.php');
        }

        $required = [
            'MPESA_CONSUMER_KEY'    => defined('MPESA_CONSUMER_KEY') ? MPESA_CONSUMER_KEY : '',
            'MPESA_CONSUMER_SECRET' => defined('MPESA_CONSUMER_SECRET') ? MPESA_CONSUMER_SECRET : '',
            'MPESA_SHORTCODE'       => defined('MPESA_SHORTCODE') ? MPESA_SHORTCODE : '',
            'MPESA_PASSKEY'         => defined('MPESA_PASSKEY') ? MPESA_PASSKEY : '',
            'MPESA_BASE_URL'        => defined('MPESA_BASE_URL') ? MPESA_BASE_URL : '',
            'MPESA_CALLBACK_URL'    => defined('MPESA_CALLBACK_URL') ? MPESA_CALLBACK_URL : '',
        ];

        foreach ($required as $name => $value) {
            if (
                $value === '' ||
                stripos($value, 'YOUR_') !== false
            ) {
                throw new Exception(
                    'Missing or placeholder value for ' . $name .
                    ' in payment_config.php'
                );
            }
        }

        if (!function_exists('curl_init')) {
            throw new Exception('PHP cURL extension is not enabled.');
        }

        if (!function_exists('paymentLog')) {
            throw new Exception(
                'paymentLog() function not found. ' .
                'Ensure payment_config.php is loaded correctly.'
            );
        }
    }

    /**
     * Initiate STK Push payment request.
     *
     * @param string      $phone
     * @param float       $amount
     * @param string      $orderId
     * @param string|null $accountReference
     * @param string|null $transactionDescription
     *
     * @return array
     * @throws Exception
     */
    public function initiatePayment(
        string $phone,
        float $amount,
        string $orderId,
        ?string $accountReference = null,
        ?string $transactionDescription = null
    ): array {
        $phone = $this->normalizePhone($phone);
        $amount = $this->normalizeAmount($amount);

        if ($accountReference === null || trim($accountReference) === '') {
            $accountReference = 'ORDER-' . $orderId;
        }

        if (
            $transactionDescription === null ||
            trim($transactionDescription) === ''
        ) {
            $transactionDescription = 'Payment for order ' . $orderId;
        }

        $timestamp = $this->generateTimestamp();
        $password  = $this->generatePassword($timestamp);
        $token     = $this->getAccessToken();

        $payload = [
            'BusinessShortCode' => MPESA_SHORTCODE,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => $amount,
            'PartyA'            => $phone,
            'PartyB'            => MPESA_SHORTCODE,
            'PhoneNumber'       => $phone,
            'CallBackURL'       => MPESA_CALLBACK_URL,
            'AccountReference'  => $this->sanitizeReference(
                $accountReference,
                12
            ),
            'TransactionDesc'   => $this->sanitizeReference(
                $transactionDescription,
                13
            ),
        ];

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ];

        $response = $this->request(
            'POST',
            MPESA_BASE_URL . '/mpesa/stkpush/v1/processrequest',
            $payload,
            $headers
        );

        $success = (
            (string)($response['ResponseCode'] ?? '') === '0'
        );

        paymentLog('M-Pesa initiatePayment: ' . json_encode([
            'order_id' => $orderId,
            'phone' => $phone,
            'amount' => $amount,
            'success' => $success,
            'merchant_request_id' =>
                $response['MerchantRequestID'] ?? null,
            'checkout_request_id' =>
                $response['CheckoutRequestID'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'success' => $success,
            'provider' => 'mpesa',
            'order_id' => $orderId,
            'phone' => $phone,
            'amount' => $amount,
            'merchant_request_id' =>
                $response['MerchantRequestID'] ?? null,
            'checkout_request_id' =>
                $response['CheckoutRequestID'] ?? null,
            'customer_message' =>
                $response['CustomerMessage'] ?? null,
            'response_code' =>
                $response['ResponseCode'] ?? null,
            'response_description' =>
                $response['ResponseDescription'] ?? null,
            'raw_response' => $response,
        ];
    }

    /**
     * Query STK Push status.
     *
     * @param string $checkoutRequestId
     * @return array
     * @throws Exception
     */
    public function checkStatus(string $checkoutRequestId): array
    {
        $checkoutRequestId = trim($checkoutRequestId);

        if ($checkoutRequestId === '') {
            throw new Exception('CheckoutRequestID is required.');
        }

        $timestamp = $this->generateTimestamp();
        $password  = $this->generatePassword($timestamp);
        $token     = $this->getAccessToken();

        $payload = [
            'BusinessShortCode' => MPESA_SHORTCODE,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ];

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ];

        $response = $this->request(
            'POST',
            MPESA_BASE_URL . '/mpesa/stkpushquery/v1/query',
            $payload,
            $headers
        );

        $resultCode = (string)($response['ResultCode'] ?? 'UNKNOWN');
        $success = ($resultCode === '0');

        paymentLog('M-Pesa checkStatus: ' . json_encode([
            'checkout_request_id' => $checkoutRequestId,
            'result_code' => $resultCode,
            'success' => $success,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'success' => $success,
            'provider' => 'mpesa',
            'checkout_request_id' => $checkoutRequestId,
            'result_code' => $resultCode,
            'result_desc' =>
                $response['ResultDesc'] ?? null,
            'raw_response' => $response,
        ];
    }
        /**
     * Verify callback payload structure.
     *
     * @param string $rawBody
     * @return bool
     */
    public function verifyCallback(string $rawBody): bool
    {
        if (trim($rawBody) === '') {
            return false;
        }

        $decoded = json_decode($rawBody, true);

        return is_array($decoded)
            && isset($decoded['Body']['stkCallback']);
    }

    /**
     * Parse callback payload into normalized fields.
     *
     * @param array $callbackData
     * @return array
     */
    public function parseCallback(array $callbackData): array
    {
        $stk = $callbackData['Body']['stkCallback'] ?? [];

        $resultCode = (string)($stk['ResultCode'] ?? '');
        $resultDesc = $stk['ResultDesc'] ?? null;

        $metadataItems = $stk['CallbackMetadata']['Item'] ?? [];
        $metadata = [];

        foreach ($metadataItems as $item) {
            $name = $item['Name'] ?? null;

            if ($name !== null) {
                $metadata[$name] = $item['Value'] ?? null;
            }
        }

        $success = ($resultCode === '0');

        $parsed = [
            'success' => $success,
            'provider' => 'mpesa',
            'merchant_request_id' =>
                $stk['MerchantRequestID'] ?? null,
            'checkout_request_id' =>
                $stk['CheckoutRequestID'] ?? null,
            'result_code' => $resultCode,
            'result_desc' => $resultDesc,
            'amount' =>
                isset($metadata['Amount'])
                    ? (float)$metadata['Amount']
                    : null,
            'mpesa_receipt_number' =>
                $metadata['MpesaReceiptNumber'] ?? null,
            'balance' =>
                $metadata['Balance'] ?? null,
            'transaction_date' =>
                $metadata['TransactionDate'] ?? null,
            'phone_number' =>
                isset($metadata['PhoneNumber'])
                    ? (string)$metadata['PhoneNumber']
                    : null,
            'raw_metadata' => $metadata,
            'raw_callback' => $callbackData,
        ];

        paymentLog('M-Pesa callback parsed: ' . json_encode([
            'checkout_request_id' =>
                $parsed['checkout_request_id'],
            'result_code' =>
                $parsed['result_code'],
            'success' =>
                $parsed['success'],
            'receipt' =>
                $parsed['mpesa_receipt_number'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $parsed;
    }

    /**
     * Get OAuth access token with caching.
     *
     * @return string
     * @throws Exception
     */
    private function getAccessToken(): string
    {
        if (
            $this->accessToken !== null &&
            time() < $this->tokenExpiresAt
        ) {
            return $this->accessToken;
        }

        $credentials = base64_encode(
            MPESA_CONSUMER_KEY .
            ':' .
            MPESA_CONSUMER_SECRET
        );

        $headers = [
            'Accept: application/json',
            'Authorization: Basic ' . $credentials,
        ];

        $response = $this->request(
            'GET',
            MPESA_BASE_URL .
                '/oauth/v1/generate?grant_type=client_credentials',
            null,
            $headers
        );

        if (
            !isset($response['access_token']) ||
            trim((string)$response['access_token']) === ''
        ) {
            throw new Exception(
                'Unable to obtain M-Pesa access token: ' .
                json_encode(
                    $response,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                )
            );
        }

        $this->accessToken = (string)$response['access_token'];

        $expiresIn = (int)($response['expires_in'] ?? 3599);

        if ($expiresIn < 120) {
            $expiresIn = 120;
        }

        $this->tokenExpiresAt = time() + ($expiresIn - 60);

        return $this->accessToken;
    }

    /**
     * Execute HTTP request with retry support.
     *
     * @param string     $method
     * @param string     $url
     * @param array|null $payload
     * @param array      $headers
     *
     * @return array
     * @throws Exception
     */
    private function request(
        string $method,
        string $url,
        ?array $payload = null,
        array $headers = []
    ): array {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                return $this->executeCurlRequest(
                    $method,
                    $url,
                    $payload,
                    $headers
                );
            } catch (Exception $e) {
                $lastException = $e;

                paymentLog(
                    'M-Pesa request attempt ' .
                    $attempt .
                    ' failed: ' .
                    $e->getMessage()
                );

                if ($attempt < $this->maxRetries) {
                    usleep(500000 * $attempt);
                }
            }
        }

        throw $lastException ??
            new Exception('Unknown request failure.');
    }

    /**
     * Execute one cURL request.
     *
     * @param string     $method
     * @param string     $url
     * @param array|null $payload
     * @param array      $headers
     *
     * @return array
     * @throws Exception
     */
    private function executeCurlRequest(
        string $method,
        string $url,
        ?array $payload,
        array $headers
    ): array
    {
        $ch = curl_init();

        if ($ch === false) {
            throw new Exception(
                'Unable to initialize cURL.'
            );
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => false,
        ];

        if ($payload !== null) {
            $json = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

            if ($json === false) {
                throw new Exception(
                    'Failed to encode JSON payload.'
                );
            }

            $options[CURLOPT_POSTFIELDS] = $json;
        }

        if (
            function_exists('isProduction') &&
            !isProduction()
        ) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }
                curl_setopt_array($ch, $options);

        $body = curl_exec($ch);

        $curlErrorNumber = curl_errno($ch);
        $curlErrorMessage = curl_error($ch);

        $httpCode = (int)curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        curl_close($ch);

        if ($curlErrorNumber !== 0) {
            throw new Exception(
                'cURL error (' .
                $curlErrorNumber .
                '): ' .
                $curlErrorMessage
            );
        }

        if ($body === false || trim($body) === '') {
            throw new Exception(
                'Empty response from M-Pesa API (HTTP ' .
                $httpCode .
                ').'
            );
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new Exception(
                'Invalid JSON response from M-Pesa API: ' .
                $body
            );
        }

        $decoded['_http_code'] = $httpCode;
        $decoded['_raw_body'] = $body;

        if (
            $httpCode >= 400 &&
            !isset($decoded['ResponseCode']) &&
            !isset($decoded['errorCode'])
        ) {
            throw new Exception(
                'HTTP ' .
                $httpCode .
                ': ' .
                substr($body, 0, 500)
            );
        }

        return $decoded;
    }

    /**
     * Generate Daraja timestamp.
     *
     * @return string
     */
    private function generateTimestamp(): string
    {
        return date('YmdHis');
    }

    /**
     * Generate encoded password.
     *
     * @param string $timestamp
     * @return string
     */
    private function generatePassword(string $timestamp): string
    {
        return base64_encode(
            MPESA_SHORTCODE .
            MPESA_PASSKEY .
            $timestamp
        );
    }

    /**
     * Normalize amount to whole integer (Daraja requirement).
     *
     * @param float $amount
     * @return int
     * @throws Exception
     */
    private function normalizeAmount(float $amount): int
    {
        if ($amount <= 0) {
            throw new Exception(
                'Amount must be greater than zero.'
            );
        }

        return max(1, (int)round($amount));
    }

    /**
     * Sanitize references to ASCII and fixed length.
     *
     * @param string $value
     * @param int    $maxLength
     * @return string
     */
    private function sanitizeReference(
        string $value,
        int $maxLength
    ): string {
        $value = trim($value);

        $value = preg_replace(
            '/[^A-Za-z0-9\-\s]/',
            '',
            $value
        );

        $value = preg_replace('/\s+/', ' ', $value);

        if ($value === null || $value === '') {
            $value = 'PAYMENT';
        }

        return substr($value, 0, $maxLength);
    }

    /**
     * Normalize phone numbers.
     *
     * Supported formats:
     * - 07XXXXXXXX
     * - 7XXXXXXXX
     * - 2547XXXXXXXX
     * - 2557XXXXXXXX
     * - +2547XXXXXXXX
     * - +2557XXXXXXXX
     *
     * Default local format assumes Tanzania (255).
     *
     * @param string $phone
     * @return string
     * @throws Exception
     */
    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D+/', '', $phone);

        if ($phone === null || $phone === '') {
            throw new Exception(
                'Phone number is required.'
            );
        }

        if (
            str_starts_with($phone, '0') &&
            strlen($phone) === 10
        ) {
            $phone = '255' . substr($phone, 1);
        }

        if (
            str_starts_with($phone, '7') &&
            strlen($phone) === 9
        ) {
            $phone = '255' . $phone;
        }

        if (
            !preg_match('/^(254|255)\d{9}$/', $phone)
        ) {
            throw new Exception(
                'Invalid M-Pesa phone number format.'
            );
        }

        return $phone;
    }

    /**
     * Clear cached token manually.
     *
     * @return void
     */
    public function clearTokenCache(): void
    {
        $this->accessToken = null;
        $this->tokenExpiresAt = 0;
    }

    /**
     * Get current gateway diagnostics.
     *
     * @return array
     */
    public function getDiagnostics(): array
    {
        return [
            'provider' => 'mpesa',
            'base_url' => MPESA_BASE_URL,
            'callback_url' => MPESA_CALLBACK_URL,
            'shortcode' => MPESA_SHORTCODE,
            'token_cached' =>
                $this->accessToken !== null,
            'token_expires_at' =>
                $this->tokenExpiresAt,
            'token_valid' =>
                (
                    $this->accessToken !== null &&
                    time() < $this->tokenExpiresAt
                ),
            'timeout' => $this->timeout,
            'connect_timeout' =>
                $this->connectTimeout,
            'max_retries' =>
                $this->maxRetries,
            'environment' =>
                defined('APP_ENV')
                    ? APP_ENV
                    : 'unknown',
        ];
    }
}
?>