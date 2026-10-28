<?php
declare(strict_types=1);

/** Errore da mostrare all'utente così com'è (niente dettagli interni). */
final class GenerationException extends RuntimeException {}

interface ContentGenerator
{
    /**
     * @param array $brand   brand kit (può essere vuoto se il progetto non ha un brand)
     * @param array $project nome, obiettivo, pubblico, budget
     * @param array $request channel, content_type, brief, variants
     * @return list<array{title: string, body: string, call_to_action: string, hashtags: list<string>, reviewer_notes: string}>
     */
    public function generate(array $brand, array $project, array $request): array;
}

final class ContentGenerators
{
    /** AI_PROVIDER=claude|fake; se non indicato usa Claude solo quando c'è una chiave API. */
    public static function make(): ContentGenerator
    {
        $provider = getenv('AI_PROVIDER') ?: 'auto';
        if ($provider === 'auto') $provider = getenv('ANTHROPIC_API_KEY') ? 'claude' : 'fake';
        return $provider === 'claude' ? new ClaudeContentGenerator() : new FakeContentGenerator();
    }
}
