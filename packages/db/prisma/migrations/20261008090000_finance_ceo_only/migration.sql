-- Финансы проектов (прибыль, расходы, план себестоимости) видит и ведёт только CEO:
-- снимаем права finance.read / expense.create / expense.update с РОП и проект-менеджера.
DELETE FROM "role_permissions" rp
USING "roles" r, "permissions" p
WHERE rp."role_id" = r."id"
  AND rp."permission_id" = p."id"
  AND r."code" IN ('ROP', 'PROJECT_MANAGER')
  AND p."code" IN ('finance.read', 'expense.create', 'expense.update');
