<?php
declare(strict_types=1);

/**
 * Storyboard di un video costruito dal post, senza AI e senza costi:
 * apertura con titolo, una scena per ogni frase chiave (con foto se disponibile), chiusura con invito all'azione.
 */
final class VideoStoryboard
{
    private const MAX_SCENES = 3;

    /**
     * @param list<string> $photoPaths foto da usare nelle scene, nell'ordine preferito
     * @return list<array{type: string, duration: float, headline?: string, text?: string, badge?: string, photo?: string}>
     */
    public static function fromPost(array $item, array $brand, array $photoPaths): array
    {
        $visual = $item['visual'] ?? [];
        $text = self::publishable((string)$item['body']);
        $cta = '';
        $paragraphs = [];
        foreach (preg_split('/\n{2,}/u', $text) as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') continue;
            if (str_starts_with($paragraph, '👉')) { $cta = trim(mb_substr($paragraph, 1)); continue; }
            if (preg_match('/^(#\S+\s*)+$/u', $paragraph)) continue;
            if (str_starts_with($paragraph, '[')) continue; // segnaposto delle bozze di esempio
            $paragraphs[] = $paragraph;
        }

        $sentences = [];
        foreach (preg_split('/(?<=[.!?])\s+/u', implode(' ', $paragraphs)) as $sentence) {
            $sentence = self::clean($sentence);
            if (mb_strlen($sentence) >= 18) $sentences[] = self::shorten($sentence, 110);
            if (count($sentences) >= self::MAX_SCENES) break;
        }
        $headline = self::clean((string)($visual['headline'] ?? '')) ?: self::clean((string)$item['title']);
        if (!$sentences) $sentences[] = self::shorten(self::clean((string)($visual['subheadline'] ?? '')) ?: self::clean((string)$item['title']), 110);

        $scenes = [['type' => 'intro', 'duration' => 2.6, 'headline' => self::shorten($headline, 60), 'badge' => self::clean((string)($visual['badge'] ?? ''))]];
        foreach ($sentences as $i => $sentence) {
            $photo = $photoPaths[$i] ?? null;
            $scenes[] = $photo
                ? ['type' => 'photo', 'duration' => 3.4, 'text' => $sentence, 'photo' => $photo]
                : ['type' => 'text', 'duration' => 3.2, 'text' => $sentence];
        }
        $site = preg_replace('#^https?://(www\.)?#', '', rtrim((string)($brand['website_url'] ?? ''), '/'));
        $scenes[] = ['type' => 'outro', 'duration' => 3.0, 'headline' => self::shorten(self::clean($cta) ?: 'Scopri di più', 50), 'text' => (string)$site];
        return $scenes;
    }

    /** Testo pubblicabile: senza le note per il revisore aggiunte in fondo alle bozze. */
    private static function publishable(string $body): string
    {
        return trim((string)preg_replace('/\n*-{3,}\s*\nNote per il revisore:.*$/su', '', $body));
    }

    /** Toglie emoji e simboli che il font non sa disegnare, e spazi superflui. */
    private static function clean(string $text): string
    {
        $text = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}\x{20E3}]/u', '', $text);
        return trim((string)preg_replace('/\s+/u', ' ', (string)$text));
    }

    private static function shorten(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) return $text;
        $cut = mb_substr($text, 0, $max);
        $space = mb_strrpos($cut, ' ');
        return rtrim(mb_substr($cut, 0, $space ?: $max), ' ,;:') . '…';
    }
}
