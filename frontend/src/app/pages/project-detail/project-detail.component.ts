import { Component, DestroyRef, OnDestroy, OnInit, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { switchMap } from 'rxjs';
import { AuthService } from '../../core/auth.service';
import { ApiService } from '../../core/api.service';
import {
  Asset, Brand, CHANNEL_LABELS, Channel, ContentItem, ContentStatus, Project, apiErrorMessage,
} from '../../core/models';
import { AssetPreviewComponent } from '../../shared/asset-preview.component';
import { PostCardComponent } from '../../shared/post-card.component';
import { PROJECT_STATUS_LABELS } from '../projects/projects.component';

type Filter = 'all' | ContentStatus;
const CAMPAIGN_STEPS = ['Definisco la strategia', 'Pianifico il calendario', 'Scrivo i post', 'Preparo le grafiche'];

@Component({
  selector: 'app-project-detail',
  standalone: true,
  imports: [ReactiveFormsModule, FormsModule, RouterLink, AssetPreviewComponent, PostCardComponent],
  template: `
    <a routerLink="/campaigns" class="back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Campagne</a>

    @if (error()) { <div class="alert">{{ error() }}</div> }

    @if (project(); as p) {
      <div class="page-head">
        <div class="campaign-title">
          @if (brand(); as b) {
            <a class="client-logo" [routerLink]="['/clients', b.id]" [style.background]="b.primary_color ?? '#5B4BDB'" [title]="b.name">
              @if (b.logo_asset_id) { <app-asset-preview [assetId]="b.logo_asset_id" fit="contain" /> } @else { {{ b.name.charAt(0) }} }
            </a>
          }
          <div>
            <h1>{{ p.name }}</h1>
            <p class="muted">{{ brand()?.name ?? 'Nessun cliente' }} · {{ p.objective }}</p>
          </div>
        </div>
        @if (auth.canEdit()) {
          <div class="head-controls">
            <select [ngModel]="p.brand_profile_id" (ngModelChange)="update({ brand_profile_id: $event })" aria-label="Cliente della campagna">
              <option [ngValue]="null">— Nessun cliente —</option>
              @for (b of brands(); track b.id) { <option [ngValue]="b.id">{{ b.name }}</option> }
            </select>
            <select [ngModel]="p.status" (ngModelChange)="update({ status: $event })" aria-label="Stato della campagna">
              @for (s of projectStatuses; track s[0]) { <option [value]="s[0]">{{ s[1] }}</option> }
            </select>
            @if (auth.canManage()) {
              <button class="icon-btn danger" (click)="removeCampaign(p)" aria-label="Elimina campagna" title="Elimina campagna"><i class="ti ti-trash" aria-hidden="true"></i></button>
            }
          </div>
        }
      </div>

      @if (planning()) {
        <section class="analyzer">
          <div class="analyzer-head">
            <span class="analyzer-icon"><i class="ti ti-sparkles" aria-hidden="true"></i></span>
            <div><h2>L'AI sta preparando la campagna</h2><p class="muted">Di solito serve meno di un minuto. Puoi restare su questa pagina.</p></div>
          </div>
          <ol class="steps">
            @for (step of steps; track step; let i = $index) {
              <li [class.done]="i < step_()" [class.active]="i === step_()">
                <span class="step-dot">@if (i < step_()) { <i class="ti ti-check" aria-hidden="true"></i> } @else if (i === step_()) { <span class="spinner"></span> }</span>
                {{ step }}
              </li>
            }
          </ol>
        </section>
      }

      @if (p.strategy; as s) {
        <section class="card strategy">
          <div class="strategy-head">
            <h2><i class="ti ti-target-arrow" aria-hidden="true"></i> Strategia</h2>
            <button class="link-btn" (click)="showStrategy.set(!showStrategy())">{{ showStrategy() ? 'Nascondi' : 'Mostra tutto' }}</button>
          </div>
          <p class="strategy-summary">{{ s.summary }}</p>
          <div class="pillars">
            @for (pillar of s.content_pillars; track pillar) { <span class="tag">{{ pillar }}</span> }
          </div>
          @if (showStrategy()) {
            <div class="strategy-grid">
              <div><p class="kit-label">Pubblico</p><p>{{ s.audience }}</p></div>
              <div><p class="kit-label">Posizionamento</p><p>{{ s.positioning }}</p></div>
              <div><p class="kit-label">Messaggi chiave</p><ul>@for (m of s.key_messages; track m) { <li>{{ m }}</li> }</ul></div>
              <div><p class="kit-label">Uso dei canali</p><p>{{ s.channel_plan }}</p></div>
            </div>
          }
        </section>
      }

      <div class="section-head">
        <div class="filters">
          @for (f of filters; track f[0]) {
            <button class="chip" [class.active]="activeFilter() === f[0]" (click)="activeFilter.set(f[0])">
              {{ f[1] }} <span class="muted">{{ count(f[0]) }}</span>
            </button>
          }
        </div>
        @if (auth.canEdit()) {
          <button class="btn ghost small" (click)="showGenerator.set(!showGenerator())"><i class="ti ti-plus" aria-hidden="true"></i> Aggiungi contenuti</button>
        }
      </div>

      @if (showGenerator()) {
        <form class="card form-grid generate" [formGroup]="genForm" (ngSubmit)="generate()">
          <div class="full"><h3>Varianti di un singolo contenuto</h3><p class="muted small">Descrivi cosa vuoi comunicare: l'AI propone più versioni da scegliere.</p></div>
          @if (genError()) { <div class="alert full">{{ genError() }}</div> }
          <label>Canale
            <select formControlName="channel">
              @for (c of channels; track c[0]) { <option [value]="c[0]">{{ c[1] }}</option> }
            </select>
          </label>
          <label>Tipo<input formControlName="content_type" placeholder="post, carosello, reel, articolo" /></label>
          <label class="full">Brief *<textarea rows="3" formControlName="brief" placeholder="Lancio della torta zucca e castagne, disponibile fino a fine novembre."></textarea></label>
          <label>Varianti<input type="number" min="1" max="5" formControlName="variants" /></label>
          <div class="full">
            <div class="field-label">Foto per la grafica <span class="muted small">(facoltativa)</span></div>
            <div class="photo-picker">
              <button type="button" class="pick-tile upload" (click)="genPhotoInput.click()" [disabled]="uploadingGenPhoto()" title="Carica una foto dal computer">
                @if (uploadingGenPhoto()) { <span class="spinner small"></span> } @else { <i class="ti ti-upload" aria-hidden="true"></i> }
                <span>Carica</span>
              </button>
              <button type="button" class="pick-tile" [class.on]="!genForm.value.asset_id" (click)="genForm.patchValue({ asset_id: null })" title="Nessuna foto">
                <i class="ti ti-photo-off" aria-hidden="true"></i><span>Nessuna</span>
              </button>
              @for (a of clientPhotos().slice(0, 10); track a.id) {
                <button type="button" class="pick-tile thumb" [class.on]="genForm.value.asset_id === a.id" (click)="genForm.patchValue({ asset_id: a.id })" [title]="a.original_name">
                  <app-asset-preview [assetId]="a.id" [alt]="a.original_name" />
                </button>
              }
            </div>
            <input #genPhotoInput type="file" hidden accept="image/png,image/jpeg,image/webp,image/gif" (change)="uploadGenPhoto($event)" />
          </div>
          <div class="actions bottom">
            <button class="btn primary" type="submit" [disabled]="genForm.invalid || generating()">{{ generating() ? 'Generazione in corso…' : 'Genera bozze' }}</button>
          </div>
        </form>
      }

      <div class="feed">
        @for (c of visibleContent(); track c.id) {
          <app-post-card [item]="c" [brand]="brand()" [assets]="assets()" (changed)="replace($event)" (removed)="removePost($event)" (added)="loadContent()" />
        } @empty {
          @if (!planning()) {
            <div class="card empty">
              <i class="ti ti-layout-grid empty-icon" aria-hidden="true"></i>
              <h3>Nessun contenuto{{ activeFilter() === 'all' ? '' : ' in questo stato' }}</h3>
              <p class="muted">Genera una campagna dalla scheda del cliente o aggiungi contenuti singoli.</p>
            </div>
          }
        }
      </div>
    } @else if (!error()) {
      <p class="muted">Caricamento…</p>
    }
  `,
})
export class ProjectDetailComponent implements OnInit, OnDestroy {
  readonly auth = inject(AuthService);
  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private destroyRef = inject(DestroyRef);

  readonly steps = CAMPAIGN_STEPS;
  readonly channels = Object.entries(CHANNEL_LABELS) as [Channel, string][];
  readonly projectStatuses = Object.entries(PROJECT_STATUS_LABELS);
  readonly filters: [Filter, string][] = [
    ['all', 'Tutti'], ['draft', 'Bozze'], ['in_review', 'In revisione'], ['rejected', 'Da rivedere'], ['approved', 'Approvati'], ['published', 'Pubblicati'],
  ];

  project = signal<Project | null>(null);
  brands = signal<Brand[]>([]);
  brand = computed(() => this.brands().find((b) => b.id === this.project()?.brand_profile_id) ?? null);
  assets = signal<Asset[]>([]);
  content = signal<ContentItem[]>([]);
  error = signal<string | null>(null);
  activeFilter = signal<Filter>('all');
  visibleContent = computed(() =>
    this.activeFilter() === 'all' ? this.content() : this.content().filter((c) => c.status === this.activeFilter()),
  );
  planning = signal(false);
  step_ = signal(0);
  showStrategy = signal(false);
  showGenerator = signal(false);
  generating = signal(false);
  genError = signal<string | null>(null);
  private ticker?: ReturnType<typeof setInterval>;

  genForm = inject(FormBuilder).nonNullable.group({
    channel: ['instagram' as Channel, Validators.required],
    content_type: ['post', [Validators.required, Validators.maxLength(80)]],
    brief: ['', [Validators.required, Validators.maxLength(2000)]],
    variants: [3, [Validators.required, Validators.min(1), Validators.max(5)]],
    asset_id: [null as number | null],
  });

  uploadingGenPhoto = signal(false);
  /** Foto della libreria, con prima quelle del cliente della campagna (logo escluso). */
  clientPhotos = computed(() => {
    const brand = this.brand();
    const images = this.assets().filter((a) => a.kind === 'image' && a.id !== brand?.logo_asset_id);
    return [...images.filter((a) => a.brand_profile_id === brand?.id), ...images.filter((a) => a.brand_profile_id !== brand?.id)];
  });

  uploadGenPhoto(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    this.uploadingGenPhoto.set(true);
    this.api.uploadAsset(file, this.brand()?.id).subscribe({
      next: (asset) => {
        this.uploadingGenPhoto.set(false);
        this.assets.update((list) => [asset, ...list]);
        this.genForm.patchValue({ asset_id: asset.id });
      },
      error: (err) => {
        this.uploadingGenPhoto.set(false);
        this.genError.set(apiErrorMessage(err));
      },
    });
  }

  private get projectId(): number {
    return Number(this.route.snapshot.paramMap.get('id'));
  }

  ngOnInit(): void {
    this.loadProject();
    this.api.brands().subscribe((list) => this.brands.set(list));
    this.api.assets().subscribe((list) => this.assets.set(list));
    this.loadContent();

    const jobId = Number(this.route.snapshot.queryParamMap.get('job'));
    if (jobId) this.followPlanning(jobId);
  }

  ngOnDestroy(): void {
    clearInterval(this.ticker);
  }

  count(f: Filter): number {
    return f === 'all' ? this.content().length : this.content().filter((c) => c.status === f).length;
  }

  replace(updated: ContentItem): void {
    this.content.update((list) => list.map((c) => (c.id === updated.id ? updated : c)));
  }

  removePost(id: number): void {
    this.content.update((list) => list.filter((c) => c.id !== id));
  }

  removeCampaign(project: Project): void {
    if (!confirm(`Eliminare la campagna "${project.name}" e tutti i suoi ${this.content().length} contenuti? L'operazione non si può annullare.`)) return;
    this.api.deleteProject(project.id).subscribe({
      next: () => this.router.navigate(project.brand_profile_id ? ['/clients', project.brand_profile_id] : ['/campaigns']),
      error: (err) => this.error.set(apiErrorMessage(err)),
    });
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
      .pipe(switchMap((job) => this.api.waitForJob(job.id)), takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (job) => {
          this.generating.set(false);
          if (job.status === 'failed') {
            this.genError.set(job.error ?? 'Generazione non riuscita.');
            return;
          }
          this.genForm.patchValue({ brief: '' });
          this.showGenerator.set(false);
          this.activeFilter.set('draft');
          this.loadContent();
        },
        error: (err) => {
          this.generating.set(false);
          this.genError.set(apiErrorMessage(err));
        },
      });
  }

  private followPlanning(jobId: number): void {
    this.planning.set(true);
    this.ticker = setInterval(() => this.step_.update((s) => Math.min(s + 1, CAMPAIGN_STEPS.length - 1)), 6000);
    this.api.waitForJob(jobId).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (job) => {
        clearInterval(this.ticker);
        this.planning.set(false);
        this.router.navigate([], { queryParams: {}, replaceUrl: true });
        if (job.status === 'failed') {
          this.error.set(job.error ?? 'Pianificazione della campagna non riuscita.');
          return;
        }
        this.loadProject();
        this.loadContent();
      },
      error: (err) => {
        clearInterval(this.ticker);
        this.planning.set(false);
        this.error.set(apiErrorMessage(err));
      },
    });
  }

  private loadProject(): void {
    this.api.project(this.projectId).subscribe({
      next: (p) => this.project.set(p),
      error: (err) => this.error.set(apiErrorMessage(err, 'Campagna non trovata.')),
    });
  }

  loadContent(): void {
    this.api.content(this.projectId).subscribe({
      next: (list) => this.content.set(list),
      error: (err) => this.error.set(apiErrorMessage(err)),
    });
  }
}
