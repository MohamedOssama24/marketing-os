import { Component, OnInit, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { Title } from '@angular/platform-browser';
import { ApiService } from '../../core/api.service';
import { Brand, CHANNEL_LABELS, PortalData, PortalPost, apiErrorMessage } from '../../core/models';
import { AssetPreviewComponent, PUBLIC_ASSET_URL } from '../../shared/asset-preview.component';
import { PostVisualComponent } from '../../shared/post-visual.component';
import { CHANNEL_ICONS } from '../../shared/post-card.component';

const MONTHS = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
const NAME_KEY = 'portal-name';

/** Portale del cliente: senza account, dal link mandato dall'agenzia. */
@Component({
  selector: 'app-portal',
  standalone: true,
  imports: [AssetPreviewComponent, DatePipe, FormsModule, PostVisualComponent],
  providers: [{
    provide: PUBLIC_ASSET_URL,
    useFactory: () => {
      const api = inject(ApiService);
      const token = inject(ActivatedRoute).snapshot.paramMap.get('token') ?? '';
      return (assetId: number) => api.publicPortalAssetUrl(token, assetId);
    },
  }],
  template: `
    <div class="portal">
      @if (data(); as d) {
        <header class="portal-head" [style.background]="d.client.primary_color || '#5B4BDB'">
          <span class="client-logo big">
            @if (d.client.logo_asset_id) { <app-asset-preview [assetId]="d.client.logo_asset_id" fit="contain" [alt]="d.client.name" /> } @else { {{ d.client.name.charAt(0) }} }
          </span>
          <div>
            <h1>{{ d.client.name }}</h1>
            <p>Il tuo spazio con {{ d.agency.name }}</p>
          </div>
        </header>

        @if (error()) { <div class="alert">{{ error() }}</div> }

        <section class="portal-section">
          <h2><i class="ti ti-checks" aria-hidden="true"></i> Da approvare @if (d.pending.length) { <span class="count-pill">{{ d.pending.length }}</span> }</h2>
          @if (d.pending.length) {
            <p class="muted">Controlla i post: approvali così come sono, oppure scrivi cosa cambiare.</p>
            <label class="portal-name">Il tuo nome (facoltativo)<input [(ngModel)]="name" (ngModelChange)="rememberName()" maxlength="120" placeholder="Es. Anna" /></label>
            <div class="feed">
              @for (p of d.pending; track p.id) {
                <article class="post-card">
                  <app-post-visual [visual]="p.visual" [brand]="brand()" [format]="p.channel === 'instagram' ? 'portrait' : 'square'" />
                  <div class="caption">
                    <span class="channel"><i class="ti" [class]="'ti ' + icons[p.channel]" aria-hidden="true"></i>{{ channelLabels[p.channel] }}@if (p.scheduled_at) { · {{ p.scheduled_at.replace(' ', 'T') | date: 'EEE d MMM, HH:mm' }} }</span>
                    <p class="caption-title">{{ p.title }}</p>
                    <p class="pre portal-body">{{ p.body }}</p>
                  </div>
                  @if (rejecting() === p.id) {
                    <div class="edit-panel">
                      <textarea rows="3" [(ngModel)]="note" maxlength="1000" placeholder="Cosa vuoi cambiare? Es. togli il prezzo, usa la foto del negozio"></textarea>
                      <div class="actions">
                        <button class="btn ghost small" (click)="rejecting.set(null)">Annulla</button>
                        <button class="btn danger small" (click)="review(p, 'reject')" [disabled]="!note.trim() || busy()">Invia richiesta</button>
                      </div>
                    </div>
                  } @else {
                    <div class="post-actions">
                      <button class="btn ghost small grow" (click)="rejecting.set(p.id); note = ''"><i class="ti ti-message-2" aria-hidden="true"></i> Chiedi modifiche</button>
                      <button class="btn success small grow" (click)="review(p, 'approve')" [disabled]="busy()"><i class="ti ti-check" aria-hidden="true"></i> Approva</button>
                    </div>
                  }
                </article>
              }
            </div>
          } @else {
            <div class="card portal-empty"><i class="ti ti-mood-happy" aria-hidden="true"></i> Niente da approvare: sei in pari!</div>
          }
          @if (reviewed().length) {
            <p class="muted small">Hai appena rivisto: @for (r of reviewed(); track r.id) { <span class="tag">{{ r.status === 'approved' ? '✓' : '✎' }} {{ r.title }}</span> }</p>
          }
        </section>

        @if (d.upcoming.length) {
          <section class="portal-section">
            <h2><i class="ti ti-calendar" aria-hidden="true"></i> In programma</h2>
            <div class="card portal-list">
              @for (p of d.upcoming; track p.id) {
                <div class="portal-row">
                  <span class="portal-date">@if (p.scheduled_at) { {{ p.scheduled_at.replace(' ', 'T') | date: 'EEE d MMM' }} } @else { Da fissare }</span>
                  <i class="ti" [class]="'ti ' + icons[p.channel]" aria-hidden="true"></i>
                  <span class="grow ellipsis">{{ p.title }}</span>
                </div>
              }
            </div>
          </section>
        }

        @if (d.published.length) {
          <section class="portal-section">
            <h2><i class="ti ti-circle-check" aria-hidden="true"></i> Pubblicati di recente</h2>
            <div class="portal-published">
              @for (p of d.published; track p.id) {
                <a class="portal-thumb" [href]="p.published_url || null" target="_blank" rel="noopener" [title]="p.title">
                  <app-post-visual [visual]="p.visual" [brand]="brand()" />
                </a>
              }
            </div>
          </section>
        }

        @if (d.reports.length) {
          <section class="portal-section">
            <h2><i class="ti ti-report-analytics" aria-hidden="true"></i> I tuoi report</h2>
            <div class="portal-reports">
              @for (r of d.reports; track r.period) {
                <a class="card link-card" [href]="'/report/' + r.token" target="_blank" rel="noopener"><i class="ti ti-file-analytics" aria-hidden="true"></i> {{ monthLabel(r.period) }}</a>
              }
            </div>
          </section>
        }

        <section class="portal-section">
          <h2><i class="ti ti-message-circle" aria-hidden="true"></i> Scrivi all'agenzia</h2>
          <form class="card portal-request" (ngSubmit)="sendRequest()">
            <textarea rows="3" [(ngModel)]="message" name="message" maxlength="3000" placeholder="Un'idea per un post, una promozione da comunicare, una foto da usare…"></textarea>
            <div class="actions">
              @if (sent()) { <span class="muted small"><i class="ti ti-check" aria-hidden="true"></i> Inviato, grazie!</span> }
              <button class="btn primary" [disabled]="!message.trim() || busy()"><i class="ti ti-send" aria-hidden="true"></i> Invia</button>
            </div>
          </form>
          @for (r of d.requests; track r.id) {
            <div class="request" [class.done]="r.status === 'done'">
              <div class="grow"><p class="pre">{{ r.message }}</p><small class="muted">{{ r.created_at.replace(' ', 'T') | date: 'd MMM' }} · {{ r.status === 'done' ? 'Fatto' : 'Ricevuto' }}</small></div>
            </div>
          }
        </section>
      } @else if (missing()) {
        <div class="card empty">
          <i class="ti ti-door-off empty-icon" aria-hidden="true"></i>
          <strong>Portale non disponibile</strong>
          <p class="muted">Il link non è più attivo. Chiedi un nuovo link alla tua agenzia.</p>
        </div>
      } @else {
        <div class="card muted center">Caricamento…</div>
      }
    </div>
  `,
})
export class PortalComponent implements OnInit {
  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private title = inject(Title);

  readonly channelLabels = CHANNEL_LABELS;
  readonly icons = CHANNEL_ICONS;
  private token = '';
  data = signal<PortalData | null>(null);
  brand = signal<Brand | null>(null);
  reviewed = signal<PortalPost[]>([]);
  rejecting = signal<number | null>(null);
  missing = signal(false);
  busy = signal(false);
  sent = signal(false);
  error = signal<string | null>(null);
  name = '';
  note = '';
  message = '';

  ngOnInit(): void {
    this.token = this.route.snapshot.paramMap.get('token') ?? '';
    try { this.name = localStorage.getItem(NAME_KEY) ?? ''; } catch { /* archiviazione non disponibile */ }
    this.load();
  }

  load(): void {
    this.api.publicPortal(this.token).subscribe({
      next: (d) => {
        this.data.set(d);
        // La grafica dei post usa colori, font e logo del cliente come nell'app.
        this.brand.set({ ...d.client, id: 0 } as unknown as Brand);
        this.title.setTitle(`${d.client.name} · Portale`);
      },
      error: () => this.missing.set(true),
    });
  }

  review(post: PortalPost, action: 'approve' | 'reject'): void {
    this.busy.set(true);
    this.error.set(null);
    this.api.publicPortalReview(this.token, post.id, action, { name: this.name.trim() || null, note: action === 'reject' ? this.note.trim() : null }).subscribe({
      next: (updated) => {
        this.busy.set(false);
        this.rejecting.set(null);
        this.reviewed.update((list) => [...list, updated]);
        this.data.update((d) => d && { ...d, pending: d.pending.filter((p) => p.id !== post.id), upcoming: updated.status === 'approved' ? [...d.upcoming, updated] : d.upcoming });
      },
      error: (err) => {
        this.busy.set(false);
        this.error.set(apiErrorMessage(err));
      },
    });
  }

  sendRequest(): void {
    this.busy.set(true);
    this.api.publicPortalRequest(this.token, { name: this.name.trim() || null, message: this.message.trim() }).subscribe({
      next: () => {
        this.busy.set(false);
        this.message = '';
        this.sent.set(true);
        this.load();
      },
      error: (err) => {
        this.busy.set(false);
        this.error.set(apiErrorMessage(err));
      },
    });
  }

  rememberName(): void {
    try { localStorage.setItem(NAME_KEY, this.name); } catch { /* archiviazione non disponibile */ }
  }

  monthLabel(period: string): string {
    const [y, m] = period.split('-').map(Number);
    const label = `${MONTHS[m - 1]} ${y}`;
    return label.charAt(0).toUpperCase() + label.slice(1);
  }
}
