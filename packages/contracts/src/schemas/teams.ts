import { z } from 'zod';

export const createTeamSchema = z.object({
  name: z.string().trim().min(2, 'Укажите название').max(120),
  headId: z.uuid().nullish(),
});
export type CreateTeamInput = z.input<typeof createTeamSchema>;

export const updateTeamSchema = createTeamSchema.partial();
export type UpdateTeamInput = z.input<typeof updateTeamSchema>;

export interface TeamDto {
  id: string;
  name: string;
  head: { id: string; fullName: string } | null;
  membersCount: number;
  createdAt: string;
}
