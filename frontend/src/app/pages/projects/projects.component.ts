import { Component, OnInit, inject, signal } from '@angular/core';
import { CurrencyPipe, DatePipe } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { AuthService } from '../../core/auth.service';
import { ProjectsService } from '../../core/projects.service';
import { Project, ProjectStatus, apiErrorMessage } from '../../core/models';

const STATUS_LABELS: Record<ProjectStatus, string> = {
  draft: 'Bozza',
  active: 'Attivo',
  paused: 'In pausa',
  completed: 'Completato',
  archived: 'Archiviato',
};

@Component({
  selector: 'app-projects',
  standalone: true,
  imports: [ReactiveFormsModule, DatePipe, CurrencyPipe],
  template: `
    <header class="topbar">
      <div class="brand">Marketing <span>OS</span></div>
      <div class="topbar-user">
        @if (auth.user(); as user) {
          <span><strong>{{ user.name }}</strong> <small class="muted">· {{ user.role }}</small></span>
        }
        <button class="btn ghost" (click)="auth.logout()">Esci</button>
      </div>
    </header>

    <main class="container">
      <div class="page-head">
        <div>
          <h1>Progetti</h1>
          <p class="muted">Le campagne marketing della tua organizzazione.</p>
        </div>
        <button class="btn primary" (click)="toggleForm()">
          {{ showForm() ? 'Annulla' : '+ Nuovo progetto' }}
        </button>
      </div>

      @if (showForm()) {
        <form class="card form-grid" [formGroup]="form" (ngSubmit)="create()">
          @if (formError()) { <div class="alert full">{{ formError() }}</div> }
          <label>Nome progetto *
            <input formControlName="name" placeholder="Es. Campagna autunno" />
          </label>
          <label>Budget mensile (€)
            <input type="number" min="0" step="0.01" formControlName="budget_monthly" placeholder="300" />
          </label>
          <label class="full">Obiettivo *
            <input formControlName="objective" placeholder="Es. Generare richieste di preventivo" />
          </label>
          <label class="full">Pubblico target
            <textarea rows="2" formControlName="target_audience" placeholder="Es. PMI in Abruzzo"></textarea>
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
            <article class="card project">
              <div class="project-head">
                <h3>{{ p.name }}</h3>
                <span class="badge" [attr.data-status]="p.status">{{ statusLabel(p.status) }}</span>
              </div>
              <p>{{ p.objective }}</p>
              @if (p.target_audience) { <p class="muted small">🎯 {{ p.target_audience }}</p> }
              <div class="project-foot muted small">
                <span>{{ p.budget_monthly !== null ? (+p.budget_monthly | currency: 'EUR') + '/mese' : 'Budget non definito' }}</span>
                <span>{{ p.created_at | date: 'dd/MM/yyyy' }}</span>
              </div>
            </article>
          } @empty {
            <div class="card empty">
              <h3>Nessun progetto ancora</h3>
              <p class="muted">Crea il primo progetto per iniziare a pianificare il marketing di un cliente.</p>
            </div>
          }
        </div>
      }
    </main>
  `,
})
export class ProjectsComponent implements OnInit {
  readonly auth = inject(AuthService);
  private projectsService = inject(ProjectsService);

  projects = signal<Project[]>([]);
  loading = signal(true);
  error = signal<string | null>(null);
  showForm = signal(false);
  saving = signal(false);
  formError = signal<string | null>(null);

  form = inject(FormBuilder).group({
    name: ['', [Validators.required, Validators.maxLength(180)]],
    objective: ['', [Validators.required, Validators.maxLength(500)]],
    target_audience: [''],
    budget_monthly: [null as number | null, Validators.min(0)],
  });

  ngOnInit(): void {
    this.auth.loadMe().subscribe();
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.projectsService.list().subscribe({
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

  toggleForm(): void {
    this.showForm.update((v) => !v);
    this.formError.set(null);
  }

  create(): void {
    if (this.form.invalid) return;
    this.saving.set(true);
    this.formError.set(null);
    const v = this.form.getRawValue();
    this.projectsService
      .create({
        name: v.name!,
        objective: v.objective!,
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

  statusLabel(status: ProjectStatus): string {
    return STATUS_LABELS[status];
  }
}
