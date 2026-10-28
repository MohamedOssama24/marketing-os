import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { API_URL, NewProject, Project } from './models';

@Injectable({ providedIn: 'root' })
export class ProjectsService {
  private http = inject(HttpClient);

  list(): Observable<Project[]> {
    return this.http.get<{ data: Project[] }>(`${API_URL}/projects`).pipe(map((res) => res.data));
  }

  create(project: NewProject): Observable<Project> {
    return this.http.post<{ data: Project }>(`${API_URL}/projects`, project).pipe(map((res) => res.data));
  }
}
