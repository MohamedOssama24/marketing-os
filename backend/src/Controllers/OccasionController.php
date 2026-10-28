<?php
declare(strict_types=1);

/**
 * Ricorrenze utili per i contenuti: festività italiane, giornate mondiali e momenti commerciali.
 * Le date mobili (Pasqua, festa della mamma, Black Friday…) si calcolano per ogni anno.
 */
final class OccasionController
{
    /** [mese-giorno, nome, idea per i contenuti, settori a cui serve di più (vuoto = tutti)] */
    private const FIXED = [
        ['01-01', 'Capodanno', 'Auguri e buoni propositi: cosa vi aspetta quest\'anno.', []],
        ['01-06', 'Epifania', 'Ultimo giorno di feste: dolci, regali e chiusure.', ['ristorazione', 'negozi']],
        ['01-07', 'Inizio saldi invernali', 'Prodotti in saldo e ultime taglie, con una scadenza chiara.', ['negozi', 'moda']],
        ['02-14', 'San Valentino', 'Idee regalo, cene e momenti di coppia.', ['ristorazione', 'negozi', 'moda', 'benessere']],
        ['03-08', 'Festa della donna', 'Storie di donne del team o delle clienti, senza stereotipi.', []],
        ['03-19', 'Festa del papà', 'Idee regalo e dediche ai papà.', ['negozi', 'ristorazione']],
        ['03-20', 'Inizio della primavera', 'Novità di stagione e cambio di collezione o di menù.', []],
        ['04-22', 'Giornata della Terra', 'Cosa fate per l\'ambiente, in modo concreto.', []],
        ['04-25', 'Festa della Liberazione', 'Orari di apertura del ponte, gite fuori porta.', ['turismo', 'ristorazione']],
        ['05-01', 'Festa dei lavoratori', 'Un grazie al team, dietro le quinte del lavoro.', []],
        ['06-02', 'Festa della Repubblica', 'Orari del ponte, prodotti del territorio.', []],
        ['06-21', 'Inizio dell\'estate', 'Proposte estive e orari di apertura.', ['turismo', 'ristorazione', 'benessere']],
        ['07-01', 'Inizio saldi estivi', 'Prodotti in saldo, con una scadenza chiara.', ['negozi', 'moda']],
        ['08-15', 'Ferragosto', 'Aperture, chiusure per ferie e auguri.', []],
        ['09-01', 'Rientro e back to school', 'Ripartenza dopo l\'estate, novità di settembre.', ['negozi', 'formazione', 'servizi']],
        ['10-01', 'Giornata internazionale del caffè', 'Una pausa caffè con il team o i clienti.', ['ristorazione']],
        ['10-16', 'Giornata mondiale dell\'alimentazione', 'Ingredienti, filiera e qualità.', ['ristorazione', 'alimentare']],
        ['10-31', 'Halloween', 'Contenuto giocoso, travestimenti o menù a tema.', ['ristorazione', 'negozi']],
        ['11-01', 'Ognissanti', 'Orari di apertura del ponte.', []],
        ['12-08', 'Immacolata e inizio del Natale', 'Addobbi, idee regalo e calendario delle feste.', []],
        ['12-24', 'Vigilia di Natale', 'Auguri del team e orari delle feste.', []],
        ['12-25', 'Natale', 'Auguri di Natale.', []],
        ['12-26', 'Santo Stefano', 'Orari e pranzi delle feste.', ['ristorazione']],
        ['12-31', 'San Silvestro', 'Bilancio dell\'anno e ringraziamenti ai clienti.', []],
    ];

    public static function index(): never
    {
        Auth::user();
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_GET['from'] ?? '')) ?: new DateTimeImmutable('today');
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_GET['to'] ?? '')) ?: $from->modify('+30 days');
        if ($to < $from || $from->diff($to)->days > 400) Http::validationError('Intervallo di date non valido (massimo un anno).');
        Http::json(['data' => self::between($from, $to)]);
    }

    /** @return list<array{date: string, name: string, idea: string, sectors: list<string>}> */
    public static function between(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $items = [];
        for ($year = (int)$from->format('Y'); $year <= (int)$to->format('Y'); $year++) {
            foreach ([...self::FIXED, ...self::movable($year)] as [$day, $name, $idea, $sectors]) {
                $date = new DateTimeImmutable("{$year}-{$day}");
                if ($date >= $from && $date <= $to) $items[] = ['date' => $date->format('Y-m-d'), 'name' => $name, 'idea' => $idea, 'sectors' => $sectors];
            }
        }
        usort($items, fn($a, $b) => $a['date'] <=> $b['date']);
        return $items;
    }

    private static function movable(int $year): array
    {
        $easter = self::easter($year);
        $nthSunday = fn(int $month, int $n) => (new DateTimeImmutable(sprintf('%d-%02d-01', $year, $month)))->modify('-1 day')->modify("+{$n} sunday");
        // Black Friday: il giorno dopo il quarto giovedì di novembre.
        $blackFriday = (new DateTimeImmutable("{$year}-10-31"))->modify('+4 thursday')->modify('+1 day');
        return [
            [$easter->modify('-47 days')->format('m-d'), 'Carnevale (martedì grasso)', 'Dolci di Carnevale, maschere, contenuto giocoso.', ['ristorazione', 'negozi']],
            [$easter->format('m-d'), 'Pasqua', 'Auguri, menù o idee regalo di Pasqua.', []],
            [$easter->modify('+1 day')->format('m-d'), 'Pasquetta', 'Gite fuori porta e orari di apertura.', ['turismo', 'ristorazione']],
            [$nthSunday(5, 2)->format('m-d'), 'Festa della mamma', 'Idee regalo e dediche alle mamme.', ['negozi', 'ristorazione', 'benessere', 'moda']],
            [$blackFriday->format('m-d'), 'Black Friday', 'Offerta a tempo, solo se l\'attività la fa davvero.', ['negozi', 'moda', 'servizi']],
            [$blackFriday->modify('+3 days')->format('m-d'), 'Cyber Monday', 'Offerte online e spedizioni.', ['negozi']],
        ];
    }

    /** Domenica di Pasqua (calendario gregoriano, algoritmo di Meeus): l'estensione calendar di PHP non è sempre installata. */
    private static function easter(int $y): DateTimeImmutable
    {
        $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100;
        $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return new DateTimeImmutable(sprintf('%d-%02d-%02d', $y, $month, $day));
    }
}
