/**
 * Resolução de shortcode → emoji Unicode.
 *
 * Mapa local em vez do flarum/emoji: lá os dados ficam num chunk lazy que talvez
 * nunca carregue, então `flarum.reg.get()` quase sempre daria `undefined`. O
 * `simple-emoji-map` é o mesmo pacote que o flarum/emoji usa, então `:heart:` é igual
 * no chat e nos posts.
 *
 * Lazy porque são 55 KB de JSON; uma tabela pequena embutida cobre as reações comuns
 * para a primeira pintura nunca sair vazia.
 */

/** Reações da barra e do picker, mais algumas óbvias, resolvidas de forma síncrona. */
const COMMON: Record<string, string> = {
  heart: '❤️',
  '+1': '👍',
  '-1': '👎',
  tada: '🎉',
  eyes: '👀',
  laughing: '😆',
  smile: '😄',
  cry: '😢',
  fire: '🔥',
  rocket: '🚀',
  thinking: '🤔',
  clap: '👏',
  speech_balloon: '💬',
  white_check_mark: '✅',
  x: '❌',
};

/** nome → unicode, invertendo o mapa unicode → nomes do pacote. */
let full: Record<string, string> | null = null;
let loading = false;

/**
 * Dispara o carregamento do chunk; só a primeira chamada busca. Redesenha ao
 * terminar para o que já foi renderizado com COMMON (ou como shortcode) atualizar.
 */
export function loadEmojiMap(): void {
  if (full || loading) return;

  loading = true;

  // `./emojiMap`, não o pacote pelo nome: o registro do chunk usa o caminho relativo
  // a este arquivo. Ver emojiMap.ts.
  import('./emojiMap')
    .then((module) => {
      const source = (module.default ?? module) as Record<string, string[]>;
      const inverted: Record<string, string> = {};

      for (const [unicode, names] of Object.entries(source)) {
        if (!Array.isArray(names)) continue;

        for (const name of names) {
          // Primeiro nome vence: o pacote lista o shortcode canônico primeiro.
          if (!(name in inverted)) inverted[name] = unicode;
        }
      }

      full = inverted;
      m.redraw();
    })
    .catch(() => {
      // Fica no COMMON; chunk ausente não pode quebrar o chat.
      full = {};
    })
    .finally(() => {
      loading = false;
    });
}

/** Remove os delimitadores e espaços de `:name:`. */
function normalise(input: string): string {
  return input.trim().replace(/^:+|:+$/g, '');
}

/**
 * Verdadeiro quando a string já contém emoji (ou pictograma não ASCII). Frouxo de
 * propósito: só distingue "colou 💬" de "digitou speech_balloon".
 */
export function looksLikeEmoji(input: string): boolean {
  const value = input.trim();

  if (value === '' || value.length > 8) return false;

  return /[\p{Extended_Pictographic}\p{Emoji_Presentation}]/u.test(value);
}

/**
 * Resolve um shortcode, com ou sem dois-pontos. Devolve a entrada se já for emoji e
 * `null` se o shortcode for desconhecido; quem chama decide o fallback.
 */
export function resolveEmoji(input: string | null | undefined): string | null {
  if (!input) return null;

  if (looksLikeEmoji(input)) return input.trim();

  const name = normalise(input).toLowerCase();

  if (name === '') return null;

  // Carrega o mapa completo para um shortcode desconhecido agora resolver depois.
  loadEmojiMap();

  return full?.[name] ?? COMMON[name] ?? null;
}

/** Emoji para exibição, com fallback para o shortcode cru em vez de ficar em branco. */
export function displayEmoji(input: string | null | undefined, fallback = ''): string {
  if (!input) return fallback;

  return resolveEmoji(input) ?? `:${normalise(input)}:`;
}

export interface EmojiSuggestion {
  /** Shortcode canônico, sem dois-pontos. */
  name: string;
  unicode: string;
}

/**
 * Busca por shortcode para o picker. Prefixo vem antes de substring ("hea" mostra
 * `heart` antes de `broken_heart`). Resultados limitados: são ~1949 entradas
 * pontuadas a cada tecla.
 */
export function searchEmoji(query: string, limit = 48): EmojiSuggestion[] {
  loadEmojiMap();

  const source = full && Object.keys(full).length > 0 ? full : COMMON;
  const term = normalise(query).toLowerCase();

  const prefix: EmojiSuggestion[] = [];
  const contains: EmojiSuggestion[] = [];

  for (const [name, unicode] of Object.entries(source)) {
    if (term === '') {
      prefix.push({ name, unicode });

      if (prefix.length >= limit) break;

      continue;
    }

    const at = name.indexOf(term);

    if (at === 0) {
      prefix.push({ name, unicode });
    } else if (at > 0) {
      contains.push({ name, unicode });
    }

    if (prefix.length >= limit) break;
  }

  return [...prefix, ...contains].slice(0, limit);
}

/** Verdadeiro quando o mapa completo já carregou, para o picker indicar carregamento. */
export function emojiMapReady(): boolean {
  return full !== null;
}

/**
 * Se o valor serve como emoji de canal. Espelha a validação de ChannelResource para
 * o formulário recusar antes do request.
 */
export function isValidEmoji(input: string | null | undefined): boolean {
  if (!input || input.trim() === '') return true; // vazio é permitido

  return resolveEmoji(input) !== null;
}
