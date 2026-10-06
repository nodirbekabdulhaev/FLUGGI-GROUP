'use client';

import type {
  CreateUserInput,
  CreateUserResponse,
  Paginated,
  TeamDto,
  UpdateUserInput,
  UserDto,
  UserListQuery,
} from '@fluggi/contracts';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api-client';

export const teamKeys = {
  users: (q: UserListQuery) => ['users', q] as const,
  teams: ['teams'] as const,
};

export function useUsers(query: UserListQuery, enabled = true) {
  return useQuery({
    queryKey: teamKeys.users(query),
    queryFn: ({ signal }) => api<Paginated<UserDto>>('/users', { query: { ...query }, signal }),
    placeholderData: keepPreviousData,
    enabled,
  });
}

export function useTeams() {
  return useQuery({
    queryKey: teamKeys.teams,
    queryFn: ({ signal }) => api<TeamDto[]>('/teams', { signal }),
  });
}

function useInvalidateUsers() {
  const qc = useQueryClient();
  return () =>
    Promise.all([
      qc.invalidateQueries({ queryKey: ['users'] }),
      qc.invalidateQueries({ queryKey: teamKeys.teams }),
    ]);
}

export function useCreateUser() {
  const invalidate = useInvalidateUsers();
  return useMutation({
    mutationFn: (body: CreateUserInput) =>
      api<CreateUserResponse>('/users', { method: 'POST', body }),
    onSuccess: invalidate,
  });
}

export function useUpdateUser() {
  const invalidate = useInvalidateUsers();
  return useMutation({
    mutationFn: ({ id, body }: { id: string; body: UpdateUserInput }) =>
      api<UserDto>(`/users/${id}`, { method: 'PATCH', body }),
    onSuccess: invalidate,
  });
}

export function useSetBlocked() {
  const invalidate = useInvalidateUsers();
  return useMutation({
    mutationFn: ({ id, blocked }: { id: string; blocked: boolean }) =>
      api<UserDto>(`/users/${id}/${blocked ? 'block' : 'unblock'}`, { method: 'POST' }),
    onSuccess: invalidate,
  });
}

export function useResetPassword() {
  return useMutation({
    mutationFn: (id: string) =>
      api<{ temporaryPassword: string }>(`/users/${id}/reset-password`, { method: 'POST' }),
  });
}
