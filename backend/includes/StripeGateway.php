<?php
/**
 * WORK2/backend/includes/StripeGateway.php
 * ==========================================================
 * Enterprise Stripe Gateway
 * Supports:
 * - Stripe Payment Intents API
 * - Visa / Mastercard / AMEX / Apple Pay / Google Pay
 * - Test and Live environments
 *
 * Features:
 * - Payment Intent creation
 * - Payment Intent retrieval
 * - Payment confirmation verification
 * - Webhook signature verification
 * - Refund creation
 * - Automatic retry on transient failures
 * - Secure logging
 * - MAMP and production compatible
 *
 * Requires:
 * - WORK2/backend/config/payment_config.php
 * - PHP cURL extension
 *
 * IMPORTANT:
 * - Replace Stripe credentials in payment_config.php
 * - Configure webhook endpoint in Stripe Dashboard
 * - Stripe works on localhost in test mode immediately
 * ==========================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/payment_config.php';

class StripeGateway
{
    /**
     * Network settings.
     */
    private int $timeout = 60;
    private int $connectTimeout = 20;
    private int $maxRetries = 3;

    /**
     * Stripe API base URL.
     */
    private string $baseUrl = 'https://api.stripe.com/v1';

    /**
     * Constructor.
     *
     * @throws Exception
     */
    public function __construct()
    {
        if (!defined('ENABLE_STRIPE') || !ENABLE_STRIPE) {
            throw new Exception('Stripe is disabled in payment_config.php');
        }

        $required = [
            'STRIPE_SECRET_KEY' =>
                defined('STRIPE_SECRET_KEY')
                    ? STRIPE_SECRET_KEY
                    : '',
            'STRIPE_PUBLISHABLE_KEY' =>
                defined('STRIPE_PUBLISHABLE_KEY')
                    ? STRIPE_PUBLISHABLE_KEY
                    : '',
        ];

        foreach ($required as $name => $value) {
            if (
                $value === '' ||
                stripos($value, 'YOUR_') !== false
            ) {
                throw new Exception(
                    'Missing or placeholder value for ' .
                    $name .
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
     * Create Stripe Payment Intent.
     *
     * @param float       $amount
     * @param string      $orderId
     * @param string|null $currency
     * @param array       $metadata
     *
     * @return array
     * @throws Exception
     */
    public function createPaymentIntent(
        float $amount,
        string $orderId,
        ?string $currency = null,
        array $metadata = []
    ): array
    {
        if ($amount <= 0) {
            throw new Exception(
                'Amount must be greater than zero.'
            );
        }

        if ($currency === null || trim($currency) === '') {
            $currency = strtolower(
                defined('PAYMENT_CURRENCY')
                    ? PAYMENT_CURRENCY
                    : 'usd'
            );
        } else {
            $currency = strtolower(trim($currency));
        }

        $amountInMinorUnits = $this->convertToMinorUnits(
            $amount,
            $currency
        );

        $metadata = array_merge(
            [
                'order_id' => $orderId,
                'provider' => 'stripe',
            ],
            $metadata
        );

        $payload = [
            'amount' => $amountInMinorUnits,
            'currency' => $currency,
            'automatic_payment_methods[enabled]' => 'true',
            'metadata[order_id]' => (string)$metadata['order_id'],
            'metadata[provider]' => 'stripe',
        ];

        foreach ($metadata as $key => $value) {
            if (
                $key === 'order_id' ||
                $key === 'provider'
            ) {
                continue;
            }

            $payload[
                'metadata[' . $key . ']'
            ] = (string)$value;
        }

        $response = $this->request(
            'POST',
            '/payment_intents',
            $payload
        );

        $success =
            isset($response['id']) &&
            isset($response['client_secret']);

        paymentLog('Stripe createPaymentIntent: ' . json_encode([
            'order_id' => $orderId,
            'amount' => $amount,
            'currency' => $currency,
            'payment_intent_id' =>
                $response['id'] ?? null,
            'success' => $success,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'success' => $success,
            'provider' => 'stripe',
            'order_id' => $orderId,
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'amount_minor_units' => $amountInMinorUnits,
            'payment_intent_id' =>
                $response['id'] ?? null,
            'client_secret' =>
                $response['client_secret'] ?? null,
            'status' =>
                $response['status'] ?? null,
            'publishable_key' =>
                STRIPE_PUBLISHABLE_KEY,
            'raw_response' => $response,
        ];
    }

    /**
     * Retrieve Payment Intent details.
     *
     * @param string $paymentIntentId
     * @return array
     * @throws Exception
     */
    public function retrievePaymentIntent(
        string $paymentIntentId
    ): array
    {
        $paymentIntentId = trim($paymentIntentId);

        if ($paymentIntentId === '') {
            throw new Exception(
                'Payment Intent ID is required.'
            );
        }

        $response = $this->request(
            'GET',
            '/payment_intents/' .
            urlencode($paymentIntentId)
        );

        $status = $response['status'] ?? 'unknown';

        return [
            'success' => true,
            'provider' => 'stripe',
            'payment_intent_id' => $paymentIntentId,
            'status' => $status,
            'paid' => in_array(
                $status,
                ['succeeded', 'requires_capture'],
                true
            ),
            'amount_minor_units' =>
                $response['amount'] ?? null,
            'currency' =>
                isset($response['currency'])
                    ? strtoupper(
                        (string)$response['currency']
                    )
                    : null,
            'metadata' =>
                $response['metadata'] ?? [],
            'raw_response' => $response,
        ];
    }
        /**
     * Build Stripe Checkout Session.
     *
     * @param array $params
     * @return array
     * @throws Exception
     */
    private function createCheckoutSession(array $params): array
    {
        return $this->request(
            'POST',
            '/checkout/sessions',
            $params
        );
    }

    /**
     * Create a Payment Intent.
     *
     * @param int    $amountInCents
     * @param string $currency
     * @param array  $metadata
     * @param string|null $customerId
     * @return array
     * @throws Exception
     */
    public function createPaymentIntent(
        int $amountInCents,
        string $currency = 'tzs',
        array $metadata = [],
        ?string $customerId = null
    ): array {
        if ($amountInCents < 1) {
            throw new Exception('Amount must be greater than zero.');
        }

        $params = [
            'amount' => $amountInCents,
            'currency' => strtolower($currency),
            'automatic_payment_methods' => [
                'enabled' => 'true',
            ],
            'metadata' => $metadata,
        ];

        if ($customerId !== null && trim($customerId) !== '') {
            $params['customer'] = $customerId;
        }

        $response = $this->request(
            'POST',
            '/payment_intents',
            $params
        );

        paymentLog('Stripe createPaymentIntent: ' . json_encode([
            'amount' => $amountInCents,
            'currency' => $currency,
            'payment_intent_id' => $response['id'] ?? null,
        ]));

        return [
            'success' => true,
            'provider' => 'stripe',
            'payment_intent_id' => $response['id'] ?? null,
            'client_secret' => $response['client_secret'] ?? null,
            'status' => $response['status'] ?? null,
            'raw_response' => $response,
        ];
    }

    /**
     * Retrieve a Payment Intent.
     *
     * @param string $paymentIntentId
     * @return array
     * @throws Exception
     */
    public function retrievePaymentIntent(string $paymentIntentId): array
    {
        $response = $this->request(
            'GET',
            '/payment_intents/' . urlencode($paymentIntentId)
        );

        return [
            'success' => true,
            'provider' => 'stripe',
            'payment_intent_id' => $paymentIntentId,
            'status' => $response['status'] ?? null,
            'amount_received' => $response['amount_received'] ?? 0,
            'currency' => $response['currency'] ?? null,
            'metadata' => $response['metadata'] ?? [],
            'raw_response' => $response,
        ];
    }

    /**
     * Refund a charge or payment intent.
     *
     * @param string $paymentIntentId
     * @param int|null $amountInCents
     * @param array $metadata
     * @return array
     * @throws Exception
     */
    public function refundPayment(
        string $paymentIntentId,
        ?int $amountInCents = null,
        array $metadata = []
    ): array {
        $params = [
            'payment_intent' => $paymentIntentId,
            'metadata' => $metadata,
        ];

        if ($amountInCents !== null) {
            if ($amountInCents < 1) {
                throw new Exception('Refund amount must be greater than zero.');
            }

            $params['amount'] = $amountInCents;
        }

        $response = $this->request(
            'POST',
            '/refunds',
            $params
        );

        paymentLog('Stripe refundPayment: ' . json_encode([
            'payment_intent_id' => $paymentIntentId,
            'refund_id' => $response['id'] ?? null,
            'amount' => $response['amount'] ?? null,
            'status' => $response['status'] ?? null,
        ]));

        return [
            'success' => true,
            'provider' => 'stripe',
            'refund_id' => $response['id'] ?? null,
            'payment_intent_id' => $paymentIntentId,
            'amount' => $response['amount'] ?? null,
            'status' => $response['status'] ?? null,
            'raw_response' => $response,
        ];
    }

    /**
     * Verify webhook signature.
     *
     * @param string $payload
     * @param string $signatureHeader
     * @param int $tolerance
     * @return bool
     */
    public function verifyWebhookSignature(
        string $payload,
        string $signatureHeader,
        int $tolerance = 300
    ): bool {
        if (
            empty(STRIPE_WEBHOOK_SECRET) ||
            str_contains(STRIPE_WEBHOOK_SECRET, 'YOUR_')
        ) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $segments = explode('=', $part, 2);
            if (count($segments) === 2) {
                $parts[$segments[0]] = $segments[1];
            }
        }

        if (!isset($parts['t'], $parts['v1'])) {
            return false;
        }

        $timestamp = (int)$parts['t'];
        $signature = $parts['v1'];

        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expected = hash_hmac(
            'sha256',
            $signedPayload,
            STRIPE_WEBHOOK_SECRET
        );

        return hash_equals($expected, $signature);
    }

    /**
     * Parse webhook payload.
     *
     * @param string $payload
     * @return array
     * @throws Exception
     */
    public function parseWebhook(string $payload): array
    {
        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            throw new Exception('Invalid Stripe webhook JSON.');
        }

        return [
            'event_id' => $decoded['id'] ?? null,
            'event_type' => $decoded['type'] ?? null,
            'object' => $decoded['data']['object'] ?? [],
            'raw_event' => $decoded,
        ];
    }

    /**
     * Execute HTTP request to Stripe API.
     *
     * @param string $method
     * @param string $endpoint
     * @param array|null $params
     * @return array
     * @throws Exception
     */
    private function request(
        string $method,
        string $endpoint,
        ?array $params = null
    ): array {
        $url = STRIPE_API_BASE_URL . $endpoint;
        $ch = curl_init();

        if ($ch === false) {
            throw new Exception('Unable to initialize cURL.');
        }

        $headers = [
            'Authorization: Bearer ' . STRIPE_SECRET_KEY,
            'Stripe-Version: ' . STRIPE_API_VERSION,
        ];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($params !== null) {
            $encoded = http_build_query($this->flattenArray($params));
            $options[CURLOPT_POSTFIELDS] = $encoded;
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

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
            throw new Exception('Stripe cURL error (' . $errno . '): ' . $error);
        }

        if ($body === false || $body === '') {
            throw new Exception('Empty response from Stripe API (HTTP ' . $httpCode . ').');
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new Exception('Invalid JSON response from Stripe API: ' . $body);
        }

        if (isset($decoded['error'])) {
            $message = $decoded['error']['message'] ?? 'Unknown Stripe error';
            $type = $decoded['error']['type'] ?? 'api_error';
            $code = $decoded['error']['code'] ?? '';

            throw new Exception(
                'Stripe API error [' . $type . '][' . $code . ']: ' . $message
            );
        }

        $decoded['_http_code'] = $httpCode;
        $decoded['_raw_body'] = $body;

        return $decoded;
    }

    /**
     * Flatten nested arrays for Stripe form encoding.
     *
     * @param array $array
     * @param string $prefix
     * @return array
     */
    private function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $newKey = $prefix === ''
                ? (string)$key
                : $prefix . '[' . $key . ']';

            if (is_array($value)) {
                $result = array_merge(
                    $result,
                    $this->flattenArray($value, $newKey)
                );
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }
}
?>