import { Component, EventEmitter, Input, OnInit, Output, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ApiService } from '../core/api.service';
import { Asset, Brand, BrandInput, apiErrorMessage } from '../core/models';

const HEX = /^#[0-9a-fA-F]{6}$/;

/** Modulo del brand kit di un cliente, per la creazione manuale e per la modifica. */
@Component({
  selector: 'app-brand-form',
  standalone: true,
  imports: [ReactiveFormsModule],
  template: `
    <form class="card form-grid" [formGroup]="form" (ngSubmit)="save()">
      @if (error()) { <div class="alert full">{{ error() }}</div> }

      <label>Nome *<input formControlName="name" placeholder="Pasticceria Rossi" /></label>
      <label>Sito web<input formControlName="website_url" placeholder="https://..." /></label>
      <label class="full">In breve<input formControlName="summary" placeholder="Cosa fa l'attività e per chi" /></label>
      <label>Instagram<input formControlName="instagram_url" placeholder="https://www.instagram.com/nomeprofilo" /></label>
      <label>Facebook<input formControlName="facebook_url" placeholder="https://www.facebook.com/nomepagina" /></label>
      <label>LinkedIn<input formControlName="linkedin_url" placeholder="https://www.linkedin.com/company/nome" /></label>
      <label>Settore<input formControlName="industry" placeholder="Alimentare, e-commerce" /></label>
      <label>Font<input formControlName="font_family" placeholder="Montserrat" /><small class="muted">Nome di un font di Google Fonts.</small></label>

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
      </label>

      <label class="full">Pubblico<textarea rows="2" formControlName="audience" placeholder="Chi sono i clienti ideali? Età, interessi, bisogni."></textarea></label>
      <label class="full">Tono di voce<textarea rows="2" formControlName="tone_of_voice" placeholder="Caldo e familiare, dà del tu, frasi brevi."></textarea></label>
      <label class="full">Prodotti e servizi<textarea rows="4" formControlName="products" placeholder="Prodotti, servizi, punti di forza e prezzi reali."></textarea></label>
      <label>Parole chiave<textarea rows="2" formControlName="keywords" placeholder="artigianale, ingredienti locali"></textarea></label>
      <label>Parole da evitare<textarea rows="2" formControlName="words_to_avoid" placeholder="economico, industriale"></textarea></label>
      <label class="full">Linee guida<textarea rows="3" formControlName="brand_guidelines" placeholder="Regole di comunicazione, claim, cose da non dire."></textarea></label>

      <div class="full actions">
        <button class="btn ghost" type="button" (click)="cancelled.emit()">Annulla</button>
        <button class="btn primary" type="submit" [disabled]="form.invalid || saving()">{{ saving() ? 'Salvataggio…' : 'Salva brand kit' }}</button>
      </div>
    </form>
  `,
})
export class BrandFormComponent implements OnInit {
  @Input() brand: Brand | null = null;
  @Output() saved = new EventEmitter<Brand>();
  @Output() cancelled = new EventEmitter<void>();

  private api = inject(ApiService);
  images = signal<Asset[]>([]);
  saving = signal(false);
  error = signal<string | null>(null);

  form = inject(FormBuilder).group({
    name: ['', [Validators.required, Validators.maxLength(160)]],
    summary: ['', Validators.maxLength(500)],
    website_url: [''],
    instagram_url: [''],
    facebook_url: [''],
    linkedin_url: [''],
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
    if (this.brand) this.form.patchValue(this.brand);
    this.api.assets().subscribe((list) => this.images.set(list.filter((a) => a.kind === 'image')));
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
    this.error.set(null);
    const value = this.form.getRawValue() as BrandInput;
    const request = this.brand ? this.api.updateBrand(this.brand.id, value) : this.api.createBrand(value);
    request.subscribe({
      next: (brand) => {
        this.saving.set(false);
        this.saved.emit(brand);
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(apiErrorMessage(err));
      },
    });
  }
}
