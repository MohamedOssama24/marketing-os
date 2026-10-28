import { Component, OnInit, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { AuthService } from '../../core/auth.service';
import { ApiService } from '../../core/api.service';
import { ROLE_LABELS, Role, TeamMember, apiErrorMessage } from '../../core/models';

@Component({
  selector: 'app-team',
  standalone: true,
  imports: [ReactiveFormsModule, DatePipe],
  template: `
    <div class="page-head">
      <div>
        <h1>Team</h1>
        <p class="muted">Chi lavora nella tua organizzazione e cosa può fare.</p>
      </div>
      @if (auth.canManage()) {
        <button class="btn primary" (click)="showForm.set(!showForm())">{{ showForm() ? 'Annulla' : '+ Aggiungi persona' }}</button>
      }
    </div>

    @if (showForm()) {
      <form class="card form-grid" [formGroup]="form" (ngSubmit)="add()">
        @if (formError()) { <div class="alert full">{{ formError() }}</div> }
        <label>Nome<input formControlName="name" /></label>
        <label>Email<input type="email" formControlName="email" /></label>
        <label>Password iniziale<input type="password" formControlName="password" autocomplete="new-password" /><small class="muted">Almeno 12 caratteri: comunicala alla persona in modo sicuro.</small></label>
        <label>Ruolo
          <select formControlName="role">
            @if (auth.user()?.role === 'owner') { <option value="admin">Amministratore — gestisce team, brand e approva</option> }
            <option value="editor">Editor — crea e modifica contenuti</option>
            <option value="viewer">Sola lettura — ad esempio il cliente</option>
          </select>
        </label>
        <div class="full actions">
          <button class="btn primary" type="submit" [disabled]="form.invalid || saving()">{{ saving() ? 'Salvataggio…' : 'Aggiungi' }}</button>
        </div>
      </form>
    }

    @if (error()) { <div class="alert">{{ error() }}</div> }

    <div class="card table-card">
      <table>
        <thead><tr><th>Nome</th><th>Email</th><th>Ruolo</th><th>Dal</th></tr></thead>
        <tbody>
          @for (m of members(); track m.id) {
            <tr>
              <td>{{ m.name }}</td>
              <td>{{ m.email }}</td>
              <td><span class="tag">{{ roleLabels[m.role] }}</span></td>
              <td class="muted">{{ m.created_at | date: 'dd/MM/yyyy' }}</td>
            </tr>
          }
        </tbody>
      </table>
    </div>
  `,
})
export class TeamComponent implements OnInit {
  readonly auth = inject(AuthService);
  private api = inject(ApiService);
  readonly roleLabels = ROLE_LABELS;

  members = signal<TeamMember[]>([]);
  showForm = signal(false);
  saving = signal(false);
  error = signal<string | null>(null);
  formError = signal<string | null>(null);

  form = inject(FormBuilder).nonNullable.group({
    name: ['', [Validators.required, Validators.maxLength(120)]],
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required, Validators.minLength(12)]],
    role: ['editor' as Exclude<Role, 'owner'>, Validators.required],
  });

  ngOnInit(): void {
    this.api.team().subscribe({ next: (m) => this.members.set(m), error: (err) => this.error.set(apiErrorMessage(err)) });
  }

  add(): void {
    if (this.form.invalid) return;
    this.saving.set(true);
    this.formError.set(null);
    this.api.addMember(this.form.getRawValue()).subscribe({
      next: (member) => {
        this.members.update((list) => [...list, member]);
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
