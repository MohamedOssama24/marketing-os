<?php
declare(strict_types=1);

/**
 * Legge il CSV esportato da Gestione inserzioni di Meta (in italiano o in inglese) e,
 * con le stesse intestazioni, quello di altre piattaforme. Separatore, codifica e
 * formato dei numeri ("1.234,56" oppure "1234.56") vengono riconosciuti da soli.
 */
final class AdsCsvParser
{
    public const MAX_ROWS = 2000;

    /** Intestazioni riconosciute, già normalizzate (minuscole, senza accenti e senza la parte tra parentesi). */
    private const COLUMNS = [
        'campaign_name' => ['nome della campagna', 'nome campagna', 'campaign name', 'campagna', 'campaign', 'nome del gruppo di inserzioni', 'ad set name', 'nome dell\'inserzione', 'ad name'],
        'period_start' => ['inizio dei report', 'inizio report', 'reporting starts', 'data di inizio', 'start date', 'giorno', 'day', 'data', 'date'],
        'period_end' => ['fine dei report', 'fine report', 'reporting ends', 'data di fine', 'end date'],
        'spent' => ['importo speso', 'amount spent', 'spesa', 'spend', 'costo', 'cost'],
        'budget' => ['budget del gruppo di inserzioni', 'ad set budget', 'budget della campagna', 'campaign budget', 'budget'],
        'impressions' => ['impression', 'impressions', 'impressioni'],
        'reach' => ['copertura', 'reach'],
        'clicks' => ['clic sul link', 'link clicks', 'clic (tutti)', 'clicks (all)', 'clic', 'clicks'],
        'results' => ['risultati', 'results', 'conversioni', 'conversions'],
    ];

    /**
     * @return array{rows: list<array<string, mixed>>, errors: list<array{line: int, message: string}>, skipped: int, currency: ?string}
     */
    public static function parse(string $content): array
    {
        $content = self::toUtf8($content);
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        // Alcune esportazioni hanno righe descrittive prima delle intestazioni: si parte dalla prima che contiene la spesa.
        $headerIndex = null;
        foreach (array_slice($lines, 0, 15, true) as $i => $line) {
            if (preg_match('/importo speso|amount spent|\bspesa\b|\bspend\b|\bcost[oi]?\b/i', self::normalize($line))) { $headerIndex = $i; break; }
        }
        if ($headerIndex === null) Http::validationError('Nel file non trovo la colonna della spesa ("Importo speso" o "Amount spent"). Esporta il report da Gestione inserzioni in formato CSV.');

        $body = implode("\n", array_slice($lines, $headerIndex));
        $delimiter = self::delimiter($lines[$headerIndex]);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $body);
        rewind($stream);
        $header = fgetcsv($stream, null, $delimiter, '"', '');
        [$map, $currency] = self::mapHeader($header ?: []);
        foreach (['campaign_name' => 'nome della campagna', 'period_start' => 'inizio dei report (o giorno)', 'spent' => 'importo speso'] as $key => $label) {
            if (!isset($map[$key])) Http::validationError("Nel file manca la colonna \"{$label}\".");
        }
        if ($currency !== null && $currency !== 'EUR') Http::validationError("Il file riporta importi in {$currency}: per ora il registro gestisce solo euro.");

        $rows = [];
        $errors = [];
        $skipped = 0;
        $lineNumber = $headerIndex + 1;
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $lineNumber++;
            if ($cells === [null] || trim(implode('', $cells)) === '') continue;
            $value = fn(string $key) => isset($map[$key]) ? trim((string)($cells[$map[$key]] ?? '')) : '';
            $name = mb_substr($value('campaign_name'), 0, 190);
            // La riga dei totali di Meta non ha il nome della campagna.
            if ($name === '') { $skipped++; continue; }
            $start = self::date($value('period_start'));
            $end = $value('period_end') === '' ? $start : self::date($value('period_end'));
            $spent = self::money($value('spent'));
            if ($start === null || $end === null) { $errors[] = ['line' => $lineNumber, 'message' => "Data non valida per \"{$name}\"."]; continue; }
            if ($end < $start) [$start, $end] = [$end, $start];
            if ($spent === null || $spent < 0) { $errors[] = ['line' => $lineNumber, 'message' => "Importo speso non valido per \"{$name}\"."]; continue; }
            $impressions = self::integer($value('impressions'));
            if ($spent == 0 && !$impressions) { $skipped++; continue; }
            if (count($rows) >= self::MAX_ROWS) Http::validationError('Il file supera ' . self::MAX_ROWS . ' righe: esporta un periodo più breve.');
            $rows[] = [
                'campaign_name' => $name,
                'period_start' => $start,
                'period_end' => $end,
                'spent' => round($spent, 2),
                'budget' => ($budget = self::money($value('budget'))) === null ? null : round($budget, 2),
                'impressions' => $impressions,
                'reach' => self::integer($value('reach')),
                'clicks' => self::integer($value('clicks')),
                'results' => self::integer($value('results')),
            ];
        }
        fclose($stream);
        return ['rows' => $rows, 'errors' => $errors, 'skipped' => $skipped, 'currency' => $currency];
    }

    /** Importo con virgola o punto decimale; null se la cella non contiene un numero. */
    public static function money(string $raw): ?float
    {
        $value = preg_replace('/[^\d,.\-]/', '', $raw) ?? '';
        if ($value === '' || !preg_match('/\d/', $value)) return null;
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        if ($lastComma !== false && $lastDot !== false) {
            // Il separatore che compare per ultimo è quello dei decimali.
            $decimal = $lastComma > $lastDot ? ',' : '.';
            $value = str_replace($decimal === ',' ? '.' : ',', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif ($lastComma !== false) {
            $value = substr_count($value, ',') > 1 ? str_replace(',', '', $value) : str_replace(',', '.', $value);
        } elseif ($lastDot !== false && substr_count($value, '.') > 1) {
            $value = str_replace('.', '', $value);
        }
        return is_numeric($value) ? (float)$value : null;
    }

    private static function integer(string $raw): ?int
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        return $digits === '' ? null : min((int)$digits, 4294967295);
    }

    private static function date(string $raw): ?string
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $raw);
            if ($date && $date->format($format) === $raw) return $date->format('Y-m-d');
        }
        return null;
    }

    private static function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\xFF\xFE")) return mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
        if (str_starts_with($content, "\xFE\xFF")) return mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
        if (str_starts_with($content, "\xEF\xBB\xBF")) $content = substr($content, 3);
        return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    private static function delimiter(string $headerLine): string
    {
        $counts = [';' => substr_count($headerLine, ';'), "\t" => substr_count($headerLine, "\t"), ',' => substr_count($headerLine, ',')];
        arsort($counts);
        return (string)array_key_first($counts);
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text, " \t\"'"));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        return $ascii === false ? $text : $ascii;
    }

    /** @return array{0: array<string, int>, 1: ?string} colonne trovate e valuta indicata nell'intestazione della spesa */
    private static function mapHeader(array $header): array
    {
        $cells = [];
        foreach ($header as $index => $cell) {
            $full = self::normalize((string)$cell);
            // "Clic (tutti)" si confronta con le parentesi, il resto senza ("Importo speso (EUR)" → "importo speso").
            $cells[$index] = [$full, trim(preg_replace('/\s*\(([^)]*)\)\s*/', ' ', $full) ?? $full)];
        }
        $map = [];
        $currency = null;
        foreach (self::COLUMNS as $key => $aliases) {
            // Gli alias sono in ordine di preferenza: "Clic sul link" vince su "Clic (tutti)".
            foreach ($aliases as $alias) {
                foreach ($cells as $index => [$full, $plain]) {
                    if (in_array($index, $map, true) || ($full !== $alias && $plain !== $alias)) continue;
                    $map[$key] = $index;
                    if ($key === 'spent' && preg_match('/\(([a-z]{3})\)/', $full, $m)) $currency = strtoupper($m[1]);
                    continue 3;
                }
            }
        }
        return [$map, $currency];
    }
}
