import { describe, expect, it } from 'vitest';
import { ROLE_CODES } from './enums';
import {
  CEO_LOCKED_PERMISSIONS,
  DEFAULT_ROLE_PERMISSIONS,
  PERMISSION_CODES,
  hasPermission,
} from './permissions';

describe('DEFAULT_ROLE_PERMISSIONS', () => {
  it('содержит все роли и только существующие коды прав', () => {
    for (const role of ROLE_CODES) {
      const map = DEFAULT_ROLE_PERMISSIONS[role];
      expect(map).toBeDefined();
      for (const code of Object.keys(map)) expect(PERMISSION_CODES).toContain(code);
    }
  });

  it('CEO имеет все права со scope ALL', () => {
    for (const code of PERMISSION_CODES) expect(DEFAULT_ROLE_PERMISSIONS.CEO[code]).toBe('ALL');
    for (const code of CEO_LOCKED_PERMISSIONS)
      expect(DEFAULT_ROLE_PERMISSIONS.CEO[code]).toBe('ALL');
  });

  it('финансы компании и зарплаты всех — только у CEO', () => {
    for (const role of ROLE_CODES.filter((r) => r !== 'CEO')) {
      const map = DEFAULT_ROLE_PERMISSIONS[role];
      expect(map['finance.company.read']).toBeUndefined();
      expect(map['payroll.manage']).toBeUndefined();
      expect(map['payroll.read'] ?? 'OWN').toBe('OWN');
    }
  });

  it('исполнитель не видит CRM-продажи и финансы', () => {
    const map = DEFAULT_ROLE_PERMISSIONS.EXECUTOR;
    for (const code of ['lead.read', 'deal.read', 'payment.read', 'finance.read'] as const) {
      expect(map[code]).toBeUndefined();
    }
  });

  it('менеджер не может менять чужие сделки и настройки', () => {
    const map = DEFAULT_ROLE_PERMISSIONS.MANAGER;
    expect(map['deal.update']).toBe('OWN');
    expect(map['settings.manage']).toBeUndefined();
    expect(map['role.manage']).toBeUndefined();
  });
});

describe('hasPermission', () => {
  it('учитывает минимальную область', () => {
    expect(hasPermission({ 'lead.read': 'TEAM' }, 'lead.read')).toBe(true);
    expect(hasPermission({ 'lead.read': 'TEAM' }, 'lead.read', 'TEAM')).toBe(true);
    expect(hasPermission({ 'lead.read': 'TEAM' }, 'lead.read', 'ALL')).toBe(false);
    expect(hasPermission({}, 'lead.read')).toBe(false);
  });
});
