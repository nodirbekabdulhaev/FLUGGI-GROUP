'use client';

import type {
  ChatContactDto,
  ChatMessageDto,
  ConversationDto,
  Paginated,
  RecurringTodoDto,
  TodoDockDto,
  TodoDto,
  TodoListQuery,
} from '@fluggi/contracts';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api-client';

export const todoKeys = { all: ['todos'] as const };

export function useTodoDock(enabled = true) {
  return useQuery({
    queryKey: ['todos', 'dock'],
    queryFn: () => api<TodoDockDto>('/todos/dock'),
    enabled,
    refetchInterval: 60_000,
  });
}

export function useTodos(q: TodoListQuery) {
  return useQuery({
    queryKey: ['todos', 'list', q],
    queryFn: () =>
      api<Paginated<TodoDto>>('/todos', {
        query: q as Record<string, string | number | undefined>,
      }),
    placeholderData: keepPreviousData,
  });
}

export function useRecurring() {
  return useQuery({
    queryKey: ['todos', 'recurring'],
    queryFn: () => api<RecurringTodoDto[]>('/recurring-todos'),
  });
}

/** Мутация дел: после успеха обновляем списки, панель и задачи. */
export function useTodoMutation<TVars, TResult = unknown>(fn: (vars: TVars) => Promise<TResult>) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: fn,
    onSuccess: () => qc.invalidateQueries({ queryKey: todoKeys.all }),
  });
}

// ─────────────────────────── Чат ───────────────────────────

export function useChatUnread() {
  return useQuery({
    queryKey: ['chat', 'unread'],
    queryFn: () => api<{ count: number }>('/chats/unread'),
    refetchInterval: 15_000,
  });
}

export function useConversations(enabled: boolean) {
  return useQuery({
    queryKey: ['chat', 'list'],
    queryFn: () => api<ConversationDto[]>('/chats'),
    enabled,
    refetchInterval: enabled ? 10_000 : false,
  });
}

export function useChatContacts(enabled: boolean) {
  return useQuery({
    queryKey: ['chat', 'contacts'],
    queryFn: () => api<ChatContactDto[]>('/chats/contacts'),
    enabled,
  });
}

export function useChatMessages(id: string | null) {
  return useQuery({
    queryKey: ['chat', 'messages', id],
    queryFn: () => api<ChatMessageDto[]>(`/chats/${id}/messages`),
    enabled: Boolean(id),
    refetchInterval: id ? 4_000 : false,
  });
}
