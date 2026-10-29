import { Component, inject, signal } from '@angular/core';
import { LangSwitchComponent } from '../../layout/lang-switch.component';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { apiErrorMessage } from '../../core/models';

@Component({
  selector: 'app-register',
  standalone: true,
  imports: [LangSwitchComponent, ReactiveFormsModule, RouterLink],
  template: `
    <main class="auth-page">
      <div class="auth-lang"><app-lang-switch /></div>
      <form class="card auth-card" [formGroup]="form" (ngSubmit)="submit()">
        <div class="brand">Marketing <span>OS</span></div>
        <h1>Crea il tuo account</h1>
        <p class="muted">Registrandoti crei anche la tua organizzazione.</p>

        @if (error()) { <div class="alert">{{ error() }}</div> }

        <label>Nome e cognome
          <input formControlName="name" autocomplete="name" />
        </label>
        <label>Nome organizzazione
          <input formControlName="organization_name" autocomplete="organization" />
        </label>
        <label>Email
          <input type="email" formControlName="email" autocomplete="email" />
        </label>
        <label>Password
          <input type="password" formControlName="password" autocomplete="new-password" />
          <small class="muted">Almeno 12 caratteri.</small>
        </label>
        <label>Codice di invito
          <input formControlName="invite_code" autocomplete="off" />
          <small class="muted">Se te ne hanno dato uno.</small>
        </label>

        <button class="btn primary" type="submit" [disabled]="form.invalid || loading()">
          {{ loading() ? 'Creazione in corso…' : 'Registrati' }}
        </button>
        <p class="muted center">Hai già un account? <a routerLink="/login">Accedi</a></p>
      </form>
    </main>
  `,
})
export class RegisterComponent {
  private auth = inject(AuthService);
  private router = inject(Router);

  loading = signal(false);
  error = signal<string | null>(null);

  form = inject(FormBuilder).nonNullable.group({
    name: ['', [Validators.required, Validators.maxLength(120)]],
    organization_name: ['', [Validators.required, Validators.maxLength(160)]],
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required, Validators.minLength(12)]],
    invite_code: ['', [Validators.maxLength(100)]],
  });

  submit(): void {
    if (this.form.invalid) return;
    this.loading.set(true);
    this.error.set(null);
    this.auth.register(this.form.getRawValue()).subscribe({
      next: () => this.router.navigate(['/dashboard']),
      error: (err) => {
        this.error.set(apiErrorMessage(err));
        this.loading.set(false);
      },
    });
  }
}
