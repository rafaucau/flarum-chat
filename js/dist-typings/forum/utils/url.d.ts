/**
 * Allowlist de protocolo para tudo que chega a `src`, `href` ou `window.open`.
 * O servidor monta as URLs, mas o valor passa por um payload JSON:API e `src`/`href`
 * aceitam `javascript:`. Qualquer coisa fora de http(s) ou caminho relativo à raiz
 * vira string vazia.
 */
export declare function safeFileUrl(raw: string | null | undefined): string;
