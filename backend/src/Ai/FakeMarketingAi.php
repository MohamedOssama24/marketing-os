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

    /** Senza AI l'audit si basa sui controlli automatici: problemi e servizi derivano da quelli non superati. */
    public function prospectAudit(array $site, array $checks): array
    {
        $name = $site['site_name'] ?: self::nameFromTitle($site['title'], $site['host']);
        $fixes = [
            'https' => ['Il sito non è protetto', 'Chi visita il sito vede l\'avviso "non sicuro" e spesso se ne va prima di contattarvi.', 'Attivare un certificato HTTPS (spesso gratuito con il proprio hosting).', 'Manutenzione del sito'],
            'title' => ['Titolo poco efficace su Google', 'Il titolo è la prima cosa che si legge nei risultati di ricerca: se è vago, si clicca su un concorrente.', 'Riscrivere il titolo con attività, città e punto di forza in 50-60 caratteri.', 'Ottimizzazione SEO di base'],
            'description' => ['Manca una descrizione convincente', 'Google mostra un testo casuale preso dalla pagina invece di un invito a sceglierci.', 'Scrivere una descrizione di 140-160 caratteri con un invito all\'azione.', 'Ottimizzazione SEO di base'],
            'socials' => ['Social non collegati al sito', 'Chi vi trova online non arriva ai profili social, dove si costruisce la fiducia giorno per giorno.', 'Collegare i profili dal sito e pubblicare con un calendario regolare.', 'Gestione dei social'],
            'contacts' => ['Contatti difficili da trovare', 'Ogni passaggio in più per contattarvi fa perdere richieste.', 'Mettere telefono, WhatsApp ed email in evidenza in ogni pagina.', 'Revisione dei testi del sito'],
            'content' => ['Testi troppo brevi', 'Con pochi contenuti Google e i clienti non capiscono bene cosa offrite e perché scegliervi.', 'Ampliare le pagine dei servizi con vantaggi, esempi e domande frequenti.', 'Revisione dei testi del sito'],
            'blog' => ['Nessun blog o notizie', 'Senza contenuti aggiornati il sito non intercetta le ricerche dei clienti e i social non hanno materiale.', 'Pubblicare due articoli al mese sulle domande più frequenti dei clienti.', 'Contenuti per il blog'],
            'brand' => ['Identità visiva poco chiara', 'Colori e logo coerenti rendono l\'attività riconoscibile su sito e social.', 'Definire un brand kit con colori, logo e stile delle immagini.', 'Brand kit e grafiche'],
        ];
        $issues = [];
        $services = [];
        foreach ($checks as $check) {
            if ($check['ok'] || !isset($fixes[$check['key']])) continue;
            [$title, $detail, $fix, $service] = $fixes[$check['key']];
            $issues[] = ['title' => $title, 'detail' => $detail, 'fix' => $fix];
            $services[$service] = true;
        }
        $services['Gestione dei social'] = true;
        $descriptions = [
            'Gestione dei social' => 'Calendario mensile di post con grafiche nei vostri colori, approvati da voi prima della pubblicazione.',
            'Ottimizzazione SEO di base' => 'Titoli e descrizioni per Google su tutte le pagine principali.',
            'Revisione dei testi del sito' => 'Testi chiari che spiegano cosa offrite e portano a contattarvi.',
            'Contenuti per il blog' => 'Articoli utili che rispondono alle domande dei vostri clienti.',
            'Brand kit e grafiche' => 'Colori, logo e modelli grafici coerenti per sito e social.',
            'Manutenzione del sito' => 'Sicurezza, aggiornamenti e piccoli interventi sul sito.',
        ];
        $product = $site['menu'][0] ?? 'i vostri servizi';
        return [
            'name' => mb_substr($name, 0, 160),
            'summary' => $site['description'] ?: "{$name} è presente online su {$site['host']}.",
            'strengths' => array_values(array_map(fn($c) => "{$c['label']}: {$c['detail']}", array_filter($checks, fn($c) => $c['ok']))),
            'issues' => $issues,
            'post_ideas' => [
                ['channel' => 'instagram', 'title' => 'Dietro le quinte', 'body' => "[Idea di esempio] Mostrate come nasce {$product}: chi ci lavora, i dettagli che fanno la differenza."],
                ['channel' => 'facebook', 'title' => 'La domanda della settimana', 'body' => '[Idea di esempio] Rispondete a una domanda frequente dei clienti con un consiglio pratico.'],
                ['channel' => 'linkedin', 'title' => 'Un risultato raccontato', 'body' => '[Idea di esempio] Raccontate un lavoro recente: il problema del cliente e come lo avete risolto.'],
            ],
            'services' => array_map(fn($s) => ['name' => $s, 'description' => $descriptions[$s]], array_keys($services)),
            'pitch' => "Questi sono i primi spunti che abbiamo notato sul sito di {$name}. Ci farebbe piacere approfondirli insieme in una breve chiamata conoscitiva, senza impegno.",
        ];
    }

    public function repurposeContent(array $brand, array $item, array $formats): array
    {
        $text = preg_replace('/\n*---\nNote per il revisore:.*$/su', '', $item['body']);
        return array_map(fn($format) => [
            'format' => $format,
            'title' => Repurpose::FORMATS[$format][2] . ' — ' . $item['title'],
            'body' => "[Versione di esempio: generazione AI non attiva]\n\n" . match ($format) {
                'carosello' => "Slide 1: {$item['title']}\nSlide 2: Il problema\nSlide 3: La soluzione\nSlide 4: Come funziona\nSlide 5: Scrivici\n\n{$text}",
                'reel' => "Gancio (0-3 s): {$item['title']}\nScena 1: mostra il prodotto\nScena 2: il vantaggio principale\nChiusura: invito all'azione\n\n{$text}",
                default => $text,
            },
            'call_to_action' => 'Scopri di più',
            'hashtags' => in_array(Repurpose::FORMATS[$format][0], ['instagram', 'linkedin'], true) ? ['esempio'] : [],
            'visual' => ['headline' => mb_substr($item['title'], 0, 60), 'subheadline' => '', 'badge' => '', 'layout' => $format === 'carosello' ? 'light' : 'bold'],
            'reviewer_notes' => self::NOTE,
        ], $formats);
    }

    public function reviewReplies(array $brand, array $review): array
    {
        $name = $review['author'] ? ' ' . trim($review['author']) : '';
        $team = 'Il team di ' . ($brand['name'] ?? 'noi');
        if ($review['rating'] >= 4) {
            return [
                "Grazie{$name} per la splendida recensione! Siamo felici che la vostra esperienza sia stata positiva: vi aspettiamo presto. {$team}",
                "Che bello leggere queste parole{$name}! Il vostro apprezzamento è la soddisfazione più grande per tutto il team. A presto!",
                "Grazie di cuore{$name}, a prestissimo!",
            ];
        }
        return [
            "Gentile{$name}, grazie per il suo riscontro. Ci dispiace che l'esperienza non sia stata all'altezza delle aspettative: ci contatti in privato, vorremmo capire meglio cosa è successo e rimediare. {$team}",
            "Ciao{$name}, ci dispiace davvero leggere questa recensione. Ogni commento ci aiuta a migliorare: scrivici in privato così possiamo parlarne con calma. {$team}",
            "Grazie{$name} per averci scritto. Ci dispiace: contattaci in privato, vogliamo rimediare.",
        ];
    }

    public function competitorBrief(array $brand, array $site): array
    {
        $name = $site['site_name'] ?: self::nameFromTitle($site['title'], $site['host']);
        return [
            'summary' => ($site['description'] ?: "{$name} è presente online su {$site['host']}.") . ' [Analisi di esempio: generazione AI non attiva]',
            'offers' => array_slice($site['menu'], 0, 6),
            'messages' => array_slice($site['headings'], 0, 5),
            'tone' => 'Da valutare: attiva l\'AI per un\'analisi del tono di voce.',
            'opportunities' => ['Attiva ANTHROPIC_API_KEY per ricevere spunti su come differenziare il cliente.'],
            'strengths' => array_map(fn($heading) => [
                'strength' => "Mette in evidenza «{$heading}»",
                'counter' => 'Racconta la stessa cosa con un caso reale del cliente e una foto vera.',
                'post_idea' => "Post che mostra come il cliente affronta «{$heading}», con un esempio concreto e un invito a scrivere.",
            ], array_slice($site['headings'], 0, 2)),
        ];
    }

    public function competitorAds(array $brand, string $competitor, array $ads, array $audience): array
    {
        $longest = $ads ? array_reduce($ads, fn($a, $b) => $a === null || $b['days'] > $a['days'] ? $b : $a) : null;
        $client = $brand['name'] ?? 'il cliente';
        return [
            'summary' => "{$competitor} ha " . count($ads) . ' inserzioni recenti' . ($longest ? "; quella attiva da più tempo ({$longest['days']} giorni) dice: «" . mb_substr($longest['title'] ?: $longest['text'], 0, 80) . '».' : '.')
                . ' [Riepilogo di esempio: imposta ANTHROPIC_API_KEY per quello completo]',
            'offer' => 'Da valutare leggendo le inserzioni qui sotto.',
            'audience' => 'Soprattutto ' . ($audience['top_age'] ? "persone tra {$audience['top_age']} anni" : 'un pubblico ampio') . ($audience['places'] ? ' a ' . implode(', ', array_slice($audience['places'], 0, 3)) : '') . '.',
            'tone' => 'Da valutare: attiva l\'AI per un\'analisi del tono.',
            'counter_ad' => [
                'primary_text' => mb_substr("Scegli {$client}: [offerta] e un servizio che ti segue davvero, dalla prima richiesta al risultato.", 0, 125),
                'headline' => mb_substr("Scopri {$client}", 0, 40),
            ],
        ];
    }

    public function styleProposal(array $brand, array $market): array
    {
        $takenColors = array_merge([], ...array_column($market['competitors'], 'colors'));
        $takenFonts = array_merge([], ...array_column($market['competitors'], 'fonts'));
        $primary = Competition::distinctColor($takenColors);
        return [
            'market' => count($market['competitors']) . ' concorrenti analizzati. [Proposta di esempio: generazione AI non attiva]',
            'gaps' => ['Foto vere del team e dei clienti', 'Un tono più diretto e caldo', 'Video brevi dietro le quinte'],
            'proposal' => [
                'primary_color' => $primary,
                'secondary_color' => Competition::distinctColor([...$takenColors, $primary]),
                'font_family' => Competition::distinctFont($takenFonts),
                'photo_style' => 'Foto vere, luce naturale, persone al lavoro e clienti soddisfatti.',
                'tone_of_voice' => 'Diretto e caldo, con il "tu"; frasi brevi e concrete.',
                'formats' => ['Caroselli con consigli pratici', 'Reel dietro le quinte', 'Storie dei clienti'],
                'rationale' => 'Colori e caratteri scelti tra quelli che nessun concorrente usa, così il cliente si riconosce subito nel feed.',
            ],
        ];
    }

    /** Diagnosi di esempio costruita sui controlli automatici: i punti mancanti diventano le priorità. */
    public function siteAnalysis(array $brand, array $site, array $checks): array
    {
        $name = $brand['name'] ?? self::nameFromTitle($site['title'], $site['host'] ?? '');
        $failing = array_values(array_filter($checks, fn($c) => !$c['ok']));
        $passing = array_values(array_filter($checks, fn($c) => $c['ok']));
        $urgent = ['conversion', 'contacts', 'tracking', 'pixel', 'mobile', 'trust'];
        $scorecard = [];
        foreach (array_keys(SiteAnalysis::AREAS) as $area) {
            $score = SiteAnalysis::areaScore($area, $checks);
            if ($score !== null) $scorecard[] = ['area' => $area, 'score' => $score, 'note' => 'Voto calcolato dai controlli automatici. [Esempio]'];
        }
        return [
            'verdict' => "{$name} supera " . count($passing) . ' controlli su ' . count($checks) . '. '
                . ($failing ? "Il punto debole principale è «{$failing[0]['label']}»: è da qui che conviene partire." : 'Le basi ci sono: ora serve spingere su contenuti e sponsorizzate.')
                . ' [Analisi di esempio: imposta ANTHROPIC_API_KEY per la diagnosi completa]',
            'business_model' => 'Da verificare con il titolare: come arrivano oggi i clienti e quanto vale ciascuno.',
            'value_proposition' => $site['description'] ?: ($site['title'] ?: 'Non chiara dal sito.'),
            'sharper_proposition' => "{$name}: " . (($site['tagline'] ?? '') ?: 'la scelta sicura per chi cerca qualità e un servizio vicino.'),
            'target' => $brand['audience'] ?? 'Clienti della zona che cercano online prima di scegliere.',
            'scorecard' => $scorecard,
            'swot' => [
                'strengths' => array_map(fn($c) => $c['label'], array_slice($passing, 0, 3)),
                'weaknesses' => array_map(fn($c) => $c['label'], array_slice($failing, 0, 3)),
                'opportunities' => ['Contenuti social costanti con foto vere', 'Sponsorizzate locali mirate'],
                'threats' => ['Concorrenti più presenti online'],
            ],
            'priorities' => array_map(fn($c) => [
                'title' => "Sistema «{$c['label']}»", 'why' => $c['detail'], 'how' => 'Passi da definire con l\'agenzia. [Esempio]',
                'impact' => in_array($c['key'], $urgent, true) ? 'alta' : 'media', 'effort' => 'basso', 'when' => 'Entro 30 giorni', 'kpi' => 'Richieste di contatto al mese',
            ], array_slice($failing, 0, 6)),
            'quick_wins' => array_map(fn($c) => $c['label'], array_slice(array_filter($failing, fn($c) => in_array($c['key'], ['title', 'description', 'socials'], true)), 0, 3)),
            'revenue_ideas' => ['Un pacchetto o abbonamento per i clienti abituali'],
            'kpis' => [['name' => 'Richieste di contatto al mese', 'target' => '+30% in 90 giorni'], ['name' => 'Visite al sito dai social', 'target' => '+50% in 90 giorni']],
            'questions' => ['Quali sono i clienti che rendono di più?', 'Da dove arrivano oggi i clienti nuovi?', 'Qual è il periodo dell\'anno più forte?'],
        ];
    }

    public function adReview(array $brand, array $ad, ?array $landing, array $localIssues): array
    {
        $severities = array_column($localIssues, 'severity');
        $verdict = in_array('block', $severities, true) ? 'blocked' : (in_array('warn', $severities, true) ? 'warning' : 'ok');
        $issues = [];
        if ($ad['url'] && !$landing) {
            $issues[] = ['severity' => 'warn', 'title' => 'Pagina di destinazione non raggiungibile', 'excerpt' => $ad['url'],
                'why' => 'Meta respinge le inserzioni che portano a pagine che non si aprono.', 'fix' => 'Controlla il link prima di pubblicare.'];
            if ($verdict === 'ok') $verdict = 'warning';
        }
        $first = trim((string)strtok($ad['primary_text'], "\n"));
        return [
            'verdict' => $verdict,
            'summary' => ['ok' => 'L\'inserzione rispetta le regole controllate.', 'warning' => 'L\'inserzione si può pubblicare, ma ci sono punti da sistemare.', 'blocked' => 'Così com\'è Meta la respingerebbe.'][$verdict]
                . ' [Revisione di esempio: imposta ANTHROPIC_API_KEY per quella completa]',
            'issues' => $issues,
            'improved' => [
                'primary_text' => mb_substr($first !== '' ? $first : $ad['primary_text'], 0, 125),
                'headline' => mb_substr($ad['headline'] ?: ($brand['name'] ?? ''), 0, 40),
                'description' => mb_substr($ad['description'], 0, 30),
            ],
            'audience_tip' => 'Parti con il pubblico Advantage+ e almeno 5 € al giorno per 7 giorni, poi guarda il costo per risultato.',
        ];
    }

    public function policyChange(string $title, string $topic, array $added, array $removed): array
    {
        return [
            'summary' => "Meta ha modificato la pagina «{$title}»: " . count($added) . ' frasi aggiunte e ' . count($removed) . ' tolte. Leggile qui sotto. [Riepilogo di esempio: imposta ANTHROPIC_API_KEY per quello completo]',
            'impact' => 'media',
            'actions' => ["Ricontrolla le sponsorizzate che riguardano: {$topic}"],
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
