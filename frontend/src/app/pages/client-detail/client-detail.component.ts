import { Component, DestroyRef, OnInit, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { finalize, switchMap } from 'rxjs';
import { AuthService } from '../../core/auth.service';
import { ApiService } from '../../core/api.service';
import { Asset, Brand, CHANNEL_LABELS, Channel, GoalSuggestion, Project, apiErrorMessage } from '../../core/models';
import { AssetPreviewComponent } from '../../shared/asset-preview.component';
import { BrandFormComponent } from '../../shared/brand-form.component';
import { MetaAdsSetupComponent } from '../../shared/meta-ads-setup.component';
import { CompetitorsPanelComponent } from '../../shared/competitors-panel.component';
import { CompetitionStudyComponent } from '../../shared/competition-study.component';
import { PortalPanelComponent } from '../../shared/portal-panel.component';
import { StylePanelComponent } from '../../shared/style-panel.component';
import { CHANNEL_ICONS } from '../../shared/post-card.component';
import { PROJECT_STATUS_LABELS } from '../projects/projects.component';

const CAMPAIGN_CHANNELS: Channel[] = ['instagram', 'facebook', 'linkedin', 'blog', 'email'];

@Component({
  selector: 'app-client-detail',
  standalone: true,
  imports: [FormsModule, DatePipe, RouterLink, AssetPreviewComponent, BrandFormComponent, MetaAdsSetupComponent, PortalPanelComponent, StylePanelComponent, CompetitorsPanelComponent, CompetitionStudyComponent],
  template: `
    <a routerLink="/clients" class="back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Clienti</a>

    @if (isNew()) {
      <div class="page-head"><div><h1>Nuovo cliente</h1><p class="muted">Compila il brand kit a mano, oppure torna indietro e analizza il suo sito.</p></div></div>
      <app-brand-form (saved)="created($event)" (cancelled)="router.navigate(['/clients'])" />
    } @else {
    @if (brand(); as b) {
      @if (notice()) {
        <div class="notice success"><i class="ti ti-circle-check" aria-hidden="true"></i> {{ notice() }}</div>
      } @else if (fromSite()) {
        <div class="notice success"><i class="ti ti-circle-check" aria-hidden="true"></i> Brand kit creato dal sito. Controllalo e correggi se serve, poi genera la prima campagna.</div>
      }

      @if (deleting()) {
        <div class="card danger-zone">
          <h2><i class="ti ti-alert-triangle" aria-hidden="true"></i> Eliminare {{ b.name }}?</h2>
          <p class="muted">Il brand kit viene eliminato definitivamente. Foto e file restano nella libreria.</p>
          @if (campaigns().length) {
            <label class="check-row">
              <input type="checkbox" [(ngModel)]="deleteCampaigns" />
              Elimina anche {{ campaigns().length === 1 ? 'la sua campagna' : 'le sue ' + campaigns().length + ' campagne' }} e tutti i post
            </label>
          }
          <div class="actions">
            <button class="btn ghost small" (click)="deleting.set(false)">Annulla</button>
            <button class="btn danger small" (click)="remove(b)">Elimina cliente</button>
          </div>
        </div>
      }

      <section class="client-hero">
        <div class="client-hero-band" [style.background]="b.primary_color ?? '#5B4BDB'"></div>
        <div class="client-hero-body">
          <span class="client-logo big">
            @if (b.logo_asset_id) { <app-asset-preview [assetId]="b.logo_asset_id" fit="contain" [alt]="'Logo ' + b.name" /> } @else { {{ b.name.charAt(0) }} }
          </span>
          <div class="client-hero-text">
            <h1>{{ b.name }}</h1>
            @if (b.summary) { <p class="muted">{{ b.summary }}</p> }
            <div class="swatches">
              @for (c of [b.primary_color, b.secondary_color]; track $index) {
                @if (c) { <span class="swatch-label"><span class="swatch" [style.background]="c"></span>{{ c }}</span> }
              }
              @if (b.font_family) { <span class="swatch-label"><i class="ti ti-typography" aria-hidden="true"></i>{{ b.font_family }}</span> }
              @if (b.website_url) { <a class="swatch-label" [href]="b.website_url" target="_blank" rel="noopener"><i class="ti ti-external-link" aria-hidden="true"></i>{{ b.website_url }}</a> }
              @if (b.instagram_url) { <a class="swatch-label" [href]="b.instagram_url" target="_blank" rel="noopener" title="Instagram"><i class="ti ti-brand-instagram" aria-hidden="true"></i>Instagram</a> }
              @if (b.facebook_url) { <a class="swatch-label" [href]="b.facebook_url" target="_blank" rel="noopener" title="Facebook"><i class="ti ti-brand-facebook" aria-hidden="true"></i>Facebook</a> }
              @if (b.linkedin_url) { <a class="swatch-label" [href]="b.linkedin_url" target="_blank" rel="noopener" title="LinkedIn"><i class="ti ti-brand-linkedin" aria-hidden="true"></i>LinkedIn</a> }
            </div>
          </div>
          @if (auth.canEdit() && !editing()) {
            <div class="actions">
              @if (auth.canManage()) { <button class="btn ghost small" (click)="deleting.set(true)"><i class="ti ti-trash" aria-hidden="true"></i> Elimina</button> }
              @if (b.website_url) {
                <button class="btn ghost small" (click)="reanalyze(b)" [disabled]="reanalyzing()">
                  @if (reanalyzing()) { <span class="spinner small"></span> Analisi in corso… } @else { <i class="ti ti-refresh" aria-hidden="true"></i> Rianalizza sito }
                </button>
              }
              <button class="btn ghost small" (click)="editing.set(true)"><i class="ti ti-pencil" aria-hidden="true"></i> Modifica</button>
            </div>
          }
        </div>
      </section>

      @if (editing()) {
        <app-brand-form [brand]="b" (saved)="brand.set($event); editing.set(false)" (cancelled)="editing.set(false)" />
      } @else {
        <div class="two-col">
          <div class="side-col">
            <section class="card kit">
              <h2>Brand kit</h2>
              @for (f of kitFields; track f[0]) {
                <div class="kit-row">
                  <p class="kit-label">{{ f[1] }}</p>
                  <p class="kit-value" [class.muted]="!b[f[0]]">{{ b[f[0]] || 'Non indicato' }}</p>
                </div>
              }
            </section>

            <section class="card">
              <div class="strategy-head">
                <h2><i class="ti ti-photo" aria-hidden="true"></i> Foto del cliente</h2>
                @if (auth.canEdit()) {
                  <label class="btn ghost small upload-btn">
                    @if (uploading()) { <span class="spinner small"></span> } @else { <i class="ti ti-upload" aria-hidden="true"></i> }
                    Carica
                    <input type="file" multiple hidden accept="image/png,image/jpeg,image/webp,image/gif" (change)="upload($event, b)" />
                  </label>
                }
              </div>
              <p class="muted small">Le foto finiscono a rotazione nei post con stile "Foto" e "Foto e colore".</p>
              <div class="photo-grid">
                @for (a of photos(); track a.id) {
                  <div class="photo-thumb" [class.is-logo]="a.id === b.logo_asset_id" [title]="a.original_name">
                    <app-asset-preview [assetId]="a.id" [alt]="a.original_name" [fit]="a.id === b.logo_asset_id ? 'contain' : 'cover'" />
                    @if (a.id === b.logo_asset_id) { <span class="tag">Logo</span> }
                  </div>
                } @empty {
                  <p class="muted small">Nessuna foto: caricale qui oppure rianalizza il sito.</p>
                }
              </div>
            </section>
          </div>

          <div class="side-col">
            @if (auth.canEdit()) {
              <form class="card campaign-form" (ngSubmit)="generate(b)">
                <h2><i class="ti ti-sparkles" aria-hidden="true"></i> Nuova campagna</h2>
                <p class="muted small">L'AI prepara strategia, calendario e post con grafica.</p>
                @if (error()) { <div class="alert">{{ error() }}</div> }
                <div class="goal-field">
                  <div class="goal-head">
                    <span class="field-label">Obiettivo</span>
                    <button type="button" class="link-btn" (click)="suggest(b)" [disabled]="suggesting()">
                      @if (suggesting()) { <span class="spinner small"></span> Sto pensando… } @else { <i class="ti ti-bulb" aria-hidden="true"></i> Suggerisci con l'AI }
                    </button>
                  </div>
                  <textarea rows="3" [(ngModel)]="goal" name="goal" aria-label="Obiettivo" placeholder="Aumentare le richieste di preventivo per i prodotti principali"></textarea>
                  @if (suggestions().length) {
                    <div class="goal-suggestions">
                      @for (s of suggestions(); track s.title) {
                        <button type="button" class="goal-card" [class.on]="goal === s.goal" (click)="goal = s.goal">
                          <strong>{{ s.title }}</strong>
                          <span>{{ s.goal }}</span>
                        </button>
                      }
                    </div>
                  }
                </div>
                <div class="edit-grid">
                  <label>Durata
                    <select [(ngModel)]="weeks" name="weeks">
                      @for (w of [1, 2, 3, 4]; track w) { <option [ngValue]="w">{{ w }} {{ w === 1 ? 'settimana' : 'settimane' }}</option> }
                    </select>
                  </label>
                  <label>Post a settimana
                    <select [(ngModel)]="perWeek" name="perWeek">
                      @for (n of [1, 2, 3, 4, 5]; track n) { <option [ngValue]="n" [disabled]="n * weeks > 16">{{ n }}</option> }
                    </select>
                  </label>
                </div>
                <label>Inizio<input type="date" [(ngModel)]="startDate" name="startDate" /></label>
                <fieldset class="channel-picks">
                  <legend>Canali</legend>
                  @for (c of channels; track c) {
                    <label class="channel-pick" [class.on]="selected().includes(c)">
                      <input type="checkbox" [checked]="selected().includes(c)" (change)="toggle(c)" />
                      <i class="ti" [class]="'ti ' + icons[c]" aria-hidden="true"></i>{{ channelLabels[c] }}
                    </label>
                  }
                </fieldset>
                <button class="btn primary full-width" type="submit" [disabled]="!goal.trim() || selected().length === 0 || weeks * perWeek > 16 || generating()">
                  {{ generating() ? 'Avvio…' : 'Genera ' + weeks * perWeek + ' post' }}
                </button>
              </form>
            }

            <section class="card">
              <h2>Campagne</h2>
              @for (p of campaigns(); track p.id) {
                <div class="campaign-row">
                  <a [routerLink]="['/campaigns', p.id]"><strong>{{ p.name }}</strong><small class="muted">{{ p.created_at | date: 'd MMM yyyy' }} · {{ p.content_total ?? 0 }} contenuti</small></a>
                  <span class="badge" [attr.data-status]="p.status">{{ statusLabels[p.status] }}</span>
                  @if (auth.canManage()) {
                    <button class="icon-btn danger" (click)="removeCampaign(p)" [attr.aria-label]="'Elimina ' + p.name" title="Elimina campagna"><i class="ti ti-trash" aria-hidden="true"></i></button>
                  }
                </div>
              } @empty {
                <p class="muted small">Nessuna campagna ancora.</p>
              }
            </section>
          </div>
        </div>
        <div class="growth-grid">
          <app-portal-panel [brandId]="b.id" />
          <app-style-panel [brandId]="b.id" />
        </div>
        <app-competitors-panel [brandId]="b.id" [campaigns]="campaigns()" (changed)="study.reload()" />
        <app-competition-study #study [brand]="b" (brandChanged)="reloadBrand(b.id)" />
        <app-meta-ads-setup [brandId]="b.id" />
      }
    } @else if (error()) {
      <div class="alert">{{ error() }}</div>
    } @else {
      <p class="muted">Caricamento…</p>
    }
    }
  `,
})
export class ClientDetailComponent implements OnInit {
  readonly auth = inject(AuthService);
  readonly router = inject(Router);
  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private destroyRef = inject(DestroyRef);

  readonly channels = CAMPAIGN_CHANNELS;
  readonly channelLabels = CHANNEL_LABELS;
  readonly icons = CHANNEL_ICONS;
  readonly statusLabels = PROJECT_STATUS_LABELS;
  readonly kitFields: [keyof Brand, string][] = [
    ['industry', 'Settore'], ['audience', 'Pubblico'], ['tone_of_voice', 'Tono di voce'], ['products', 'Prodotti e servizi'],
    ['keywords', 'Parole chiave'], ['words_to_avoid', 'Parole da evitare'], ['brand_guidelines', 'Linee guida'],
  ];

  brand = signal<Brand | null>(null);
  projects = signal<Project[]>([]);
  campaigns = computed(() => this.projects().filter((p) => p.brand_profile_id === this.brand()?.id));
  editing = signal(false);
  generating = signal(false);
  error = signal<string | null>(null);
  notice = signal<string | null>(null);
  selected = signal<Channel[]>(['instagram', 'facebook']);
  photos = signal<Asset[]>([]);
  uploading = signal(false);
  reanalyzing = signal(false);
  suggesting = signal(false);
  suggestions = signal<GoalSuggestion[]>([]);
  deleting = signal(false);
  deleteCampaigns = true;

  goal = '';
  weeks = 4;
  perWeek = 3;
  startDate = this.tomorrow();

  isNew = () => this.route.snapshot.paramMap.get('id') === 'new';
  fromSite = () => this.route.snapshot.queryParamMap.has('nuovo');

  ngOnInit(): void {
    if (this.isNew()) return;
    const id = Number(this.route.snapshot.paramMap.get('id'));
    this.api.brand(id).subscribe({
      next: (b) => {
        this.brand.set(b);
        // Dagli avvisi "nuova richiesta" si arriva direttamente al portale.
        if (this.route.snapshot.queryParamMap.get('tab') === 'portal') setTimeout(() => document.getElementById('portal')?.scrollIntoView({ behavior: 'smooth' }), 300);
      },
      error: (err) => this.error.set(apiErrorMessage(err, 'Cliente non trovato.')),
    });
    this.api.projects().subscribe((list) => this.projects.set(list));
    this.loadPhotos(id);
  }

  /** Dopo che lo stile proposto è stato applicato o annullato. */
  reloadBrand(id: number): void {
    this.api.brand(id).subscribe((b) => this.brand.set(b));
  }

  /** Rilegge il sito e aggiorna brand kit, colori e foto. */
  reanalyze(brand: Brand): void {
    this.reanalyzing.set(true);
    this.error.set(null);
    this.notice.set(null);
    this.api
      .reanalyzeBrand(brand.id)
      .pipe(switchMap((job) => this.api.waitForJob(job.id)), finalize(() => this.reanalyzing.set(false)), takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (job) => {
          if (job.status === 'failed') {
            this.error.set(job.error ?? 'Analisi non riuscita.');
            return;
          }
          const photos = job.result?.photos_imported ?? 0;
          this.notice.set(`Brand kit aggiornato dal sito${photos ? `, ${photos} ${photos === 1 ? 'foto importata' : 'foto importate'}` : ''}.`);
          this.api.brand(brand.id).subscribe((b) => this.brand.set(b));
          this.loadPhotos(brand.id);
        },
        error: (err) => this.error.set(apiErrorMessage(err)),
      });
  }

  suggest(brand: Brand): void {
    this.suggesting.set(true);
    this.error.set(null);
    this.api
      .suggestGoals(brand.id)
      .pipe(switchMap((job) => this.api.waitForJob(job.id)), finalize(() => this.suggesting.set(false)), takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (job) => {
          if (job.status === 'failed') this.error.set(job.error ?? 'Suggerimenti non disponibili.');
          else this.suggestions.set(job.result?.goals ?? []);
        },
        error: (err) => this.error.set(apiErrorMessage(err)),
      });
  }

  upload(event: Event, brand: Brand): void {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    input.value = '';
    if (!files.length) return;
    this.uploading.set(true);
    let pending = files.length;
    for (const file of files) {
      this.api
        .uploadAsset(file, brand.id)
        .pipe(finalize(() => { if (--pending === 0) this.uploading.set(false); }))
        .subscribe({
          next: (asset) => this.photos.update((list) => [asset, ...list]),
          error: (err) => this.error.set(`${file.name}: ${apiErrorMessage(err)}`),
        });
    }
  }

  removeCampaign(project: Project): void {
    if (!confirm(`Eliminare la campagna "${project.name}" e tutti i suoi ${project.content_total ?? 0} contenuti?`)) return;
    this.api.deleteProject(project.id).subscribe({
      next: () => this.projects.update((list) => list.filter((p) => p.id !== project.id)),
      error: (err) => this.error.set(apiErrorMessage(err)),
    });
  }

  private loadPhotos(brandId: number): void {
    this.api.assets(brandId).subscribe((list) => this.photos.set(list.filter((a) => a.kind === 'image')));
  }

  created(brand: Brand): void {
    this.router.navigate(['/clients', brand.id]);
  }

  toggle(channel: Channel): void {
    this.selected.update((list) => (list.includes(channel) ? list.filter((c) => c !== channel) : [...list, channel]));
  }

  generate(brand: Brand): void {
    this.generating.set(true);
    this.error.set(null);
    this.api
      .createCampaign(brand.id, { goal: this.goal.trim(), weeks: this.weeks, posts_per_week: this.perWeek, channels: this.selected(), start_date: this.startDate })
      .subscribe({
        next: ({ project, job }) => this.router.navigate(['/campaigns', project.id], { queryParams: { job: job.id } }),
        error: (err) => {
          this.generating.set(false);
          this.error.set(apiErrorMessage(err));
        },
      });
  }

  remove(brand: Brand): void {
    this.api.deleteBrand(brand.id, this.deleteCampaigns && this.campaigns().length > 0).subscribe({
      next: () => this.router.navigate(['/clients']),
      error: (err) => this.error.set(apiErrorMessage(err)),
    });
  }

  private tomorrow(): string {
    const d = new Date();
    d.setDate(d.getDate() + 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }
}
