<!doctype html><html><head><meta charset="utf-8">
<style>
 body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1e293b; }
 h1 { font-size: 15px; margin: 0 0 4px; } .meta { color: #64748b; margin-bottom: 10px; }
 table { width: 100%; border-collapse: collapse; } th { background: #eef2ff; text-align: left; } th, td { border: 1px solid #cbd5e1; padding: 4px 5px; }
 .sum { margin: 8px 0; } .sum span { display: inline-block; margin-right: 14px; }
</style></head><body>
<h1>{{ $title }}</h1><div class="meta">{{ now()->format('d.m.Y H:i') }}</div>
@if($summary)<div class="sum">@foreach($summary as $k => $v)<span><b>{{ $k }}:</b> {{ $v }}</span>@endforeach</div>@endif
<table><thead><tr>@foreach($headers as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
<tbody>@foreach($rows as $r)<tr>@foreach((array)$r as $c)<td>{{ $c }}</td>@endforeach</tr>@endforeach</tbody></table>
</body></html>
