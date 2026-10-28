<?php
declare(strict_types=1);

/**
 * Configurazione delle sponsorizzate Meta di un cliente: chi paga (il cliente con la sua carta o l'agenzia,
 * che poi rifattura), l'account pubblicitario e i passaggi della guida già fatti.
 * I dati di pagamento non passano mai dall'app: si inseriscono solo su Meta.
 */
final class AdsSetupController
{
    public const STEPS = [
        'client' => ['client_portfolio', 'client_ad_account', 'client_payment', 'partner_access', 'people_assigned'],
        'agency' => ['agency_ad_account', 'agency_payment', 'page_access', 'people_assigned', 'billing_setup'],
    ];

    public static function show(int $brandId): never
    {
        $user = Auth::user();
        Http::json(['data' => self::find($brandId, $user['organization_id'])]);
    }

    public static function update(int $brandId): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $current = self::find($brandId, $user['organization_id']);
        $data = Http::body();
        $fields = [];
        if (array_key_exists('ads_payer', $data)) {
            $fields['ads_payer'] = $data['ads_payer'] === null ? null : Http::oneOf($data, 'ads_payer', ['client', 'agency']);
        }
        // Meta mostra gli ID come "act_123…" o solo cifre: si salvano solo le cifre.
        foreach (['meta_ad_account_id' => 'dell\'account pubblicitario', 'meta_business_id' => 'del portfolio business'] as $key => $label) {
            if (!array_key_exists($key, $data)) continue;
            $value = Http::optionalString($data, $key, 40);
            if ($value !== null) {
                $value = preg_replace('/^act_/i', '', str_replace(' ', '', $value)) ?? '';
                if (!preg_match('/^\d{5,25}$/', $value)) Http::validationError("L'ID {$label} è fatto solo di cifre (lo trovi nelle impostazioni di Meta).");
            }
            $fields[$key] = $value;
        }
        $payer = array_key_exists('ads_payer', $fields) ? $fields['ads_payer'] : $current['ads_payer'];
        if (array_key_exists('steps', $data)) {
            if (!is_array($data['steps'])) Http::validationError("Campo 'steps' non valido.");
            $allowed = $payer ? self::STEPS[$payer] : [];
            $steps = array_values(array_unique(array_filter($data['steps'], fn($s) => is_string($s) && in_array($s, $allowed, true))));
            $fields['ads_setup_steps'] = json_encode($steps);
        } elseif (array_key_exists('ads_payer', $fields) && $fields['ads_payer'] !== $current['ads_payer']) {
            // Cambiando chi paga i passaggi sono diversi: si riparte da zero.
            $fields['ads_setup_steps'] = json_encode([]);
        }
        if ($fields) {
            $set = implode(', ', array_map(fn($c) => "{$c}=?", array_keys($fields)));
            Database::connection()->prepare("UPDATE brand_profiles SET {$set} WHERE id=? AND organization_id=?")->execute([...array_values($fields), $brandId, $user['organization_id']]);
            Audit::log($user, 'brand.ads_setup_updated', 'brand_profile', $brandId);
            if (array_key_exists('ads_payer', $fields) && $fields['ads_payer'] !== $current['ads_payer']) {
                // Le bozze di fattura cambiano: se paga il cliente le sponsorizzate non si rifatturano.
                $stmt = Database::connection()->prepare("SELECT id FROM invoices WHERE organization_id=? AND brand_profile_id=? AND status='to_invoice'");
                $stmt->execute([$user['organization_id'], $brandId]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $invoiceId) BillingController::rebuild((int)$invoiceId, $user['organization_id']);
            }
        }
        Http::json(['data' => self::find($brandId, $user['organization_id'])]);
    }

    private static function find(int $brandId, int $organizationId): array
    {
        $row = Tenant::findOrFail('brand_profiles', $brandId, $organizationId, 'id, ads_payer, meta_ad_account_id, meta_business_id, ads_setup_steps');
        $steps = $row['ads_setup_steps'] === null ? [] : (json_decode($row['ads_setup_steps'], true) ?: []);
        return [
            'brand_profile_id' => (int)$row['id'],
            'ads_payer' => $row['ads_payer'],
            'meta_ad_account_id' => $row['meta_ad_account_id'],
            'meta_business_id' => $row['meta_business_id'],
            'steps' => $steps,
            'total_steps' => $row['ads_payer'] ? count(self::STEPS[$row['ads_payer']]) : 0,
        ];
    }
}
