<?php
declare(strict_types=1);

/** Implementazione locale senza costi: risultati segnaposto per provare i flussi senza chiave API. */
final class FakeMarketingAi implements MarketingAi
{
    private const NOTE = 'Testo segnaposto: imposta ANTHROPIC_API_KEY per generare contenuti reali.';

    public function contentVariants(array $brand, array $project, array $request): array
    {
        $brandName = $brand['name'] ?? $project['name'];
        $variants = [];
        for ($i = 1; $i <= $request['variants']; $i++) {
            $variants[] = [
                'title' => "Variante {$i} — {$project['name']}",
                'body' => "[Bozza di esempio: generazione AI non attiva]\n\n{$brandName}: {$request['brief']}\n\nObiettivo: {$project['objective']}.",
                'call_to_action' => 'Scopri di più',
                'hashtags' => ['marketing', 'esempio'],
                'reviewer_notes' => self::NOTE,
            ];
        }
        return $variants;
    }

    public function brandKitFromSite(array $site): array
    {
        $name = $site['site_name'] ?: (trim(preg_split('/[|\-–—:·•]/u', $site['title'])[0] ?? '') ?: $site['host']);
        return [
            'name' => mb_substr($name, 0, 160),
            'summary' => $site['description'] ?: "Attività presente online su {$site['host']}.",
            'industry' => '',
            'audience' => '',
            'tone_of_voice' => '',
            'products' => $site['description'],
            'keywords' => '',
            'words_to_avoid' => '',
            'brand_guidelines' => self::NOTE,
            'primary_color' => $site['colors'][0] ?? null,
            'secondary_color' => $site['colors'][1] ?? null,
        ];
    }

    public function campaignPlan(array $brand, array $project, array $options): array
    {
        $total = $options['weeks'] * $options['posts_per_week'];
        $types = ['instagram' => 'post', 'facebook' => 'post', 'linkedin' => 'post', 'blog' => 'articolo', 'email' => 'email', 'website' => 'post', 'other' => 'post'];
        $ideas = ['Presentazione del brand', 'Prodotto in evidenza', 'Dietro le quinte', 'Consiglio utile', 'Offerta speciale', 'Parola ai clienti'];
        $layouts = ['bold', 'light', 'photo'];
        $posts = [];
        for ($i = 0; $i < $total; $i++) {
            $channel = $options['channels'][$i % count($options['channels'])];
            $idea = $ideas[$i % count($ideas)];
            $posts[] = [
                'day' => intdiv($i * $options['weeks'] * 7, $total) + 1,
                'channel' => $channel,
                'content_type' => $types[$channel],
                'title' => "{$idea} — {$brand['name']}",
                'body' => "[Bozza di esempio: generazione AI non attiva]\n\n{$idea} per {$brand['name']}. Obiettivo della campagna: {$options['goal']}.",
                'call_to_action' => 'Scopri di più',
                'hashtags' => ['esempio', 'marketing'],
                'visual' => ['headline' => $idea, 'subheadline' => $brand['name'], 'badge' => $i === 0 ? 'Novità' : '', 'layout' => $layouts[$i % 3]],
                'reviewer_notes' => self::NOTE,
            ];
        }
        return [
            'strategy' => [
                'summary' => "Campagna di esempio per {$brand['name']}: {$options['goal']}.",
                'audience' => $brand['audience'] ?: 'Da definire con il cliente.',
                'positioning' => 'Da definire: attiva l\'AI per una strategia reale.',
                'key_messages' => ['Messaggio di esempio 1', 'Messaggio di esempio 2', 'Messaggio di esempio 3'],
                'content_pillars' => ['Prodotti', 'Dietro le quinte', 'Consigli'],
                'channel_plan' => 'Pubblicazioni distribuite in modo uniforme sui canali scelti.',
            ],
            'posts' => $posts,
        ];
    }
}
