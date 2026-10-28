import { Component, DestroyRef, OnInit, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { DatePipe } from '@angular/common';
import { FormBuilder, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { filter, switchMap, take, timer } from 'rxjs';
import { AuthService } from '../../core/auth.service';
import { ApiService } from '../../core/api.service';
import {
  Brand, CHANNEL_LABELS, CONTENT_STATUS_LABELS, Channel, ContentItem, ContentStatus, Project, apiErrorMessage,
} from '../../core/models';
import { PROJECT_STATUS_LABELS } from '../projects/projects.component';

type Filter = 'all' | ContentStatus;

@Component({
  selector: 'app-project-detail',
  standalone: true,
  imports: [ReactiveFormsModule, FormsModule, DatePipe, RouterLink],
  template: `
    <a routerLink="/projects" class="back">← Progetti</a>

    @if (error()) { <div class="alert">{{ error() }}</div> }

    @if (project(); as p) {
      <div class="page-head">
        <div>
          <h1>{{ p.name }}</h1>
          <p class="muted">{{ p.objective }}</p>
          <p class="muted small">
            🏷️ {{ brand()?.name ?? 'Nessun brand associato' }}
            @if (p.target_audience) { · 🎯 {{ p.target_audience }} }
          </p>
        </div>
        @if (auth.canEdit()) {
          <div class="head-controls">
            <select [ngModel]="p.brand_profile_id" (ngModelChange)="update({ brand_profile_id: $event })" aria-label="Brand del progetto">
              <option [ngValue]="null">— Nessun brand —</option>
              @for (b of brands(); track b.id) { <option [ngValue]="b.id">{{ b.name }}</option> }
            </select>
            <select class="status-select" [ngModel]="p.status" (ngModelChange)="update({ status: $event })" aria-label="Stato del progetto">
              @for (s of projectStatuses; track s[0]) { <option [value]="s[0]">{{ s[1] }}</option> }
            </select>
          </div>
        } @else {
          <span class="badge" [attr.data-status]="p.status">{{ projectStatusLabels[p.status] }}</span>
        }
      </div>

      @if (!brand()) {
        <div class="hint">Associa un brand kit al progetto per contenuti con il tono e le regole del cliente.</div>
      }

      @if (auth.canEdit()) {
        <form class="card form-grid generate" [formGroup]="genForm" (ngSubmit)="generate()">
          <div class="full">
            <h3>✨ Genera contenuti con l'AI</h3>
            <p class="muted small">L'AI prepara delle bozze: le rivedi, le modifichi e le mandi in approvazione.</p>
          </div>
          @if (genError()) { <div class="alert full">{{ genError() }}</div> }
          <label>Canale
            <select formControlName="channel">
              @for (c of channels; track c[0]) { <option [value]="c[0]">{{ c[1] }}</option> }
            </select>
          </label>
          <label>Tipo di contenuto
            <input formControlName="content_type" placeholder="Es. post, annuncio, newsletter, articolo" />
          </label>
          <label class="full">Brief *
            <textarea rows="3" formControlName="brief" placeholder="Cosa vuoi comunicare? Es. Lancio della nuova linea autunnale, sconto del 20% fino a fine mese, punta sulla qualità artigianale."></textarea>
          </label>
          <label>Varianti
            <input type="number" min="1" max="5" formControlName="variants" />
          </label>
          <div class="actions bottom">
            <button class="btn primary" type="submit" [disabled]="genForm.invalid || generating()">
              {{ generating() ? 'Generazione in corso…' : 'Genera bozze' }}
            </button>
          </div>
        </form>
      }

      <div class="section-head">
        <h2>Contenuti</h2>
        <div class="filters">
          @for (f of filters; track f[0]) {
            <button class="chip" [class.active]="activeFilter() === f[0]" (click)="activeFilter.set(f[0])">
              {{ f[1] }} <span class="muted">{{ count(f[0]) }}</span>
            </button>
          }
        </div>
      </div>

      <div class="content-list">
        @for (c of visibleContent(); track c.id) {
          <article class="card content-card">
            <div class="content-meta">
              <span class="tag">{{ channelLabels[c.channel] }}</span>
              <span class="muted small">{{ c.content_type }}</span>
              @if (c.ai_generated) { <span class="tag ai">AI</span> }
              <span class="badge" [attr.data-status]="c.status">{{ contentStatusLabels[c.status] }}</span>
            </div>

            @if (editingId() === c.id) {
              <input class="edit-title" [(ngModel)]="editTitle" />
              <textarea class="edit-body" rows="10" [(ngModel)]="editBody"></textarea>
              <div class="actions">
                <button class="btn ghost" (click)="editingId.set(null)">Annulla</button>
                <button class="btn primary" (click)="saveEdit(c)" [disabled]="busyId() === c.id">Salva</button>
              </div>
            } @else {
              <h3>{{ c.title }}</h3>
              <p class="content-body">{{ c.body }}</p>
            }

            @if (c.review_note) {
              <div class="review-note" [class.rejected]="c.status === 'rejected'">
                <strong>Nota di revisione:</strong> {{ c.review_note }}
              </div>
            }

            @if (reviewingId() === c.id) {
              <textarea rows="2" [(ngModel)]="reviewNote" placeholder="Cosa va cambiato?"></textarea>
              <div class="actions">
                <button class="btn ghost" (click)="reviewingId.set(null)">Annulla</button>
                <button class="btn danger" (click)="reject(c)" [disabled]="!reviewNote.trim() || busyId() === c.id">Richiedi modifiche</button>
              </div>
            } @else if (editingId() !== c.id) {
              <div class="content-foot">
                <span class="muted small">{{ c.created_at | date: 'dd/MM/yyyy HH:mm' }}</span>
                <div class="actions">
                  <button class="btn ghost small" (click)="copy(c)">{{ copiedId() === c.id ? 'Copiato ✓' : 'Copia' }}</button>
                  @if (auth.canEdit() && (c.status === 'draft' || c.status === 'rejected')) {
                    <button class="btn ghost small" (click)="startEdit(c)">Modifica</button>
                    <button class="btn primary small" (click)="submit(c)" [disabled]="busyId() === c.id">Invia in revisione</button>
                  }
                  @if (auth.canManage() && c.status === 'in_review') {
                    <button class="btn ghost small" (click)="startReject(c)">Richiedi modifiche</button>
                    <button class="btn success small" (click)="approve(c)" [disabled]="busyId() === c.id">Approva</button>
                  }
                </div>
              </div>
            }
            @if (cardError()?.id === c.id) { <div class="alert">{{ cardError()?.message }}</div> }
          </article>
        } @empty {
          <div class="card empty">
            <h3>Nessun contenuto{{ activeFilter() === 'all' ? '' : ' in questo stato' }}</h3>
            <p class="muted">Usa il generatore qui sopra per creare le prime bozze.</p>
          </div>
        }
      </div>
    } @else if (!error()) {
      <p class="muted">Caricamento…</p>
    }
  `,
})
export class ProjectDetailComponent implements OnInit {
  readonly auth = inject(AuthService);
  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private destroyRef = inject(DestroyRef);

  readonly channelLabels = CHANNEL_LABELS;
  readonly contentStatusLabels = CONTENT_STATUS_LABELS;
  readonly projectStatusLabels = PROJECT_STATUS_LABELS;
  readonly channels = Object.entries(CHANNEL_LABELS) as [Channel, string][];
  readonly projectStatuses = Object.entries(PROJECT_STATUS_LABELS);
  readonly filters: [Filter, string][] = [
    ['all', 'Tutti'], ['draft', 'Bozze'], ['in_review', 'In revisione'], ['rejected', 'Da rivedere'], ['approved', 'Approvati'],
  ];

  project = signal<Project | null>(null);
  brands = signal<Brand[]>([]);
  brand = computed(() => this.brands().find((b) => b.id === this.project()?.brand_profile_id) ?? null);
  content = signal<ContentItem[]>([]);
  error = signal<string | null>(null);
  activeFilter = signal<Filter>('all');
  visibleContent = computed(() =>
    this.activeFilter() === 'all' ? this.content() : this.content().filter((c) => c.status === this.activeFilter()),
  );

  generating = signal(false);
  genError = signal<string | null>(null);
  busyId = signal<number | null>(null);
  editingId = signal<number | null>(null);
  reviewingId = signal<number | null>(null);
  copiedId = signal<number | null>(null);
  cardError = signal<{ id: number; message: string } | null>(null);
  editTitle = '';
  editBody = '';
  reviewNote = '';

  genForm = inject(FormBuilder).nonNullable.group({
    channel: ['instagram' as Channel, Validators.required],
    content_type: ['post', [Validators.required, Validators.maxLength(80)]],
    brief: ['', [Validators.required, Validators.maxLength(2000)]],
    variants: [3, [Validators.required, Validators.min(1), Validators.max(5)]],
  });

  private get projectId(): number {
    return Number(this.route.snapshot.paramMap.get('id'));
  }

  ngOnInit(): void {
    this.api.project(this.projectId).subscribe({
      next: (p) => this.project.set(p),
      error: (err) => this.error.set(apiErrorMessage(err, 'Progetto non trovato.')),
    });
    this.api.brands().subscribe((list) => this.brands.set(list));
    this.loadContent();
  }

  count(f: Filter): number {
    return f === 'all' ? this.content().length : this.content().filter((c) => c.status === f).length;
  }

  update(changes: { status?: string; brand_profile_id?: number | null }): void {
    this.api.updateProject(this.projectId, changes).subscribe({
      next: (p) => this.project.set(p),
      error: (err) => this.error.set(apiErrorMessage(err)),
    });
  }

  generate(): void {
    if (this.genForm.invalid) return;
    this.generating.set(true);
    this.genError.set(null);
    this.api
      .generate(this.projectId, this.genForm.getRawValue())
      .pipe(
        // Controlla lo stato del lavoro ogni 2 secondi finché non termina (al massimo 3 minuti).
        switchMap((job) => timer(1500, 2000).pipe(take(90), switchMap(() => this.api.job(job.id)))),
        filter((job) => job.status === 'done' || job.status === 'failed'),
        take(1),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (job) => {
          this.generating.set(false);
          if (job.status === 'failed') {
            this.genError.set(job.error ?? 'Generazione non riuscita.');
            return;
          }
          this.genForm.patchValue({ brief: '' });
          this.activeFilter.set('draft');
          this.loadContent();
        },
        error: (err) => {
          this.generating.set(false);
          this.genError.set(apiErrorMessage(err));
        },
        complete: () => {
          if (this.generating()) {
            this.generating.set(false);
            this.genError.set('La generazione sta richiedendo più del previsto. Ricarica la pagina tra poco.');
          }
        },
      });
  }

  startEdit(c: ContentItem): void {
    this.editingId.set(c.id);
    this.editTitle = c.title;
    this.editBody = c.body;
  }

  saveEdit(c: ContentItem): void {
    this.run(c, this.api.updateContent(c.id, { title: this.editTitle, body: this.editBody }), () => this.editingId.set(null));
  }

  submit(c: ContentItem): void {
    this.run(c, this.api.submitContent(c.id));
  }

  approve(c: ContentItem): void {
    this.run(c, this.api.approveContent(c.id));
  }

  startReject(c: ContentItem): void {
    this.reviewingId.set(c.id);
    this.reviewNote = '';
  }

  reject(c: ContentItem): void {
    this.run(c, this.api.rejectContent(c.id, this.reviewNote.trim()), () => this.reviewingId.set(null));
  }

  copy(c: ContentItem): void {
    navigator.clipboard?.writeText(`${c.title}\n\n${c.body}`).then(() => {
      this.copiedId.set(c.id);
      setTimeout(() => this.copiedId.set(null), 1500);
    });
  }

  private run(c: ContentItem, request: ReturnType<ApiService['submitContent']>, onDone?: () => void): void {
    this.busyId.set(c.id);
    this.cardError.set(null);
    request.subscribe({
      next: (updated) => {
        this.content.update((list) => list.map((x) => (x.id === updated.id ? updated : x)));
        this.busyId.set(null);
        onDone?.();
      },
      error: (err) => {
        this.cardError.set({ id: c.id, message: apiErrorMessage(err) });
        this.busyId.set(null);
      },
    });
  }

  private loadContent(): void {
    this.api.content(this.projectId).subscribe({
      next: (list) => this.content.set(list),
      error: (err) => this.error.set(apiErrorMessage(err)),
    });
  }
}
