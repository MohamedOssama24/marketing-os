import { Routes } from '@angular/router';
import { authGuard, guestGuard } from './core/auth.guards';

export const routes: Routes = [
  { path: 'login', canActivate: [guestGuard], loadComponent: () => import('./pages/login/login.component').then((m) => m.LoginComponent) },
  { path: 'register', canActivate: [guestGuard], loadComponent: () => import('./pages/register/register.component').then((m) => m.RegisterComponent) },
  { path: 'projects', canActivate: [authGuard], loadComponent: () => import('./pages/projects/projects.component').then((m) => m.ProjectsComponent) },
  { path: '', pathMatch: 'full', redirectTo: 'projects' },
  { path: '**', redirectTo: 'projects' },
];
