<?php
declare(strict_types=1);

/** Errore da mostrare all'utente così com'è (niente dettagli interni). */
final class GenerationException extends RuntimeException {}

interface MarketingAi
{
    public const CHANNELS = ['instagram', 'facebook', 'linkedin', 'blog', 'email', 'website', 'other'];
    public const CONTENT_TYPES = ['post', 'carosello', 'reel', 'storia', 'articolo', 'email'];
    public const LAYOUTS = ['bold', 'light', 'photo'];

    /**
     * Varianti di testo per un singolo brief.
     * @return list<array{title: string, body: string, call_to_action: string, hashtags: list<string>, reviewer_notes: string}>
     */
    public function contentVariants(array $brand, array $project, array $request): array;

    /**
     * Brand kit ricavato dai contenuti di un sito (vedi SiteReader::read).
     * @return array{name: string, summary: string, industry: string, audience: string, tone_of_voice: string, products: string,
     *   keywords: string, words_to_avoid: string, brand_guidelines: string, primary_color: ?string, secondary_color: ?string}
     */
    public function brandKitFromSite(array $site): array;

    /**
     * Strategia e calendario di post per una campagna.
     * @param array $options goal, weeks, posts_per_week, channels
     * @return array{strategy: array, posts: list<array>}
     */
    public function campaignPlan(array $brand, array $project, array $options): array;
}

final class MarketingAis
{
    /** AI_PROVIDER=claude|fake; se non indicato usa Claude solo quando c'è una chiave API. */
    public static function make(): MarketingAi
    {
        $provider = getenv('AI_PROVIDER') ?: 'auto';
        if ($provider === 'auto') $provider = getenv('ANTHROPIC_API_KEY') ? 'claude' : 'fake';
        return $provider === 'claude' ? new ClaudeMarketingAi() : new FakeMarketingAi();
    }
}
