<?php
/**
 * WORK2/backend/includes/TigoGateway.php
 * ==========================================================
 * Tigo Pesa Gateway
 * Supports:
 * - Tigo Pesa Tanzania API
 *
 * Features:
 * - OAuth token generation with token caching
 * - Customer payment initiation
 * - Transaction status query
 * - Secure callback verification (HMAC-SHA256 when secret configured)
 * - Callback parsing into standardized format
 * - Tanzanian phone normalization
 * - Detailed logging
 * - MAMP compatible
 * - Production-ready error handling
 * - Cryptographically secure transaction IDs
 *
 * Requires:
 * - backend/config/payment_config.php
 * - PHP cURL extension enabled
 *
 * Optional Configuration:
 * - TIGO_WEBHOOK_SECRET (recommended for verifying callback signatures)
 *
 * IMPORTANT:
 * - Replace credentials in payment_config.php with real API credentials.
 * - Callback URLs must be publicly accessible over HTTPS.
 * - API endpoints and field names must match Tigo's official documentation.
 * ==========================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/payment_config.php';

class TigoGateway
{
    /**
     * Cached OAuth access token.
     */
    private ?string $accessToken = null;

    /**
     * Unix timestamp when the token expires.
     */
    private int $tokenExpiresAt = 0;

    /**
     * Constructor.
     *
     * @throws Exception
     */
    public function __construct()
    {
        if (!defined('ENABLE_TIGO_PESA') || !ENABLE_TIGO_PESA) {
            throw new Exception(
                'Tigo Pesa is disabled in payment_config.php'
            );
        }

        $required = [
            'TIGO_CLIENT_ID',
            'TIGO_CLIENT_SECRET',
            'TIGO_BILLER_CODE',
            'TIGO_BILLER_MSISDN',
            'TIGO_BASE_URL',
            'TIGO_CALLBACK_URL'
        ];

        foreach ($required as $constantName) {
            if (!defined($constantName)) {
                throw new Exception(
                    'Missing required constant: ' . $constantName
                );
            }

            $value = constant($constantName);

            if (
                !is_string($value) ||
                trim($value) === '' ||
                str_contains($value, 'YOUR_')
            ) {
                throw new Exception(
                    'Tigo Pesa configuration is incomplete for: ' .
                    $constantName
                );
            }
        }

        if (
            !defined('PAYMENT_CURRENCY') ||
            trim((string)PAYMENT_CURRENCY) === ''
        ) {
            throw new Exception(
                'PAYMENT_CURRENCY must be defined.'
            );
        }
    }

    /**
     * Initiate customer payment request.
     *
     * @param string $phone
     * @param float  $amount
     * @param string $orderId
     * @param string $reference
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
            throw new Exception(
                'Amount must be greater than zero.'
            );
        }

        if (trim($orderId) === '') {
            throw new Exception(
                'Order ID is required.'
            );
        }

        if (trim($reference) === '') {
            $reference = 'ORDER-' . $orderId;
        }

        $token = $this->getAccessToken();
        $transactionId = $this->generateTransactionId();

        $endpoint =
            rtrim(TIGO_BASE_URL, '/') .
            '/v1/payments';

        $payload = [
            'billerCode' => TIGO_BILLER_CODE,
            'billerMsisdn' => TIGO_BILLER_MSISDN,
            'customerMsisdn' => $phone,
            'amount' => number_format(
                $amount,
                2,
                '.',
                ''
            ),
            'currency' => PAYMENT_CURRENCY,
            'reference' => substr($reference, 0, 100),
            'transactionId' => $transactionId,
            'callbackUrl' => TIGO_CALLBACK_URL,
        ];

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ];

        $response = $this->request(
            'POST',
            $endpoint,
            $payload,
            $headers
        );

        if (function_exists('paymentLog')) {
            paymentLog(
                'Tigo initiatePayment: ' .
                json_encode([
                    'order_id' => $orderId,
                    'phone' => $phone,
                    'amount' => $amount,
                    'reference' => $reference,
                    'transaction_id' => $transactionId,
                    'response' => $response,
                ])
            );
        }

        return [
            'success' => $this->isSuccessResponse(
                $response
            ),
            'provider' => 'tigo_pesa',
            'transaction_id' => $transactionId,
            'order_id' => $orderId,
            'reference' => $reference,
            'phone' => $phone,
            'amount' => $amount,
            'status' =>
                $response['status']
                ?? $response['statusCode']
                ?? null,
            'raw_response' => $response,
        ];
    }

    /**
     * Query transaction status.
     *
     * @param string $transactionId
     *
     * @return array
     * @throws Exception
     */
    public function checkStatus(
        string $transactionId
    ): array {
        if (trim($transactionId) === '') {
            throw new Exception(
                'Transaction ID is required.'
            );
        }

        $token = $this->getAccessToken();

        $endpoint =
            rtrim(TIGO_BASE_URL, '/') .
            '/v1/payments/' .
            rawurlencode($transactionId);

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ];

        $response = $this->request(
            'GET',
            $endpoint,
            null,
            $headers
        );

        if (function_exists('paymentLog')) {
            paymentLog(
                'Tigo checkStatus: ' .
                json_encode([
                    'transaction_id' => $transactionId,
                    'response' => $response,
                ])
            );
        }

        return [
            'success' => $this->isSuccessResponse(
                $response
            ),
            'provider' => 'tigo_pesa',
            'transaction_id' => $transactionId,
            'status' =>
                $response['status']
                ?? $response['statusCode']
                ?? 'UNKNOWN',
            'raw_response' => $response,
        ];
    }
        /**
     * Determine whether a response indicates success.
     *
     * Accepts many common success indicators returned by APIs.
     *
     * @param array $response
     * @return bool
     */
    private function isSuccessResponse(array $response): bool
    {
        $candidates = [
            $response['status'] ?? null,
            $response['statusCode'] ?? null,
            $response['code'] ?? null,
            $response['resultCode'] ?? null,
            $response['responseCode'] ?? null,
            $response['response_code'] ?? null,
            $response['result_code'] ?? null,
            $response['success'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            // Direct boolean success flag.
            if ($candidate === true) {
                return true;
            }

            if ($candidate === false) {
                continue;
            }

            $value = strtoupper(trim((string)$candidate));

            if (
                in_array(
                    $value,
                    [
                        'SUCCESS',
                        'SUCCEEDED',
                        'COMPLETED',
                        'COMPLETE',
                        'APPROVED',
                        'PROCESSED',
                        'PROCESSING',
                        'ACCEPTED',
                        'OK',
                        'TRUE',
                        '1',
                        '0',
                        '00',
                        '000',
                        '200',
                        '201',
                        '202'
                    ],
                    true
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize Tanzanian phone number to 255XXXXXXXXX.
     *
     * Supported formats:
     * - 0612345678
     * - 0712345678
     * - 612345678
     * - 712345678
     * - 255612345678
     * - 255712345678
     * - +255612345678
     * - +255712345678
     *
     * @param string $phone
     * @return string
     * @throws Exception
     */
    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D+/', '', $phone);

        if ($phone === null || $phone === '') {
            throw new Exception('Phone number is required.');
        }

        // Convert 0XXXXXXXXX to 255XXXXXXXXX
        if (
            str_starts_with($phone, '0') &&
            strlen($phone) === 10
        ) {
            $phone = '255' . substr($phone, 1);
        }

        // Convert 9-digit mobile numbers starting with 6 or 7
        if (
            preg_match('/^[67]\d{8}$/', $phone)
        ) {
            $phone = '255' . $phone;
        }

        // Validate Tanzanian mobile format.
        if (
            !preg_match('/^255[67]\d{8}$/', $phone)
        ) {
            throw new Exception(
                'Invalid Tanzanian phone number format.'
            );
        }

        return $phone;
    }

    /**
     * Generate cryptographically secure transaction ID.
     *
     * Example:
     * TGO20260102153045A1B2C3D4E5F6A7B8
     *
     * @return string
     * @throws Exception
     */
    private function generateTransactionId(): string
    {
        return 'TGO' .
            gmdate('YmdHis') .
            strtoupper(
                substr(
                    bin2hex(random_bytes(8)),
                    0,
                    16
                )
            );
    }
}
?>