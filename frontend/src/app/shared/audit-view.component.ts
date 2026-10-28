import { Component, Input } from '@angular/core';
import { CurrencyPipe, DatePipe } from '@angular/common';
import { CHANNEL_LABELS, ProspectAudit } from '../core/models';
import { textOn } from './color';

/** L'audit gratuito come documento da mandare al potenziale cliente: stampabile in PDF. */
@Component({
  selector: 'app-audit-view',
  standalone: true,
  imports: [CurrencyPipe, DatePipe],
  template: `
    <article class="audit">
      <header class="audit-hero" [style.background]="accent()" [style.color]="onAccent()">
        <p class="audit-kicker">Analisi gratuita del sito · {{ agencyName }}</p>
        <h1>{{ name || audit.name }}</h1>
        <p class="audit-url">{{ audit.site_url }}</p>
        <div class="audit-score">
          <div class="score-ring" [style.--p]="audit.score" [style.--ring]="onAccent()">
            <span>{{ audit.score }}</span>
          </div>
          <div>
            <strong>Punteggio di presenza online</strong>
            <p>{{ scoreLabel() }} · {{ passed() }} controlli superati su {{ audit.checks.length }}</p>
          </div>
        </div>
      </header>

      @if (audit.summary) { <section class="audit-section"><p class="audit-lead">{{ audit.summary }}</p></section> }

      <section class="audit-section">
        <h2>I controlli</h2>
        <ul class="audit-checks">
          @for (c of audit.checks; track c.key) {
            <li [class.ok]="c.ok">
              <i class="ti" [class]="c.ok ? 'ti ti-circle-check' : 'ti ti-alert-triangle'" aria-hidden="true"></i>
              <div><strong>{{ c.label }}</strong><span>{{ c.detail }}</span></div>
            </li>
          }
        </ul>
      </section>

      @if (audit.strengths.length) {
        <section class="audit-section">
          <h2>Cosa funziona già</h2>
          <ul class="audit-list">@for (s of audit.strengths; track $index) { <li>{{ s }}</li> }</ul>
        </section>
      }

      @if (audit.issues.length) {
        <section class="audit-section">
          <h2>Dove migliorare</h2>
          <ol class="audit-issues">
            @for (i of audit.issues; track $index) {
              <li>
                <strong>{{ i.title }}</strong>
                <p>{{ i.detail }}</p>
                @if (i.fix) { <p class="audit-fix"><i class="ti ti-bulb" aria-hidden="true"></i> {{ i.fix }}</p> }
              </li>
            }
          </ol>
        </section>
      }

      @if (audit.post_ideas.length) {
        <section class="audit-section">
          <h2>Tre post che potremmo pubblicare per voi</h2>
          <div class="audit-ideas">
            @for (p of audit.post_ideas; track $index) {
              <div class="audit-idea">
                <span class="tag">{{ channelLabels[p.channel] || p.channel }}</span>
                <strong>{{ p.title }}</strong>
                <p>{{ p.body }}</p>
              </div>
            }
          </div>
        </section>
      }

      @if (audit.services.length) {
        <section class="audit-section">
          <h2>Come possiamo aiutarvi</h2>
          <div class="audit-services">
            @for (s of audit.services; track $index) {
              <div><strong>{{ s.name }}</strong><p>{{ s.description }}</p></div>
            }
          </div>
          @if (monthlyFee) {
            <p class="audit-fee">Proposta: <strong>{{ monthlyFee | currency: 'EUR' }}</strong> al mese + IVA</p>
          }
        </section>
      }

      @if (agencyNote || audit.pitch) {
        <section class="audit-section audit-pitch" [style.border-color]="accent()">
          @if (agencyNote) { <p class="pre">{{ agencyNote }}</p> } @else { <p>{{ audit.pitch }}</p> }
          <p class="muted small">{{ agencyName }} · analisi del {{ audit.analyzed_at.replace(' ', 'T') | date: 'd MMMM y' }}</p>
        </section>
      }
    </article>
  `,
})
export class AuditViewComponent {
  @Input({ required: true }) audit!: ProspectAudit;
  @Input() name: string | null = null;
  @Input() agencyName = '';
  @Input() monthlyFee: number | null = null;
  @Input() agencyNote: string | null = null;

  readonly channelLabels = CHANNEL_LABELS as Record<string, string>;

  accent(): string { return this.audit.colors[0] ?? '#5B4BDB'; }
  onAccent(): string { return textOn(this.accent()); }
  passed(): number { return this.audit.checks.filter((c) => c.ok).length; }
  scoreLabel(): string {
    const s = this.audit.score;
    return s >= 75 ? 'Buona base' : s >= 50 ? 'Con margini di miglioramento' : 'Molte occasioni da cogliere';
  }
}
