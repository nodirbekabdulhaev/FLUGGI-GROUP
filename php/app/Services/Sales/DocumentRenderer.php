<?php

namespace App\Services\Sales;

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings as WordSettings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Tab;

/**
 * Документ (КП, договор) из модели блоков: HTML (предпросмотр и печать), PDF, Word, TXT.
 * Дизайн у всех форматов один и закреплён здесь: синяя шапка, таблица позиций, реквизиты сторон.
 * PDF — DejaVu Sans (кириллица и узбекская латиница с ʻ ʼ).
 */
final class DocumentRenderer
{
    public const ACCENT = '#2a78d6';

    public const INK = '#18181b';

    public const MUTED = '#71717a';

    public const LINE = '#e4e4e7';

    public const ZEBRA = '#f4f7fb';

    public const TYPES = [
        'html' => 'text/html; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public static function render(array $doc, string $format): string
    {
        return match ($format) {
            'html' => self::html($doc),
            'txt' => self::txt($doc),
            'pdf' => self::pdf($doc),
            'docx' => self::docx($doc),
        };
    }

    /**
     * Имя файла латиницей: «ДГ-00001» → «DG-00001». Только filename="…" — с filename*=UTF-8''
     * часть браузеров отдаёт имя «download».
     */
    public static function asciiName(string $name): string
    {
        $tr = ['Д' => 'D', 'Г' => 'G', 'К' => 'K', 'П' => 'P'];
        $out = preg_replace_callback('/[^\x20-\x7e]/u', fn ($m) => $tr[$m[0]] ?? '_', $name);

        return str_replace(['"', '\\'], '_', $out);
    }

    private static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ─────────────── HTML ───────────────

    private static function css(): string
    {
        [$a, $i, $m, $l, $z] = [self::ACCENT, self::INK, self::MUTED, self::LINE, self::ZEBRA];

        return <<<CSS
@page { size: A4; margin: 18mm 16mm; }
* { box-sizing: border-box; }
html { background: #eef0f3; }
body { margin: 0; font: 10.5pt/1.5 "DejaVu Sans", "Segoe UI", Arial, sans-serif; color: $i; }
.page { background: #fff; max-width: 210mm; margin: 16px auto; padding: 18mm 16mm; box-shadow: 0 1px 4px rgba(0,0,0,.12); }
@media print { html { background: #fff; } .page { margin: 0; padding: 0; box-shadow: none; max-width: none; } }
@media (max-width: 640px) { .page { padding: 16px; margin: 0; } .parties { grid-template-columns: 1fr !important; } }
.brand { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; border-bottom: 2px solid $a; padding-bottom: 8px; margin-bottom: 22px; }
.brand b { color: $a; font-size: 17pt; letter-spacing: .02em; }
.brand span { color: $m; font-size: 8.5pt; text-align: right; }
h1 { font-size: 16pt; margin: 0 0 2px; }
h1.center, .sub.center { text-align: center; }
.sub { color: $m; margin: 0 0 14px; }
.meta { display: flex; justify-content: space-between; margin: 6px 0 16px; }
h2 { font-size: 11pt; margin: 16px 0 6px; }
p { margin: 0 0 6px; text-align: justify; }
table.items { width: 100%; border-collapse: collapse; margin: 8px 0 12px; font-size: 9.5pt; }
table.items th { background: $a; color: #fff; font-weight: 600; padding: 6px 8px; }
table.items td { padding: 6px 8px; border-bottom: 1px solid $l; vertical-align: top; }
table.items tbody tr:nth-child(even) td { background: $z; }
table.items .r { text-align: right; white-space: nowrap; }
table.items tfoot td { border: 0; padding: 3px 8px; }
table.items tfoot .strong td { font-weight: 700; font-size: 11pt; border-top: 2px solid $a; padding-top: 6px; }
table.kv { border-collapse: collapse; margin: 4px 0 12px; }
table.kv td { padding: 2px 16px 2px 0; vertical-align: top; }
table.kv td:first-child { color: $m; white-space: nowrap; }
.parties { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 8px; font-size: 9.5pt; }
.party h3 { margin: 0 0 4px; font-size: 10pt; color: $a; text-transform: uppercase; letter-spacing: .04em; }
.party .name { font-weight: 700; margin-bottom: 4px; }
.party div.l { margin: 1px 0; }
.party div.l span { color: $m; }
.signline { margin-top: 22px; }
.signline .line { display: inline-block; width: 45%; border-bottom: 1px solid $i; margin-right: 6px; }
.signline small { display: block; color: $m; margin-top: 2px; }
.sign { margin-top: 28px; display: flex; justify-content: space-between; align-items: flex-end; gap: 24px; }
.sign .line { display: inline-block; width: 140px; border-bottom: 1px solid $i; }
CSS;
    }

    private static function party(array $p): string
    {
        $lines = implode('', array_map(fn ($kv) => '<div class="l"><span>'.self::e($kv[0]).':</span> '.self::e($kv[1]).'</div>', $p['lines']));

        return '<div class="party"><h3>'.self::e($p['role']).'</h3><div class="name">'.self::e($p['name']).'</div>'.$lines
            .'<div class="signline">'.self::e($p['position']).'<br><span class="line"></span>/ '.self::e($p['signer']).' /<small>подпись · М.П.</small></div></div>';
    }

    private static function table(array $b): string
    {
        $cls = fn ($i) => ($b['align'][$i] ?? 'left') === 'right' ? ' class="r"' : '';
        $span = count($b['head']) - 1;
        $html = '<table class="items"><colgroup>';
        foreach ($b['widths'] as $w) {
            $html .= '<col style="width:'.number_format($w * 100, 1, '.', '').'%">';
        }
        $html .= '</colgroup><thead><tr>';
        foreach ($b['head'] as $i => $h) {
            $html .= '<th'.$cls($i).'>'.self::e($h).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($b['rows'] as $r) {
            $html .= '<tr>';
            foreach ($r as $i => $c) {
                $html .= '<td'.$cls($i).'>'.nl2br(self::e($c), false).'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody><tfoot>';
        foreach ($b['totals'] as $t) {
            $html .= '<tr'.(! empty($t['strong']) ? ' class="strong"' : '').'><td colspan="'.$span.'" class="r">'.self::e($t['label']).'</td><td class="r">'.self::e($t['value']).'</td></tr>';
        }

        return $html.'</tfoot></table>';
    }

    private static function block(array $b): string
    {
        return match ($b['t']) {
            'title' => '<h1'.(! empty($b['center']) ? ' class="center"' : '').'>'.self::e($b['text']).'</h1>'
                .(! empty($b['sub']) ? '<p class="sub'.(! empty($b['center']) ? ' center' : '').'">'.self::e($b['sub']).'</p>' : ''),
            'meta' => '<div class="meta"><span>'.self::e($b['left']).'</span><span>'.self::e($b['right']).'</span></div>',
            'h' => '<h2>'.self::e($b['text']).'</h2>',
            'p' => '<p>'.self::e($b['text']).'</p>',
            'kv' => '<table class="kv">'.implode('', array_map(fn ($r) => '<tr><td>'.self::e($r[0]).'</td><td>'.self::e($r[1]).'</td></tr>', $b['rows'])).'</table>',
            'table' => self::table($b),
            'parties' => '<div class="parties">'.self::party($b['parties'][0]).self::party($b['parties'][1]).'</div>',
            'sign' => '<div class="sign"><div>'.self::e($b['position']).'<br><b>'.self::e($b['company']).'</b></div><div><span class="line"></span> / '.self::e($b['signer']).' /</div></div>',
        };
    }

    /** HTML документа: предпросмотр в CRM и печать из браузера. */
    public static function html(array $doc): string
    {
        return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
            .self::e($doc['title']).'</title><style>'.self::css().'</style></head><body><div class="page"><div class="brand"><b>'
            .self::e($doc['brand']['name']).'</b><span>'.self::e($doc['brand']['contacts']).'</span></div>'
            .implode("\n", array_map([self::class, 'block'], $doc['blocks'])).'</div></body></html>';
    }

    // ─────────────── TXT ───────────────

    private static function txtBlock(array $b): string
    {
        return match ($b['t']) {
            'title' => implode("\n", array_filter([mb_strtoupper($b['text']), $b['sub'] ?? null])),
            'meta' => "{$b['left']}    {$b['right']}",
            'h' => "\n{$b['text']}",
            'p' => $b['text'],
            'kv' => implode("\n", array_map(fn ($r) => "{$r[0]}: {$r[1]}", $b['rows'])),
            'table' => implode("\n", array_merge(
                [implode(' | ', $b['head'])],
                array_map(fn ($r, $i) => ($i + 1).'. '.implode(' | ', array_map(fn ($c) => str_replace("\n", ' — ', $c), $r)), $b['rows'], array_keys($b['rows'])),
                array_map(fn ($t) => "{$t['label']}: {$t['value']}", $b['totals']),
            )),
            'parties' => implode("\n\n", array_map(fn ($p) => implode("\n", array_merge(
                [mb_strtoupper($p['role']).': '.$p['name']],
                array_map(fn ($kv) => "{$kv[0]}: {$kv[1]}", $p['lines']),
                ["{$p['position']} ____________ / {$p['signer']} /"],
            )), $b['parties'])),
            'sign' => "\n{$b['position']} {$b['company']} ____________ / {$b['signer']} /",
        };
    }

    /** Текст документа — для копирования в мессенджер или почту. */
    public static function txt(array $doc): string
    {
        return "{$doc['brand']['name']}\n{$doc['brand']['contacts']}\n\n".implode("\n", array_map([self::class, 'txtBlock'], $doc['blocks']))."\n";
    }

    // ─────────────── PDF ───────────────

    /** HTML для dompdf: таблицы вместо flex/grid (dompdf их не поддерживает). */
    private static function pdfHtml(array $doc): string
    {
        [$a, $i, $m, $l, $z] = [self::ACCENT, self::INK, self::MUTED, self::LINE, self::ZEBRA];
        $css = <<<CSS
@page { size: A4; margin: 16mm 16mm 18mm; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; line-height: 1.45; color: $i; }
table.brand { width: 100%; border-collapse: collapse; border-bottom: 1.6pt solid $a; margin-bottom: 16pt; }
table.brand td { vertical-align: bottom; padding: 0 0 5pt; }
.bname { color: $a; font-size: 16pt; font-weight: bold; }
.bcontacts { color: $m; font-size: 7.5pt; text-align: right; }
h1 { font-size: 15pt; margin: 0 0 2pt; }
.center { text-align: center; }
.sub { color: $m; font-size: 10pt; margin: 0 0 10pt; }
table.meta { width: 100%; margin: 4pt 0 14pt; font-size: 10pt; }
h2 { font-size: 10.5pt; margin: 12pt 0 4pt; }
p { margin: 0 0 5pt; text-align: justify; }
table.items { width: 100%; border-collapse: collapse; margin: 6pt 0 4pt; font-size: 8.5pt; }
table.items th { background: $a; color: #fff; font-weight: bold; padding: 5pt; text-align: left; }
table.items td { padding: 5pt; border-bottom: 0.5pt solid $l; vertical-align: top; }
table.items tr.zebra td { background: $z; }
.r { text-align: right !important; }
table.totals { width: 100%; border-collapse: collapse; margin: 0 0 10pt; }
table.totals td { padding: 2pt 5pt; text-align: right; }
table.totals tr.strong td { font-weight: bold; font-size: 11pt; border-top: 1.4pt solid $a; padding-top: 4pt; }
table.kv { border-collapse: collapse; margin: 2pt 0 8pt; }
table.kv td { padding: 1.5pt 14pt 1.5pt 0; vertical-align: top; }
table.kv td.k { color: $m; white-space: nowrap; }
table.parties { width: 100%; border-collapse: collapse; margin-top: 6pt; page-break-inside: avoid; }
table.parties td { width: 50%; vertical-align: top; padding-right: 12pt; font-size: 8.5pt; }
.prole { color: $a; font-weight: bold; font-size: 9pt; text-transform: uppercase; }
.pname { font-weight: bold; margin-bottom: 3pt; }
.muted { color: $m; }
.sline { display: inline-block; width: 45%; border-bottom: 0.6pt solid $i; }
table.sign { width: 100%; margin-top: 24pt; page-break-inside: avoid; }
CSS;
        $body = '';
        foreach ($doc['blocks'] as $b) {
            $body .= match ($b['t']) {
                'title' => '<h1'.(! empty($b['center']) ? ' class="center"' : '').'>'.self::e($b['text']).'</h1>'
                    .(! empty($b['sub']) ? '<p class="sub'.(! empty($b['center']) ? ' center' : '').'">'.self::e($b['sub']).'</p>' : ''),
                'meta' => '<table class="meta"><tr><td>'.self::e($b['left']).'</td><td class="r">'.self::e($b['right']).'</td></tr></table>',
                'h' => '<h2>'.self::e($b['text']).'</h2>',
                'p' => '<p>'.self::e($b['text']).'</p>',
                'kv' => '<table class="kv">'.implode('', array_map(fn ($r) => '<tr><td class="k">'.self::e($r[0]).'</td><td>'.self::e($r[1]).'</td></tr>', $b['rows'])).'</table>',
                'table' => self::pdfTable($b),
                'parties' => '<table class="parties"><tr>'.implode('', array_map(fn ($p) => '<td><div class="prole">'.self::e($p['role']).'</div><div class="pname">'.self::e($p['name']).'</div>'
                    .implode('', array_map(fn ($kv) => '<div>'.self::e($kv[0]).': '.self::e($kv[1]).'</div>', $p['lines']))
                    .'<div style="margin-top:12pt">'.self::e($p['position']).'</div><div style="margin-top:12pt"><span class="sline">&nbsp;</span> / '.self::e($p['signer']).' /</div><div class="muted" style="font-size:7pt">подпись · М.П.</div></td>', $b['parties'])).'</tr></table>',
                'sign' => '<table class="sign"><tr><td>'.self::e($b['position']).'<br><b>'.self::e($b['company']).'</b></td><td class="r" style="vertical-align:bottom"><span class="sline" style="width:120pt">&nbsp;</span> / '.self::e($b['signer']).' /</td></tr></table>',
            };
        }

        return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>'.self::e($doc['title']).'</title><style>'.$css.'</style></head><body>'
            .'<table class="brand"><tr><td class="bname">'.self::e($doc['brand']['name']).'</td><td class="bcontacts">'.self::e($doc['brand']['contacts']).'</td></tr></table>'
            .$body.'</body></html>';
    }

    private static function pdfTable(array $b): string
    {
        $cls = fn ($i) => ($b['align'][$i] ?? 'left') === 'right' ? ' class="r"' : '';
        $html = '<table class="items"><thead><tr>';
        foreach ($b['head'] as $i => $h) {
            $html .= '<th'.$cls($i).' style="width:'.number_format($b['widths'][$i] * 100, 1, '.', '').'%">'.self::e($h).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($b['rows'] as $ri => $r) {
            $html .= '<tr'.($ri % 2 ? ' class="zebra"' : '').'>';
            foreach ($r as $i => $c) {
                $html .= '<td'.$cls($i).'>'.nl2br(self::e($c), false).'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table><table class="totals">';
        foreach ($b['totals'] as $t) {
            $html .= '<tr'.(! empty($t['strong']) ? ' class="strong"' : '').'><td style="width:72%">'.self::e($t['label']).'</td><td>'.self::e($t['value']).'</td></tr>';
        }

        return $html.'</table>';
    }

    public static function pdf(array $doc): string
    {
        $cache = storage_path('framework/cache/dompdf');
        if (! is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }
        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setFontCache($cache);
        $options->setTempDir($cache);
        $dompdf = new Dompdf($options);
        $dompdf->getOptions()->setChroot([base_path('vendor/dompdf/dompdf')]);
        $dompdf->addInfo('Title', $doc['title']);
        $dompdf->addInfo('Author', $doc['brand']['name']);
        $dompdf->loadHtml(self::pdfHtml($doc), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();
        // Нумерация страниц
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $text = $doc['title'].' · стр. {PAGE_NUM} из {PAGE_COUNT}';
        $w = $dompdf->getFontMetrics()->getTextWidth(str_replace(['{PAGE_NUM}', '{PAGE_COUNT}'], ['00', '00'], $text), $font, 7);
        $canvas->page_text(($canvas->get_width() - $w) / 2, $canvas->get_height() - 32, $text, $font, 7, [0.44, 0.44, 0.48]);

        return (string) $dompdf->output();
    }

    // ─────────────── Word ───────────────

    public static function docx(array $doc): string
    {
        WordSettings::setOutputEscapingEnabled(true);
        $hex = fn (string $c) => ltrim($c, '#');
        $font = 'Arial';
        // A4 минус поля 2 см с каждой стороны, в twip
        $content = 11906 - 2 * 1134;
        $word = new PhpWord;
        $word->getDocInfo()->setCreator($doc['brand']['name'])->setTitle($doc['title']);
        $word->setDefaultFontName($font);
        $word->setDefaultFontSize(10);
        $section = $word->addSection(['marginTop' => 1134, 'marginBottom' => 1134, 'marginLeft' => 1134, 'marginRight' => 1134]);

        $header = $section->addHeader();
        $hp = $header->addTextRun([
            'borderBottomSize' => 12, 'borderBottomColor' => $hex(self::ACCENT), 'spaceAfter' => 80,
            'tabs' => [new Tab('right', $content)],
        ]);
        $hp->addText($doc['brand']['name'], ['bold' => true, 'size' => 15, 'color' => $hex(self::ACCENT)]);
        $hp->addText("\t".$doc['brand']['contacts'], ['size' => 7.5, 'color' => $hex(self::MUTED)]);
        $section->addFooter()->addPreserveText($doc['title'].' · стр. {PAGE}', ['size' => 7, 'color' => $hex(self::MUTED)], ['alignment' => Jc::CENTER]);

        $p = fn (array $o = []) => ['spaceAfter' => ($o['after'] ?? 80) * 1, 'spaceBefore' => $o['before'] ?? 0, 'alignment' => $o['align'] ?? Jc::START];
        $none = ['borderSize' => 0, 'borderColor' => 'FFFFFF'];

        foreach ($doc['blocks'] as $b) {
            switch ($b['t']) {
                case 'title':
                    $align = ! empty($b['center']) ? Jc::CENTER : Jc::START;
                    $section->addText($b['text'], ['bold' => true, 'size' => 15], $p(['align' => $align, 'after' => ! empty($b['sub']) ? 20 : 160]));
                    if (! empty($b['sub'])) {
                        $section->addText($b['sub'], ['color' => $hex(self::MUTED)], $p(['align' => $align, 'after' => 160]));
                    }
                    break;
                case 'meta':
                    $t = $section->addTable(['borderSize' => 0, 'borderColor' => 'FFFFFF', 'width' => $content, 'unit' => 'dxa']);
                    $t->addRow();
                    $t->addCell($content / 2, $none)->addText($b['left']);
                    $t->addCell($content / 2, $none)->addText($b['right'], [], ['alignment' => Jc::END]);
                    $section->addText('', [], $p(['after' => 120]));
                    break;
                case 'h':
                    $section->addText($b['text'], ['bold' => true, 'size' => 10.5], $p(['before' => 160, 'after' => 60]));
                    break;
                case 'p':
                    $section->addText($b['text'], ['size' => 9.5], $p(['align' => Jc::BOTH]));
                    break;
                case 'kv':
                    foreach ($b['rows'] as [$k, $v]) {
                        $run = $section->addTextRun($p(['after' => 30]));
                        $run->addText("$k: ", ['color' => $hex(self::MUTED), 'size' => 9.5]);
                        $run->addText($v, ['size' => 9.5]);
                    }
                    break;
                case 'table':
                    $cols = array_map(fn ($w) => (int) round($w * $content), $b['widths']);
                    $t = $section->addTable(['borderSize' => 0, 'borderColor' => 'FFFFFF', 'cellMarginLeft' => 90, 'cellMarginRight' => 90, 'cellMarginTop' => 60, 'cellMarginBottom' => 60, 'width' => $content, 'unit' => 'dxa']);
                    $t->addRow(null, ['tblHeader' => true]);
                    foreach ($b['head'] as $i => $h) {
                        $t->addCell($cols[$i], ['bgColor' => $hex(self::ACCENT)])
                            ->addText($h, ['bold' => true, 'size' => 8.5, 'color' => 'FFFFFF'], ['alignment' => $b['align'][$i] === 'right' ? Jc::END : Jc::START, 'spaceAfter' => 0]);
                    }
                    foreach ($b['rows'] as $ri => $r) {
                        $t->addRow();
                        foreach ($r as $i => $c) {
                            $cell = $t->addCell($cols[$i], array_filter(['bgColor' => $ri % 2 ? $hex(self::ZEBRA) : null, 'borderBottomSize' => 4, 'borderBottomColor' => $hex(self::LINE)]));
                            foreach (explode("\n", $c) as $j => $line) {
                                $cell->addText($line, ['size' => $j ? 7.5 : 8.5, 'color' => $j ? $hex(self::MUTED) : null], ['alignment' => $b['align'][$i] === 'right' ? Jc::END : Jc::START, 'spaceAfter' => 0]);
                            }
                        }
                    }
                    foreach ($b['totals'] as $tt) {
                        $strong = ! empty($tt['strong']);
                        $run = $section->addTextRun($p(['align' => Jc::END, 'before' => $strong ? 60 : 40, 'after' => 20]));
                        $run->addText("{$tt['label']}: ", ['bold' => $strong, 'size' => $strong ? 11 : 9.5]);
                        $run->addText($tt['value'], ['bold' => $strong, 'size' => $strong ? 11 : 9.5]);
                    }
                    $section->addText('', [], $p(['after' => 120]));
                    break;
                case 'parties':
                    $t = $section->addTable(['borderSize' => 0, 'borderColor' => 'FFFFFF', 'width' => $content, 'unit' => 'dxa']);
                    $t->addRow(null, ['cantSplit' => true]);
                    foreach ($b['parties'] as $party) {
                        $cell = $t->addCell($content / 2, $none);
                        $cell->addText(mb_strtoupper($party['role']), ['bold' => true, 'size' => 9, 'color' => $hex(self::ACCENT)], ['spaceAfter' => 40]);
                        $cell->addText($party['name'], ['bold' => true, 'size' => 9], ['spaceAfter' => 40]);
                        foreach ($party['lines'] as [$k, $v]) {
                            $run = $cell->addTextRun(['spaceAfter' => 20]);
                            $run->addText("$k: ", ['size' => 8.5, 'color' => $hex(self::MUTED)]);
                            $run->addText($v, ['size' => 8.5]);
                        }
                        $cell->addText($party['position'], ['size' => 8.5], ['spaceBefore' => 240, 'spaceAfter' => 200]);
                        $cell->addText("____________________ / {$party['signer']} /", ['size' => 8.5], ['spaceAfter' => 20]);
                        $cell->addText('подпись · М.П.', ['size' => 7, 'color' => $hex(self::MUTED)]);
                    }
                    break;
                case 'sign':
                    $section->addText($b['position'], ['size' => 9.5], $p(['before' => 480, 'after' => 20]));
                    $run = $section->addTextRun($p());
                    $run->addText($b['company'], ['bold' => true, 'size' => 9.5]);
                    $run->addText("        ____________________ / {$b['signer']} /", ['size' => 9.5]);
                    break;
            }
        }

        $dir = storage_path('framework/cache');
        $tmp = tempnam(is_dir($dir) && is_writable($dir) ? $dir : sys_get_temp_dir(), 'docx');
        try {
            IOFactory::createWriter($word, 'Word2007')->save($tmp);

            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}
