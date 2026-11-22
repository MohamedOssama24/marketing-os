<?php
declare(strict_types=1);

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;

/** Chiamate essenziali all'API di Stripe (Checkout, portale cliente, lettura abbonamenti) e verifica dei webhook. */
final class Stripe
{
    private const API = 'https://api.stripe.com/v1/';

    public static function enabled(): bool
    {
        return (string)getenv('STRIPE_SECRET_KEY') !== '';
    }

    public static function post(string $path, array $params): array
    {
        return self::call('POST', $path, ['form_params' => $params]);
    }

    public static function get(string $path, array $query = []): array
    {
        return self::call('GET', $path, ['query' => $query]);
    }

    private static function call(string $method, string $path, array $options): array
    {
        $key = (string)getenv('STRIPE_SECRET_KEY');
        if ($key === '') throw new RuntimeException('STRIPE_SECRET_KEY mancante.');
        $http = new HttpClient(['base_uri' => self::API, 'timeout' => 30, 'connect_timeout' => 10]);
        try {
            $response = $http->request($method, $path, $options + ['auth' => [$key, '']]);
        } catch (ConnectException) {
            throw new StripeException('Impossibile contattare Stripe. Riprova tra poco.');
        } catch (RequestException $e) {
            $body = (string)$e->getResponse()?->getBody();
            error_log('Stripe API error ' . ($e->getResponse()?->getStatusCode() ?? 0) . ': ' . mb_substr($body, 0, 1000));
            throw new StripeException('Stripe ha rifiutato la richiesta: ' . (json_decode($body, true)['error']['message'] ?? 'errore sconosciuto'));
        }
        return json_decode((string)$response->getBody(), true) ?: [];
    }

    /**
     * Verifica la firma dell'intestazione Stripe-Signature (t=…,v1=…) con il segreto del webhook.
     * Rifiuta eventi più vecchi di 5 minuti, così un evento intercettato non si può rimandare più tardi.
     */
    public static function verifyWebhook(string $payload, string $header, string $secret, int $tolerance = 300): bool
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') $timestamp = (int)$v;
            if ($k === 'v1') $signatures[] = $v;
        }
        if (!$timestamp || !$signatures || abs(time() - $timestamp) > $tolerance) return false;
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) return true;
        }
        return false;
    }
}

final class StripeException extends RuntimeException {}
