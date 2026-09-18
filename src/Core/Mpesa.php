<?php

namespace Rider\Core;

use RuntimeException;

/**
 * M-Pesa Daraja client (OAuth + STK Push + STK Push Query + B2C). Per
 * planning/00-portfolio/shared-architecture.md the payments core is shared
 * across platforms; this is the per-platform thin wrapper (ported from
 * laundry.co.ke's reference implementation, plus the B2C leg rider payouts
 * need) and should be extracted to a shared package once a third platform
 * needs it.
 *
 * Talks to Safaricom's sandbox or production host depending on MPESA_ENV;
 * MPESA_BASE_URL overrides the host entirely (used by tests/support to point
 * at a local Daraja stand-in — never set it in production).
 */
final class Mpesa
{
    private const SANDBOX_BASE = 'https://sandbox.safaricom.co.ke';
    private const PRODUCTION_BASE = 'https://api.safaricom.co.ke';

    public static function isConfigured(): bool
    {
        return (bool) (getenv('MPESA_CONSUMER_KEY') && getenv('MPESA_CONSUMER_SECRET')
            && getenv('MPESA_SHORTCODE') && getenv('MPESA_PASSKEY'));
    }

    /** B2C needs an initiator (API operator) and its encrypted security credential on top of the STK setup. */
    public static function isB2cConfigured(): bool
    {
        return self::isConfigured()
            && (bool) (getenv('MPESA_INITIATOR_NAME') && getenv('MPESA_SECURITY_CREDENTIAL')
                && getenv('MPESA_B2C_RESULT_URL') && getenv('MPESA_B2C_TIMEOUT_URL'));
    }

    private static function baseUrl(): string
    {
        $override = getenv('MPESA_BASE_URL');
        if ($override) {
            return rtrim($override, '/');
        }

        return getenv('MPESA_ENV') === 'production' ? self::PRODUCTION_BASE : self::SANDBOX_BASE;
    }

    /**
     * @throws RuntimeException on transport failure or a non-2xx response —
     * callers must catch this and fail the request soft, since Daraja being
     * unreachable/unconfigured shouldn't fatal-error the whole request.
     */
    private static function getAccessToken(): string
    {
        $key = getenv('MPESA_CONSUMER_KEY');
        $secret = getenv('MPESA_CONSUMER_SECRET');

        if (!$key || !$secret) {
            throw new RuntimeException('M-Pesa is not configured (MPESA_CONSUMER_KEY/MPESA_CONSUMER_SECRET missing).');
        }

        $response = self::request(
            'GET',
            self::baseUrl() . '/oauth/v1/generate?grant_type=client_credentials',
            null,
            ['Authorization: Basic ' . base64_encode($key . ':' . $secret)]
        );

        $token = $response['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('M-Pesa did not return an access token: ' . json_encode($response));
        }

        return $token;
    }

    /**
     * Initiates an STK Push (Lipa na M-Pesa Online) prompt to the given
     * phone number. Returns Daraja's response array, containing
     * CheckoutRequestID/MerchantRequestID on success.
     *
     * @return array<string,mixed>
     * @throws RuntimeException if M-Pesa isn't configured or the request fails
     */
    public static function stkPush(
        string $phone,
        float $amount,
        string $accountReference,
        string $transactionDesc
    ): array {
        $shortcode = getenv('MPESA_SHORTCODE');
        $passkey = getenv('MPESA_PASSKEY');
        $callbackUrl = getenv('MPESA_CALLBACK_URL');

        if (!$shortcode || !$passkey) {
            throw new RuntimeException('M-Pesa is not configured (MPESA_SHORTCODE/MPESA_PASSKEY missing).');
        }
        if (!$callbackUrl) {
            throw new RuntimeException('MPESA_CALLBACK_URL is not configured.');
        }

        $token = self::getAccessToken();
        $timestamp = date('YmdHis');
        $password = base64_encode($shortcode . $passkey . $timestamp);

        $payload = [
            'BusinessShortCode' => $shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => (int) ceil($amount),
            'PartyA' => $phone,
            'PartyB' => $shortcode,
            'PhoneNumber' => $phone,
            'CallBackURL' => $callbackUrl,
            'AccountReference' => $accountReference,
            'TransactionDesc' => $transactionDesc,
        ];

        $response = self::request(
            'POST',
            self::baseUrl() . '/mpesa/stkpush/v1/processrequest',
            $payload,
            ['Authorization: Bearer ' . $token, 'Content-Type: application/json']
        );

        if (($response['ResponseCode'] ?? null) !== '0' && ($response['ResponseCode'] ?? null) !== 0) {
            $desc = $response['errorMessage'] ?? $response['ResponseDescription'] ?? 'Unknown error';
            throw new RuntimeException('M-Pesa STK Push was rejected: ' . $desc);
        }

        return $response;
    }

    /**
     * Asks Daraja for the outcome of an earlier STK Push — the safety net for
     * a missed/late callback (shared-architecture.md's "reconcile against the
     * Transaction Status Query API" requirement).
     *
     * @return array{result_code:?int,result_desc:string,receipt:?string} result_code is null
     *         while Safaricom is still processing the prompt (customer hasn't answered yet).
     * @throws RuntimeException if M-Pesa isn't configured or the request fails
     */
    public static function stkQuery(string $checkoutRequestId): array
    {
        $shortcode = getenv('MPESA_SHORTCODE');
        $passkey = getenv('MPESA_PASSKEY');
        if (!$shortcode || !$passkey) {
            throw new RuntimeException('M-Pesa is not configured (MPESA_SHORTCODE/MPESA_PASSKEY missing).');
        }

        $token = self::getAccessToken();
        $timestamp = date('YmdHis');

        try {
            $response = self::request(
                'POST',
                self::baseUrl() . '/mpesa/stkpushquery/v1/query',
                [
                    'BusinessShortCode' => $shortcode,
                    'Password' => base64_encode($shortcode . $passkey . $timestamp),
                    'Timestamp' => $timestamp,
                    'CheckoutRequestID' => $checkoutRequestId,
                ],
                ['Authorization: Bearer ' . $token, 'Content-Type: application/json']
            );
        } catch (RuntimeException $e) {
            // Daraja answers a query for a prompt the customer hasn't
            // responded to yet with an HTTP 500 "being processed" error.
            if (stripos($e->getMessage(), 'being processed') !== false) {
                return ['result_code' => null, 'result_desc' => 'Awaiting customer PIN entry', 'receipt' => null];
            }
            throw $e;
        }

        if (!isset($response['ResultCode'])) {
            return ['result_code' => null, 'result_desc' => (string) ($response['ResponseDescription'] ?? 'Pending'), 'receipt' => null];
        }

        return [
            'result_code' => (int) $response['ResultCode'],
            'result_desc' => (string) ($response['ResultDesc'] ?? ''),
            'receipt' => null,
        ];
    }

    /**
     * Disburses a rider payout to their M-Pesa number (Business Payment).
     * Returns Daraja's acknowledgement; the actual success/failure arrives
     * later on MPESA_B2C_RESULT_URL (see PaymentController::b2cResult).
     *
     * @return array<string,mixed> includes ConversationID / OriginatorConversationID
     * @throws RuntimeException if B2C isn't configured or Daraja rejects the request
     */
    public static function b2c(string $phone, int $amount, string $remarks): array
    {
        if (!self::isB2cConfigured()) {
            throw new RuntimeException('M-Pesa B2C is not configured (MPESA_INITIATOR_NAME/MPESA_SECURITY_CREDENTIAL/MPESA_B2C_*_URL missing).');
        }

        $token = self::getAccessToken();

        $response = self::request(
            'POST',
            self::baseUrl() . '/mpesa/b2c/v1/paymentrequest',
            [
                'InitiatorName' => getenv('MPESA_INITIATOR_NAME'),
                'SecurityCredential' => getenv('MPESA_SECURITY_CREDENTIAL'),
                'CommandID' => 'BusinessPayment',
                'Amount' => $amount,
                'PartyA' => getenv('MPESA_SHORTCODE'),
                'PartyB' => $phone,
                'Remarks' => $remarks,
                'QueueTimeOutURL' => getenv('MPESA_B2C_TIMEOUT_URL'),
                'ResultURL' => getenv('MPESA_B2C_RESULT_URL'),
                'Occasion' => 'Rider payout',
            ],
            ['Authorization: Bearer ' . $token, 'Content-Type: application/json']
        );

        if (($response['ResponseCode'] ?? null) !== '0' && ($response['ResponseCode'] ?? null) !== 0) {
            $desc = $response['errorMessage'] ?? $response['ResponseDescription'] ?? 'Unknown error';
            throw new RuntimeException('M-Pesa B2C was rejected: ' . $desc);
        }

        return $response;
    }

    /**
     * Normalizes a Kenyan phone number to Daraja's expected 2547XXXXXXXX /
     * 2541XXXXXXXX format (no leading +, no leading 0).
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (str_starts_with($digits, '0')) {
            return '254' . substr($digits, 1);
        }
        if (str_starts_with($digits, '254')) {
            return $digits;
        }
        if (str_starts_with($digits, '7') || str_starts_with($digits, '1')) {
            return '254' . $digits;
        }

        return $digits;
    }

    /**
     * @param array<string,mixed>|null $body
     * @param string[] $headers
     * @return array<string,mixed>
     */
    private static function request(string $method, string $url, ?array $body, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize HTTP client for M-Pesa request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new MpesaTransportException("M-Pesa request to {$url} failed: {$error}");
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            // Not a Daraja answer at all (empty body, gateway HTML) — we can't tell whether it acted on the request.
            throw new MpesaTransportException("M-Pesa returned a non-JSON response (HTTP {$httpCode}): " . substr((string) $raw, 0, 500));
        }

        if ($httpCode >= 400) {
            $desc = $decoded['errorMessage'] ?? $decoded['ResponseDescription'] ?? json_encode($decoded);
            throw new RuntimeException("M-Pesa request to {$url} failed (HTTP {$httpCode}): {$desc}");
        }

        return $decoded;
    }
}
