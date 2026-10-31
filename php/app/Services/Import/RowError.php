<?php

namespace App\Services\Import;

use RuntimeException;

/** Ошибка одной строки файла импорта: текст причины уже на языке пользователя. Строка пропускается, остальные импортируются. */
final class RowError extends RuntimeException {}
