import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { ApiService } from '../../core/api.service';
import { Brand, CHANNEL_LABELS, Channel, Project, apiErrorMessage } from '../../core/models';
import { AssetPreviewComponent } from '../../shared/asset-preview.component';
import { BrandFormComponent } from '../../shared/brand-form.component';
import { CHANNEL_ICONS } from '../../shared/post-card.component';
import { PROJECT_STATUS_LABELS } from '../projects/projects.component';

const CAMPAIGN_CHANNELS: Channel[] = ['instagram', 'facebook', 'linkedin', 'blog', 'email'];

@Component({
  selector: 'app-client-detail',
  standalone: true,
  imports: [FormsModule, DatePipe, RouterLink, AssetPreviewComponent, BrandFormComponent],
  template: `
    <a routerLink="/clients" class="back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Clienti</a>

    @if (isNew()) {
      <div class="page-head"><div><h1>Nuovo cliente</h1><p class="muted">Compila il brand kit a mano, oppure torna indietro e analizza il suo sito.</p></div></div>
      <app-brand-form (saved)="created($event)" (cancelled)="router.navigate(['/clients'])" />
    } @else {
    @if (brand(); as b) {
      @if (fromSite()) {
        <div class="notice success"><i class="ti ti-circle-check" aria-hidden="true"></i> Brand kit creato dal sito. Controllalo e correggi se serve, poi genera la prima campagna.</div>
      }

      <section class="client-hero">
        <div class="client-hero-band" [style.background]="b.primary_color ?? '#5B4BDB'"></div>
        <div class="client-hero-body">
          <span class="client-logo big">
            @if (b.logo_asset_id) { <app-asset-preview [assetId]="b.logo_asset_id" [alt]="'Logo ' + b.name" /> } @else { {{ b.name.charAt(0) }} }
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
            </div>
          </div>
          @if (auth.canEdit() && !editing()) {
            <div class="actions">
              @if (auth.canManage()) { <button class="btn ghost small" (click)="remove(b)">Elimina</button> }
              <button class="btn ghost small" (click)="editing.set(true)"><i class="ti ti-pencil" aria-hidden="true"></i> Modifica</button>
            </div>
          }
        </div>
      </section>

      @if (editing()) {
        <app-brand-form [brand]="b" (saved)="brand.set($event); editing.set(false)" (cancelled)="editing.set(false)" />
      } @else {
        <div class="two-col">
          <section class="card kit">
            <h2>Brand kit</h2>
            @for (f of kitFields; track f[0]) {
              <div class="kit-row">
                <p class="kit-label">{{ f[1] }}</p>
                <p class="kit-value" [class.muted]="!b[f[0]]">{{ b[f[0]] || 'Non indicato' }}</p>
              </div>
            }
          </section>

          <div class="side-col">
            @if (auth.canEdit()) {
              <form class="card campaign-form" (ngSubmit)="generate(b)">
                <h2><i class="ti ti-sparkles" aria-hidden="true"></i> Nuova campagna</h2>
                <p class="muted small">L'AI prepara strategia, calendario e post con grafica.</p>
                @if (error()) { <div class="alert">{{ error() }}</div> }
                <label>Obiettivo<textarea rows="2" [(ngModel)]="goal" name="goal" placeholder="Far conoscere le torte autunnali e aumentare gli ordini"></textarea></label>
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
                <a class="campaign-row" [routerLink]="['/campaigns', p.id]">
                  <span><strong>{{ p.name }}</strong><small class="muted">{{ p.created_at | date: 'd MMM yyyy' }} · {{ p.content_total ?? 0 }} contenuti</small></span>
                  <span class="badge" [attr.data-status]="p.status">{{ statusLabels[p.status] }}</span>
                </a>
              } @empty {
                <p class="muted small">Nessuna campagna ancora.</p>
              }
            </section>
          </div>
        </div>
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
  selected = signal<Channel[]>(['instagram', 'facebook']);

  goal = '';
  weeks = 4;
  perWeek = 3;
  startDate = this.tomorrow();

  isNew = () => this.route.snapshot.paramMap.get('id') === 'new';
  fromSite = () => this.route.snapshot.queryParamMap.has('nuovo');

  ngOnInit(): void {
    if (this.isNew()) return;
    const id = Number(this.route.snapshot.paramMap.get('id'));
    this.api.brand(id).subscribe({ next: (b) => this.brand.set(b), error: (err) => this.error.set(apiErrorMessage(err, 'Cliente non trovato.')) });
    this.api.projects().subscribe((list) => this.projects.set(list));
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
    if (!confirm(`Eliminare il cliente "${brand.name}"? Le campagne resteranno senza brand.`)) return;
    this.api.deleteBrand(brand.id).subscribe({
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
