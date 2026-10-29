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
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
    private const FALLBACK_MODEL = 'gemini-flash-lite-latest';

    private HttpClient $http;
    private string $apiKey;

    public function __construct()
    {
        $apiKey = getenv('GEMINI_API_KEY');
        if (!$apiKey) throw new GenerationException('Generazione AI non configurata: manca GEMINI_API_KEY.');
        $this->apiKey = $apiKey;
        $this->http = new HttpClient(['timeout' => 180,'connect_timeout' => 15]);
    }

    protected function callJson(string $system, string $prompt, array $schema, ?string $effort = null, int $maxTokens = 16000): array
    {
        $model = getenv('GEMINI_MODEL') ?: 'gemini-flash-latest';
        $body = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseJsonSchema' => self::geminiSchema($schema),
                // Su Gemini il ragionamento conta nei token di risposta: serve più margine che con Claude.
                'maxOutputTokens' => min(65536, max($maxTokens, 8000) * 2),
            ],
        ];
        // Sul piano gratuito i modelli sono spesso "molto richiesti" (503) per pochi secondi: si riprova, l'ultima volta con il modello leggero.
        $attempts = [[$model, 0], [$model, 3], [self::FALLBACK_MODEL, 8]];
        foreach ($attempts as $i => [$attemptModel, $wait]) {
            sleep($wait);
            try {
                $response = $this->http->post(sprintf(self::ENDPOINT, rawurlencode($attemptModel)), [
                    'headers' => ['x-goog-api-key' => $this->apiKey],
                    'json' => $body,
                ]);
                break;
            } catch (ConnectException) {
                // Anche una risposta che non arriva entro il tempo massimo è un sovraccarico momentaneo.
                if ($i < count($attempts) - 1) continue;
                throw new GenerationException('Impossibile contattare il servizio AI.');
            } catch (RequestException $e) {
                $status = $e->getResponse()?->getStatusCode() ?? 0;
                if (in_array($status, [500, 503], true) && $i < count($attempts) - 1) continue;
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

    private function fail(RequestException $e, string $model): never
    {
        $status = $e->getResponse()?->getStatusCode() ?? 0;
        $body = (string)$e->getResponse()?->getBody();
        error_log("Gemini API error {$status}: " . mb_substr($body, 0, 2000));
        throw new GenerationException(match (true) {
            $status === 429 => 'Limite di richieste di Gemini raggiunto (il piano gratuito ne permette poche al minuto e al giorno). Riprova più tardi.',
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
