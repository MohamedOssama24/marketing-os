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

    /** Senza AI ricava dal sito ciò che si può dedurre con certezza: nome, descrizione, prodotti dal menu e parole ricorrenti. */
    public function brandKitFromSite(array $site): array
    {
        $name = $site['site_name'] ?: self::nameFromTitle($site['title'], $site['host']);

        $generic = '/^(chi siamo|about|azienda|storia|nel mondo|applicazioni|risorse|cataloghi?|case study|fiere|video|direct industry|faq|lavora con noi|dove siamo|servizi|prodotti|products|shop|negozio)$/iu';
        $notProducts = '/condizion|politic|termini|lavora con|dove siamo|nel mondo|chi siamo|garanzi|resi|spedizion|pagament|download|area riservata/iu';
        $products = array_values(array_filter($site['menu'], fn($label) => !preg_match($generic, $label) && !preg_match($notProducts, $label)
            && !str_contains(mb_strtolower($label), mb_strtolower($name))));
        $products = array_slice($products, 0, 15);

        $summary = $site['description'] ?: $site['tagline'];
        if ($summary === '' && $products) {
            $summary = "{$name}: " . mb_strtolower(implode(', ', array_slice($products, 0, 3))) . ' e altre soluzioni.';
        }

        return [
            'name' => mb_substr($name, 0, 160),
            'summary' => $summary ?: "Attività presente online su {$site['host']}.",
            'industry' => '',
            'audience' => '',
            'tone_of_voice' => '',
            'products' => implode("\n", $products),
            'keywords' => $site['keywords'] ?: implode(', ', self::frequentWords(implode(' ', array_column($site['pages'], 'text')), $name)),
            'words_to_avoid' => '',
            'brand_guidelines' => "Brand kit compilato in automatico dal sito, senza AI: completa pubblico e tono di voce, oppure imposta ANTHROPIC_API_KEY per un'analisi completa.",
            'primary_color' => $site['colors'][0] ?? null,
            'secondary_color' => $site['colors'][1] ?? null,
        ];
    }

    public function suggestGoals(array $brand): array
    {
        $name = $brand['name'];
        $products = array_values(array_filter(array_map('trim', preg_split('/\R|,/', (string)$brand['products']))));
        $main = mb_strtolower($products[0] ?? 'i prodotti principali');
        $second = mb_strtolower($products[1] ?? 'le altre soluzioni');
        $audience = $brand['audience'] ? mb_strtolower(rtrim($brand['audience'], '.')) : 'i clienti potenziali';
        $sector = $brand['industry'] ? mb_strtolower($brand['industry']) : 'il proprio settore';
        $site = $brand['website_url'] ? preg_replace('#^https?://(www\.)?#', '', rtrim($brand['website_url'], '/')) : 'il sito';
        return [
            ['title' => 'Notorietà del marchio', 'goal' => "Aumentare la notorietà di {$name} presso {$audience}, raccontando con continuità chi è l'azienda, cosa la distingue e perché sceglierla."],
            ['title' => 'Contatti qualificati', 'goal' => "Generare richieste di informazioni e preventivi per {$main} e {$second}, portando il pubblico dai social a {$site} con inviti all'azione chiari."],
            ['title' => 'Prodotto in evidenza', 'goal' => "Mettere in evidenza {$main}: spiegarne vantaggi, applicazioni e casi d'uso concreti per far crescere l'interesse e le vendite."],
            ['title' => 'Autorevolezza', 'goal' => "Posizionare {$name} come punto di riferimento per {$sector}, condividendo consigli pratici, dietro le quinte e risultati ottenuti con i clienti."],
        ];
    }

    public function rewriteContent(array $brand, array $item, string $instruction): array
    {
        $visual = $item['visual'] ?? [];
        return [
            'title' => $item['title'],
            'body' => "[Riscrittura di esempio — istruzione: {$instruction}]\n\n" . preg_replace('/^\[Riscrittura di esempio[^\]]*\]\s*/u', '', $item['body']),
            'call_to_action' => '',
            'hashtags' => [],
            'visual' => [
                'headline' => $visual['headline'] ?? $item['title'],
                'subheadline' => $visual['subheadline'] ?? '',
                'badge' => $visual['badge'] ?? '',
                'layout' => MarketingAi::LAYOUTS[(array_search($visual['layout'] ?? 'bold', MarketingAi::LAYOUTS, true) + 1) % count(MarketingAi::LAYOUTS)],
            ],
            'reviewer_notes' => self::NOTE,
        ];
    }

    private static function nameFromTitle(string $title, string $host): string
    {
        foreach (preg_split('/\s+[|\-–—:·•]\s+/u', $title) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '' && !preg_match('/^(home|homepage|home page|benvenuti|welcome)$/iu', $part)) return $part;
        }
        return preg_replace('/^www\./', '', $host);
    }

    /** @return list<string> le 8 parole più frequenti, escluse quelle comuni */
    private static function frequentWords(string $text, string $brandName): array
    {
        $stop = array_flip(['della', 'delle', 'degli', 'dello', 'nella', 'nelle', 'negli', 'sono', 'anche', 'come', 'questo', 'questa', 'questi', 'nostro', 'nostra', 'nostri', 'nostre',
            'scopri', 'tutti', 'tutte', 'ogni', 'oltre', 'molto', 'nostro', 'perché', 'quando', 'dove', 'home', 'contatti', 'sempre', 'grazie', 'with', 'your', 'from', 'that', 'this', 'more',
            'their', 'about', 'which', 'possono', 'essere', 'viene', 'vengono', 'hanno', 'proprio', 'propri', 'loro', 'altri', 'altre', 'tipo', 'tipi', 'sei', 'qui', 'torna', 'whatsapp']);
        $counts = [];
        preg_match_all('/\p{L}{5,}/u', mb_strtolower($text), $matches);
        foreach ($matches[0] as $word) {
            if (!isset($stop[$word]) && $word !== mb_strtolower($brandName)) $counts[$word] = ($counts[$word] ?? 0) + 1;
        }
        arsort($counts);
        return array_slice(array_keys(array_filter($counts, fn($c) => $c >= 2)), 0, 8);
    }

    public function campaignPlan(array $brand, array $project, array $options): array
    {
        $total = $options['weeks'] * $options['posts_per_week'];
        $types = ['instagram' => 'post', 'facebook' => 'post', 'linkedin' => 'post', 'blog' => 'articolo', 'email' => 'email', 'website' => 'post', 'other' => 'post'];
        $products = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($brand['products'] ?? '')))));
        $product = fn(int $i) => $products ? $products[$i % count($products)] : 'Il nostro prodotto';
        // Idea, stile grafico, titolo ed etichetta della grafica.
        $ideas = [
            fn($i) => ['Presentazione del brand', 'bold', $brand['name'], 'Chi siamo'],
            fn($i) => ['Prodotto in evidenza', 'split', $product($i), 'In evidenza'],
            fn($i) => ['Dietro le quinte', 'photo', 'Come lavoriamo', ''],
            fn($i) => ['Consiglio utile', 'light', 'Il consiglio della settimana', 'Consiglio'],
            fn($i) => ['Parola ai clienti', 'quote', 'Qualità e assistenza su cui contare', ''],
            fn($i) => ['Invito all\'azione', 'offer', 'Contattaci oggi', 'Info'],
        ];
        $posts = [];
        for ($i = 0; $i < $total; $i++) {
            $channel = $options['channels'][$i % count($options['channels'])];
            [$idea, $layout, $headline, $badge] = $ideas[$i % count($ideas)]($i);
            $posts[] = [
                'day' => intdiv($i * $options['weeks'] * 7, $total) + 1,
                'channel' => $channel,
                'content_type' => $types[$channel],
                'title' => "{$idea} — {$brand['name']}",
                'body' => "[Bozza di esempio: generazione AI non attiva]\n\n{$idea} per {$brand['name']}. Obiettivo della campagna: {$options['goal']}.",
                'call_to_action' => 'Scopri di più',
                'hashtags' => ['esempio', 'marketing'],
                'visual' => ['headline' => mb_substr($headline, 0, 60), 'subheadline' => $brand['summary'] ? mb_substr($brand['summary'], 0, 90) : $brand['name'], 'badge' => $badge, 'layout' => $layout],
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
