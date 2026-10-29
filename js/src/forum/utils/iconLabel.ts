/**
 * Atributos de um botão só com ícone. O mesmo texto vira tooltip e nome
 * acessível: `title` sozinho não é lido por leitores de tela.
 */
export default function iconLabel(text: string): {
  title: string;
  'aria-label': string;
} {
  return { title: text, 'aria-label': text };
}
