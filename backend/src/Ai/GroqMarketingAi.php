<?php
declare(strict_types=1);

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

/**
 * Stesse istruzioni e stessi formati di risposta di Claude, generati con Groq (piano gratuito).
 * Pensato come riserva di Gemini: i limiti di token al minuto sono bassi, quindi le richieste molto lunghe possono non starci.
 */
final class GroqMarketingAi extends ClaudeMarketingAi
{
    public const PROVIDER = 'groq';

    private const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

    private HttpClient $http;
    private string $apiKey;

    public function __construct()
    {
        $apiKey = getenv('GROQ_API_KEY');
        if (!$apiKey) throw new GenerationException('Generazione AI non configurata: manca GROQ_API_KEY.');
        $this->apiKey = $apiKey;
        $this->http = new HttpClient(['timeout' => 180, 'connect_timeout' => 15]);
    }

    protected function request(string $system, string $prompt, array $schema, ?string $effort, int $maxTokens): array
    {
        // Il modello deve supportare json_schema in modalità strict (gpt-oss, vedi la documentazione di Groq).
        $model = getenv('GROQ_MODEL') ?: 'openai/gpt-oss-120b';
        try {
            $response = $this->http->post(self::ENDPOINT, [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey],
                'json' => [
                    'model' => $model,
                    'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]],
                    'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'risposta', 'strict' => true, 'schema' => $schema]],
                    // Sul piano gratuito i token al minuto sono pochi: ragionamento breve e risposta contenuta.
                    'reasoning_effort' => 'low',
                    'max_completion_tokens' => min($maxTokens, 6000),
                ],
            ]);
        } catch (ConnectException) {
            throw new ProviderUnavailableException('Impossibile contattare il servizio AI.');
        } catch (RequestException $e) {
            $this->fail($e, $model);
        }

        $data = json_decode((string)$response->getBody(), true);
        $choice = $data['choices'][0] ?? [];
        if (($choice['finish_reason'] ?? '') === 'length') throw new GenerationException('Risposta AI troppo lunga: riduci il numero di contenuti richiesti.');
        $result = json_decode((string)($choice['message']['content'] ?? ''), true);
        if (!is_array($result)) throw new GenerationException('Risposta AI in un formato inatteso. Riprova.');
        return $result;
    }

    private function fail(RequestException $e, string $model): never
    {
        $status = $e->getResponse()?->getStatusCode() ?? 0;
        $body = (string)$e->getResponse()?->getBody();
        error_log("Groq API error {$status}: " . mb_substr($body, 0, 2000));
        if ($status === 429) throw new ProviderUnavailableException('Limite di richieste di Groq raggiunto. Riprova più tardi.');
        if ($status >= 500 || $status === 0) throw new ProviderUnavailableException('Il servizio AI non è disponibile al momento. Riprova più tardi.');
        throw new GenerationException(match (true) {
            $status === 401 || $status === 403 => 'Chiave API di Groq non valida.',
            $status === 404 => "Il modello Groq «{$model}» non esiste o non è disponibile: controlla GROQ_MODEL.",
            $status === 413 => 'Richiesta troppo lunga per il piano gratuito di Groq. Riprova più tardi con Gemini.',
            str_contains($body, 'json_validate_failed') => 'Risposta AI in un formato inatteso. Riprova.',
            default => 'Richiesta AI non valida. Controlla i log del worker.',
        });
    }
}
