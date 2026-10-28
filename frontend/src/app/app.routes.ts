import { Routes } from '@angular/router';
import { adminGuard, authGuard, guestGuard } from './core/auth.guards';
import { ShellComponent } from './layout/shell.component';

export const routes: Routes = [
  { path: 'login', canActivate: [guestGuard], loadComponent: () => import('./pages/login/login.component').then((m) => m.LoginComponent) },
  { path: 'register', canActivate: [guestGuard], loadComponent: () => import('./pages/register/register.component').then((m) => m.RegisterComponent) },
  {
    path: '',
    component: ShellComponent,
    canActivate: [authGuard],
    children: [
      { path: 'dashboard', loadComponent: () => import('./pages/dashboard/dashboard.component').then((m) => m.DashboardComponent) },
      { path: 'clients', loadComponent: () => import('./pages/clients/clients.component').then((m) => m.ClientsComponent) },
      { path: 'clients/:id', loadComponent: () => import('./pages/client-detail/client-detail.component').then((m) => m.ClientDetailComponent) },
      { path: 'campaigns', loadComponent: () => import('./pages/projects/projects.component').then((m) => m.ProjectsComponent) },
      { path: 'campaigns/:id', loadComponent: () => import('./pages/project-detail/project-detail.component').then((m) => m.ProjectDetailComponent) },
      { path: 'calendar', loadComponent: () => import('./pages/calendar/calendar.component').then((m) => m.CalendarComponent) },
      { path: 'approvals', loadComponent: () => import('./pages/approvals/approvals.component').then((m) => m.ApprovalsComponent) },
      { path: 'assets', loadComponent: () => import('./pages/assets/assets.component').then((m) => m.AssetsComponent) },
      { path: 'team', loadComponent: () => import('./pages/team/team.component').then((m) => m.TeamComponent) },
      { path: 'admin', canActivate: [adminGuard], loadComponent: () => import('./pages/admin/admin.component').then((m) => m.AdminComponent) },
      { path: 'admin/organizations/:id', canActivate: [adminGuard], loadComponent: () => import('./pages/admin/admin-organization.component').then((m) => m.AdminOrganizationComponent) },
      // Indirizzi della versione precedente
      { path: 'projects', redirectTo: 'campaigns' },
      { path: 'projects/:id', redirectTo: 'campaigns/:id' },
      { path: 'brands', redirectTo: 'clients' },
      { path: '', pathMatch: 'full', redirectTo: 'dashboard' },
    ],
  },
  { path: '**', redirectTo: 'dashboard' },
];
