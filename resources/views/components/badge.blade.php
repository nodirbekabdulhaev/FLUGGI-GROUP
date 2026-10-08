@props(['color' => 'slate'])
@php
$map = [
 'slate' => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
 'blue' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
 'indigo' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',
 'amber' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
 'emerald' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
 'rose' => 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300',
];
@endphp
<span {{ $attributes->merge(['class' => 'badge '.($map[$color] ?? $map['slate'])]) }}>{{ $slot }}</span>
