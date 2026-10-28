import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { DatePipe, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { forkJoin } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import { Brand, CHANNEL_LABELS, CLICK_SOURCE_LABELS, LinkSummary, TrackedLink, apiErrorMessage } from '../../core/models';

const DEVICE_LABELS: Record<string, string | undefined> = { mobile: 'Telefono', tablet: 'Tablet', desktop: 'Computer' };

/** Link tracciati: quante persone hanno cliccato sui post, da dove e da quale dispositivo. */
@Component({
  selector: 'app-links',
  standalone: true,
  imports: [DatePipe, DecimalPipe, FormsModule],
  template: `
    <div class="page-head">
      <div>
        <h1>Link e clic</h1>
        <p class="muted">Quante persone hanno cliccato sui link dei post, da quale social e da quale dispositivo.</p>
      </div>
      <div class="head-controls">
        <select [(ngModel)]="brandId" (ngModelChange)="selected.set(null); load()" aria-label="Filtra per cliente">
          <option [ngValue]="null">Tutti i clienti</option>
          @for (b of brands(); track b.id) { <option [ngValue]="b.id">{{ b.name }}</option> }
        </select>
        <select [(ngModel)]="days" (ngModelChange)="loadSummary()" aria-label="Periodo">
          <option [ngValue]="7">Ultimi 7 giorni</option>
          <option [ngValue]="30">Ultimi 30 giorni</option>
          <option [ngValue]="90">Ultimi 90 giorni</option>
        </select>
        @if (auth.canEdit()) {
          <button class="btn primary" (click)="creating.set(true); formError.set(null)"><i class="ti ti-link-plus" aria-hidden="true"></i> Nuovo link</button>
        }
      </div>
    </div>

    @if (error()) { <div class="alert">{{ error() }}</div> }

    <div class="hint links-hint"><i class="ti ti-info-circle" aria-hidden="true"></i><span>
      Nel pannello <strong>Pubblica</strong> di ogni post trovi "Link per contare i clic": crea il link breve e mettilo nel testo, nelle storie o nella bio.
      Le anteprime automatiche dei social non vengono contate e non si salva nessun dato personale dei visitatori.</span></div>

    @if (summary(); as s) {
      <div class="metrics">
        <div class="metric"><span class="metric-icon"><i class="ti ti-click" aria-hidden="true"></i></span><span class="metric-label">Clic · ultimi {{ s.days }} giorni</span><strong>{{ s.clicks | number }}</strong></div>
        <div class="metric"><span class="metric-icon"><i class="ti ti-users" aria-hidden="true"></i></span><span class="metric-label">Persone diverse al giorno</span><strong>{{ s.unique_clicks | number }}</strong></div>
        <div class="metric"><span class="metric-icon"><i class="ti ti-share" aria-hidden="true"></i></span><span class="metric-label">Provenienza principale</span><strong>{{ s.sources[0] ? sourceLabel(s.sources[0].name) : '—' }}</strong></div>
        <div class="metric"><span class="metric-icon"><i class="ti ti-device-mobile" aria-hidden="true"></i></span><span class="metric-label">Da telefono</span><strong>{{ mobileShare() === null ? '—' : mobileShare() + '%' }}</strong></div>
      </div>

      <div class="two-col ad-charts">
        <section class="card">
          <div class="chart-head">
            <h2>Clic al giorno</h2>
            @if (selectedLink(); as l) { <button class="chip active" (click)="select(null)">{{ l.label }} <i class="ti ti-x" aria-hidden="true"></i></button> }
          </div>
          @if (s.clicks > 0) {
            <div class="bar-chart" [style.grid-template-columns]="'repeat(' + s.daily.length + ', minmax(0, 1fr))'" role="img" [attr.aria-label]="'Clic al giorno negli ultimi ' + s.days + ' giorni'">
              @for (d of s.daily; track d.day; let i = $index) {
                <div class="bar-col" tabindex="0">
                  <div class="bar-track">
                    @if (d.day === peakDay()) { <span class="bar-value">{{ d.clicks }}</span> }
                    <div class="bar" [style.height.%]="barHeight(d.clicks)"></div>
                  </div>
                  <span class="bar-label">{{ i % labelEvery() === 0 ? (d.day | date: 'd/M') : '' }}</span>
                  <div class="bar-tip"><strong>{{ d.day | date: 'EEE d MMM' }}</strong>{{ d.clicks }} clic<span>{{ d.unique_clicks }} persone</span></div>
                </div>
              }
            </div>
          } @else {
            <p class="empty-chart muted">Nessun clic negli ultimi {{ s.days }} giorni.</p>
          }
        </section>

        <section class="card">
          <h2>Da dove arrivano</h2>
          <div class="hbar-list">
            @for (src of s.sources; track src.name) {
              <div class="hbar static">
                <span class="hbar-head"><strong>{{ sourceLabel(src.name) }}</strong><span class="hbar-value">{{ src.clicks | number }}</span></span>
                <span class="hbar-track"><span class="hbar-fill" [style.width.%]="(src.clicks / s.clicks) * 100"></span></span>
              </div>
            } @empty {
              <p class="empty-chart muted">Ancora nessun clic.</p>
            }
          </div>
          @if (s.devices.length) {
            <p class="muted small devices">@for (d of s.devices; track d.name; let last = $last) { {{ deviceLabels[d.name] ?? d.name }} {{ d.clicks }}@if (!last) { · }}</p>
          }
        </section>
      </div>
    }

    <div class="section-head"><h2>Link</h2></div>
    <div class="card table-card">
      <table class="admin-table">
        <thead><tr><th>Link</th><th>Destinazione</th><th class="num">Clic</th><th class="num">Persone</th><th>Ultimo clic</th><th></th></tr></thead>
        <tbody>
          @for (l of links(); track l.id) {
            <tr class="clickable" tabindex="0" [class.selected]="selected() === l.id" (click)="select(l.id)" (keydown.enter)="select(l.id)">
              <td>
                @if (l.brand_color) { <span class="swatch small" [style.background]="l.brand_color"></span> }<strong>{{ l.label }}</strong><br />
                <small class="muted">{{ l.brand_name ?? 'Senza cliente' }}@if (l.content_channel) { · {{ channelLabels[l.content_channel] }} }</small>
              </td>
              <td class="link-cell"><code>{{ shortLabel(l.short_url) }}</code><br /><small class="muted ellipsis" [title]="l.destination_url">{{ l.target_url }}</small></td>
              <td class="num"><strong>{{ l.clicks | number }}</strong></td>
              <td class="num">{{ l.unique_clicks | number }}</td>
              <td class="muted">{{ l.last_click_at ? (l.last_click_at.replace(' ', 'T') | date: 'd MMM, HH:mm') : 'Mai' }}</td>
              <td class="row-actions" (click)="$event.stopPropagation()">
                <button class="icon-btn" (click)="copy(l)" [title]="copied() === l.id ? 'Copiato' : 'Copia link'" aria-label="Copia link">
                  <i class="ti" [class]="copied() === l.id ? 'ti ti-check' : 'ti ti-copy'" aria-hidden="true"></i></button>
                @if (l.content_item_id) {
                  <button class="icon-btn" (click)="openPost(l)" title="Apri la campagna del post" aria-label="Apri la campagna del post"><i class="ti ti-external-link" aria-hidden="true"></i></button>
                }
                @if (auth.canEdit()) {
                  <button class="icon-btn" (click)="remove(l)" title="Elimina" aria-label="Elimina"><i class="ti ti-trash" aria-hidden="true"></i></button>
                }
              </td>
            </tr>
          } @empty {
            <tr><td colspan="6" class="muted center">{{ loading() ? 'Caricamento…' : 'Nessun link. Crealo dal pannello Pubblica di un post o con "Nuovo link".' }}</td></tr>
          }
        </tbody>
      </table>
    </div>

    @if (creating()) {
      <div class="modal-backdrop" (click)="creating.set(false)">
        <form class="modal narrow" (click)="$event.stopPropagation()" (ngSubmit)="create()">
          <div class="modal-head"><h2><i class="ti ti-link-plus" aria-hidden="true"></i> Nuovo link</h2><span class="muted">Per la bio, una storia o un volantino</span>
            <button type="button" class="icon-btn" (click)="creating.set(false)" aria-label="Chiudi"><i class="ti ti-x" aria-hidden="true"></i></button></div>
          <div class="modal-body form-grid">
            <label class="full">Pagina di destinazione<input [(ngModel)]="newTarget" name="target" required placeholder="https://sito-del-cliente.it/offerta" /></label>
            <label>Nome<input [(ngModel)]="newLabel" name="label" maxlength="160" placeholder="Link in bio Instagram" /></label>
            <label>Cliente
              <select [(ngModel)]="newBrand" name="brand" (ngModelChange)="prefillTarget()">
                <option [ngValue]="null">Nessuno</option>
                @for (b of brands(); track b.id) { <option [ngValue]="b.id">{{ b.name }}</option> }
              </select></label>
          </div>
          @if (formError()) { <div class="alert modal-alert">{{ formError() }}</div> }
          <div class="modal-foot"><span class="grow"></span>
            <button type="button" class="btn ghost" (click)="creating.set(false)">Annulla</button>
            <button class="btn primary" [disabled]="busy() || !newTarget.trim()">Crea link</button></div>
        </form>
      </div>
    }
  `,
})
export class LinksComponent implements OnInit {
  readonly auth = inject(AuthService);
  private api = inject(ApiService);
  private router = inject(Router);

  readonly channelLabels = CHANNEL_LABELS;
  readonly deviceLabels = DEVICE_LABELS;
  brands = signal<Brand[]>([]);
  links = signal<TrackedLink[]>([]);
  summary = signal<LinkSummary | null>(null);
  selected = signal<number | null>(null);
  loading = signal(true);
  busy = signal(false);
  creating = signal(false);
  copied = signal<number | null>(null);
  error = signal<string | null>(null);
  formError = signal<string | null>(null);
  brandId: number | null = null;
  days = 30;
  newTarget = '';
  newLabel = '';
  newBrand: number | null = null;

  readonly selectedLink = computed(() => this.links().find((l) => l.id === this.selected()) ?? null);
  readonly maxDay = computed(() => Math.max(0, ...(this.summary()?.daily.map((d) => d.clicks) ?? [])));
  readonly peakDay = computed(() => this.summary()?.daily.find((d) => d.clicks === this.maxDay() && d.clicks > 0)?.day ?? null);
  /** Con molti giorni si etichetta una barra ogni tanto, perché le date non si sovrappongano. */
  readonly labelEvery = computed(() => Math.max(1, Math.ceil((this.summary()?.daily.length ?? 7) / 8)));
  readonly mobileShare = computed(() => {
    const s = this.summary();
    if (!s?.clicks) return null;
    const mobile = s.devices.filter((d) => d.name !== 'desktop').reduce((sum, d) => sum + d.clicks, 0);
    return Math.round((mobile / s.clicks) * 100);
  });

  ngOnInit(): void {
    this.api.brands().subscribe({ next: (b) => this.brands.set(b), error: (err) => this.error.set(apiErrorMessage(err)) });
    this.load();
  }

  load(): void {
    this.loading.set(true);
    forkJoin([this.api.links({ brandId: this.brandId }), this.api.linkSummary(this.days, { brandId: this.brandId, linkId: this.selected() })]).subscribe({
      next: ([links, summary]) => {
        this.links.set(links);
        this.summary.set(summary);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(apiErrorMessage(err));
        this.loading.set(false);
      },
    });
  }

  loadSummary(): void {
    this.api.linkSummary(this.days, { brandId: this.brandId, linkId: this.selected() }).subscribe({
      next: (s) => this.summary.set(s),
      error: (err) => this.error.set(apiErrorMessage(err)),
    });
  }

  /** Clic su una riga: i grafici mostrano solo quel link (un secondo clic torna a tutti). */
  select(id: number | null): void {
    this.selected.set(this.selected() === id ? null : id);
    this.loadSummary();
  }

  barHeight(clicks: number): number {
    const max = this.maxDay();
    return max > 0 && clicks > 0 ? Math.max(2, (clicks / max) * 100) : 0;
  }

  sourceLabel(name: string): string {
    return CLICK_SOURCE_LABELS[name] ?? name;
  }

  shortLabel(url: string): string {
    return url.replace(/^https?:\/\//, '');
  }

  copy(link: TrackedLink): void {
    navigator.clipboard?.writeText(link.short_url).then(() => {
      this.copied.set(link.id);
      setTimeout(() => this.copied.set(null), 1500);
    });
  }

  /** Il post si apre nella sua campagna: si cerca la campagna dal contenuto. */
  openPost(link: TrackedLink): void {
    this.api.contentItem(link.content_item_id!).subscribe({
      next: (item) => this.router.navigate(['/campaigns', item.project_id]),
      error: () => this.error.set('Il post non esiste più: il link continua comunque a funzionare.'),
    });
  }

  prefillTarget(): void {
    const site = this.brands().find((b) => b.id === this.newBrand)?.website_url;
    if (site && !this.newTarget.trim()) this.newTarget = site;
  }

  create(): void {
    this.busy.set(true);
    this.formError.set(null);
    this.api.createLink({ target_url: this.newTarget.trim(), brand_profile_id: this.newBrand, label: this.newLabel.trim() || null }).subscribe({
      next: () => {
        this.busy.set(false);
        this.creating.set(false);
        this.newTarget = '';
        this.newLabel = '';
        this.load();
      },
      error: (err) => {
        this.busy.set(false);
        this.formError.set(apiErrorMessage(err));
      },
    });
  }

  remove(link: TrackedLink): void {
    const warning = link.clicks ? ` Ha già ${link.clicks} clic: chi lo apre da un post pubblicato troverà "Link non valido".` : '';
    if (!confirm(`Eliminare il link "${link.label}"?${warning}`)) return;
    this.api.deleteLink(link.id).subscribe({
      next: () => {
        if (this.selected() === link.id) this.selected.set(null);
        this.load();
      },
      error: (err) => this.error.set(apiErrorMessage(err)),
    });
  }
}
