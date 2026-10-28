import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import { ClientReport, ReportStatus, apiErrorMessage } from '../../core/models';
import { AssetPreviewComponent } from '../../shared/asset-preview.component';
import { ReportViewComponent } from '../../shared/report-view.component';

const MONTHS = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

/** Report mensili: l'agenzia scrive il commento, scarica il PDF o manda al cliente un link che si apre senza account. */
@Component({
  selector: 'app-reports',
  standalone: true,
  imports: [AssetPreviewComponent, DatePipe, FormsModule, ReportViewComponent],
  template: `
    <div class="page-head no-print">
      <div>
        <h1>Report clienti</h1>
        <p class="muted">Un riepilogo del mese da mandare a ogni cliente: post, clic, sponsorizzate e il tuo commento.</p>
      </div>
      <div class="head-controls">
        <select [(ngModel)]="period" (ngModelChange)="changePeriod()" aria-label="Mese del report">
          @for (m of months; track m.value) { <option [ngValue]="m.value">{{ m.label }}</option> }
        </select>
      </div>
    </div>

    @if (error()) { <div class="alert no-print">{{ error() }}</div> }

    <div class="reports-layout">
      <nav class="report-clients no-print" aria-label="Clienti">
        @for (c of clients(); track c.id) {
          <button class="report-client" [class.active]="c.id === brandId()" (click)="select(c.id)">
            <span class="client-logo small-logo" [style.background]="c.logo_asset_id ? '#fff' : c.primary_color || 'var(--primary)'">
              @if (c.logo_asset_id) { <app-asset-preview [assetId]="c.logo_asset_id" fit="contain" [alt]="c.name" /> } @else { {{ c.name.charAt(0) }} }
            </span>
            <span class="grow">
              <strong class="ellipsis">{{ c.name }}</strong>
              <small [class]="'report-state ' + state(c).kind"><i class="ti" [class]="'ti ' + state(c).icon" aria-hidden="true"></i>{{ state(c).label }}</small>
            </span>
          </button>
        } @empty {
          <p class="muted small">{{ loadingList() ? 'Caricamento…' : 'Nessun cliente.' }}</p>
        }
      </nav>

      <div class="report-main">
        @if (report(); as r) {
          <section class="card report-tools no-print">
            @if (auth.canEdit()) {
              <div class="report-notes">
                <label>Com'è andato il mese<textarea [(ngModel)]="summary" rows="4" maxlength="5000" placeholder="Es. Il post sul nuovo menù ha portato il doppio dei clic: lo riproponiamo come sponsorizzata."></textarea></label>
                <label>Il prossimo mese<textarea [(ngModel)]="nextSteps" rows="4" maxlength="5000" placeholder="Es. Campagna per San Valentino dal 1° febbraio, budget 300 €."></textarea></label>
              </div>
            }
            <div class="report-actions">
              @if (auth.canEdit()) {
                <button class="btn" (click)="save()" [disabled]="busy() || !dirty()"><i class="ti ti-device-floppy" aria-hidden="true"></i> {{ saved() && !dirty() ? 'Salvato' : 'Salva commento' }}</button>
              }
              <button class="btn" (click)="print()"><i class="ti ti-file-type-pdf" aria-hidden="true"></i> Scarica PDF</button>
              <span class="grow"></span>
              @if (r.share?.token) {
                <div class="share-box">
                  <code class="ellipsis" [title]="shareUrl()">{{ shareUrl() }}</code>
                  <button class="btn primary" (click)="copy()"><i class="ti" [class]="copied() ? 'ti ti-check' : 'ti ti-copy'" aria-hidden="true"></i> {{ copied() ? 'Copiato' : 'Copia link' }}</button>
                  <a class="btn ghost" [href]="shareUrl()" target="_blank" rel="noopener" title="Apri come lo vede il cliente" aria-label="Apri come lo vede il cliente"><i class="ti ti-external-link" aria-hidden="true"></i></a>
                </div>
              } @else if (auth.canEdit()) {
                <button class="btn primary" (click)="share()" [disabled]="busy()"><i class="ti ti-share" aria-hidden="true"></i> Condividi con il cliente</button>
              }
            </div>
            @if (r.share?.token) {
              <p class="muted small share-info">
                <i class="ti ti-eye" aria-hidden="true"></i>
                @if (r.share!.views) {
                  Aperto {{ r.share!.views === 1 ? 'una volta' : r.share!.views + ' volte' }}, l'ultima il {{ r.share!.last_viewed_at!.replace(' ', 'T') | date: 'd MMM, HH:mm' }}.
                } @else {
                  Link creato il {{ r.share!.shared_at!.replace(' ', 'T') | date: 'd MMM' }}: il cliente non l'ha ancora aperto.
                }
                Chi ha il link vede solo questo report, senza accedere.
                @if (auth.canEdit()) { <button class="link-btn" (click)="unshare()" [disabled]="busy()">Disattiva il link</button> }
              </p>
            }
            @if (dirty() && r.share?.token) { <p class="muted small">Il cliente vede il commento solo dopo che lo salvi.</p> }
          </section>

          <app-report-view [report]="r" />
        } @else if (loading()) {
          <div class="card muted center">Preparazione del report…</div>
        } @else if (!clients().length && !loadingList()) {
          <div class="card empty"><i class="ti ti-report-analytics empty-icon" aria-hidden="true"></i><strong>Ancora nessun cliente</strong><p class="muted">Aggiungi un cliente per preparare il suo report.</p></div>
        }
      </div>
    </div>
  `,
})
export class ReportsComponent implements OnInit {
  readonly auth = inject(AuthService);
  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);

  readonly months = lastMonths(13);
  period = defaultPeriod();
  clients = signal<ReportStatus[]>([]);
  brandId = signal<number | null>(null);
  report = signal<ClientReport | null>(null);
  loading = signal(false);
  loadingList = signal(true);
  busy = signal(false);
  saved = signal(false);
  copied = signal(false);
  error = signal<string | null>(null);
  summary = '';
  nextSteps = '';

  readonly shareUrl = computed(() => {
    const token = this.report()?.share?.token;
    return token ? `${location.origin}/report/${token}` : '';
  });
  /** Un metodo e non un computed: summary e nextSteps sono legati con ngModel, non sono signal. */
  dirty(): boolean {
    const r = this.report();
    return !!r && (this.summary.trim() !== (r.summary ?? '') || this.nextSteps.trim() !== (r.next_steps ?? ''));
  }

  ngOnInit(): void {
    const params = this.route.snapshot.queryParamMap;
    const period = params.get('period');
    if (period && this.months.some((m) => m.value === period)) this.period = period;
    const client = Number(params.get('client'));
    if (client) this.brandId.set(client);
    this.loadList();
  }

  loadList(): void {
    this.loadingList.set(true);
    this.api.reports(this.period).subscribe({
      next: (res) => {
        this.clients.set(res.clients);
        this.loadingList.set(false);
        const current = this.brandId();
        const id = res.clients.some((c) => c.id === current) ? current : res.clients[0]?.id ?? null;
        if (id !== null) this.select(id);
      },
      error: (err) => {
        this.error.set(apiErrorMessage(err));
        this.loadingList.set(false);
      },
    });
  }

  changePeriod(): void {
    this.report.set(null);
    this.loadList();
  }

  select(id: number): void {
    this.brandId.set(id);
    this.copied.set(false);
    this.saved.set(false);
    this.router.navigate([], { queryParams: { client: id, period: this.period }, replaceUrl: true });
    this.loading.set(true);
    this.report.set(null);
    this.api.report(id, this.period).subscribe({
      next: (r) => this.show(r),
      error: (err) => {
        this.error.set(apiErrorMessage(err));
        this.loading.set(false);
      },
    });
  }

  save(): void {
    const id = this.brandId();
    if (!id) return;
    this.run(this.api.updateReport(id, { period: this.period, summary: this.summary.trim() || null, next_steps: this.nextSteps.trim() || null }), () => this.saved.set(true));
  }

  share(): void {
    const id = this.brandId();
    if (id) this.run(this.api.shareReport(id, this.period));
  }

  unshare(): void {
    const id = this.brandId();
    if (!id || !confirm('Disattivare il link? Chi lo ha ricevuto non vedrà più il report. Se lo condividi di nuovo, il link sarà diverso.')) return;
    this.run(this.api.unshareReport(id, this.period));
  }

  copy(): void {
    navigator.clipboard?.writeText(this.shareUrl()).then(() => {
      this.copied.set(true);
      setTimeout(() => this.copied.set(false), 2000);
    });
  }

  /** "Salva come PDF" nella finestra di stampa del browser: gratis e senza librerie. */
  print(): void {
    window.print();
  }

  state(c: ReportStatus): { kind: string; icon: string; label: string } {
    if (c.shared && c.views) return { kind: 'seen', icon: 'ti-eye-check', label: `Aperto dal cliente${c.views > 1 ? ` (${c.views} volte)` : ''}` };
    if (c.shared) return { kind: 'sent', icon: 'ti-send', label: 'Condiviso, non ancora aperto' };
    if (c.has_summary) return { kind: 'draft', icon: 'ti-pencil', label: 'Commento scritto' };
    return { kind: 'todo', icon: 'ti-circle-dashed', label: 'Da preparare' };
  }

  private run(request: ReturnType<ApiService['report']>, done?: () => void): void {
    this.busy.set(true);
    this.error.set(null);
    request.subscribe({
      next: (r) => {
        this.show(r);
        this.busy.set(false);
        done?.();
        this.refreshStatus(r);
      },
      error: (err) => {
        this.error.set(apiErrorMessage(err));
        this.busy.set(false);
      },
    });
  }

  private show(r: ClientReport): void {
    this.report.set(r);
    this.summary = r.summary ?? '';
    this.nextSteps = r.next_steps ?? '';
    this.loading.set(false);
  }

  /** Aggiorna lo stato nella lista senza ricaricarla. */
  private refreshStatus(r: ClientReport): void {
    this.clients.update((list) => list.map((c) => c.id !== r.client.id ? c : {
      ...c, has_summary: !!r.summary, shared: !!r.share?.token, views: r.share?.views ?? 0, last_viewed_at: r.share?.last_viewed_at ?? null,
    }));
  }
}

function key(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

/** Nei primi giorni del mese si prepara il report di quello appena finito. */
function defaultPeriod(): string {
  const now = new Date();
  return key(now.getDate() <= 10 ? new Date(now.getFullYear(), now.getMonth() - 1, 1) : now);
}

function lastMonths(count: number): { value: string; label: string }[] {
  const now = new Date();
  return Array.from({ length: count }, (_, i) => {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
    const label = `${MONTHS[d.getMonth()]} ${d.getFullYear()}`;
    return { value: key(d), label: label.charAt(0).toUpperCase() + label.slice(1) };
  });
}
