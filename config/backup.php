<?php

return [
    // how many daily dumps to keep (spec: 7–30)
    'keep' => (int) env('BACKUP_KEEP', 14),
];
