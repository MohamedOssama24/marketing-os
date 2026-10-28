import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { AuthService } from '../../core/auth.service';
import { ApiService } from '../../core/api.service';
import { Asset, Brand, BrandInput, apiErrorMessage } from '../../core/models';
import { AssetPreviewComponent } from '../../shared/asset-preview.component';

const HEX = /^#[0-9a-fA-F]{6}$/;

@Component({
  selector: 'app-brands',
  standalone: true,
  imports: [ReactiveFormsModule, AssetPreviewComponent],
  template: `
    <div class="page-head">
      <div>
        <h1>Brand kit</h1>
        <p class="muted">Identità di ogni cliente: l'AI usa queste informazioni per scrivere con la sua voce.</p>
      </div>
      @if (auth.canEdit() && editing() === null) {
        <button class="btn primary" (click)="openForm(null)">+ Nuovo brand</button>
      }
    </div>

    @if (editing() !== null) {
      <form class="card form-grid" [formGroup]="form" (ngSubmit)="save()">
        <h3 class="full">{{ editing() === 'new' ? 'Nuovo brand' : 'Modifica brand' }}</h3>
        @if (formError()) { <div class="alert full">{{ formError() }}</div> }

        <label>Nome *<input formControlName="name" placeholder="Es. Pasticceria Rossi" /></label>
        <label>Sito web<input formControlName="website_url" placeholder="https://..." /></label>
        <label>Settore<input formControlName="industry" placeholder="Es. Alimentare, e-commerce" /></label>
        <label>Font<input formControlName="font_family" placeholder="Es. Montserrat" /></label>

        <label>Colore principale
          <div class="color-field">
            <input type="color" [value]="colorOr('primary_color', '#5B4BDB')" (input)="setColor('primary_color', $event)" />
            <input formControlName="primary_color" placeholder="#5B4BDB" />
          </div>
        </label>
        <label>Colore secondario
          <div class="color-field">
            <input type="color" [value]="colorOr('secondary_color', '#F5F6FA')" (input)="setColor('secondary_color', $event)" />
            <input formControlName="secondary_color" placeholder="#F5F6FA" />
          </div>
        </label>

        <label class="full">Logo
          <select formControlName="logo_asset_id">
            <option [ngValue]="null">— Nessun logo —</option>
            @for (a of images(); track a.id) { <option [ngValue]="a.id">{{ a.original_name }}</option> }
          </select>
          @if (images().length === 0) { <small class="muted">Carica prima il logo nella Libreria file.</small> }
        </label>

        <label class="full">Pubblico<textarea rows="2" formControlName="audience" placeholder="Chi sono i clienti ideali? Età, interessi, bisogni."></textarea></label>
        <label class="full">Tono di voce<textarea rows="2" formControlName="tone_of_voice" placeholder="Es. Caldo e familiare, dà del tu, frasi brevi, niente tecnicismi."></textarea></label>
        <label class="full">Prodotti e servizi<textarea rows="3" formControlName="products" placeholder="Elenca prodotti, servizi, punti di forza e prezzi reali."></textarea></label>
        <label>Parole chiave<textarea rows="2" formControlName="keywords" placeholder="artigianale, ingredienti locali, ..."></textarea></label>
        <label>Parole da evitare<textarea rows="2" formControlName="words_to_avoid" placeholder="economico, industriale, ..."></textarea></label>
        <label class="full">Linee guida<textarea rows="3" formControlName="brand_guidelines" placeholder="Regole di comunicazione, claim, cose da non dire mai."></textarea></label>

        <div class="full actions">
          <button class="btn ghost" type="button" (click)="editing.set(null)">Annulla</button>
          <button class="btn primary" type="submit" [disabled]="form.invalid || saving()">{{ saving() ? 'Salvataggio…' : 'Salva brand' }}</button>
        </div>
      </form>
    }

    @if (error()) { <div class="alert">{{ error() }}</div> }

    <div class="grid">
      @for (b of brands(); track b.id) {
        <article class="card brand-card">
          <div class="brand-card-head">
            <div class="brand-logo">
              @if (b.logo_asset_id) {
                <app-asset-preview [assetId]="b.logo_asset_id" [alt]="'Logo ' + b.name" />
              } @else {
                <span>{{ b.name.charAt(0) }}</span>
              }
            </div>
            <div>
              <h3>{{ b.name }}</h3>
              <p class="muted small">{{ b.industry ?? 'Settore non indicato' }}</p>
            </div>
          </div>
          <div class="swatches">
            @for (c of [b.primary_color, b.secondary_color]; track $index) {
              @if (c) { <span class="swatch" [style.background]="c" [title]="c"></span> }
            }
            @if (b.font_family) { <span class="muted small">{{ b.font_family }}</span> }
          </div>
          @if (b.tone_of_voice) { <p class="small clamp">🗣️ {{ b.tone_of_voice }}</p> }
          @if (auth.canEdit()) {
            <div class="actions">
              @if (auth.canManage()) { <button class="btn ghost small" (click)="remove(b)">Elimina</button> }
              <button class="btn ghost small" (click)="openForm(b)">Modifica</button>
            </div>
          }
        </article>
      } @empty {
        <div class="card empty">
          <h3>Nessun brand ancora</h3>
          <p class="muted">Crea il brand kit del tuo primo cliente: logo, colori, tono di voce e prodotti.</p>
        </div>
      }
    </div>
  `,
})
export class BrandsComponent implements OnInit {
  readonly auth = inject(AuthService);
  private api = inject(ApiService);

  brands = signal<Brand[]>([]);
  assets = signal<Asset[]>([]);
  images = computed(() => this.assets().filter((a) => a.kind === 'image'));
  editing = signal<'new' | number | null>(null);
  saving = signal(false);
  error = signal<string | null>(null);
  formError = signal<string | null>(null);

  form = inject(FormBuilder).group({
    name: ['', [Validators.required, Validators.maxLength(160)]],
    website_url: [''],
    industry: [''],
    font_family: [''],
    primary_color: ['', Validators.pattern(HEX)],
    secondary_color: ['', Validators.pattern(HEX)],
    logo_asset_id: [null as number | null],
    audience: [''],
    tone_of_voice: [''],
    products: [''],
    keywords: [''],
    words_to_avoid: [''],
    brand_guidelines: [''],
  });

  ngOnInit(): void {
    this.api.brands().subscribe({ next: (b) => this.brands.set(b), error: (err) => this.error.set(apiErrorMessage(err)) });
    this.api.assets().subscribe({ next: (a) => this.assets.set(a) });
  }

  openForm(brand: Brand | null): void {
    this.formError.set(null);
    this.form.reset();
    if (brand) this.form.patchValue(brand);
    this.editing.set(brand ? brand.id : 'new');
  }

  colorOr(key: 'primary_color' | 'secondary_color', fallback: string): string {
    const value = this.form.controls[key].value ?? '';
    return HEX.test(value) ? value : fallback;
  }

  setColor(key: 'primary_color' | 'secondary_color', event: Event): void {
    this.form.controls[key].setValue((event.target as HTMLInputElement).value.toUpperCase());
  }

  save(): void {
    if (this.form.invalid) return;
    this.saving.set(true);
    this.formError.set(null);
    const value = this.form.getRawValue() as BrandInput;
    const editing = this.editing();
    const request = editing === 'new' ? this.api.createBrand(value) : this.api.updateBrand(editing as number, value);
    request.subscribe({
      next: (saved) => {
        this.brands.update((list) =>
          editing === 'new' ? [...list, saved].sort((a, b) => a.name.localeCompare(b.name)) : list.map((b) => (b.id === saved.id ? saved : b)),
        );
        this.editing.set(null);
        this.saving.set(false);
      },
      error: (err) => {
        this.formError.set(apiErrorMessage(err));
        this.saving.set(false);
      },
    });
  }

  remove(brand: Brand): void {
    if (!confirm(`Eliminare il brand "${brand.name}"? I progetti collegati resteranno senza brand.`)) return;
    this.api.deleteBrand(brand.id).subscribe({
      next: () => this.brands.update((list) => list.filter((b) => b.id !== brand.id)),
      error: (err) => this.error.set(apiErrorMessage(err)),
    });
  }
}
