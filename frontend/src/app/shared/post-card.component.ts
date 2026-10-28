import { Component, EventEmitter, Input, Output, ViewChild, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Observable, switchMap } from 'rxjs';
import { AuthService } from '../core/auth.service';
import { ApiService } from '../core/api.service';
import {
  Asset, Brand, CHANNEL_LABELS, CONTENT_STATUS_LABELS, ContentItem, LAYOUT_LABELS, Visual, VisualLayout, apiErrorMessage,
} from '../core/models';
import { PostVisualComponent } from './post-visual.component';
import { AssetPreviewComponent } from './asset-preview.component';

export const CHANNEL_ICONS: Record<string, string> = {
  instagram: 'ti-brand-instagram',
  facebook: 'ti-brand-facebook',
  linkedin: 'ti-brand-linkedin',
  blog: 'ti-article',
  email: 'ti-mail',
  website: 'ti-world',
  other: 'ti-file-text',
};

/** Anteprima realistica di un post (cornice tipo telefono) con revisione, modifica ed export della grafica. */
@Component({
  selector: 'app-post-card',
  standalone: true,
  imports: [DatePipe, FormsModule, PostVisualComponent, AssetPreviewComponent],
  template: `
    <article class="post-card" [class.editing]="editing()">
      <div class="phone">
        <header class="phone-head">
          <span class="avatar" [style.background]="brand?.primary_color ?? '#5B4BDB'">
            @if (brand?.logo_asset_id) { <app-asset-preview [assetId]="brand!.logo_asset_id!" fit="contain" /> } @else { {{ (brand?.name ?? '?').charAt(0) }} }
          </span>
          <span class="handle">{{ handle() }}</span>
          <span class="channel"><i class="ti" [class]="'ti ' + channelIcon()" aria-hidden="true"></i>{{ channelLabels[item.channel] }}</span>
        </header>

        <app-post-visual #visualRef [visual]="editing() ? draftVisual : item.visual" [brand]="brand" [format]="format()" />

        <div class="caption">
          @if (item.channel === 'instagram' || item.channel === 'facebook') {
            <div class="social-icons" aria-hidden="true"><i class="ti ti-heart"></i><i class="ti ti-message-circle"></i><i class="ti ti-send"></i></div>
          }
          <p class="caption-title">{{ item.title }}</p>
          <p class="caption-body" [class.expanded]="expanded()">{{ item.body }}</p>
          @if (item.body.length > 140) {
            <button class="link-btn" (click)="expanded.set(!expanded())">{{ expanded() ? 'meno' : 'altro' }}</button>
          }
        </div>
      </div>

      <div class="post-meta">
        <span class="badge" [attr.data-status]="item.status">{{ statusLabels[item.status] }}</span>
        @if (item.scheduled_at) {
          <span class="muted small"><i class="ti ti-calendar-event" aria-hidden="true"></i> {{ item.scheduled_at | date: 'EEE d MMM, HH:mm' }}</span>
        }
        @if (item.ai_generated) { <span class="tag ai"><i class="ti ti-sparkles" aria-hidden="true"></i> AI</span> }
      </div>

      @if (item.review_note) {
        <div class="review-note" [class.rejected]="item.status === 'rejected'"><strong>Nota:</strong> {{ item.review_note }}</div>
      }

      @if (editing()) {
        <div class="edit-panel">
          <label>Titolo<input [(ngModel)]="draftTitle" /></label>
          <label>Testo<textarea rows="7" [(ngModel)]="draftBody"></textarea></label>
          <div class="edit-grid">
            <label>Titolo grafica<input [(ngModel)]="draftVisual.headline" maxlength="80" /></label>
            <label>Etichetta<input [(ngModel)]="draftVisual.badge" maxlength="24" placeholder="Novità" /></label>
          </div>
          <label>Sottotitolo grafica<input [(ngModel)]="draftVisual.subheadline" maxlength="140" /></label>
          <div class="edit-grid">
            <label>Stile
              <select [(ngModel)]="draftVisual.layout">
                @for (l of layouts; track l[0]) { <option [value]="l[0]">{{ l[1] }}</option> }
              </select>
            </label>
            <label>Foto
              <select [(ngModel)]="draftVisual.asset_id">
                <option [ngValue]="null">— Nessuna —</option>
                @for (a of images; track a.id) { <option [ngValue]="a.id">{{ a.original_name }}</option> }
              </select>
            </label>
          </div>
          <div class="actions">
            <button class="btn ghost small" (click)="editing.set(false)">Annulla</button>
            <button class="btn primary small" (click)="save()" [disabled]="busy()">Salva</button>
          </div>
        </div>
      } @else if (rejecting()) {
        <div class="edit-panel">
          <textarea rows="2" [(ngModel)]="note" placeholder="Cosa va cambiato?"></textarea>
          <div class="actions">
            <button class="btn ghost small" (click)="rejecting.set(false)">Annulla</button>
            <button class="btn danger small" (click)="reject()" [disabled]="!note.trim() || busy()">Richiedi modifiche</button>
          </div>
        </div>
      } @else if (rewriting()) {
        <div class="edit-panel rewrite-panel">
          @if (rewriteBusy()) {
            <p class="rewrite-progress"><span class="spinner"></span> L'AI sta riscrivendo il post…</p>
          } @else {
            <p class="small muted">Come vuoi riscriverlo?</p>
            <div class="quick-picks">
              @for (q of quickInstructions; track q) { <button class="chip" (click)="rewrite(q)">{{ q }}</button> }
            </div>
            <div class="rewrite-custom">
              <input [(ngModel)]="customInstruction" maxlength="300" placeholder="Oppure scrivi tu: metti in risalto la qualità" (keydown.enter)="rewrite(customInstruction)" />
              <button class="icon-btn" (click)="rewrite(customInstruction)" [disabled]="!customInstruction.trim()" aria-label="Riscrivi"><i class="ti ti-arrow-right" aria-hidden="true"></i></button>
            </div>
            <div class="actions"><button class="btn ghost small" (click)="rewriting.set(false)">Annulla</button></div>
          }
        </div>
      } @else {
        <div class="post-actions">
          <button class="icon-btn" (click)="copy()" aria-label="Copia testo" title="Copia testo"><i class="ti" [class]="copied() ? 'ti ti-check' : 'ti ti-copy'" aria-hidden="true"></i></button>
          <button class="icon-btn" (click)="download()" aria-label="Scarica grafica" title="Scarica grafica PNG"><i class="ti ti-download" aria-hidden="true"></i></button>
          @if (editable()) {
            <button class="icon-btn" (click)="cycleStyle()" [disabled]="busy()" aria-label="Cambia stile" [title]="'Cambia stile (ora: ' + layoutLabels[item.visual?.layout ?? 'bold'] + ')'"><i class="ti ti-palette" aria-hidden="true"></i></button>
            <button class="icon-btn ai" (click)="rewriting.set(true); customInstruction = ''" aria-label="Riscrivi con AI" title="Riscrivi con AI"><i class="ti ti-sparkles" aria-hidden="true"></i></button>
            <button class="icon-btn" (click)="startEdit()" aria-label="Modifica" title="Modifica"><i class="ti ti-pencil" aria-hidden="true"></i></button>
            <button class="icon-btn danger" (click)="remove()" [disabled]="busy()" aria-label="Elimina" title="Elimina post"><i class="ti ti-trash" aria-hidden="true"></i></button>
            <button class="btn primary small full-width" (click)="run(api.submitContent(item.id))" [disabled]="busy()">Invia in revisione</button>
          }
          @if (auth.canManage() && item.status === 'in_review') {
            <button class="btn ghost small grow" (click)="rejecting.set(true); note = ''">Modifiche</button>
            <button class="btn success small grow" (click)="run(api.approveContent(item.id))" [disabled]="busy()"><i class="ti ti-check" aria-hidden="true"></i> Approva</button>
          }
        </div>
      }
      @if (error()) { <div class="alert small">{{ error() }}</div> }
    </article>
  `,
})
export class PostCardComponent {
  @Input({ required: true }) item!: ContentItem;
  @Input() brand: Brand | null = null;
  @Input() assets: Asset[] = [];
  @Output() changed = new EventEmitter<ContentItem>();
  @Output() removed = new EventEmitter<number>();
  @ViewChild('visualRef') visualRef?: PostVisualComponent;

  readonly auth = inject(AuthService);
  readonly api = inject(ApiService);
  readonly channelLabels = CHANNEL_LABELS;
  readonly statusLabels = CONTENT_STATUS_LABELS;
  readonly layoutLabels = LAYOUT_LABELS;
  readonly layouts = Object.entries(LAYOUT_LABELS) as [VisualLayout, string][];
  readonly quickInstructions = ['Più breve', 'Più emozionale', 'Più professionale', 'Più ironico', 'Aggiungi urgenza', 'Racconta una storia'];

  editing = signal(false);
  rejecting = signal(false);
  rewriting = signal(false);
  rewriteBusy = signal(false);
  expanded = signal(false);
  copied = signal(false);
  busy = signal(false);
  error = signal<string | null>(null);
  draftTitle = '';
  draftBody = '';
  draftVisual: Visual = { headline: '', subheadline: '', badge: '', layout: 'bold', asset_id: null };
  note = '';
  customInstruction = '';

  /** Immagini della libreria, con prima quelle del cliente del post. */
  get images(): Asset[] {
    const images = this.assets.filter((a) => a.kind === 'image' && a.id !== this.brand?.logo_asset_id);
    return [...images.filter((a) => a.brand_profile_id === this.brand?.id), ...images.filter((a) => a.brand_profile_id !== this.brand?.id)];
  }

  editable(): boolean {
    return this.auth.canEdit() && (this.item.status === 'draft' || this.item.status === 'rejected');
  }

  /** Passa allo stile grafico successivo e lo salva subito. */
  cycleStyle(): void {
    const current = this.item.visual?.layout ?? 'bold';
    const order = this.layouts.map(([key]) => key);
    const next = order[(order.indexOf(current) + 1) % order.length];
    const visual: Visual = { headline: this.item.title.slice(0, 60), subheadline: '', badge: '', asset_id: null, ...(this.item.visual ?? {}), layout: next };
    // Per gli stili con foto propone la prima foto del cliente, se il post non ne ha una.
    if ((next === 'photo' || next === 'split') && !visual.asset_id) visual.asset_id = this.images[0]?.id ?? null;
    this.run(this.api.updateContent(this.item.id, { visual }));
  }

  rewrite(instruction: string): void {
    if (!instruction.trim()) return;
    this.rewriteBusy.set(true);
    this.error.set(null);
    this.api
      .rewriteContent(this.item.id, instruction.trim())
      .pipe(switchMap((job) => this.api.waitForJob(job.id)))
      .subscribe({
        next: (job) => {
          if (job.status === 'failed') {
            this.rewriteBusy.set(false);
            this.error.set(job.error ?? 'Riscrittura non riuscita.');
            return;
          }
          this.api.contentItem(this.item.id).subscribe((updated) => {
            this.rewriteBusy.set(false);
            this.rewriting.set(false);
            this.changed.emit(updated);
          });
        },
        error: (err) => {
          this.rewriteBusy.set(false);
          this.error.set(apiErrorMessage(err));
        },
      });
  }

  remove(): void {
    if (!confirm(`Eliminare il post "${this.item.title}"?`)) return;
    this.busy.set(true);
    this.api.deleteContent(this.item.id).subscribe({
      next: () => this.removed.emit(this.item.id),
      error: (err) => {
        this.busy.set(false);
        this.error.set(apiErrorMessage(err));
      },
    });
  }

  channelIcon(): string {
    return CHANNEL_ICONS[this.item.channel] ?? 'ti-file-text';
  }

  handle(): string {
    const name = this.brand?.name ?? 'brand';
    return this.item.channel === 'instagram' ? name.toLowerCase().replace(/[^a-z0-9]+/g, '') : name;
  }

  format(): 'square' | 'portrait' | 'story' {
    if (['reel', 'storia'].includes(this.item.content_type)) return 'story';
    return this.item.channel === 'instagram' ? 'portrait' : 'square';
  }

  startEdit(): void {
    this.draftTitle = this.item.title;
    this.draftBody = this.item.body;
    this.draftVisual = { headline: '', subheadline: '', badge: '', layout: 'bold', asset_id: null, ...(this.item.visual ?? {}) };
    this.editing.set(true);
  }

  save(): void {
    this.run(this.api.updateContent(this.item.id, { title: this.draftTitle, body: this.draftBody, visual: this.draftVisual }), () => this.editing.set(false));
  }

  reject(): void {
    this.run(this.api.rejectContent(this.item.id, this.note.trim()), () => this.rejecting.set(false));
  }

  copy(): void {
    navigator.clipboard?.writeText(`${this.item.title}\n\n${this.item.body}`).then(() => {
      this.copied.set(true);
      setTimeout(() => this.copied.set(false), 1500);
    });
  }

  download(): void {
    const name = `${this.brand?.name ?? 'post'}-${this.item.id}`.toLowerCase().replace(/[^a-z0-9]+/g, '-');
    this.visualRef?.exportPng(name).catch(() => this.error.set('Impossibile esportare la grafica.'));
  }

  run(request: Observable<ContentItem>, onDone?: () => void): void {
    this.busy.set(true);
    this.error.set(null);
    request.subscribe({
      next: (updated) => {
        this.busy.set(false);
        onDone?.();
        this.changed.emit(updated);
      },
      error: (err) => {
        this.busy.set(false);
        this.error.set(apiErrorMessage(err));
      },
    });
  }
}
