import { Component, inject, signal } from '@angular/core';
import { LangSwitchComponent } from '../../layout/lang-switch.component';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { apiErrorMessage } from '../../core/models';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [LangSwitchComponent, ReactiveFormsModule, RouterLink],
  template: `
    <main class="auth-page">
      <div class="auth-lang"><app-lang-switch /></div>
      <form class="card auth-card" [formGroup]="form" (ngSubmit)="submit()">
        <div class="brand">Marketing <span>OS</span></div>
        <h1>Accedi</h1>
        <p class="muted">Gestisci i progetti marketing dei tuoi clienti.</p>

        @if (error()) { <div class="alert">{{ error() }}</div> }

        <label>Email
          <input type="email" formControlName="email" autocomplete="email" />
        </label>
        <label>Password
          <input type="password" formControlName="password" autocomplete="current-password" />
        </label>

        <button class="btn primary" type="submit" [disabled]="form.invalid || loading()">
          {{ loading() ? 'Accesso in corso…' : 'Accedi' }}
        </button>
        <p class="muted center">Non hai un account? <a routerLink="/register">Registrati</a></p>
      </form>
    </main>
  `,
})
export class LoginComponent {
  private auth = inject(AuthService);
  private router = inject(Router);

  loading = signal(false);
  // Arrivo qui perché l'organizzazione è stata sospesa durante la sessione.
  error = signal<string | null>(
    inject(ActivatedRoute).snapshot.queryParamMap.has('sospeso') ? 'Questo account è sospeso. Contatta l\'assistenza.' : null,
  );

  form = inject(FormBuilder).nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', Validators.required],
  });

  submit(): void {
    if (this.form.invalid) return;
    this.loading.set(true);
    this.error.set(null);
    const { email, password } = this.form.getRawValue();
    this.auth.login(email, password).subscribe({
      next: () => this.router.navigate(['/dashboard']),
      error: (err) => {
        this.error.set(apiErrorMessage(err));
        this.loading.set(false);
      },
    });
  }
}
