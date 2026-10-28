<?php
declare(strict_types=1);

/** Generatore locale senza costi: produce bozze segnaposto per provare il flusso senza chiave API. */
final class FakeContentGenerator implements ContentGenerator
{
    public function generate(array $brand, array $project, array $request): array
    {
        $brandName = $brand['name'] ?? $project['name'];
        $variants = [];
        for ($i = 1; $i <= $request['variants']; $i++) {
            $variants[] = [
                'title' => "Variante {$i} — {$project['name']}",
                'body' => "[Bozza di esempio: generazione AI non attiva]\n\n{$brandName}: {$request['brief']}\n\nObiettivo: {$project['objective']}.",
                'call_to_action' => 'Scopri di più',
                'hashtags' => ['marketing', 'esempio'],
                'reviewer_notes' => 'Testo segnaposto: imposta ANTHROPIC_API_KEY per generare contenuti reali.',
            ];
        }
        return $variants;
    }
}
