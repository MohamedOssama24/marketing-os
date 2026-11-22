<?php
declare(strict_types=1);

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

/**
 * Stesse istruzioni e stessi formati di risposta di Claude, ma generati con Google Gemini
 * (ha un piano gratuito con limiti di richieste; sul piano gratuito Google può usare i dati per migliorare i suoi modelli).
 */
final class GeminiMarketingAi extends ClaudeMarketingAi
{
    public const PROVIDER = 'gemini';

    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
    private const FALLBACK_MODEL = 'gemini-flash-lite-latest';

    private HttpClient $http;
    private string $apiKey;

    public function __construct()
    {
        $apiKey = getenv('GEMINI_API_KEY');
        if (!$apiKey) throw new GenerationException('Generazione AI non configurata: manca GEMINI_API_KEY.');
        $this->apiKey = $apiKey;
        $this->http = new HttpClient(['timeout' => 120, 'connect_timeout' => 10]);
    }

    protected function request(string $system, string $prompt, array $schema, ?string $effort, int $maxTokens): array
    {
        $model = getenv('GEMINI_MODEL') ?: 'gemini-flash-latest';
        $config = [
            'responseMimeType' => 'application/json',
            'responseJsonSchema' => self::geminiSchema($schema),
            // Su Gemini il ragionamento conta nei token di risposta: serve più margine che con Claude.
            'maxOutputTokens' => min(65536, max($maxTokens, 8000) * 2),
        ];
        // Il ragionamento è la parte più lenta: per testi di marketing basta poco (GEMINI_THINKING: minimal, low, medium, high).
        $thinking = strtolower((string)(getenv('GEMINI_THINKING') ?: 'low'));
        if (in_array($thinking, ['minimal', 'low', 'medium', 'high'], true)) $config['thinkingConfig'] = ['thinkingLevel' => $thinking];
        $body = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => $config,
        ];
        // Sul piano gratuito il modello principale è spesso "molto richiesto" (503) o non risponde: invece di aspettare
        // si passa subito al modello leggero, e solo alla fine si riprova il principale. [modello, attesa, tempo massimo]
        $attempts = $model === self::FALLBACK_MODEL
            ? [[$model, 0, 90], [$model, 3, 120]]
            : [[$model, 0, 40], [self::FALLBACK_MODEL, 0, 90], [$model, 4, 120]];
        foreach ($attempts as $i => [$attemptModel, $wait, $timeout]) {
            sleep($wait);
            try {
                $response = $this->send($attemptModel, $body, $timeout);
                break;
            } catch (ConnectException) {
                // Anche una risposta che non arriva entro il tempo massimo è un sovraccarico momentaneo.
                if ($i < count($attempts) - 1) continue;
                throw new ProviderUnavailableException('Impossibile contattare il servizio AI.');
            } catch (RequestException $e) {
                $status = $e->getResponse()?->getStatusCode() ?? 0;
                // Un modello che non conosce il livello di ragionamento: da qui in poi si chiede senza.
                if ($status === 400 && isset($body['generationConfig']['thinkingConfig']) && str_contains((string)$e->getResponse()?->getBody(), 'hinking')) {
                    unset($body['generationConfig']['thinkingConfig']);
                    try {
                        $response = $this->send($attemptModel, $body, $timeout);
                        break;
                    } catch (RequestException $retry) {
                        $e = $retry;
                        $status = $e->getResponse()?->getStatusCode() ?? 0;
                    }
                }
                // 429: il modello leggero ha una quota sua, quindi vale la pena provarlo.
                if (in_array($status, [429, 500, 503], true) && $i < count($attempts) - 1) continue;
                $this->fail($e, $attemptModel);
            }
        }

        $data = json_decode((string)$response->getBody(), true);
        if (!empty($data['promptFeedback']['blockReason'])) throw new GenerationException('L\'AI ha rifiutato questa richiesta. Riformula il brief.');
        $candidate = $data['candidates'][0] ?? [];
        $finish = $candidate['finishReason'] ?? '';
        if ($finish === 'MAX_TOKENS') throw new GenerationException('Risposta AI troppo lunga: riduci il numero di contenuti richiesti.');
        if (in_array($finish, ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII', 'RECITATION'], true)) {
            throw new GenerationException('L\'AI ha rifiutato questa richiesta. Riformula il brief.');
        }

        $text = '';
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (empty($part['thought']) && isset($part['text'])) $text .= $part['text'];
        }
        $result = json_decode($text, true);
        if (!is_array($result)) throw new GenerationException('Risposta AI in un formato inatteso. Riprova.');
        return $result;
    }

    private function send(string $model, array $body, int $timeout): \Psr\Http\Message\ResponseInterface
    {
        return $this->http->post(sprintf(self::ENDPOINT, rawurlencode($model)), [
            'headers' => ['x-goog-api-key' => $this->apiKey],
            'json' => $body,
            'timeout' => $timeout,
        ]);
    }

    private function fail(RequestException $e, string $model): never
    {
        $status = $e->getResponse()?->getStatusCode() ?? 0;
        $body = (string)$e->getResponse()?->getBody();
        error_log("Gemini API error {$status}: " . mb_substr($body, 0, 2000));
        // Quota finita o servizio giù: si può passare al provider di riserva.
        if ($status === 429) throw new ProviderUnavailableException('Limite di richieste di Gemini raggiunto (il piano gratuito ne permette poche al minuto e al giorno). Riprova più tardi.');
        if ($status >= 500 || $status === 0) throw new ProviderUnavailableException('Il servizio AI non è disponibile al momento. Riprova più tardi.');
        throw new GenerationException(match (true) {
            str_contains($body, 'API_KEY_INVALID') || $status === 401 || $status === 403 => 'Chiave API di Gemini non valida.',
            $status === 404 => "Il modello Gemini «{$model}» non esiste o non è disponibile: controlla GEMINI_MODEL.",
            $status === 400 => 'Richiesta AI non valida. Controlla i log del worker.',
            default => 'Il servizio AI non è disponibile al momento. Riprova più tardi.',
        });
    }

    /** Gemini accetta solo una parte di JSON Schema: le chiavi che non conosce fanno fallire la richiesta. */
    private static function geminiSchema(array $schema): array
    {
        unset($schema['additionalProperties']);
        foreach ($schema['properties'] ?? [] as $name => $sub) $schema['properties'][$name] = self::geminiSchema($sub);
        if (isset($schema['items']) && is_array($schema['items'])) $schema['items'] = self::geminiSchema($schema['items']);
        return $schema;
    }
}
