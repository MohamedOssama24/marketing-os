<?php
declare(strict_types=1);

/** Errore da mostrare all'utente così com'è (niente dettagli interni). */
final class GenerationException extends RuntimeException {}

interface MarketingAi
{
    public const CHANNELS = ['instagram', 'facebook', 'linkedin', 'blog', 'email', 'website', 'other'];
    public const CONTENT_TYPES = ['post', 'carosello', 'reel', 'storia', 'articolo', 'email'];
    public const LAYOUTS = ['bold', 'light', 'photo', 'split', 'offer', 'quote'];

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

    /**
     * Obiettivi di campagna proposti per il cliente, scritti in modo professionale.
     * @return list<array{title: string, goal: string}>
     */
    public function suggestGoals(array $brand): array;

    /**
     * Riscrive un post seguendo un'istruzione (più breve, più emozionale…), grafica compresa.
     * @return array{title: string, body: string, call_to_action: string, hashtags: list<string>, visual: array, reviewer_notes: string}
     */
    public function rewriteContent(array $brand, array $item, string $instruction): array;

    /**
     * Audit del sito di un potenziale cliente: cosa funziona, cosa migliorare, idee di post e servizi da proporre.
     * @param list<array{key: string, ok: bool, label: string, detail: string}> $checks controlli automatici (SiteChecks::run)
     * @return array{name: string, summary: string, strengths: list<string>, issues: list<array{title: string, detail: string, fix: string}>,
     *   post_ideas: list<array{channel: string, title: string, body: string}>, services: list<array{name: string, description: string}>, pitch: string}
     */
    public function prospectAudit(array $site, array $checks): array;

    /**
     * Trasforma un contenuto in altri formati (carosello, reel, newsletter…).
     * @param list<string> $formats chiavi di Repurpose::FORMATS
     * @return list<array{format: string, title: string, body: string, call_to_action: string, hashtags: list<string>, visual: array, reviewer_notes: string}>
     */
    public function repurposeContent(array $brand, array $item, array $formats): array;

    /**
     * Risposte alternative a una recensione, nel tono del brand.
     * @param array{platform: string, rating: int, author: ?string, text: string} $review
     * @return list<string>
     */
    public function reviewReplies(array $brand, array $review): array;

    /**
     * Cosa comunica un concorrente sul suo sito, cosa fa bene (e come fare meglio) e quali spazi lascia al cliente.
     * @return array{summary: string, offers: list<string>, messages: list<string>, tone: string, opportunities: list<string>, strengths: list<array{strength: string, counter: string, post_idea: string}>}
     */
    public function competitorBrief(array $brand, array $site): array;

    /**
     * Uno stile per il cliente che lo distingue dai concorrenti: com'è il mercato, cosa nessuno fa e la proposta.
     * @param array{client: array{colors: list<string>, fonts: list<string>}, competitors: list<array{name: string, colors: list<string>, fonts: list<string>, tone: string, messages: list<string>}>} $market
     * @return array{market: string, gaps: list<string>, proposal: array{primary_color: string, secondary_color: string, font_family: string, photo_style: string, tone_of_voice: string, formats: list<string>, rationale: string}}
     */
    public function styleProposal(array $brand, array $market): array;
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
