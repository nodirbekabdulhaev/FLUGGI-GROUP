import { describe, expect, it } from 'vitest';
import { supportsSkipLocked } from './prisma.service';

describe('supportsSkipLocked', () => {
  it('MySQL 8+ и MariaDB 10.6+ — да; MySQL 5.7 и старая MariaDB — нет', () => {
    expect(supportsSkipLocked('8.0.46')).toBe(true);
    expect(supportsSkipLocked('8.4.2-log')).toBe(true);
    expect(supportsSkipLocked('5.7.44')).toBe(false);
    expect(supportsSkipLocked('5.7.44-log')).toBe(false);
    expect(supportsSkipLocked('10.6.12-MariaDB')).toBe(true);
    expect(supportsSkipLocked('10.3.39-MariaDB-0+deb10u1')).toBe(false);
    expect(supportsSkipLocked('11.4.2-MariaDB')).toBe(true);
  });
});
