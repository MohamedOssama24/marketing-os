import { Component, OnInit, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import { SubscriptionInfo, SubscriptionPlan, apiErrorMessage } from '../../core/models';

/**
 * Abbonamento a Marketing OS: giorni di prova rimasti, scelta mensile/annuale con pagamento su Stripe,
 * gestione dell'abbonamento attivo. Si apre anche a prova scaduta (è l'unica pagina disponibile).
 */
@Component({
  selector: 'app-subscription',
  standalone: true,
  imports: [DatePipe, RouterLink],
  template: `
    <main class="sub-page">
      <header class="sub-top">
        <div class="brand"><span class="brand-mark"><i class="ti ti-sparkles" aria-hidden="true"></i></span>Marketing <span>OS</span></div>
        <div class="sub-top-actions">
          @if (info()?.has_access) {
            <a class="btn ghost small" routerLink="/dashboard"><i class="ti ti-arrow-left" aria-hidden="true"></i> Torna all'app</a>
          }
          <button class="btn ghost small" (click)="auth.logout()"><i class="ti ti-logout" aria-hidden="true"></i> Esci</button>
        </div>
      </header>

      <section class="sub-wrap">
        @if (notice(); as n) { <div class="notice" [class.warning]="n.warn"><i class="ti" [class]="'ti ' + n.icon" aria-hidden="true"></i>{{ n.text }}</div> }
        @if (error()) { <div class="alert">{{ error() }}</div> }

        @if (info(); as s) {
          <div class="sub-head">
            @switch (s.state) {
              @case ('trial') {
                <h1>Prova gratuita: {{ dayLabel(s.trial_days_left) }}</h1>
                <p class="muted">Usi Marketing OS senza limiti fino al {{ s.trial_ends_at | date: 'd MMMM yyyy' }}. Abbonati quando vuoi: i giorni di prova non si perdono.</p>
              }
              @case ('expired') {
                <h1>{{ s.plan === 'trial' ? 'La prova gratuita è terminata' : 'L\\'abbonamento non è attivo' }}</h1>
                <p class="muted">I tuoi clienti, le campagne e i contenuti sono al sicuro. Scegli un piano per continuare da dove eri rimasto.</p>
              }
              @case ('active') {
                <h1>Abbonamento {{ s.plan === 'yearly' ? 'annuale' : 'mensile' }} attivo</h1>
                <p class="muted">
                  @if (s.cancel_at_period_end) { Disdetto: resta attivo fino al {{ s.current_period_end | date: 'd MMMM yyyy' }}. }
                  @else { Prossimo rinnovo il {{ s.current_period_end | date: 'd MMMM yyyy' }}. }
                  @if (s.status === 'past_due') { <strong class="warn-text">L'ultimo pagamento non è andato a buon fine: aggiorna la carta.</strong> }
                </p>
              }
              @case ('free') {
                <h1>Accesso completo incluso</h1>
                <p class="muted">La tua organizzazione usa Marketing OS senza abbonamento.</p>
              }
            }
          </div>

          @if (s.state === 'active') {
            <div class="card sub-manage">
              <div class="grow">
                <strong>{{ price(s.plan === 'yearly' ? s.prices.yearly : s.prices.monthly) }} {{ s.plan === 'yearly' ? 'all\\'anno' : 'al mese' }}</strong>
                <p class="muted small">Cambia carta, scarica le fatture, passa da mensile ad annuale o disdici.</p>
              </div>
              @if (auth.canManage()) {
                <button class="btn primary" (click)="portal()" [disabled]="busy()"><i class="ti ti-settings" aria-hidden="true"></i> Gestisci abbonamento</button>
              }
            </div>
          } @else if (s.state !== 'free') {
            <div class="plans">
              @for (p of plans; track p.id) {
                <article class="card plan" [class.featured]="p.id === 'yearly'">
                  @if (p.id === 'yearly') { <span class="plan-flag">Risparmi {{ price(s.prices.monthly * 12 - s.prices.yearly) }}</span> }
                  <h2>{{ p.title }}</h2>
                  <p class="plan-price"><strong>{{ price(p.id === 'yearly' ? s.prices.yearly : s.prices.monthly) }}</strong><span class="muted">/{{ p.id === 'yearly' ? 'anno' : 'mese' }}</span></p>
                  <p class="muted small">{{ p.id === 'yearly' ? 'Pari a ' + price(s.prices.yearly / 12) + ' al mese, un solo pagamento.' : 'Paghi mese per mese, disdici quando vuoi.' }}</p>
                  <ul class="plan-list">
                    <li><i class="ti ti-check" aria-hidden="true"></i> Clienti e campagne illimitati</li>
                    <li><i class="ti ti-check" aria-hidden="true"></i> Contenuti e video con l'AI</li>
                    <li><i class="ti ti-check" aria-hidden="true"></i> Sponsorizzate su Facebook e Instagram</li>
                    <li><i class="ti ti-check" aria-hidden="true"></i> Report e portale per i clienti</li>
                  </ul>
                  @if (auth.canManage()) {
                    <button class="btn" [class.primary]="p.id === 'yearly'" (click)="checkout(p.id)" [disabled]="busy() || !s.payments_enabled">
                      {{ busy() === p.id ? 'Apertura pagamento…' : 'Abbonati ' + (p.id === 'yearly' ? 'per un anno' : 'mensilmente') }}
                    </button>
                  }
                </article>
              }
            </div>
            @if (!auth.canManage()) {
              <p class="hint">Solo il titolare o un amministratore della tua organizzazione può attivare l'abbonamento.</p>
            } @else if (!s.payments_enabled) {
              <p class="hint">I pagamenti online non sono ancora attivi. Contatta l'assistenza per attivare l'abbonamento.</p>
            } @else {
              <p class="muted small center"><i class="ti ti-lock" aria-hidden="true"></i> Pagamento sicuro con Stripe (carta, Apple Pay, Google Pay).</p>
            }
          }
        } @else if (!error()) {
          <p class="muted center">Caricamento…</p>
        }
      </section>
    </main>
  `,
  styles: [`
    .sub-page { min-height: 100vh; background: radial-gradient(circle at 20% 0%, var(--analyzer-from) 0, transparent 45%), var(--bg); }
    .sub-top { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 16px 24px; flex-wrap: wrap; }
    .sub-top .brand { display: flex; align-items: center; gap: 8px; font-weight: 700; }
    .sub-top-actions { display: flex; gap: 8px; }
    .sub-wrap { max-width: 860px; margin: 0 auto; padding: 16px 16px 48px; }
    .sub-head { text-align: center; margin: 12px 0 28px; }
    .sub-head h1 { margin-bottom: 8px; }
    .plans { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 18px; margin-bottom: 18px; }
    .plan { position: relative; display: flex; flex-direction: column; gap: 10px; }
    .plan.featured { border-color: var(--primary); box-shadow: 0 0 0 1px var(--primary), var(--shadow); }
    .plan-flag { position: absolute; top: -11px; right: 16px; background: var(--primary); color: #fff; font-size: 0.78rem; font-weight: 600; padding: 3px 10px; border-radius: 999px; }
    .plan h2 { font-size: 1.05rem; margin: 0; }
    .plan-price { margin: 0; } .plan-price strong { font-size: 2rem; }
    .plan-list { list-style: none; padding: 0; margin: 4px 0 8px; display: flex; flex-direction: column; gap: 6px; font-size: 0.92rem; flex: 1; }
    .plan-list i { color: var(--success); margin-right: 6px; }
    .sub-manage { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
    .grow { flex: 1; min-width: 200px; }
    .warn-text { color: var(--warning); display: block; margin-top: 6px; }
    .center { text-align: center; }
  `],
})
export class SubscriptionComponent implements OnInit {
  readonly auth = inject(AuthService);
  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);

  info = signal<SubscriptionInfo | null>(null);
  error = signal<string | null>(null);
  busy = signal<string | null>(null);
  notice = signal<{ text: string; icon: string; warn?: boolean } | null>(null);

  readonly plans: { id: SubscriptionPlan; title: string }[] = [
    { id: 'monthly', title: 'Mensile' },
    { id: 'yearly', title: 'Annuale' },
  ];
  private readonly euro = new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' });

  ngOnInit(): void {
    const q = this.route.snapshot.queryParamMap;
    const sessionId = q.get('session_id');
    if (q.get('esito') === 'ok' && sessionId) {
      // Ritorno da Stripe dopo il pagamento: si conferma subito, senza aspettare la notifica di Stripe.
      this.api.subscriptionConfirm(sessionId).subscribe({
        next: (s) => {
          this.info.set(s);
          this.notice.set(s.has_access
            ? { text: 'Pagamento completato: grazie! L\'abbonamento è attivo.', icon: 'ti-circle-check' }
            : { text: 'Pagamento ricevuto, attivazione in corso: ricarica la pagina tra qualche secondo.', icon: 'ti-clock', warn: true });
          this.auth.reloadMe().subscribe();
          this.router.navigate([], { queryParams: {}, replaceUrl: true });
        },
        error: (err) => { this.error.set(apiErrorMessage(err)); this.load(); },
      });
      return;
    }
    if (q.get('esito') === 'annullato') this.notice.set({ text: 'Pagamento annullato: nessun addebito.', icon: 'ti-info-circle', warn: true });
    this.load();
  }

  checkout(plan: SubscriptionPlan): void {
    this.busy.set(plan);
    this.error.set(null);
    this.api.subscriptionCheckout(plan).subscribe({
      next: (r) => (window.location.href = r.url),
      error: (err) => { this.error.set(apiErrorMessage(err)); this.busy.set(null); },
    });
  }

  portal(): void {
    this.busy.set('portal');
    this.error.set(null);
    this.api.subscriptionPortal().subscribe({
      next: (r) => (window.location.href = r.url),
      error: (err) => { this.error.set(apiErrorMessage(err)); this.busy.set(null); },
    });
  }

  price(cents: number): string {
    return this.euro.format(cents / 100).replace(',00', '');
  }

  dayLabel(days: number | null): string {
    if (days === null) return '';
    return days <= 1 ? 'ultimo giorno' : `mancano ${days} giorni`;
  }

  private load(): void {
    this.api.subscription().subscribe({
      next: (s) => this.info.set(s),
      error: (err) => this.error.set(apiErrorMessage(err)),
    });
  }
}
