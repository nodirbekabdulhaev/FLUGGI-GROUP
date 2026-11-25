'use client';

import type { FormField, PublicFormDto } from '@fluggi/contracts';
import { useEffect, useRef, useState } from 'react';

const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

/** Форма для посетителя сайта: без входа, отправка в API, высота сообщается родительской странице. */
export function PublicForm({ formKey }: { formKey: string }) {
  const [form, setForm] = useState<PublicFormDto | null>(null);
  const [missing, setMissing] = useState(false);
  const [values, setValues] = useState<Record<string, string>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [sending, setSending] = useState(false);
  const [done, setDone] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const renderedAt = useRef(Date.now());
  const trap = useRef<HTMLInputElement>(null);
  const root = useRef<HTMLDivElement>(null);

  useEffect(() => {
    fetch(`/api/v1/public/forms/${encodeURIComponent(formKey)}`)
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((f: PublicFormDto) => setForm(f))
      .catch(() => setMissing(true));
  }, [formKey]);

  // Сайт подстраивает высоту iframe под форму
  useEffect(() => {
    if (!root.current || window.parent === window) return;
    const post = () =>
      window.parent.postMessage(
        {
          fluggiForm: formKey,
          height: Math.ceil(root.current!.getBoundingClientRect().height) + 8,
        },
        '*',
      );
    const ro = new ResizeObserver(post);
    ro.observe(root.current);
    post();
    return () => ro.disconnect();
  }, [formKey, form, done]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSending(true);
    setErrors({});
    setFailure(null);
    const q = new URLSearchParams(window.location.search);
    const utm = Object.fromEntries(UTM_KEYS.filter((k) => q.get(k)).map((k) => [k, q.get(k)!]));
    try {
      const res = await fetch(`/api/v1/public/forms/${encodeURIComponent(formKey)}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          data: values,
          utm,
          page: q.get('page') ?? document.referrer ?? undefined,
          website: trap.current?.value || undefined,
          renderedAt: renderedAt.current,
        }),
      });
      const body = (await res.json().catch(() => null)) as {
        message?: string;
        error?: { message?: string; details?: { path: string; message: string }[] };
      } | null;
      if (!res.ok) {
        setErrors(
          Object.fromEntries(
            (body?.error?.details ?? []).map((d) => [d.path.replace(/^data\./, ''), d.message]),
          ),
        );
        setFailure(
          res.status === 429
            ? 'Слишком много заявок. Попробуйте через минуту.'
            : (body?.error?.message ?? 'Не удалось отправить. Попробуйте ещё раз.'),
        );
        return;
      }
      setDone(body?.message ?? form?.successMessage ?? 'Спасибо!');
    } catch {
      setFailure('Нет связи. Проверьте интернет и попробуйте ещё раз.');
    } finally {
      setSending(false);
    }
  };

  const input = (f: FormField) => {
    const common = {
      id: `ff-${f.key}`,
      name: f.key,
      required: f.required,
      value: values[f.key] ?? '',
      'aria-invalid': Boolean(errors[f.key]),
      className:
        'w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-[15px] text-zinc-900 outline-none focus:border-[#2a78d6] focus:ring-2 focus:ring-[#2a78d6]/20 aria-[invalid=true]:border-red-500',
      onChange: (
        e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>,
      ) => setValues((s) => ({ ...s, [f.key]: e.target.value })),
    };
    if (f.type === 'textarea') return <textarea rows={3} {...common} />;
    if (f.type === 'select')
      return (
        <select {...common}>
          <option value="">—</option>
          {f.options?.map((o) => (
            <option key={o}>{o}</option>
          ))}
        </select>
      );
    return (
      <input
        {...common}
        type={f.type === 'phone' ? 'tel' : f.type === 'email' ? 'email' : 'text'}
        inputMode={f.type === 'phone' ? 'tel' : undefined}
        autoComplete={
          f.type === 'phone'
            ? 'tel'
            : f.type === 'email'
              ? 'email'
              : f.key === 'name'
                ? 'name'
                : undefined
        }
        placeholder={f.type === 'phone' ? '+998 90 123-45-67' : undefined}
      />
    );
  };

  return (
    <div ref={root} className="mx-auto max-w-lg bg-white p-4 font-sans text-zinc-900">
      <style>{`html,body{background:#fff}`}</style>
      {missing ? (
        <p className="py-8 text-center text-sm text-zinc-500">Форма недоступна.</p>
      ) : !form ? (
        <p className="py-8 text-center text-sm text-zinc-400">Загрузка…</p>
      ) : done ? (
        <div className="py-8 text-center" role="status">
          <p className="text-3xl">✓</p>
          <p className="mt-2 text-lg font-medium">{done}</p>
        </div>
      ) : (
        <form onSubmit={submit} className="grid gap-3" noValidate>
          <div>
            <h1 className="text-xl font-semibold">{form.title}</h1>
            {form.description ? (
              <p className="mt-1 text-sm text-zinc-600">{form.description}</p>
            ) : null}
          </div>
          {form.fields.map((f) => (
            <label key={f.key} htmlFor={`ff-${f.key}`} className="grid gap-1 text-sm">
              <span className="font-medium">
                {f.label}
                {f.required ? <span className="text-red-500"> *</span> : null}
              </span>
              {input(f)}
              {errors[f.key] ? <span className="text-xs text-red-600">{errors[f.key]}</span> : null}
            </label>
          ))}
          {/* Ловушка для ботов: человек это поле не видит */}
          <input
            ref={trap}
            name="website"
            tabIndex={-1}
            autoComplete="off"
            aria-hidden
            className="absolute -left-[9999px] h-0 w-0 opacity-0"
          />
          {failure ? <p className="text-sm text-red-600">{failure}</p> : null}
          <button
            type="submit"
            disabled={sending}
            className="mt-1 rounded-lg bg-[#2a78d6] px-4 py-3 text-[15px] font-semibold text-white hover:bg-[#2266bb] disabled:opacity-60"
          >
            {sending ? 'Отправляем…' : form.buttonText}
          </button>
        </form>
      )}
    </div>
  );
}
