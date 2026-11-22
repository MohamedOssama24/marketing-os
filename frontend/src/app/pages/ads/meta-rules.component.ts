import { Component, OnDestroy, OnInit, computed, inject, signal } from '@angular/core';
import { CurrencyPipe, DatePipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { Subscription, switchMap, takeWhile, timer } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import { MetaRulebook, RuleSeverity, apiErrorMessage } from '../../core/models';

const GROUPS: { severity: RuleSeverity; title: string; icon: string; intro: string }[] = [
  { severity: 'block', title: 'Vietato', icon: 'ti-circle-x', intro: 'Meta respinge l\'inserzione o la legge italiana la vieta.' },
  { severity: 'warn', title: 'Si può, con dei limiti', icon: 'ti-alert-triangle', intro: 'Serve un pubblico maggiorenne, una categoria dichiarata o un\'autorizzazione.' },
  { severity: 'tip', title: 'Consigli', icon: 'ti-bulb', intro: 'Non bloccano l\'inserzione ma fanno spendere meno.' },
];

/**
 * Le regole di Meta per le sponsorizzate in parole semplici, con il monitor delle pagine ufficiali:
 * ogni giorno si controlla se Meta le ha cambiate e qui si legge cosa è cambiato.
 */
@Component({
  selector: 'app-meta-rules',
  standalone: true,
  imports: [CurrencyPipe, DatePipe, RouterLink],
  template: `
    <a routerLink="/ads" class="back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Sponsorizzate</a>
    <div class="page-head">
      <div>
        <h1><i class="ti ti-brand-meta" aria-hidden="true"></i> Regole Meta</h1>
        <p class="muted">Cosa si può sponsorizzare su Facebook e Instagram, spiegato semplice. Ogni giorno controlliamo le pagine ufficiali di Meta e ti avvisiamo se cambiano.</p>
      </div>
    </div>

    @if (error()) { <div class="alert">{{ error() }}</div> }

    @if (book(); as b) {
      <section class="card monitor-card">
        <div class="monitor-status">
          <span class="metric-icon" [class.alert-icon]="b.monitor.unread > 0"><i class="ti" [class]="b.monitor.unread ? 'ti ti-bell-ringing' : 'ti ti-shield-check'" aria-hidden="true"></i></span>
          <div class="grow">
            <strong>{{ b.monitor.unread ? (b.monitor.unread === 1 ? 'Meta ha cambiato una pagina di regole' : 'Meta ha cambiato ' + b.monitor.unread + ' pagine di regole') : 'Nessuna novità da leggere' }}</strong>
            <p class="muted small">{{ b.monitor.sources.length }} pagine ufficiali controllate ogni giorno
              @if (b.monitor.last_check) { · ultimo controllo {{ b.monitor.last_check | date: 'd MMM, HH:mm' }} } @else { · primo controllo in corso }
              · regole riviste il {{ b.version | date: 'd MMMM yyyy' }}</p>
          </div>
          @if (b.monitor.unread) { <button class="btn primary small" (click)="markSeen()" [disabled]="busy()"><i class="ti ti-checks" aria-hidden="true"></i> Segna come letto</button> }
          @if (auth.canManage()) {
            <button class="btn ghost small" (click)="refresh()" [disabled]="busy() || refreshing()">
              @if (refreshing()) { <span class="spinner"></span> Controllo… } @else { <i class="ti ti-refresh" aria-hidden="true"></i> Controlla ora }
            </button>
          }
        </div>

        @for (c of b.monitor.changes; track c.id) {
          <article class="policy-change" [class.unread]="c.unread">
            <div class="policy-change-head">
              <strong>{{ c.title }}</strong>
              @if (c.summary) { <span class="badge" [attr.data-impact]="c.summary.impact">Impatto {{ c.summary.impact }}</span> }
              @if (c.unread) { <span class="tag ai">Nuovo</span> }
              <span class="muted small grow right">{{ c.detected_at | date: 'd MMM yyyy' }}</span>
            </div>
            @if (c.summary) {
              <p>{{ c.summary.summary }}</p>
              @if (c.summary.actions.length) { <ul class="small">@for (a of c.summary.actions; track $index) { <li>{{ a }}</li> }</ul> }
            }
            <details>
              <summary class="small">Frasi cambiate ({{ c.added.length }} aggiunte, {{ c.removed.length }} tolte)</summary>
              @for (l of c.added; track $index) { <p class="diff-line added">+ {{ l }}</p> }
              @for (l of c.removed; track $index) { <p class="diff-line removed">− {{ l }}</p> }
            </details>
            <a class="small" [href]="c.url" target="_blank" rel="noopener">Apri la pagina su Meta <i class="ti ti-external-link" aria-hidden="true"></i></a>
          </article>
        } @empty {
          <p class="muted small">Nessun cambiamento trovato da quando controlliamo. Se Meta modifica una regola, comparirà qui e tra gli avvisi in alto.</p>
        }
      </section>

      <div class="section-head"><h2>Prima di sponsorizzare</h2></div>
      <p class="muted small">Queste regole sono controllate in automatico in ogni sponsorizzata preparata con «Nuova sponsorizzata».</p>
      <div class="rule-groups">
        @for (g of groups; track g.severity) {
          <section class="card">
            <h3 class="rule-group-title" [attr.data-severity]="g.severity"><i class="ti" [class]="'ti ' + g.icon" aria-hidden="true"></i> {{ g.title }}</h3>
            <p class="muted small">{{ g.intro }}</p>
            @for (r of rulesBy()[g.severity]; track r.key) {
              <details class="rule-item">
                <summary>{{ r.title }}</summary>
                <p>{{ r.why }}</p>
                <p class="fix"><i class="ti ti-arrow-right" aria-hidden="true"></i> {{ r.fix }}</p>
                @if (r.source) { <a class="small" [href]="r.source" target="_blank" rel="noopener">Regola ufficiale</a> }
              </details>
            }
            @if (g.severity === 'tip') {
              <details class="rule-item"><summary>Testi</summary>
                <p>Testo principale fino a {{ b.text_limits.primary_text }} caratteri, titolo fino a {{ b.text_limits.headline }}, descrizione fino a {{ b.text_limits.description }}: oltre, Meta li taglia sul telefono.</p></details>
              <details class="rule-item"><summary>Budget</summary>
                <p>Minimo {{ b.budget.min_daily | currency: 'EUR' }} al giorno. Con almeno {{ b.budget.suggested_daily | currency: 'EUR' : 'symbol' : '1.0-0' }} al giorno per 7 giorni Meta finisce la fase di apprendimento e i risultati costano meno.</p></details>
            }
          </section>
        }
      </div>

      <div class="two-col">
        <section class="card">
          <h2>I 6 obiettivi di Meta</h2>
          <dl class="objective-list">
            @for (o of b.objectives; track o.key) {
              <div><dt><i class="ti" [class]="'ti ' + o.icon" aria-hidden="true"></i> {{ o.label }}</dt><dd>{{ o.when }}</dd></div>
            }
          </dl>
        </section>
        <section class="card">
          <h2>Categorie speciali</h2>
          <p class="muted small">Vanno dichiarate nella campagna: se mancano, Meta respinge l'inserzione.</p>
          <dl class="objective-list">
            @for (c of specialCategories(); track c.key) { <div><dt>{{ c.label }}</dt><dd>{{ c.detail }}</dd></div> }
          </dl>
        </section>
      </div>

      <section class="card table-card">
        <h2 class="table-title">Formati consigliati</h2>
        <table>
          <thead><tr><th>Dove</th><th>Proporzioni</th><th>Dimensioni</th><th>Da sapere</th></tr></thead>
          <tbody>@for (s of b.specs; track s.placement) { <tr><td><strong>{{ s.placement }}</strong></td><td>{{ s.ratio }}</td><td>{{ s.size }}</td><td class="muted">{{ s.note }}</td></tr> }</tbody>
        </table>
      </section>

      <section class="card table-card">
        <h2 class="table-title">Pagine ufficiali controllate</h2>
        <table>
          <thead><tr><th>Pagina</th><th>Ultimo controllo</th><th>Ultimo cambiamento</th></tr></thead>
          <tbody>
            @for (s of b.monitor.sources; track s.id) {
              <tr>
                <td><a [href]="s.url" target="_blank" rel="noopener">{{ s.title }}</a><br /><small class="muted">{{ s.topic }}</small></td>
                <td>@if (s.last_error) { <span class="error-text small">{{ s.last_error }}</span> } @else { {{ s.checked_at ? (s.checked_at | date: 'd MMM, HH:mm') : 'In attesa' }} }</td>
                <td class="muted">{{ s.changed_at ? (s.changed_at | date: 'd MMM yyyy') : '—' }}</td>
              </tr>
            }
          </tbody>
        </table>
      </section>
    } @else if (!error()) {
      <p class="muted">Caricamento…</p>
    }
  `,
})
export class MetaRulesComponent implements OnInit, OnDestroy {
  readonly auth = inject(AuthService);
  private api = inject(ApiService);
  readonly groups = GROUPS;

  book = signal<MetaRulebook | null>(null);
  busy = signal(false);
  refreshing = signal(false);
  error = signal<string | null>(null);
  private sub = new Subscription();

  rulesBy = computed(() => {
    const out: Record<RuleSeverity, MetaRulebook['rules']> = { block: [], warn: [], tip: [] };
    for (const r of this.book()?.rules ?? []) out[r.severity].push(r);
    return out;
  });
  specialCategories = computed(() => (this.book()?.special_categories ?? []).filter((c) => c.key !== 'none'));

  ngOnInit(): void {
    this.load();
  }

  ngOnDestroy(): void {
    this.sub.unsubscribe();
  }

  load(): void {
    this.api.metaRules().subscribe({ next: (b) => this.book.set(b), error: (e) => this.error.set(apiErrorMessage(e)) });
  }

  markSeen(): void {
    this.busy.set(true);
    this.api.markMetaRulesSeen().subscribe({
      next: (monitor) => { this.book.update((b) => (b ? { ...b, monitor } : b)); this.busy.set(false); },
      error: (e) => { this.error.set(apiErrorMessage(e)); this.busy.set(false); },
    });
  }

  /** Avvia il controllo delle pagine e aspetta che il lavoro finisca (circa mezzo minuto). */
  refresh(): void {
    this.refreshing.set(true);
    this.error.set(null);
    this.sub.add(this.api.refreshMetaRules().pipe(
      switchMap(({ job_id }) => timer(1000, 1500).pipe(switchMap(() => this.api.job(job_id)))),
      takeWhile((job) => job.status === 'queued' || job.status === 'running', true),
    ).subscribe({
      next: (job) => {
        if (job.status === 'failed') this.error.set(job.error ?? 'Controllo non riuscito.');
        if (job.status === 'done' || job.status === 'failed') { this.refreshing.set(false); this.load(); }
      },
      error: (e) => { this.error.set(apiErrorMessage(e)); this.refreshing.set(false); },
    }));
  }
}
