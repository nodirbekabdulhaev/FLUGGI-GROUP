#!/usr/bin/env bash
# Печатает путь к PHP 8.2+ (на Beget в консоли по умолчанию может быть старая версия).
for c in "${PHP_BIN:-}" php8.4 php8.3 php8.2 /usr/local/bin/php8.4 /usr/local/bin/php8.3 /usr/local/bin/php8.2 php; do
  [ -n "$c" ] || continue
  p="$(command -v "$c" 2>/dev/null)" || continue
  if "$p" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' 2>/dev/null; then echo "$p"; exit 0; fi
done
exit 1
