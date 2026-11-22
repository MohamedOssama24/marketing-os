import { Routes } from '@angular/router';
import { adminGuard, appGuard, authGuard, guestGuard, managerGuard } from './core/auth.guards';
import { ShellComponent } from './layout/shell.component';

export const routes: Routes = [
  { path: 'login', canActivate: [guestGuard], loadComponent: () => import('./pages/login/login.component').then((m) => m.LoginComponent) },
  { path: 'register', canActivate: [guestGuard], loadComponent: () => import('./pages/register/register.component').then((m) => m.RegisterComponent) },
  // Report aperto dal cliente con il link condiviso: pubblico, senza accesso.
  { path: 'report/:token', loadComponent: () => import('./pages/reports/public-report.component').then((m) => m.PublicReportComponent) },
  // Audit gratuito aperto dal potenziale cliente e portale del cliente: pubblici, senza accesso.
  { path: 'audit/:token', loadComponent: () => import('./pages/prospects/public-audit.component').then((m) => m.PublicAuditComponent) },
  { path: 'portal/:token', loadComponent: () => import('./pages/portal/portal.component').then((m) => m.PortalComponent) },
  // Abbonamento: fuori dalla struttura dell'app perché si apre anche a prova scaduta.
  { path: 'subscription', canActivate: [authGuard], loadComponent: () => import('./pages/subscription/subscription.component').then((m) => m.SubscriptionComponent) },
  {
    path: '',
    component: ShellComponent,
    canActivate: [appGuard],
    children: [
      { path: 'dashboard', loadComponent: () => import('./pages/dashboard/dashboard.component').then((m) => m.DashboardComponent) },
      { path: 'clients', loadComponent: () => import('./pages/clients/clients.component').then((m) => m.ClientsComponent) },
      { path: 'clients/:id', loadComponent: () => import('./pages/client-detail/client-detail.component').then((m) => m.ClientDetailComponent) },
      { path: 'prospects', loadComponent: () => import('./pages/prospects/prospects.component').then((m) => m.ProspectsComponent) },
      { path: 'prospects/:id', loadComponent: () => import('./pages/prospects/prospect-detail.component').then((m) => m.ProspectDetailComponent) },
      { path: 'campaigns', loadComponent: () => import('./pages/projects/projects.component').then((m) => m.ProjectsComponent) },
      { path: 'campaigns/:id', loadComponent: () => import('./pages/project-detail/project-detail.component').then((m) => m.ProjectDetailComponent) },
      { path: 'calendar', loadComponent: () => import('./pages/calendar/calendar.component').then((m) => m.CalendarComponent) },
      { path: 'approvals', loadComponent: () => import('./pages/approvals/approvals.component').then((m) => m.ApprovalsComponent) },
      { path: 'assets', loadComponent: () => import('./pages/assets/assets.component').then((m) => m.AssetsComponent) },
      { path: 'ads', loadComponent: () => import('./pages/ads/ads.component').then((m) => m.AdsComponent) },
      { path: 'ads/rules', loadComponent: () => import('./pages/ads/meta-rules.component').then((m) => m.MetaRulesComponent) },
      { path: 'links', loadComponent: () => import('./pages/links/links.component').then((m) => m.LinksComponent) },
      { path: 'reports', loadComponent: () => import('./pages/reports/reports.component').then((m) => m.ReportsComponent) },
      { path: 'reviews', loadComponent: () => import('./pages/reviews/reviews.component').then((m) => m.ReviewsComponent) },
      { path: 'profitability', loadComponent: () => import('./pages/profitability/profitability.component').then((m) => m.ProfitabilityComponent) },
      { path: 'billing', canActivate: [managerGuard], loadComponent: () => import('./pages/billing/billing.component').then((m) => m.BillingComponent) },
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
