import { Component, Input, computed, signal } from '@angular/core';
import { CurrencyPipe, DatePipe, DecimalPipe } from '@angular/common';
import { CHANNEL_LABELS, CLICK_SOURCE_LABELS, ClientReport, PLATFORM_LABELS } from '../core/models';
import { AssetPreviewComponent } from './asset-preview.component';
import { CHANNEL_ICONS } from './post-card.component';
import { DEFAULT_PRIMARY, mix, readableOnLight, textOn } from './color';

const DEVICE_LABELS: Record<string, string | undefined> = { mobile: 'telefono', tablet: 'tablet', desktop: 'computer' };

/**
 * Il report mensile come lo vede il cliente: lo stesso componente serve l'anteprima nell'app e il link pubblico.
 * È pensato anche per la stampa, così "Salva come PDF" del browser produce un documento pulito.
 */
@Component({
  selector: 'app-report-view',
  standalone: true,
  imports: [AssetPreviewComponent, CurrencyPipe, DatePipe, DecimalPipe],
  template: `
    @if (r(); as r) {
      <article class="report" [style.--accent]="accent()" [style.--accent-soft]="accentSoft()">
        <header class="report-cover" [style.background]="coverBackground()" [style.color]="coverText()">
          <div class="cover-client">
            <span class="cover-logo">
              @if (logoUrl) { <img [src]="logoUrl" [alt]="'Logo ' + r.client.name" />
              } @else if (r.client.logo_asset_id) { <app-asset-preview [assetId]="r.client.logo_asset_id" fit="contain" [alt]="'Logo ' + r.client.name" />
              } @else { <span class="cover-initial" [style.color]="accent()">{{ r.client.name.charAt(0) }}</span> }
            </span>
            <div>
              <p class="cover-kicker">Report mensile</p>
              <h1>{{ r.client.name }}</h1>
              <p class="cover-period">{{ r.period_label }}</p>
            </div>
          </div>
          <p class="cover-agency">Preparato da<strong>{{ r.agency.name }}</strong></p>
        </header>

        <section class="report-section">
          <h2>Il mese in sintesi</h2>
          <div class="kpis">
            <div class="kpi">
              <span class="kpi-label"><i class="ti ti-photo" aria-hidden="true"></i>Post pubblicati</span>
              <strong>{{ r.content.published | number }}</strong>
              <span class="delta">{{ delta(r.content.published, r.content.previous) }}</span>
            </div>
            <div class="kpi">
              <span class="kpi-label"><i class="ti ti-click" aria-hidden="true"></i>Clic sui link</span>
              <strong>{{ r.clicks.clicks | number }}</strong>
              <span class="delta">{{ delta(r.clicks.clicks, r.clicks.previous.clicks) }}</span>
            </div>
            @if (hasAds()) {
              <div class="kpi">
                <span class="kpi-label"><i class="ti ti-users" aria-hidden="true"></i>Persone raggiunte</span>
                <strong>{{ r.ads.totals.reach | number }}</strong>
                <span class="delta">con le sponsorizzate</span>
              </div>
              <div class="kpi">
                <span class="kpi-label"><i class="ti ti-coin-euro" aria-hidden="true"></i>Investimento pubblicitario</span>
                <strong>{{ r.ads.totals.spent | currency: 'EUR' }}</strong>
                <span class="delta">{{ delta(r.ads.totals.spent, r.ads.previous.spent) }}</span>
              </div>
            } @else {
              <div class="kpi">
                <span class="kpi-label"><i class="ti ti-users" aria-hidden="true"></i>Persone diverse</span>
                <strong>{{ r.clicks.unique_clicks | number }}</strong>
                <span class="delta">hanno cliccato almeno una volta</span>
              </div>
              <div class="kpi">
                <span class="kpi-label"><i class="ti ti-calendar-event" aria-hidden="true"></i>Già in programma</span>
                <strong>{{ r.content.planned_next_month | number }}</strong>
                <span class="delta">post per il mese prossimo</span>
              </div>
            }
          </div>
        </section>

        @if (r.summary) {
          <section class="report-section note">
            <h2>Com'è andato il mese</h2>
            <p class="prose">{{ r.summary }}</p>
          </section>
        }

        <section class="report-section">
          <h2>Clic sui link dei post</h2>
          @if (r.clicks.clicks > 0) {
            <div class="clicks-grid">
              <div>
                <div class="bar-chart" [style.grid-template-columns]="'repeat(' + r.clicks.daily.length + ', minmax(0, 1fr))'" role="img" [attr.aria-label]="'Clic al giorno a ' + r.period_label">
                  @for (d of r.clicks.daily; track d.day; let i = $index) {
                    <div class="bar-col" tabindex="0">
                      <div class="bar-track">
                        @if (d.day === peakDay()) { <span class="bar-value">{{ d.clicks }}</span> }
                        <div class="bar" [style.height.%]="maxDay() ? (d.clicks / maxDay()) * 100 : 0"></div>
                      </div>
                      <span class="bar-label">{{ i % 5 === 0 ? (d.day | date: 'd') : '' }}</span>
                      <div class="bar-tip"><strong>{{ d.day | date: 'EEE d MMM' }}</strong>{{ d.clicks }} clic</div>
                    </div>
                  }
                </div>
                <p class="muted small chart-note">{{ r.clicks.unique_clicks | number }} persone diverse · {{ deviceLine() }}</p>
              </div>
              <div>
                <h3>Da dove arrivano</h3>
                <div class="hbar-list">
                  @for (s of r.clicks.sources; track s.name) {
                    <div class="hbar static">
                      <span class="hbar-head"><strong>{{ sourceLabels[s.name] ?? s.name }}</strong><span class="hbar-value">{{ s.clicks | number }}</span></span>
                      <span class="hbar-track"><span class="hbar-fill" [style.width.%]="(s.clicks / r.clicks.clicks) * 100"></span></span>
                    </div>
                  }
                </div>
              </div>
            </div>
          } @else {
            <p class="muted">Nessun clic registrato a {{ r.period_label }}.</p>
          }
        </section>

        <section class="report-section">
          <h2>Contenuti pubblicati</h2>
          @if (r.content.posts.length) {
            <p class="muted small section-lead">
              @for (c of r.content.by_channel; track c.name; let last = $last) { {{ c.posts }} su {{ channelLabels[c.name] }}@if (!last) {, } }
            </p>
            <table class="report-table">
              <thead><tr><th>Data</th><th>Contenuto</th><th class="num">Clic</th></tr></thead>
              <tbody>
                @for (p of r.content.posts; track p.id) {
                  <tr>
                    <td class="nowrap">{{ p.published_at.replace(' ', 'T') | date: 'd MMM' }}</td>
                    <td>
                      <span class="post-channel"><i class="ti" [class]="'ti ' + channelIcons[p.channel]" aria-hidden="true"></i>{{ channelLabels[p.channel] }}</span>
                      <span class="post-title">{{ p.title }}</span>
                      @if (p.published_url) { <a class="post-link no-print" [href]="p.published_url" target="_blank" rel="noopener">Vedi il post <i class="ti ti-external-link" aria-hidden="true"></i></a> }
                    </td>
                    <td class="num">{{ p.clicks | number }}</td>
                  </tr>
                }
              </tbody>
            </table>
          } @else {
            <p class="muted">Nessun contenuto pubblicato a {{ r.period_label }}.</p>
          }
        </section>

        @if (hasAds()) {
          <section class="report-section">
            <h2>Sponsorizzate</h2>
            <div class="ad-stats">
              <div><span>Visualizzazioni</span><strong>{{ r.ads.totals.impressions | number }}</strong></div>
              <div><span>Persone raggiunte</span><strong>{{ r.ads.totals.reach | number }}</strong></div>
              <div><span>Clic</span><strong>{{ r.ads.totals.clicks | number }}</strong></div>
              <div><span>Percentuale di clic</span><strong>{{ r.ads.totals.ctr === null ? '—' : (r.ads.totals.ctr | number: '1.0-2') + '%' }}</strong></div>
              <div><span>Costo per clic</span><strong>{{ r.ads.totals.cpc === null ? '—' : (r.ads.totals.cpc | currency: 'EUR') }}</strong></div>
              <div><span>Risultati</span><strong>{{ r.ads.totals.results | number }}</strong>
                @if (r.ads.totals.cost_per_result !== null) { <small>{{ r.ads.totals.cost_per_result | currency: 'EUR' }} l'uno</small> }</div>
            </div>
            <table class="report-table">
              <thead><tr><th>Campagna</th><th class="num">Spesa</th><th class="num">Clic</th><th class="num">Risultati</th></tr></thead>
              <tbody>
                @for (c of r.ads.campaigns; track $index) {
                  <tr>
                    <td><span class="post-title">{{ c.campaign_name }}</span><small class="muted">{{ platformLabels[c.platform] }} · {{ c.period_start | date: 'd MMM' }} – {{ c.period_end | date: 'd MMM' }}</small></td>
                    <td class="num">{{ c.spent | currency: 'EUR' }}</td>
                    <td class="num">{{ c.clicks | number }}</td>
                    <td class="num">{{ c.results | number }}</td>
                  </tr>
                }
              </tbody>
            </table>
          </section>
        }

        @if (r.next_steps || r.content.planned_next_month) {
          <section class="report-section note">
            <h2>Il prossimo mese</h2>
            @if (r.next_steps) { <p class="prose">{{ r.next_steps }}</p> }
            @if (r.content.planned_next_month) {
              <p class="planned"><i class="ti ti-calendar-check" aria-hidden="true"></i>{{ r.content.planned_next_month }} {{ r.content.planned_next_month === 1 ? 'contenuto è già pronto e programmato' : 'contenuti sono già pronti e programmati' }}.</p>
            }
          </section>
        }

        <footer class="report-foot">
          <span>{{ r.agency.name }} · Report di {{ r.period_label }} per {{ r.client.name }}</span>
          <span>Dati aggiornati al {{ r.generated_at.replace(' ', 'T') | date: 'd MMMM y, HH:mm' }}</span>
        </footer>
      </article>
    }
  `,
  styles: [`
    :host { display: block; }
    .report { background: #fff; border: 1px solid var(--border); border-radius: 18px; overflow: hidden; box-shadow: var(--shadow); color: var(--text); }
    .report-cover { display: flex; justify-content: space-between; align-items: flex-end; gap: 24px; flex-wrap: wrap; padding: 34px 36px 30px; }
    .cover-client { display: flex; align-items: center; gap: 18px; min-width: 0; }
    .cover-logo { width: 72px; height: 72px; border-radius: 16px; background: #fff; flex-shrink: 0; overflow: hidden; display: grid; place-items: center; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.12);
      img { width: 100%; height: 100%; object-fit: contain; padding: 8%; box-sizing: border-box; } }
    .cover-initial { font-size: 2rem; font-weight: 700; }
    .cover-kicker { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.08em; opacity: 0.85; font-weight: 600; }
    h1 { font-size: 1.75rem; line-height: 1.2; overflow-wrap: anywhere; }
    .cover-period { font-size: 1.05rem; text-transform: capitalize; opacity: 0.92; margin-top: 2px; }
    .cover-agency { margin-left: auto; font-size: 0.82rem; opacity: 0.9; display: flex; flex-direction: column; text-align: right; strong { font-size: 1rem; } }
    .report-section { padding: 26px 36px; border-top: 1px solid var(--border); break-inside: avoid;
      h2 { font-size: 1.05rem; margin-bottom: 14px; } h3 { font-size: 0.9rem; margin-bottom: 4px; } }
    .report-section.note { background: var(--accent-soft); }
    .prose { white-space: pre-wrap; font-size: 0.96rem; line-height: 1.65; max-width: 72ch; }
    .kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
    .kpi { border: 1px solid var(--border); border-radius: 14px; padding: 14px 16px; display: flex; flex-direction: column; gap: 2px;
      strong { font-size: 1.6rem; font-variant-numeric: tabular-nums; letter-spacing: -0.02em; } }
    .kpi-label { display: flex; align-items: center; gap: 6px; font-size: 0.8rem; color: var(--muted); i { color: var(--accent); font-size: 17px; } }
    .delta { font-size: 0.78rem; color: var(--muted); }
    .clicks-grid { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(220px, 1fr); gap: 28px; align-items: start; }
    .bar-chart { height: 170px; margin-top: 8px; }
    .bar, .bar-col:hover .bar, .bar-col:focus .bar { background: var(--accent); }
    .bar-col:hover .bar, .bar-col:focus .bar { opacity: 0.8; }
    .hbar-fill { background: var(--accent); }
    .chart-note { margin-top: 26px; }
    .section-lead { margin: -8px 0 10px; }
    .report-table { th, td { padding: 10px 8px; font-size: 0.88rem; vertical-align: top; } th { padding-top: 0; } }
    .nowrap { white-space: nowrap; }
    .post-channel { display: inline-flex; align-items: center; gap: 4px; font-size: 0.76rem; color: var(--muted); margin-right: 8px; }
    .post-title { font-weight: 500; display: block; }
    .post-link { font-size: 0.8rem; text-decoration: none; color: var(--accent); display: inline-flex; align-items: center; gap: 3px; }
    td small { display: block; }
    .ad-stats { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 10px; margin-bottom: 16px;
      div { background: var(--surface-2); border-radius: 12px; padding: 10px 12px; display: flex; flex-direction: column; gap: 2px; }
      span { font-size: 0.74rem; color: var(--muted); } strong { font-size: 1.05rem; font-variant-numeric: tabular-nums; } small { font-size: 0.72rem; color: var(--muted); } }
    .planned { display: flex; align-items: center; gap: 8px; margin-top: 12px; font-weight: 500; i { color: var(--accent); font-size: 18px; } }
    .report-foot { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 16px 36px; border-top: 1px solid var(--border); font-size: 0.78rem; color: var(--muted); }
    @media (max-width: 900px) {
      .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .ad-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
      .clicks-grid { grid-template-columns: minmax(0, 1fr); }
    }
    @media (max-width: 560px) {
      .report-cover, .report-section, .report-foot { padding-left: 18px; padding-right: 18px; }
      .cover-agency { text-align: left; }
      .ad-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      h1 { font-size: 1.35rem; }
    }
    @media print {
      .report { border: none; border-radius: 0; box-shadow: none; }
      .report-cover, .report-section.note, .bar, .hbar-fill, .ad-stats div { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .kpis { grid-template-columns: repeat(4, minmax(0, 1fr)); }
      .ad-stats { grid-template-columns: repeat(6, minmax(0, 1fr)); }
      .clicks-grid { grid-template-columns: minmax(0, 1.6fr) minmax(200px, 1fr); }
      .report-section { padding: 18px 28px; }
      .bar-tip { display: none !important; }
    }
  `],
})
export class ReportViewComponent {
  @Input({ required: true }) set report(value: ClientReport) { this.r.set(value); }
  /** Indirizzo pubblico del logo; nell'app il logo si carica dalla libreria con il token. */
  @Input() logoUrl: string | null = null;

  readonly r = signal<ClientReport | null>(null);
  readonly channelLabels = CHANNEL_LABELS;
  readonly channelIcons = CHANNEL_ICONS;
  readonly sourceLabels = CLICK_SOURCE_LABELS;
  readonly platformLabels = PLATFORM_LABELS;

  private readonly primary = computed(() => this.r()?.client.primary_color ?? DEFAULT_PRIMARY);
  /** Il colore del cliente, scurito quanto serve perché grafici e icone si leggano sul bianco. */
  readonly accent = computed(() => readableOnLight(this.primary()));
  readonly accentSoft = computed(() => mix(this.primary(), '#ffffff', 0.92));
  readonly coverBackground = computed(() => {
    const second = this.r()?.client.secondary_color ?? mix(this.primary(), '#000000', 0.25);
    return `linear-gradient(120deg, ${this.primary()} 0%, ${second} 100%)`;
  });
  readonly coverText = computed(() => textOn(this.primary()));
  readonly hasAds = computed(() => (this.r()?.ads.campaigns.length ?? 0) > 0);
  readonly maxDay = computed(() => Math.max(0, ...(this.r()?.clicks.daily.map((d) => d.clicks) ?? [])));
  readonly peakDay = computed(() => this.r()?.clicks.daily.find((d) => d.clicks === this.maxDay() && d.clicks > 0)?.day ?? null);
  readonly deviceLine = computed(() => {
    const c = this.r()?.clicks;
    if (!c?.clicks) return '';
    return c.devices.map((d) => `${Math.round((d.clicks / c.clicks) * 100)}% da ${DEVICE_LABELS[d.name] ?? d.name}`).join(', ');
  });

  /** Confronto con il mese prima, a parole (il colore da solo non basta, e in stampa può sparire). */
  delta(current: number, previous: number): string {
    const month = this.r()?.previous_label ?? 'il mese prima';
    if (!previous) return current ? `a ${month} erano 0` : `come a ${month}`;
    const pct = Math.round(((current - previous) / previous) * 100);
    if (pct === 0) return `come a ${month}`;
    return `${pct > 0 ? '▲ +' : '▼ '}${pct}% rispetto a ${month}`;
  }
}
