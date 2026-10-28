import { Routes } from '@angular/router';
import { authGuard, guestGuard } from './core/auth.guards';
import { ShellComponent } from './layout/shell.component';

export const routes: Routes = [
  { path: 'login', canActivate: [guestGuard], loadComponent: () => import('./pages/login/login.component').then((m) => m.LoginComponent) },
  { path: 'register', canActivate: [guestGuard], loadComponent: () => import('./pages/register/register.component').then((m) => m.RegisterComponent) },
  {
    path: '',
    component: ShellComponent,
    canActivate: [authGuard],
    children: [
      { path: 'projects', loadComponent: () => import('./pages/projects/projects.component').then((m) => m.ProjectsComponent) },
      { path: 'projects/:id', loadComponent: () => import('./pages/project-detail/project-detail.component').then((m) => m.ProjectDetailComponent) },
      { path: 'brands', loadComponent: () => import('./pages/brands/brands.component').then((m) => m.BrandsComponent) },
      { path: 'assets', loadComponent: () => import('./pages/assets/assets.component').then((m) => m.AssetsComponent) },
      { path: 'team', loadComponent: () => import('./pages/team/team.component').then((m) => m.TeamComponent) },
      { path: '', pathMatch: 'full', redirectTo: 'projects' },
    ],
  },
  { path: '**', redirectTo: 'projects' },
];
