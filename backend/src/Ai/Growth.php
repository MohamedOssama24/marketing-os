<?php
declare(strict_types=1);

/** Controlli automatici sul sito di un potenziale cliente: oggettivi, senza AI, alla base del punteggio dell'audit. */
final class SiteChecks
{
    /** @return list<array{key: string, ok: bool, label: string, detail: string}> */
    public static function run(array $site): array
    {
        $text = implode(' ', array_column($site['pages'], 'text'));
        $titleLength = mb_strlen($site['title']);
        $descLength = mb_strlen($site['description']);
        $socials = array_keys($site['socials']);
        $urls = implode(' ', array_column($site['pages'], 'url'));
        $hasContacts = (bool)preg_match('/(\+39|tel\.?|telefono|whatsapp)\s*[:\d(]|\b\d{2,4}[\s.]?\d{6,8}\b|@[a-z0-9-]+\.[a-z]{2,}/iu', $text);
        $checks = [
            ['https', str_starts_with($site['url'], 'https://'), 'Sito protetto (HTTPS)',
                str_starts_with($site['url'], 'https://') ? 'Il sito usa una connessione sicura.' : 'Il sito non usa HTTPS: i browser lo segnalano come "non sicuro".'],
            ['title', $titleLength >= 20 && $titleLength <= 65, 'Titolo per Google',
                $titleLength === 0 ? 'La pagina non ha un titolo.' : "Il titolo è di {$titleLength} caratteri (ideale 20-65)."],
            ['description', $descLength >= 70 && $descLength <= 170, 'Descrizione per Google',
                $descLength === 0 ? 'Manca la descrizione che Google mostra sotto il titolo.' : "La descrizione è di {$descLength} caratteri (ideale 70-170)."],
            ['socials', count($socials) >= 2, 'Profili social collegati',
                $socials ? 'Collegati dal sito: ' . implode(', ', array_map(fn($k) => ucfirst(str_replace('_url', '', $k)), $socials)) . '.' : 'Il sito non porta a nessun profilo social.'],
            ['contacts', $hasContacts, 'Contatti visibili',
                'Telefono, email o WhatsApp ' . ($hasContacts ? 'sono presenti nelle pagine.' : 'non si trovano facilmente.')],
            ['content', mb_strlen($text) >= 2500, 'Testi sufficienti',
                mb_strlen($text) >= 2500 ? 'Le pagine hanno testi utili da cui Google e i clienti capiscono l\'offerta.' : 'Le pagine hanno pochi testi: Google fatica a capire cosa offre l\'attività.'],
            ['blog', (bool)preg_match('#/(blog|news|notizie|articoli|magazine)#i', $urls) || (bool)preg_match('/\b(blog|news|notizie)\b/iu', implode(' ', $site['menu'])), 'Blog o notizie',
                'Un blog aggiornato porta visite da Google e contenuti per i social.'],
            ['brand', count($site['colors']) > 0 && $site['logo_candidates'] !== [], 'Identità visiva riconoscibile',
                count($site['colors']) > 0 ? 'Colori del marchio riconosciuti: ' . implode(', ', array_slice($site['colors'], 0, 3)) . '.' : 'Non emergono colori del marchio coerenti.'],
        ];
        return array_map(fn($c) => ['key' => $c[0], 'ok' => $c[1], 'label' => $c[2], 'detail' => $c[3]], $checks);
    }

    public static function score(array $checks): int
    {
        return $checks ? (int)round(100 * count(array_filter($checks, fn($c) => $c['ok'])) / count($checks)) : 0;
    }
}

/** Formati in cui si può trasformare un contenuto. */
final class Repurpose
{
    /** formato => [canale, tipo di contenuto, etichetta, istruzioni per l'AI] */
    public const FORMATS = [
        'instagram_post' => ['instagram', 'post', 'Post Instagram', 'post per il feed Instagram'],
        'carosello' => ['instagram', 'carosello', 'Carosello', "carosello Instagram di 5-7 slide: nel body scrivi ogni slide su una riga che inizia con \"Slide N:\", poi una riga vuota e la didascalia"],
        'reel' => ['instagram', 'reel', 'Copione Reel', 'copione per un Reel di 20-40 secondi: nel body scrivi gancio iniziale, scene con testo a schermo e voce, chiusura; poi la didascalia'],
        'facebook' => ['facebook', 'post', 'Post Facebook', 'post per Facebook'],
        'linkedin' => ['linkedin', 'post', 'Post LinkedIn', 'post per LinkedIn'],
        'newsletter' => ['email', 'email', 'Newsletter', 'newsletter: il titolo è l\'oggetto, il body è il testo completo della email'],
        'blog' => ['blog', 'articolo', 'Articolo blog', 'articolo per il blog di 400-700 parole con sottotitoli'],
    ];
}

/** Esempi di stile appresi dalle correzioni del team e dalle richieste di modifica, per cliente. */
final class StyleMemory
{
    private const KEEP = 30;

    /** Ultimi esempi da mostrare all'AI per questo cliente: correzioni prima/dopo e note di revisione. */
    public static function forBrand(int $orgId, int $brandId, int $limit = 6): array
    {
        $stmt = Database::connection()->prepare("SELECT kind, channel, before_text, after_text FROM style_examples WHERE organization_id=? AND brand_profile_id=? ORDER BY id DESC LIMIT {$limit}");
        $stmt->execute([$orgId, $brandId]);
        return $stmt->fetchAll();
    }

    /** All'approvazione: se il team ha corretto il testo dell'AI, salva la coppia prima/dopo. */
    public static function captureEdit(int $orgId, array $item): void
    {
        $before = trim(self::stripNotes((string)($item['ai_body'] ?? '')));
        $after = trim(self::stripNotes((string)$item['body']));
        if ($before === '' || $after === '' || self::similar($before, $after)) return;
        $brandId = self::brandOf($orgId, (int)$item['project_id']);
        if ($brandId) self::store($orgId, $brandId, (int)$item['id'], $item['channel'], 'edit', $before, $after);
    }

    /** Alla richiesta di modifiche: la nota del revisore o del cliente vale come regola di stile. */
    public static function captureFeedback(int $orgId, array $item, string $note): void
    {
        $brandId = self::brandOf($orgId, (int)$item['project_id']);
        if ($brandId) self::store($orgId, $brandId, (int)$item['id'], $item['channel'], 'feedback', null, $note);
    }

    private static function store(int $orgId, int $brandId, int $contentId, string $channel, string $kind, ?string $before, string $after): void
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO style_examples (organization_id, brand_profile_id, content_item_id, channel, kind, before_text, after_text, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$orgId, $brandId, $contentId, $channel, $kind, $before === null ? null : mb_substr($before, 0, 4000), mb_substr($after, 0, 4000), date('Y-m-d H:i:s')]);
        // Si tengono solo gli esempi più recenti: lo stile cambia nel tempo.
        $stmt = $pdo->prepare('SELECT id FROM style_examples WHERE organization_id=? AND brand_profile_id=? ORDER BY id DESC LIMIT 1 OFFSET ' . self::KEEP);
        $stmt->execute([$orgId, $brandId]);
        if ($oldest = $stmt->fetchColumn()) {
            $pdo->prepare('DELETE FROM style_examples WHERE organization_id=? AND brand_profile_id=? AND id<=?')->execute([$orgId, $brandId, $oldest]);
        }
    }

    private static function brandOf(int $orgId, int $projectId): ?int
    {
        $project = Tenant::find('projects', $projectId, $orgId, 'brand_profile_id');
        return $project && $project['brand_profile_id'] ? (int)$project['brand_profile_id'] : null;
    }

    private static function stripNotes(string $body): string
    {
        return preg_replace('/\n*---\nNote per il revisore:.*$/su', '', $body);
    }

    /** Ritocchi minimi (spazi, un paio di parole) non insegnano niente. */
    private static function similar(string $a, string $b): bool
    {
        $a = preg_replace('/\s+/u', ' ', $a);
        $b = preg_replace('/\s+/u', ' ', $b);
        if ($a === $b) return true;
        if (mb_strlen($a) > 2000 || mb_strlen($b) > 2000) return false;
        similar_text($a, $b, $percent);
        return $percent >= 97;
    }
}
