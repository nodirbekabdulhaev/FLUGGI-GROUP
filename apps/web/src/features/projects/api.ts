'use client';

import type {
  ActivityDto,
  FileDto,
  MemberCandidateDto,
  Paginated,
  ProjectDetailDto,
  ProjectDto,
  ProjectListQuery,
  ProjectTemplateDto,
  TaskDetailDto,
  TaskDto,
  TaskListQuery,
} from '@fluggi/contracts';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api-client';

const q = (o: object) => o as Record<string, string | number | boolean | undefined>;

export function useProjects(p: ProjectListQuery) {
  return useQuery({
    queryKey: ['projects', p],
    queryFn: () => api<Paginated<ProjectDto>>('/projects', { query: q(p) }),
    placeholderData: keepPreviousData,
  });
}

export function useProject(id: string) {
  return useQuery({
    queryKey: ['project', id],
    queryFn: () => api<ProjectDetailDto>(`/projects/${id}`),
  });
}

export function useProjectTimeline(id: string) {
  return useQuery({
    queryKey: ['timeline', 'project', id],
    queryFn: () => api<ActivityDto[]>(`/projects/${id}/timeline`),
  });
}

export function useCandidates(id: string, enabled: boolean) {
  return useQuery({
    queryKey: ['project', id, 'candidates'],
    queryFn: () => api<MemberCandidateDto[]>(`/projects/${id}/candidates`),
    enabled,
  });
}

export function useTasks(p: TaskListQuery, enabled = true) {
  return useQuery({
    queryKey: ['tasks', p],
    queryFn: () => api<Paginated<TaskDto>>('/tasks', { query: q(p) }),
    placeholderData: keepPreviousData,
    enabled,
  });
}

export function useTask(id: string | null) {
  return useQuery({
    queryKey: ['task', id],
    queryFn: () => api<TaskDetailDto>(`/tasks/${id}`),
    enabled: Boolean(id),
  });
}

export function useTemplates(enabled = true) {
  return useQuery({
    queryKey: ['project-templates'],
    queryFn: () => api<ProjectTemplateDto[]>('/project-templates'),
    enabled,
  });
}

export function useEntityFiles(target: { projectId?: string; taskId?: string }) {
  return useQuery({
    queryKey: ['files', target],
    queryFn: () => api<FileDto[]>('/files', { query: q(target) }),
  });
}
