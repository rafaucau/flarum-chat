/**
 * Allowlist de protocolo para tudo que chega a `src`, `href` ou `window.open`.
 * O servidor monta as URLs, mas o valor passa por um payload JSON:API e `src`/`href`
 * aceitam `javascript:`. Qualquer coisa fora de http(s) ou caminho relativo à raiz
 * vira string vazia.
 */
export function safeFileUrl(raw: string | null | undefined): string {
  const value = (raw ?? '').trim();

  if (value === '') return '';

  // `//host` fica de fora: é relativo ao protocolo e pode apontar para qualquer lugar.
  if (value.startsWith('/') && !value.startsWith('//')) return value;

  try {
    const parsed = new URL(value, window.location.origin);

    return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.href : '';
  } catch {
    return '';
  }
}
