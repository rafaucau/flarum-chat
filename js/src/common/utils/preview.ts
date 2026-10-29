import type Message from '../models/Message';

/**
 * Resumo de uma linha, em texto puro, para onde uma mensagem aparece dentro de
 * outra coisa (barra de fixadas, resposta, thread, busca, favoritos). Lê o HTML
 * renderizado, não o Markdown cru. Texto puro mantém o layout de uma linha e
 * nunca passa por `m.trust`.
 */
export function messagePreview(message: Message | null | undefined, limit = 200): string {
  if (!message) return '';

  const html = message.contentHtml();

  const text = html ? stripHtml(html) : stripMarkdown(message.content() ?? '');

  return text.length > limit ? `${text.slice(0, limit)}…` : text;
}

// DOMParser em vez de regex: trata entidades, `>` em atributos e markup
// quebrado, e nada é inserido no documento.
function stripHtml(html: string): string {
  const doc = new DOMParser().parseFromString(html, 'text/html');

  // Fim de bloco vira espaço, senão "Título" e "Corpo" se colam.
  doc.body.querySelectorAll('br, p, div, li, blockquote, h1, h2, h3, h4, h5, h6').forEach((el) => {
    el.append(' ');
  });

  return collapse(doc.body.textContent ?? '');
}

// Para quando o HTML ainda não chegou (cópia otimista ou payload realtime sem
// ele). Só remove o que apareceria como pontuação.
function stripMarkdown(source: string): string {
  let text = source;

  text = text.replace(/!?\[([^\]]*)\]\([^)]*\)/g, '$1');
  text = text.replace(/^[ \t]*>[ \t]?/gm, '');
  text = text.replace(/^#{1,6}[ \t]*/gm, '');
  text = text.replace(/[*_~`]+/g, '');

  return collapse(text);
}

function collapse(text: string): string {
  return text.replace(/\s+/g, ' ').trim();
}

export default messagePreview;
