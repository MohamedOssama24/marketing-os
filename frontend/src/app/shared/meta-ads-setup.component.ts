import { Component, Input, OnInit, computed, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { ApiService } from '../core/api.service';
import { AuthService } from '../core/auth.service';
import { AdsPayer, AdsSetup, MetaAssets, MetaConnection, apiErrorMessage } from '../core/models';

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
  imports: [DatePipe, FormsModule, RouterLink],
  template: `
    <section class="card meta-setup">
      <div class="strategy-head">
        <h2><i class="ti ti-brand-meta" aria-hidden="true"></i> Sponsorizzate su Meta</h2>
        @if (setup()?.ads_payer) {
          <span class="count-pill" [class.complete]="doneCount() === steps().length">{{ doneCount() }}/{{ steps().length }} passaggi</span>
        }
      </div>

      @if (error()) { <div class="alert">{{ error() }}</div> }
      @if (notice(); as n) { <div class="notice" [class.warning]="n.warning"><i class="ti" [class]="n.warning ? 'ti ti-alert-triangle' : 'ti ti-circle-check'" aria-hidden="true"></i> <span>{{ n.text }}</span></div> }

      @if (conn(); as c) {
        @if (c.mode) {
          <div class="meta-connection">
            @if (!c.connected) {
              <div class="meta-connect-row">
                <span><strong>Pubblica le sponsorizzate da qui</strong><br />
                  <small class="muted">Accedi con il Facebook di chi gestisce l'account pubblicitario: con lo stesso accesso le sponsorizzate si pubblicano su Facebook e su Instagram
                  senza aprire Gestione inserzioni, e spesa e risultati arrivano da soli.</small></span>
                @if (auth.canEdit()) {
                  <button class="btn primary" (click)="connect()" [disabled]="connBusy()"><i class="ti ti-brand-facebook" aria-hidden="true"></i><i class="ti ti-brand-instagram" aria-hidden="true"></i> Collega Facebook e Instagram</button>
                }
              </div>
              @if (c.mode === 'sample') { <p class="muted small"><i class="ti ti-flask" aria-hidden="true"></i> Modalità di prova: il collegamento usa un account di esempio e non chiama Meta.</p> }
            } @else {
              <div class="meta-connect-row">
                <span><i class="ti ti-plug-connected" aria-hidden="true"></i> Collegato con <strong>{{ c.user_name }}</strong>@if (c.sample) { <span class="tag">Esempio</span> }<br />
                  @if (c.expired) { <small class="danger-text">Il collegamento è scaduto: ricollega Facebook per pubblicare e leggere i risultati.</small> }
                  @else if (c.error) { <small class="danger-text">{{ c.error }}</small> }
                  @else if (c.expires_at) { <small class="muted">Da rinnovare entro il {{ c.expires_at | date: 'd MMM y' }}</small> }</span>
                @if (auth.canEdit()) {
                  @if (c.expired || c.error) { <button class="btn primary small" (click)="connect()" [disabled]="connBusy()">Ricollega</button> }
                  <button class="btn ghost small" (click)="disconnect()" [disabled]="connBusy()">Scollega</button>
                }
              </div>
              @if (!c.expired) {
                <div class="form-grid">
                  <label>Account pubblicitario
                    @if (assets(); as a) {
                      <select [ngModel]="c.ad_account?.id ?? null" (ngModelChange)="choose({ ad_account_id: $event })" [disabled]="!auth.canEdit() || connBusy()">
                        @if (!c.ad_account) { <option [ngValue]="null" disabled>Scegli l'account</option> }
                        @for (acc of a.ad_accounts; track acc.id) {
                          <option [ngValue]="acc.id" [disabled]="!acc.active">{{ acc.name }} · {{ acc.currency }}@if (acc.business) { · {{ acc.business }}}@if (!acc.active) { (non attivo)}</option>
                        }
                      </select>
                    } @else { <input [value]="c.ad_account?.name ?? 'Non scelto'" disabled /> }
                  </label>
                  <label>Pagina Facebook
                    @if (assets(); as a) {
                      <select [ngModel]="c.page?.id ?? null" (ngModelChange)="choose({ page_id: $event })" [disabled]="!auth.canEdit() || connBusy()">
                        @if (!c.page) { <option [ngValue]="null" disabled>Scegli la pagina</option> }
                        @for (p of a.pages; track p.id) { <option [ngValue]="p.id">{{ p.name }}@if (p.instagram_username) { · Instagram &#64;{{ p.instagram_username }}}</option> }
                      </select>
                    } @else { <input [value]="c.page?.name ?? 'Non scelta'" disabled /> }
                  </label>
                </div>
                @if (auth.canEdit() && !assets()) {
                  <button class="link-btn small" (click)="loadAssets()" [disabled]="connBusy()">Cambia account o pagina</button>
                }
                <p class="muted small">
                  @if (c.ready) {
                    <i class="ti ti-circle-check" aria-hidden="true"></i> Pronto: le sponsorizzate escono su Facebook
                    @if (c.instagram) { e su Instagram (&#64;{{ c.instagram.username }})} @else { (la pagina non ha un Instagram professionale collegato: escono solo su Facebook)}.
                  } @else { Scegli account pubblicitario e pagina per pubblicare. }
                </p>
                @if (assets()?.pages?.length === 0) {
                  <p class="hint small"><i class="ti ti-alert-triangle" aria-hidden="true"></i> Facebook non ha condiviso nessuna pagina: le inserzioni escono a nome di una pagina.
                    Crea una pagina Facebook (o fatti dare l'accesso a quella del cliente), poi premi «Scollega», ricollega e, quando Facebook lo chiede, seleziona la pagina.</p>
                }
                @if (c.ad_account?.currency && c.ad_account!.currency!.toUpperCase() !== 'EUR') {
                  <p class="hint small"><i class="ti ti-alert-triangle" aria-hidden="true"></i> L'account è in {{ c.ad_account!.currency }}, ma i budget dell'app sono in euro: per pubblicare scegli un account pubblicitario in EUR.</p>
                }
              }
            }
          </div>
        } @else {
          <p class="muted small"><i class="ti ti-info-circle" aria-hidden="true"></i> Per pubblicare le sponsorizzate da qui va configurata l'app Meta (META_APP_ID e META_APP_SECRET). Intanto segui la guida qui sotto.</p>
        }
      }

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
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  readonly billingUrl = BILLING;

  conn = signal<MetaConnection | null>(null);
  assets = signal<MetaAssets | null>(null);
  connBusy = signal(false);
  notice = signal<{ text: string; warning: boolean } | null>(null);

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
    this.api.metaConnection(this.brandId).subscribe({ next: (c) => this.applyConnection(c), error: (err) => this.error.set(apiErrorMessage(err)) });
    // Ritorno da Facebook: l'esito arriva nell'indirizzo e poi si toglie, così ricaricando non ricompare.
    const params = this.route.snapshot.queryParamMap;
    const result = params.get('meta');
    if (result) {
      this.notice.set(result === 'connected'
        ? { text: 'Facebook collegato. Controlla account pubblicitario e pagina.', warning: false }
        : { text: params.get('meta_message') || 'Collegamento a Facebook non riuscito.', warning: true });
      this.router.navigate([], { queryParams: { meta: null, meta_message: null }, queryParamsHandling: 'merge', replaceUrl: true });
    }
  }

  /** Apre l'accesso a Facebook nella stessa finestra; con i dati di esempio il collegamento è immediato. */
  connect(): void {
    this.connBusy.set(true);
    this.error.set(null);
    this.api.connectMeta(this.brandId).subscribe({
      next: (r) => {
        if (r.url) {
          window.location.href = r.url;
          return;
        }
        this.connBusy.set(false);
        if (r.connection) this.applyConnection(r.connection);
      },
      error: (err) => { this.connBusy.set(false); this.error.set(apiErrorMessage(err)); },
    });
  }

  disconnect(): void {
    if (!confirm('Scollegare Facebook per questo cliente? Le sponsorizzate già pubblicate restano su Meta, ma da qui non si potranno più gestire.')) return;
    this.connBusy.set(true);
    this.api.disconnectMeta(this.brandId).subscribe({
      next: (c) => { this.connBusy.set(false); this.assets.set(null); this.applyConnection(c); },
      error: (err) => { this.connBusy.set(false); this.error.set(apiErrorMessage(err)); },
    });
  }

  loadAssets(): void {
    this.connBusy.set(true);
    this.api.metaAssets(this.brandId).subscribe({
      next: (a) => { this.connBusy.set(false); this.assets.set(a); },
      error: (err) => { this.connBusy.set(false); this.error.set(apiErrorMessage(err)); },
    });
  }

  choose(data: { ad_account_id?: string; page_id?: string }): void {
    this.connBusy.set(true);
    this.error.set(null);
    this.api.updateMetaConnection(this.brandId, data).subscribe({
      next: (c) => {
        this.connBusy.set(false);
        this.conn.set(c);
        // L'account scelto diventa anche quello della guida qui sotto.
        if (data.ad_account_id) this.api.adsSetup(this.brandId).subscribe({ next: (s) => this.apply(s) });
      },
      error: (err) => { this.connBusy.set(false); this.error.set(apiErrorMessage(err)); },
    });
  }

  /** Appena collegato si mostrano subito account e pagine, se manca ancora una scelta. */
  private applyConnection(c: MetaConnection): void {
    this.conn.set(c);
    if (c.connected && !c.expired && !c.ready && !this.assets()) this.loadAssets();
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
