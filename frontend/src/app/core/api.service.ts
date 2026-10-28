import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, catchError, filter, map, shareReplay, switchMap, take, throwError, timer } from 'rxjs';
import {
  API_URL, Asset, Brand, BrandInput, CalendarItem, CampaignRequest, ContentItem, Dashboard, GenerateRequest, Job,
  NewProject, Project, Role, TeamMember, Visual,
} from './models';

type Data<T> = { data: T };

/** Chiamate alle API di Marketing OS (tutte le risposte sono nel formato { data }). */
@Injectable({ providedIn: 'root' })
export class ApiService {
  private http = inject(HttpClient);

  private get<T>(path: string): Observable<T> {
    return this.http.get<Data<T>>(`${API_URL}${path}`).pipe(map((r) => r.data));
  }
  private post<T>(path: string, body: unknown = {}): Observable<T> {
    return this.http.post<Data<T>>(`${API_URL}${path}`, body).pipe(map((r) => r.data));
  }
  private patch<T>(path: string, body: unknown): Observable<T> {
    return this.http.patch<Data<T>>(`${API_URL}${path}`, body).pipe(map((r) => r.data));
  }
  private delete(path: string): Observable<unknown> {
    return this.http.delete(`${API_URL}${path}`);
  }

  // Progetti
  projects = () => this.get<Project[]>('/projects');
  project = (id: number) => this.get<Project>(`/projects/${id}`);
  createProject = (p: NewProject) => this.post<Project>('/projects', p);
  updateProject = (id: number, p: Partial<NewProject> & { status?: string }) => this.patch<Project>(`/projects/${id}`, p);

  // Dashboard, calendario, approvazioni
  dashboard = () => this.get<Dashboard>('/dashboard');
  calendar = (from: string, to: string) => this.get<CalendarItem[]>(`/calendar?from=${from}&to=${to}`);
  approvals = () => this.get<ContentItem[]>('/approvals');

  // Dal sito alla campagna
  analyzeSite = (url: string) => this.post<Job>('/onboarding/analyze', { url });
  createCampaign = (brandId: number, req: CampaignRequest) =>
    this.post<{ project: Project; job: Job }>(`/brands/${brandId}/campaigns`, req);
  reanalyzeBrand = (brandId: number, url?: string) => this.post<Job>(`/brands/${brandId}/analyze`, url ? { url } : {});
  suggestGoals = (brandId: number) => this.post<Job>(`/brands/${brandId}/goal-suggestions`);
  deleteProject = (id: number) => this.delete(`/projects/${id}`);

  /** Segue un lavoro in background ogni 2 secondi finché termina (al massimo 4 minuti). */
  waitForJob(id: number): Observable<Job> {
    return timer(800, 2000).pipe(
      take(120),
      switchMap(() => this.job(id)),
      filter((job) => job.status === 'done' || job.status === 'failed'),
      take(1),
    );
  }

  // Clienti (brand kit)
  brands = () => this.get<Brand[]>('/brands');
  brand = (id: number) => this.get<Brand>(`/brands/${id}`);
  createBrand = (b: BrandInput) => this.post<Brand>('/brands', b);
  updateBrand = (id: number, b: BrandInput) => this.patch<Brand>(`/brands/${id}`, b);
  /** Con withCampaigns elimina anche le campagne del cliente e i loro contenuti. */
  deleteBrand = (id: number, withCampaigns = false) => this.delete(`/brands/${id}${withCampaigns ? '?with_campaigns=1' : ''}`);

  // Libreria file
  assets = (brandId?: number) => this.get<Asset[]>(brandId ? `/assets?brand_id=${brandId}` : '/assets');
  deleteAsset = (id: number) => {
    this.blobCache.delete(id);
    return this.delete(`/assets/${id}`);
  };
  uploadAsset(file: File, brandId?: number): Observable<Asset> {
    const form = new FormData();
    form.append('file', file);
    if (brandId) form.append('brand_profile_id', String(brandId));
    return this.post<Asset>('/assets', form);
  }
  /**
   * Il file richiede il token, quindi si scarica come blob e si mostra con un object URL.
   * I file non cambiano mai: ogni id si scarica una volta sola anche se compare in molte anteprime.
   */
  assetBlob(id: number): Observable<Blob> {
    let blob = this.blobCache.get(id);
    if (!blob) {
      blob = this.http.get(`${API_URL}/assets/${id}/file`, { responseType: 'blob' }).pipe(
        catchError((err) => {
          this.blobCache.delete(id);
          return throwError(() => err);
        }),
        shareReplay(1),
      );
      this.blobCache.set(id, blob);
    }
    return blob;
  }
  private blobCache = new Map<number, Observable<Blob>>();

  /** Da chiamare all'uscita, così un altro utente sullo stesso browser non riceve file in cache. */
  clearCache(): void {
    this.blobCache.clear();
  }

  // Contenuti
  content = (projectId: number) => this.get<ContentItem[]>(`/projects/${projectId}/content`);
  contentItem = (id: number) => this.get<ContentItem>(`/content/${id}`);
  deleteContent = (id: number) => this.delete(`/content/${id}`);
  rewriteContent = (id: number, instruction: string) => this.post<Job>(`/content/${id}/rewrite`, { instruction });
  publishContent = (id: number, url?: string | null) => this.post<ContentItem>(`/content/${id}/publish`, url ? { url } : {});
  unpublishContent = (id: number) => this.post<ContentItem>(`/content/${id}/unpublish`);
  /** Carica una foto personalizzata nella grafica del post (finisce anche nella libreria del cliente). */
  uploadContentPhoto(id: number, file: File): Observable<ContentItem> {
    const form = new FormData();
    form.append('file', file);
    return this.post<ContentItem>(`/content/${id}/photo`, form);
  }
  createContent = (projectId: number, c: Pick<ContentItem, 'channel' | 'content_type' | 'title' | 'body'>) =>
    this.post<ContentItem>(`/projects/${projectId}/content`, c);
  updateContent = (id: number, c: Partial<Pick<ContentItem, 'channel' | 'content_type' | 'title' | 'body'>> & { visual?: Visual | null }) =>
    this.patch<ContentItem>(`/content/${id}`, c);
  scheduleContent = (id: number, scheduledAt: string | null) =>
    this.post<ContentItem>(`/content/${id}/schedule`, { scheduled_at: scheduledAt });
  submitContent = (id: number) => this.post<ContentItem>(`/content/${id}/submit`);
  approveContent = (id: number, note?: string) => this.post<ContentItem>(`/content/${id}/approve`, note ? { note } : {});
  rejectContent = (id: number, note: string) => this.post<ContentItem>(`/content/${id}/reject`, { note });
  generate = (projectId: number, req: GenerateRequest) => this.post<Job>(`/projects/${projectId}/generate`, req);
  job = (id: number) => this.get<Job>(`/jobs/${id}`);

  // Team
  team = () => this.get<TeamMember[]>('/users');
  addMember = (m: { name: string; email: string; password: string; role: Exclude<Role, 'owner'> }) => this.post<TeamMember>('/users', m);
}
