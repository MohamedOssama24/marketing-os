import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import {
  API_URL, Asset, Brand, BrandInput, ContentItem, GenerateRequest, Job, NewProject, Project, Role, TeamMember,
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

  // Brand kit
  brands = () => this.get<Brand[]>('/brands');
  createBrand = (b: BrandInput) => this.post<Brand>('/brands', b);
  updateBrand = (id: number, b: BrandInput) => this.patch<Brand>(`/brands/${id}`, b);
  deleteBrand = (id: number) => this.delete(`/brands/${id}`);

  // Libreria file
  assets = () => this.get<Asset[]>('/assets');
  deleteAsset = (id: number) => this.delete(`/assets/${id}`);
  uploadAsset(file: File): Observable<Asset> {
    const form = new FormData();
    form.append('file', file);
    return this.post<Asset>('/assets', form);
  }
  /** Il file richiede il token, quindi si scarica come blob e si mostra con un object URL. */
  assetBlob = (id: number) => this.http.get(`${API_URL}/assets/${id}/file`, { responseType: 'blob' });

  // Contenuti
  content = (projectId: number) => this.get<ContentItem[]>(`/projects/${projectId}/content`);
  createContent = (projectId: number, c: Pick<ContentItem, 'channel' | 'content_type' | 'title' | 'body'>) =>
    this.post<ContentItem>(`/projects/${projectId}/content`, c);
  updateContent = (id: number, c: Partial<Pick<ContentItem, 'channel' | 'content_type' | 'title' | 'body'>>) =>
    this.patch<ContentItem>(`/content/${id}`, c);
  submitContent = (id: number) => this.post<ContentItem>(`/content/${id}/submit`);
  approveContent = (id: number, note?: string) => this.post<ContentItem>(`/content/${id}/approve`, note ? { note } : {});
  rejectContent = (id: number, note: string) => this.post<ContentItem>(`/content/${id}/reject`, { note });
  generate = (projectId: number, req: GenerateRequest) => this.post<Job>(`/projects/${projectId}/generate`, req);
  job = (id: number) => this.get<Job>(`/jobs/${id}`);

  // Team
  team = () => this.get<TeamMember[]>('/users');
  addMember = (m: { name: string; email: string; password: string; role: Exclude<Role, 'owner'> }) => this.post<TeamMember>('/users', m);
}
