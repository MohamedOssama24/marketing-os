import { Component, OnInit, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { ApiService } from '../../core/api.service';
import { Brand, Project, ProjectStatus, apiErrorMessage } from '../../core/models';
import { AssetPreviewComponent } from '../../shared/asset-preview.component';

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
  imports: [ReactiveFormsModule, DatePipe, RouterLink, AssetPreviewComponent],
  template: `
    <div class="page-head">
      <div>
        <h1>Campagne</h1>
        <p class="muted">Le campagne di tutti i tuoi clienti. Per generarne una con l'AI apri la scheda del cliente.</p>
      </div>
      @if (auth.canEdit()) {
        <div class="actions">
          <button class="btn ghost" (click)="toggleForm()">{{ showForm() ? 'Annulla' : 'Campagna vuota' }}</button>
          <a class="btn primary" routerLink="/clients"><i class="ti ti-sparkles" aria-hidden="true"></i> Genera con l'AI</a>
        </div>
      }
    </div>

    @if (showForm()) {
      <form class="card form-grid" [formGroup]="form" (ngSubmit)="create()">
        @if (formError()) { <div class="alert full">{{ formError() }}</div> }
        <label>Nome progetto *
          <input formControlName="name" placeholder="Es. Campagna autunno" />
        </label>
        <label>Cliente
          <select formControlName="brand_profile_id">
            <option [ngValue]="null">— Nessun cliente —</option>
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
          <a class="card project link-card" [routerLink]="['/campaigns', p.id]">
            <div class="project-head">
              @if (brandOf(p.brand_profile_id); as b) {
                <span class="client-logo small" [style.background]="b.primary_color ?? '#5B4BDB'">
                  @if (b.logo_asset_id) { <app-asset-preview [assetId]="b.logo_asset_id" /> } @else { {{ b.name.charAt(0) }} }
                </span>
              }
              <div class="grow">
                <h3>{{ p.name }}</h3>
                <p class="muted small">{{ brandOf(p.brand_profile_id)?.name ?? 'Nessun cliente' }}</p>
              </div>
              <span class="badge" [attr.data-status]="p.status">{{ statusLabels[p.status] }}</span>
            </div>
            <p class="clamp">{{ p.objective }}</p>
            <div class="progress" [title]="(p.content_approved ?? 0) + ' approvati su ' + (p.content_total ?? 0)">
              <span [style.width.%]="percent(p)" [style.background]="brandOf(p.brand_profile_id)?.primary_color ?? '#5B4BDB'"></span>
            </div>
            <div class="project-foot muted small">
              <span>{{ p.content_approved ?? 0 }}/{{ p.content_total ?? 0 }} approvati @if (p.content_in_review) { · {{ p.content_in_review }} in revisione }</span>
              <span>{{ p.created_at | date: 'dd/MM/yyyy' }}</span>
            </div>
          </a>
        } @empty {
          <div class="card empty">
            <i class="ti ti-speakerphone empty-icon" aria-hidden="true"></i>
            <h3>Nessuna campagna ancora</h3>
            <p class="muted">Apri un cliente e genera la sua prima campagna con l'AI.</p>
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

  brandOf(id: number | null): Brand | null {
    return id === null ? null : this.brands().find((b) => b.id === id) ?? null;
  }

  percent(p: Project): number {
    return p.content_total ? Math.round(((p.content_approved ?? 0) / p.content_total) * 100) : 0;
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
