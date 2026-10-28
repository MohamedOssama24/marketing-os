import { Component, OnInit, inject, signal } from '@angular/core';
import { CurrencyPipe, DatePipe } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { ApiService } from '../../core/api.service';
import { Brand, Project, ProjectStatus, apiErrorMessage } from '../../core/models';

export const PROJECT_STATUS_LABELS: Record<ProjectStatus, string> = {
  draft: 'Bozza',
  active: 'Attivo',
  paused: 'In pausa',
  completed: 'Completato',
  archived: 'Archiviato',
};

@Component({
  selector: 'app-projects',
  standalone: true,
  imports: [ReactiveFormsModule, DatePipe, CurrencyPipe, RouterLink],
  template: `
    <div class="page-head">
      <div>
        <h1>Progetti</h1>
        <p class="muted">Le campagne marketing della tua organizzazione.</p>
      </div>
      @if (auth.canEdit()) {
        <button class="btn primary" (click)="toggleForm()">
          {{ showForm() ? 'Annulla' : '+ Nuovo progetto' }}
        </button>
      }
    </div>

    @if (showForm()) {
      <form class="card form-grid" [formGroup]="form" (ngSubmit)="create()">
        @if (formError()) { <div class="alert full">{{ formError() }}</div> }
        <label>Nome progetto *
          <input formControlName="name" placeholder="Es. Campagna autunno" />
        </label>
        <label>Brand
          <select formControlName="brand_profile_id">
            <option [ngValue]="null">— Nessun brand —</option>
            @for (b of brands(); track b.id) { <option [ngValue]="b.id">{{ b.name }}</option> }
          </select>
        </label>
        <label class="full">Obiettivo *
          <input formControlName="objective" placeholder="Es. Generare richieste di preventivo" />
        </label>
        <label>Pubblico target
          <input formControlName="target_audience" placeholder="Es. PMI in Abruzzo" />
        </label>
        <label>Budget mensile (€)
          <input type="number" min="0" step="0.01" formControlName="budget_monthly" placeholder="300" />
        </label>
        <div class="full actions">
          <button class="btn primary" type="submit" [disabled]="form.invalid || saving()">
            {{ saving() ? 'Salvataggio…' : 'Crea progetto' }}
          </button>
        </div>
      </form>
    }

    @if (loading()) {
      <p class="muted">Caricamento…</p>
    } @else if (error()) {
      <div class="alert">{{ error() }}</div>
    } @else {
      <div class="grid">
        @for (p of projects(); track p.id) {
          <a class="card project link-card" [routerLink]="['/projects', p.id]">
            <div class="project-head">
              <h3>{{ p.name }}</h3>
              <span class="badge" [attr.data-status]="p.status">{{ statusLabels[p.status] }}</span>
            </div>
            <p>{{ p.objective }}</p>
            @if (brandName(p.brand_profile_id); as name) { <p class="muted small">🏷️ {{ name }}</p> }
            @if (p.target_audience) { <p class="muted small">🎯 {{ p.target_audience }}</p> }
            <div class="project-foot muted small">
              <span>{{ p.budget_monthly !== null ? (+p.budget_monthly | currency: 'EUR') + '/mese' : 'Budget non definito' }}</span>
              <span>{{ p.created_at | date: 'dd/MM/yyyy' }}</span>
            </div>
          </a>
        } @empty {
          <div class="card empty">
            <h3>Nessun progetto ancora</h3>
            <p class="muted">Crea il primo progetto per iniziare a pianificare il marketing di un cliente.</p>
          </div>
        }
      </div>
    }
  `,
})
export class ProjectsComponent implements OnInit {
  readonly auth = inject(AuthService);
  private api = inject(ApiService);
  readonly statusLabels = PROJECT_STATUS_LABELS;

  projects = signal<Project[]>([]);
  brands = signal<Brand[]>([]);
  loading = signal(true);
  error = signal<string | null>(null);
  showForm = signal(false);
  saving = signal(false);
  formError = signal<string | null>(null);

  form = inject(FormBuilder).group({
    name: ['', [Validators.required, Validators.maxLength(180)]],
    objective: ['', [Validators.required, Validators.maxLength(500)]],
    brand_profile_id: [null as number | null],
    target_audience: [''],
    budget_monthly: [null as number | null, Validators.min(0)],
  });

  ngOnInit(): void {
    this.api.brands().subscribe({ next: (b) => this.brands.set(b) });
    this.api.projects().subscribe({
      next: (list) => {
        this.projects.set(list);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(apiErrorMessage(err));
        this.loading.set(false);
      },
    });
  }

  brandName(id: number | null): string | null {
    return id === null ? null : this.brands().find((b) => b.id === id)?.name ?? null;
  }

  toggleForm(): void {
    this.showForm.update((v) => !v);
    this.formError.set(null);
  }

  create(): void {
    if (this.form.invalid) return;
    this.saving.set(true);
    this.formError.set(null);
    const v = this.form.getRawValue();
    this.api
      .createProject({
        name: v.name!,
        objective: v.objective!,
        brand_profile_id: v.brand_profile_id,
        target_audience: v.target_audience?.trim() || null,
        budget_monthly: v.budget_monthly ?? null,
      })
      .subscribe({
        next: (project) => {
          this.projects.update((list) => [project, ...list]);
          this.form.reset();
          this.showForm.set(false);
          this.saving.set(false);
        },
        error: (err) => {
          this.formError.set(apiErrorMessage(err));
          this.saving.set(false);
        },
      });
  }
}
