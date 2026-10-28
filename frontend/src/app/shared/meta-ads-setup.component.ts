import { Component, Input, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ApiService } from '../core/api.service';
import { AuthService } from '../core/auth.service';
import { AdsPayer, AdsSetup, apiErrorMessage } from '../core/models';

interface GuideStep {
  key: string;
  title: string;
  who: 'Cliente' | 'Agenzia';
  /** Passaggi da seguire; i link si aprono su Meta. */
  items: string[];
  links?: { label: string; url: string }[];
  billingLink?: boolean;
}

const BUSINESS_SETTINGS = 'https://business.facebook.com/settings';
const BILLING = 'https://business.facebook.com/billing_hub/payment_settings';

const STEPS: Record<AdsPayer, GuideStep[]> = {
  client: [
    {
      key: 'client_portfolio', who: 'Cliente', title: 'Il cliente ha un portfolio business su Meta',
      items: [
        'Il cliente apre business.facebook.com con il suo profilo Facebook e crea il portfolio business (il vecchio "Business Manager"). Se lo ha già, questo passaggio è fatto.',
        'Nel portfolio collega la sua Pagina Facebook e il profilo Instagram professionale.',
      ],
      links: [{ label: 'Apri Meta Business Suite', url: 'https://business.facebook.com/' }],
    },
    {
      key: 'client_ad_account', who: 'Cliente', title: 'Il cliente crea il suo account pubblicitario',
      items: [
        'In Impostazioni business → Account → Account pubblicitari → Aggiungi → Crea un nuovo account pubblicitario.',
        'Valuta EUR e fuso orario Europa/Roma: dopo non si possono più cambiare.',
        'L\'ID dell\'account è il numero sotto il nome: fattelo mandare e inseriscilo nel campo qui sopra.',
      ],
      links: [{ label: 'Impostazioni business', url: BUSINESS_SETTINGS }],
    },
    {
      key: 'client_payment', who: 'Cliente', title: 'Il cliente aggiunge il suo metodo di pagamento',
      items: [
        'In Impostazioni business → Fatturazione e pagamenti → Metodi di pagamento → Aggiungi, oppure dalle impostazioni di pagamento dell\'account pubblicitario.',
        'Può usare carta di credito o di debito, PayPal o, dove disponibile, l\'addebito sul conto. Conviene aggiungere anche un metodo di riserva, così le inserzioni non si fermano se la carta scade.',
        'Consiglia un limite di spesa dell\'account pari al budget concordato: Meta non spende oltre.',
      ],
      links: [{ label: 'Impostazioni di pagamento', url: BILLING }],
    },
    {
      key: 'partner_access', who: 'Cliente', title: 'Il cliente ti aggiunge come partner',
      items: [
        'Mandagli l\'ID del tuo portfolio business: lo trovi nelle tue Impostazioni business → Informazioni sul business.',
        'Il cliente apre Impostazioni business → Account pubblicitari → il suo account → Assegna partner, inserisce il tuo ID e ti dà il controllo completo (o almeno la gestione delle campagne). Fa lo stesso per la Pagina e per l\'account Instagram.',
        'In alternativa sei tu a chiederlo: nelle tue Impostazioni business → Account pubblicitari → Aggiungi → Richiedi l\'accesso a un account pubblicitario, con l\'ID dell\'account del cliente. Il cliente deve approvare.',
      ],
      links: [{ label: 'Le tue impostazioni business', url: BUSINESS_SETTINGS }],
    },
    {
      key: 'people_assigned', who: 'Agenzia', title: 'Assegni le persone del tuo team',
      items: [
        'Nel tuo portfolio: Account pubblicitari → l\'account del cliente → Assegna persone.',
        'Aggiungi te e i colleghi che creano le campagne. Senza questo passaggio l\'account non compare in Gestione inserzioni.',
      ],
      links: [{ label: 'Le tue impostazioni business', url: BUSINESS_SETTINGS }],
    },
  ],
  agency: [
    {
      key: 'agency_ad_account', who: 'Agenzia', title: 'Crei un account pubblicitario per il cliente',
      items: [
        'Nel tuo portfolio: Impostazioni business → Account pubblicitari → Aggiungi → Crea un nuovo account pubblicitario, con il nome del cliente.',
        'Un account per cliente: la spesa resta separata e il report CSV di Meta si importa direttamente in Sponsorizzate.',
        'Valuta EUR e fuso orario Europa/Roma. Inserisci l\'ID dell\'account nel campo qui sopra.',
      ],
      links: [{ label: 'Impostazioni business', url: BUSINESS_SETTINGS }],
    },
    {
      key: 'agency_payment', who: 'Agenzia', title: 'Aggiungi il metodo di pagamento dell\'agenzia',
      items: [
        'In Fatturazione e pagamenti → Metodi di pagamento aggiungi la carta (o il conto) dell\'agenzia e associalo all\'account del cliente.',
        'Imposta un limite di spesa dell\'account pari al budget concordato con il cliente: eviti di anticipare più del previsto.',
        'Le ricevute di Meta per la tua contabilità si scaricano da Fatturazione e pagamenti → Attività di pagamento.',
      ],
      links: [{ label: 'Impostazioni di pagamento', url: BILLING }],
    },
    {
      key: 'page_access', who: 'Cliente', title: 'Il cliente ti dà accesso alla Pagina e a Instagram',
      items: [
        'Mandagli l\'ID del tuo portfolio business (Impostazioni business → Informazioni sul business).',
        'Il cliente apre le sue Impostazioni business → Pagine → la sua Pagina → Assegna partner, e lo stesso per l\'account Instagram. Se non ha un portfolio, ti aggiunge tra le persone con accesso alla Pagina.',
      ],
      links: [{ label: 'Impostazioni business', url: BUSINESS_SETTINGS }],
    },
    {
      key: 'people_assigned', who: 'Agenzia', title: 'Assegni le persone del tuo team',
      items: ['Account pubblicitari → l\'account del cliente → Assegna persone: aggiungi te e i colleghi che creano le campagne.'],
      links: [{ label: 'Impostazioni business', url: BUSINESS_SETTINGS }],
    },
    {
      key: 'billing_setup', who: 'Agenzia', title: 'Imposti canone e commissione in Fatturazione',
      items: [
        'In Fatturazione → Canoni e dati dei clienti indica la commissione sulle sponsorizzate e i dati di fatturazione del cliente.',
        'Ogni mese importa il report di Meta in Sponsorizzate e prepara le fatture: le spese vengono rifatturate al cliente con la commissione.',
      ],
      billingLink: true,
    },
  ],
};

/** Guida per collegare le sponsorizzate Meta di un cliente: chi paga, account pubblicitario e passaggi da spuntare. */
@Component({
  selector: 'app-meta-ads-setup',
  standalone: true,
  imports: [FormsModule, RouterLink],
  template: `
    <section class="card meta-setup">
      <div class="strategy-head">
        <h2><i class="ti ti-brand-meta" aria-hidden="true"></i> Sponsorizzate su Meta</h2>
        @if (setup()?.ads_payer) {
          <span class="count-pill" [class.complete]="doneCount() === steps().length">{{ doneCount() }}/{{ steps().length }} passaggi</span>
        }
      </div>

      @if (error()) { <div class="alert">{{ error() }}</div> }

      @if (setup(); as s) {
        @if (!s.ads_payer || choosing()) {
          <p class="muted small">Chi paga le inserzioni su Facebook e Instagram? Da qui dipendono i passaggi su Meta e cosa finisce in fattura.</p>
          <div class="payer-options">
            <button class="payer-option" [class.on]="s.ads_payer === 'client'" (click)="setPayer('client')" [disabled]="!auth.canEdit() || busy()">
              <strong><i class="ti ti-credit-card" aria-hidden="true"></i> Paga il cliente <span class="tag ai">Consigliato</span></strong>
              <span>Il cliente mette la sua carta su Meta e tu gestisci le campagne come partner. Non anticipi soldi e fatturi solo canone e commissione.</span>
            </button>
            <button class="payer-option" [class.on]="s.ads_payer === 'agency'" (click)="setPayer('agency')" [disabled]="!auth.canEdit() || busy()">
              <strong><i class="ti ti-building-bank" aria-hidden="true"></i> Paga l'agenzia</strong>
              <span>Usi la carta dell'agenzia e rifatturi la spesa al cliente ogni mese con la Fatturazione. Serve liquidità per anticipare.</span>
            </button>
          </div>
        } @else {
          <p class="payer-line">
            <i class="ti" [class]="s.ads_payer === 'client' ? 'ti ti-credit-card' : 'ti ti-building-bank'" aria-hidden="true"></i>
            {{ s.ads_payer === 'client' ? 'Paga il cliente con il suo metodo di pagamento: in fattura vanno solo canone e commissione.' : 'Paga l\\'agenzia: la spesa viene rifatturata al cliente.' }}
            @if (auth.canEdit()) { <button class="link-btn" (click)="choosing.set(true)">Cambia</button> }
          </p>

          <div class="account-row">
            <label>ID account pubblicitario
              <input [(ngModel)]="accountId" placeholder="Es. 1234567890" [disabled]="!auth.canEdit()" (keydown.enter)="saveAccount()" />
            </label>
            @if (auth.canEdit() && accountId.trim() !== (s.meta_ad_account_id ?? '')) {
              <button class="btn ghost small" (click)="saveAccount()" [disabled]="busy()">Salva</button>
            }
            @if (s.meta_ad_account_id) {
              <a class="btn ghost small" [href]="'https://adsmanager.facebook.com/adsmanager/manage/campaigns?act=' + s.meta_ad_account_id" target="_blank" rel="noopener"><i class="ti ti-external-link" aria-hidden="true"></i> Gestione inserzioni</a>
              <a class="btn ghost small" [href]="billingUrl + '?asset_id=' + s.meta_ad_account_id" target="_blank" rel="noopener"><i class="ti ti-credit-card" aria-hidden="true"></i> Pagamenti</a>
            }
          </div>

          <ol class="setup-steps">
            @for (step of steps(); track step.key; let i = $index) {
              <li [class.done]="isDone(step.key)" [class.open]="open() === step.key">
                <div class="setup-step-head">
                  <input type="checkbox" [checked]="isDone(step.key)" (change)="toggle(step.key)" [disabled]="!auth.canEdit() || busy()" [attr.aria-label]="'Fatto: ' + step.title" />
                  <button class="setup-step-title" (click)="open.set(open() === step.key ? null : step.key)" [attr.aria-expanded]="open() === step.key">
                    <span>{{ i + 1 }}. {{ step.title }}</span>
                    <span class="tag" [class.ai]="step.who === 'Agenzia'">{{ step.who }}</span>
                    <i class="ti" [class]="open() === step.key ? 'ti ti-chevron-up' : 'ti ti-chevron-down'" aria-hidden="true"></i>
                  </button>
                </div>
                @if (open() === step.key) {
                  <div class="setup-step-body">
                    <ul>@for (item of step.items; track $index) { <li>{{ item }}</li> }</ul>
                    <div class="actions start">
                      @for (l of step.links ?? []; track l.url) {
                        <a class="btn ghost small" [href]="l.url" target="_blank" rel="noopener"><i class="ti ti-external-link" aria-hidden="true"></i> {{ l.label }}</a>
                      }
                      @if (step.billingLink && auth.canManage()) { <a class="btn ghost small" routerLink="/billing"><i class="ti ti-file-invoice" aria-hidden="true"></i> Apri Fatturazione</a> }
                    </div>
                  </div>
                }
              </li>
            }
          </ol>

          <p class="safety small"><i class="ti ti-shield-lock" aria-hidden="true"></i>
            <span>I dati della carta si inseriscono solo su Meta, dall'account di chi paga. Non chiederli al cliente via email o WhatsApp e non scriverli in Marketing OS.
            I nomi dei menu di Meta cambiano spesso: se non trovi una voce, cercala in Impostazioni business.</span></p>
        }
      } @else if (!error()) {
        <p class="muted small">Caricamento…</p>
      }
    </section>
  `,
})
export class MetaAdsSetupComponent implements OnInit {
  @Input({ required: true }) brandId!: number;

  readonly auth = inject(AuthService);
  private api = inject(ApiService);
  readonly billingUrl = BILLING;

  setup = signal<AdsSetup | null>(null);
  choosing = signal(false);
  open = signal<string | null>(null);
  busy = signal(false);
  error = signal<string | null>(null);
  accountId = '';

  readonly steps = computed(() => {
    const payer = this.setup()?.ads_payer;
    return payer ? STEPS[payer] : [];
  });
  readonly doneCount = computed(() => this.steps().filter((s) => this.isDone(s.key)).length);

  ngOnInit(): void {
    this.api.adsSetup(this.brandId).subscribe({ next: (s) => this.apply(s), error: (err) => this.error.set(apiErrorMessage(err)) });
  }

  isDone(key: string): boolean {
    return this.setup()?.steps.includes(key) ?? false;
  }

  setPayer(payer: AdsPayer): void {
    if (this.setup()?.ads_payer === payer) {
      this.choosing.set(false);
      return;
    }
    this.save({ ads_payer: payer }, () => this.choosing.set(false));
  }

  saveAccount(): void {
    this.save({ meta_ad_account_id: this.accountId.trim() || null });
  }

  toggle(key: string): void {
    const steps = this.setup()?.steps ?? [];
    this.save({ steps: steps.includes(key) ? steps.filter((s) => s !== key) : [...steps, key] });
  }

  private save(data: Partial<Pick<AdsSetup, 'ads_payer' | 'meta_ad_account_id' | 'steps'>>, after?: () => void): void {
    this.busy.set(true);
    this.error.set(null);
    this.api.updateAdsSetup(this.brandId, data).subscribe({
      next: (s) => {
        this.busy.set(false);
        this.apply(s);
        after?.();
      },
      error: (err) => {
        this.busy.set(false);
        this.error.set(apiErrorMessage(err));
      },
    });
  }

  /** Apre il primo passaggio ancora da fare. */
  private apply(s: AdsSetup): void {
    this.setup.set(s);
    this.accountId = s.meta_ad_account_id ?? '';
    this.open.set(this.steps().find((step) => !s.steps.includes(step.key))?.key ?? null);
  }
}
