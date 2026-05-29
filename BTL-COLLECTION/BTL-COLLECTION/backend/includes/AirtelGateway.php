<?php
/**
 * WORK2/backend/includes/AirtelGateway.php
 * ==========================================================
 * Airtel Money Gateway
 * Supports Airtel Africa Open API (Tanzania)
 *
 * Features:
 * - OAuth token generation
 * - Collection payment request
 * - Transaction status query
 * - Callback verification helper
 * - Detailed logging
 * - MAMP compatible
 *
 * Requires:
 * - backend/config/payment_config.php
 * - PHP cURL extension enabled
 *
 * Documentation:
 * https://developers.airtel.africa
 * ==========================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/payment_config.php';

class AirtelGateway
{
    /**
     * Cached OAuth token and expiry.
     */
    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;

    /**
     * Constructor validation.
     */
    public function __construct()
    {
        if (!ENABLE_AIRTEL_MONEY) {
            throw new Exception('Airtel Money is disabled in payment_config.php');
        }

        if (
            AIRTEL_CLIENT_ID === 'YOUR_AIRTEL_CLIENT_ID_HERE' ||
            AIRTEL_CLIENT_SECRET === 'YOUR_AIRTEL_CLIENT_SECRET_HERE'
        ) {
            throw new Exception(
                'Airtel Money credentials are not configured in payment_config.php'
            );
        }
    }

    /**
     * Initiate a customer payment request.
     *
     * @param string $phone   Customer phone number (255XXXXXXXXX)
     * @param float  $amount  Amount in TZS
     * @param string $orderId Internal order ID
     * @param string $reference Merchant reference
     *
     * @return array
     * @throws Exception
     */
    public function initiatePayment(
        string $phone,
        float $amount,
        string $orderId,
        string $reference = ''
    ): array {
        $phone = $this->normalizePhone($phone);
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new Exception('Amount must be greater than zero.');
        }

        if ($reference === '') {
            $reference = 'ORDER-' . $orderId;
        }

        $token = $this->getAccessToken();
        $transactionId = $this->generateTransactionId();

        $endpoint = AIRTEL_BASE_URL .
            '/merchant/v1/payments/';

        $payload = [
            'reference' => $reference,
            'subscriber' => [
                'country' => AIRTEL_COUNTRY,
                'currency' => AIRTEL_CURRENCY,
                'msisdn' => $phone,
            ],
            'transaction' => [
                'amount' => number_format($amount, 2, '.', ''),
                'country' => AIRTEL_COUNTRY,
                'currency' => AIRTEL_CURRENCY,
                'id' => $transactionId,
            ],
        ];

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'X-Country: ' . AIRTEL_COUNTRY,
            'X-Currency: ' . AIRTEL_CURRENCY,
        ];

        $response = $this->request(
            'POST',
            $endpoint,
            $payload,
            $headers
        );

        paymentLog('Airtel initiatePayment: ' . json_encode([
            'order_id' => $orderId,
            'phone' => $phone,
            'amount' => $amount,
            'reference' => $reference,
            'transaction_id' => $transactionId,
            'response' => $response,
        ]));

        return [
            'success' => $this->isSuccessResponse($response),
            'provider' => 'airtel_money',
            'transaction_id' => $transactionId,
            'order_id' => $orderId,
            'reference' => $reference,
            'phone' => $phone,
            'amount' => $amount,
            'raw_response' => $response,
        ];
    }

    /**
     * Query transaction status.
     *
     * @param string $transactionId
     * @return array
     * @throws Exception
     */
    public function checkStatus(string $transactionId): array
    {
        $token = $this->getAccessToken();

        $endpoint = AIRTEL_BASE_URL .
            '/standard/v1/payments/' .
            urlencode($transactionId);

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'X-Country: ' . AIRTEL_COUNTRY,
            'X-Currency: ' . AIRTEL_CURRENCY,
        ];

        $response = $this->request(
            'GET',
            $endpoint,
            null,
            $headers
        );

        paymentLog('Airtel checkStatus: ' . json_encode([
            'transaction_id' => $transactionId,
            'response' => $response,
        ]));

        return [
            'success' => true,
            'provider' => 'airtel_money',
            'transaction_id' => $transactionId,
            'status' => $response['status']['code'] ?? 'UNKNOWN',
            'raw_response' => $response,
        ];
    }

    /**
     * Verify callback authenticity.
     * Placeholder helper; can be enhanced if Airtel adds signatures.
     */
    public function verifyCallback(string $rawBody): bool
    {
        return !empty($rawBody);
    }

    /**
     * Obtain OAuth token.
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

        $endpoint = AIRTEL_BASE_URL . '/auth/oauth2/token';

        $payload = [
            'client_id' => AIRTEL_CLIENT_ID,
            'client_secret' => AIRTEL_CLIENT_SECRET,
            'grant_type' => 'client_credentials',
        ];

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $response = $this->request(
            'POST',
            $endpoint,
            $payload,
            $headers
        );

        if (!isset($response['access_token'])) {
            throw new Exception(
                'Unable to obtain Airtel OAuth token: ' .
                json_encode($response)
            );
        }

        $this->accessToken = $response['access_token'];
        $expiresIn = (int)($response['expires_in'] ?? 3600);
        $this->tokenExpiresAt = time() + max(60, $expiresIn - 60);

        return $this->accessToken;
    }

    /**
     * Execute HTTP request using cURL.
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
        $ch = curl_init();

        if ($ch === false) {
            throw new Exception('Unable to initialize cURL.');
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        }

        // Sandbox/local development convenience.
        if (!isProduction()) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($errno !== 0) {
            throw new Exception(
                'cURL error (' . $errno . '): ' . $error
            );
        }

        if ($body === false || $body === '') {
            throw new Exception(
                'Empty response from Airtel API (HTTP ' . $httpCode . ').'
            );
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new Exception(
                'Invalid JSON response from Airtel API: ' . $body
            );
        }

        $decoded['_http_code'] = $httpCode;
        $decoded['_raw_body'] = $body;

        return $decoded;
    }

    /**
     * Determine whether Airtel accepted the payment request.
     */
    private function isSuccessResponse(array $response): bool
    {
        $code = strtoupper((string)($response['status']['code'] ?? ''));

        return in_array($code, [
            'SUCCESS',
            '200',
            '201',
            'TS',
        ], true);
    }

    /**
     * Normalize phone number to 255XXXXXXXXX.
     *
     * @param string $phone
     * @return string
     * @throws Exception
     */
    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D+/', '', $phone);

        if (str_starts_with($phone, '0')) {
            $phone = '255' . substr($phone, 1);
        }

        if (str_starts_with($phone, '7') && strlen($phone) === 9) {
            $phone = '255' . $phone;
        }

        if (!preg_match('/^255\d{9}$/', $phone)) {
            throw new Exception(
                'Invalid Tanzanian phone number format.'
            );
        }

        return $phone;
    }

    /**
     * Generate unique transaction ID.
     */
    private function generateTransactionId(): string
    {
        return 'AIR' .
            date('YmdHis') .
            strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }
}
?>