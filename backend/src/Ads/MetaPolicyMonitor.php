<?php
declare(strict_types=1);

/**
 * Controlla ogni giorno le pagine ufficiali di Meta sulle regole delle inserzioni. Se il testo cambia,
 * salva cosa è stato aggiunto e tolto, chiede all'AI cosa significa per le sponsorizzate dei clienti
 * e tutte le agenzie ricevono un avviso finché non lo segnano come letto.
 */
final class MetaPolicyMonitor
{
    private const BASE = 'https://transparency.meta.com/it-it/policies/ad-standards/';

    /** Pagine controllate: titolo, cosa coprono e indirizzo. Le regole in MetaRules puntano a queste chiavi. */
    public const SOURCES = [
        'ad-standards' => ['title' => 'Standard pubblicitari', 'topic' => 'Le regole generali delle inserzioni e l\'elenco dei contenuti vietati e limitati.', 'url' => self::BASE],
        'personal-attributes' => ['title' => 'Attributi personali', 'topic' => 'Testi che parlano di salute, soldi, religione o altre caratteristiche di chi legge.', 'url' => self::BASE . 'objectionable-content/privacy-violations-personal-attributes'],
        'commercial-practices' => ['title' => 'Pratiche commerciali vietate', 'topic' => 'Promesse irrealistiche, truffe, guadagni facili.', 'url' => self::BASE . 'deceptive-content/prohibited-commercial-practices'],
        'health-wellness' => ['title' => 'Salute e benessere', 'topic' => 'Dimagrimento, estetica, immagini prima e dopo.', 'url' => self::BASE . 'restricted-goods-services/health-wellness'],
        'alcohol' => ['title' => 'Prodotti alcolici', 'topic' => 'Vino, birra, locali e Paesi dove l\'alcol non si può promuovere.', 'url' => self::BASE . 'restricted-goods-services/alcohol'],
        'financial' => ['title' => 'Servizi finanziari', 'topic' => 'Prestiti, investimenti, assicurazioni.', 'url' => self::BASE . 'restricted-goods-services/financial-services'],
        'gambling' => ['title' => 'Giochi e scommesse', 'topic' => 'Gioco d\'azzardo, lotterie, scommesse.', 'url' => self::BASE . 'restricted-goods-services/gambling-games'],
        'dating' => ['title' => 'Incontri', 'topic' => 'App e siti di incontri.', 'url' => self::BASE . 'restricted-goods-services/dating-ads'],
        'drugs' => ['title' => 'Farmaci', 'topic' => 'Farmaci, farmacie online, prodotti farmaceutici.', 'url' => self::BASE . 'restricted-goods-services/drugs-pharmaceuticals'],
        'crypto' => ['title' => 'Criptovalute', 'topic' => 'Exchange, wallet, trading di criptovalute.', 'url' => self::BASE . 'restricted-goods-services/cryptocurrency-products-and-services'],
        'tobacco' => ['title' => 'Tabacco', 'topic' => 'Sigarette, sigarette elettroniche, nicotina.', 'url' => self::BASE . 'restricted-goods-services/tobacco-related-products'],
        'weapons' => ['title' => 'Armi', 'topic' => 'Armi, munizioni, esplosivi e accessori.', 'url' => self::BASE . 'restricted-goods-services/weapons-ammunitions-explosives'],
        'adult' => ['title' => 'Nudità e contenuti per adulti', 'topic' => 'Immagini e testi allusivi.', 'url' => self::BASE . 'objectionable-content/adult-nudity-and-sexual-activity'],
        'profanity' => ['title' => 'Linguaggio volgare', 'topic' => 'Parolacce e insulti.', 'url' => self::BASE . 'objectionable-content/profanity'],
        'siep' => ['title' => 'Temi sociali, elezioni e politica', 'topic' => 'Inserzioni politiche e su temi sociali.', 'url' => self::BASE . 'SIEP-advertising/SIEP'],
        'meta-brand' => ['title' => 'Uso dei marchi di Meta', 'topic' => 'Come citare Facebook, Instagram e WhatsApp nelle inserzioni.', 'url' => self::BASE . 'intellectual-property-infringement/Using-Meta-Intellectual-Property-Licenses'],
        'special-categories' => ['title' => 'Categorie speciali di inserzioni', 'topic' => 'Lavoro, immobili e servizi finanziari: cosa va dichiarato e come cambia il pubblico.', 'url' => 'https://www.facebook.com/business/help/298000447747885'],
        'api-changelog' => ['title' => 'Novità tecniche (API di Meta)', 'topic' => 'Nuove versioni e funzioni ritirate di Gestione inserzioni.', 'url' => 'https://developers.facebook.com/docs/graph-api/changelog/'],
    ];

    /** Ogni pagina si ricontrolla dopo questo tempo. */
    private const INTERVAL = '-24 hours';
    /** Sotto questa lunghezza la pagina non è stata letta bene (accesso richiesto, errore): non si confronta. */
    private const MIN_CHARS = 300;
    /** Cambiamenti più piccoli di così sono rumore (date, contatori), non nuove regole. */
    private const MIN_CHANGE_CHARS = 60;

    /** Controlla le pagine scadute (o tutte, con $force). Restituisce quante pagine sono cambiate. */
    public static function refresh(bool $force = false): int
    {
        $pdo = Database::connection();
        self::syncSources($pdo);
        $limit = date('Y-m-d H:i:s', strtotime(self::INTERVAL));
        $stmt = $pdo->prepare('SELECT * FROM meta_policy_sources WHERE ' . ($force ? '1=1' : '(checked_at IS NULL OR checked_at < ?)') . ' ORDER BY checked_at IS NULL DESC, checked_at');
        $stmt->execute($force ? [] : [$limit]);
        $changed = 0;
        foreach ($stmt->fetchAll() as $source) {
            try {
                if (self::checkSource($pdo, $source)) $changed++;
            } catch (Throwable $e) {
                error_log('Meta policy check ' . $source['slug'] . ': ' . $e->getMessage());
                $pdo->prepare('UPDATE meta_policy_sources SET checked_at=?, last_error=? WHERE id=?')
                    ->execute([date('Y-m-d H:i:s'), 'Controllo non riuscito.', $source['id']]);
            }
        }
        return $changed;
    }

    /** Legge una pagina e, se è cambiata rispetto all'ultima volta, registra il cambiamento. */
    private static function checkSource(PDO $pdo, array $source): bool
    {
        $now = date('Y-m-d H:i:s');
        try {
            $page = SafeHttp::get($source['url'], 4_000_000, 20);
        } catch (SiteException $e) {
            $pdo->prepare('UPDATE meta_policy_sources SET checked_at=?, last_error=? WHERE id=?')->execute([$now, mb_substr($e->getMessage(), 0, 300), $source['id']]);
            return false;
        }
        $lines = self::extract($page['body']);
        $content = implode("\n", $lines);
        if (mb_strlen($content) < self::MIN_CHARS) {
            $pdo->prepare('UPDATE meta_policy_sources SET checked_at=?, last_error=? WHERE id=?')
                ->execute([$now, 'La pagina non si è letta bene: riproverò al prossimo controllo.', $source['id']]);
            return false;
        }
        $hash = hash('sha256', $content);
        $old = $source['content'] === null ? null : explode("\n", $source['content']);
        $diff = $old === null || $hash === $source['content_hash'] ? null : self::diff($old, $lines);
        $pdo->prepare('UPDATE meta_policy_sources SET content=?, content_hash=?, checked_at=?, last_error=NULL' . ($diff ? ', changed_at=?' : '') . ' WHERE id=?')
            ->execute($diff ? [$content, $hash, $now, $now, $source['id']] : [$content, $hash, $now, $source['id']]);
        if (!$diff) return false;

        $summary = null;
        try {
            $summary = self::cleanSummary(MarketingAis::make()->policyChange($source['title'], $source['topic'], $diff['added'], $diff['removed']));
        } catch (GenerationException $e) {
            error_log('Meta policy summary: ' . $e->getMessage());
        }
        $pdo->prepare('INSERT INTO meta_policy_changes (source_id, detected_at, added, removed, summary) VALUES (?, ?, ?, ?, ?)')->execute([
            $source['id'], $now,
            json_encode($diff['added'], JSON_UNESCAPED_UNICODE), json_encode($diff['removed'], JSON_UNESCAPED_UNICODE),
            $summary === null ? null : json_encode($summary, JSON_UNESCAPED_UNICODE),
        ]);
        return true;
    }

    /**
     * Il testo leggibile di una pagina di Meta, una riga per paragrafo. Le pagine del Centro sulla trasparenza
     * sono disegnate in JavaScript: i testi stanno nei dati della pagina ("children": ["…"]), non nell'HTML.
     * @return list<string>
     */
    public static function extract(string $html): array
    {
        $lines = [];
        $bs = '\\';
        // I dati sono JSON dentro stringhe JSON: si tolgono le virgolette e le barre escapate per trovarli.
        $data = str_replace([$bs . $bs . $bs . '"', $bs . '"', $bs . '/'], ['"', '"', '/'], $html);
        if (preg_match_all('/"children":\["((?:[^"\\\\]|\\\\.)++)"\]/', $data, $m)) {
            foreach ($m[1] as $raw) {
                $decoded = json_decode('"' . str_replace($bs . $bs, $bs, $raw) . '"');
                $lines[] = is_string($decoded) ? $decoded : $raw;
            }
        }
        // Il Centro assistenza per le aziende invece mette l'articolo come HTML dentro una stringa JSON, con i "<" scritti come escape unicode.
        $documents = [$html];
        // Si parte da ':"' (un valore JSON) così le virgolette degli attributi HTML non confondono l'inizio della stringa.
        if (preg_match_all('/:"((?:[^"\\\\]|\\\\.)*?\\\\u003C(?:[^"\\\\]|\\\\.)*+)"/', $html, $strings)) {
            foreach ($strings[1] as $string) {
                if (strlen($string) < 300) continue;
                $decoded = json_decode('"' . $string . '"');
                if (is_string($decoded)) $documents[] = $decoded;
            }
        }
        foreach ($documents as $document) {
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $document . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors();
            $xpath = new DOMXPath($dom);
            foreach (iterator_to_array($xpath->query('//script|//style|//noscript|//svg|//template|//head|//nav|//footer')) as $node) {
                $node->parentNode?->removeChild($node);
            }
            foreach ($xpath->query('//body//*[self::h1 or self::h2 or self::h3 or self::h4 or self::p or self::li or self::td]') as $node) {
                $lines[] = $node->textContent;
            }
        }
        $clean = [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
            // Si tengono frasi e parole vere (come i nomi dei Paesi), non nomi di classi o codici.
            $words = str_contains($line, ' ') && preg_match('/\p{L}{2}/u', $line);
            if ($words || preg_match('/^\p{Lu}?\p{Ll}{2,}$/u', $line)) $clean[$line] = true;
        }
        return array_keys($clean);
    }

    /**
     * Righe aggiunte e tolte tra due versioni; null se il cambiamento è solo rumore.
     * @return array{added: list<string>, removed: list<string>}|null
     */
    public static function diff(array $old, array $new): ?array
    {
        $added = array_values(array_diff($new, $old));
        $removed = array_values(array_diff($old, $new));
        $size = array_sum(array_map('mb_strlen', [...$added, ...$removed]));
        if ($size < self::MIN_CHANGE_CHARS) return null;
        return ['added' => array_slice($added, 0, 60), 'removed' => array_slice($removed, 0, 60)];
    }

    /** @return array{summary: string, impact: string, actions: list<string>} */
    private static function cleanSummary(array $s): array
    {
        $actions = array_values(array_filter(array_map(fn($a) => is_scalar($a) ? mb_substr(trim((string)$a), 0, 300) : '', is_array($s['actions'] ?? null) ? $s['actions'] : [])));
        return [
            'summary' => mb_substr(trim((string)($s['summary'] ?? '')), 0, 1200),
            'impact' => in_array($s['impact'] ?? '', ['alta', 'media', 'bassa'], true) ? $s['impact'] : 'media',
            'actions' => array_slice($actions, 0, 5),
        ];
    }

    /** Crea le righe delle pagine nuove e aggiorna titolo e indirizzo di quelle esistenti. */
    private static function syncSources(PDO $pdo): void
    {
        $stmt = $pdo->prepare('INSERT INTO meta_policy_sources (slug, title, topic, url) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE title=VALUES(title), topic=VALUES(topic), url=VALUES(url)');
        foreach (self::SOURCES as $slug => $s) $stmt->execute([$slug, $s['title'], $s['topic'], $s['url']]);
    }

    /** Pagine controllate, cambiamenti recenti e quanti non sono ancora stati letti dall'organizzazione. */
    public static function overview(int $organizationId): array
    {
        $pdo = Database::connection();
        self::syncSources($pdo);
        // Un'organizzazione nuova non riceve avvisi sui cambiamenti precedenti alla sua iscrizione.
        $seen = $pdo->prepare('SELECT COALESCE(meta_rules_seen_at, created_at) FROM organizations WHERE id=?');
        $seen->execute([$organizationId]);
        $seenAt = $seen->fetchColumn() ?: null;

        $sources = [];
        foreach ($pdo->query('SELECT id, slug, title, topic, url, checked_at, changed_at, last_error FROM meta_policy_sources ORDER BY id')->fetchAll() as $s) {
            if (!isset(self::SOURCES[$s['slug']])) continue;
            $sources[] = ['id' => (int)$s['id']] + array_intersect_key($s, array_flip(['slug', 'title', 'topic', 'url', 'checked_at', 'changed_at', 'last_error']));
        }
        $changes = [];
        foreach ($pdo->query('SELECT c.id, c.detected_at, c.added, c.removed, c.summary, s.slug, s.title, s.url FROM meta_policy_changes c
            JOIN meta_policy_sources s ON s.id=c.source_id ORDER BY c.detected_at DESC, c.id DESC LIMIT 30')->fetchAll() as $c) {
            $changes[] = [
                'id' => (int)$c['id'], 'detected_at' => $c['detected_at'], 'slug' => $c['slug'], 'title' => $c['title'], 'url' => $c['url'],
                'added' => json_decode($c['added'], true) ?: [], 'removed' => json_decode($c['removed'], true) ?: [],
                'summary' => $c['summary'] === null ? null : json_decode($c['summary'], true),
                'unread' => $seenAt === null || $c['detected_at'] > $seenAt,
            ];
        }
        $checked = array_filter(array_column($sources, 'checked_at'));
        return [
            'rules_version' => MetaRules::VERSION,
            'last_check' => $checked ? max($checked) : null,
            'unread' => count(array_filter($changes, fn($c) => $c['unread'])),
            'sources' => $sources,
            'changes' => $changes,
        ];
    }

    /** Cambiamenti non ancora letti dall'organizzazione, per gli avvisi. */
    public static function unread(int $organizationId): array
    {
        $stmt = Database::connection()->prepare('SELECT s.title, c.detected_at, c.summary FROM meta_policy_changes c JOIN meta_policy_sources s ON s.id=c.source_id
            JOIN organizations o ON o.id=? WHERE c.detected_at > COALESCE(o.meta_rules_seen_at, o.created_at) ORDER BY c.detected_at DESC LIMIT 10');
        $stmt->execute([$organizationId]);
        return $stmt->fetchAll();
    }
}
