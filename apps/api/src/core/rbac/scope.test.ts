import { describe, expect, it } from 'vitest';
import type { AuthContext } from '../auth/auth-context';
import { scopeWhere } from './scope';

const base: AuthContext = {
  sessionId: 's',
  userId: 'u1',
  email: 'a@b.c',
  fullName: 'A',
  locale: 'ru',
  roleCode: 'ROP',
  roleName: 'ROP',
  teamId: 't1',
  teamName: 'T1',
  headedTeamIds: ['t1', 't2'],
  permissions: {},
  telegramLinked: false,
};

const fields = {
  own: (userId: string) => ({ ownerId: userId }),
  team: (teamIds: string[]) => ({ teamId: { in: teamIds } }),
};

describe('scopeWhere', () => {
  it('ALL — без ограничений', () => {
    expect(scopeWhere({ ...base, permissions: { 'lead.read': 'ALL' } }, 'lead.read', fields)).toEqual({});
  });
  it('TEAM — отделы руководителя без дублей', () => {
    expect(scopeWhere({ ...base, permissions: { 'lead.read': 'TEAM' } }, 'lead.read', fields)).toEqual({
      teamId: { in: ['t1', 't2'] },
    });
  });
  it('OWN — только свои', () => {
    expect(scopeWhere({ ...base, permissions: { 'lead.read': 'OWN' } }, 'lead.read', fields)).toEqual({
      ownerId: 'u1',
    });
  });
  it('нет права — исключение', () => {
    expect(() => scopeWhere(base, 'lead.read', fields)).toThrow();
  });
});
