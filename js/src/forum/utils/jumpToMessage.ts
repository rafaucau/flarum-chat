import type ChatState from '../state/ChatState';

/**
 * Tempo de destaque após o salto. Acompanha `@chat-jump-highlight` no LESS, que
 * controla a animação; se divergirem, a classe sai antes ou depois da animação.
 */
const HIGHLIGHT_MS = 3000;

/**
 * Centraliza uma linha do stream e a destaca por alguns segundos. Busca dentro do
 * scroller que contém `from`: o painel de tópico e o canal desenham o mesmo
 * `ChatMessage`, e uma busca no documento rolaria o stream errado.
 *
 * Retorna `false` quando a linha não está na janela carregada, para quem chama avisar.
 */
export function jumpToMessage(id: number | string, from?: HTMLElement | null): boolean {
  const scroller =
    from?.closest<HTMLElement>('.ChatChannel-stream, .ChatThreadPanel-stream') ?? document.querySelector<HTMLElement>('.ChatChannel-stream');

  const node = scroller?.querySelector<HTMLElement>(`.ChatMessage[data-id="${id}"]`);

  if (!scroller || !node) return false;

  // Não usa `scrollIntoView`: ele rola todos os ancestrais, o documento inclusive,
  // o que arrasta a página no celular. Mede por rects porque `offsetTop` é relativo
  // ao ancestral posicionado, não ao scroller.
  const nodeRect = node.getBoundingClientRect();
  const scrollerRect = scroller.getBoundingClientRect();
  const centred = nodeRect.top - scrollerRect.top - (scroller.clientHeight - nodeRect.height) / 2;

  // Suave só em saltos curtos; animar por centenas de linhas leva segundos.
  scroller.scrollTo({
    top: Math.max(0, scroller.scrollTop + centred),
    behavior: Math.abs(centred) > scroller.clientHeight * 2 ? 'auto' : 'smooth',
  });

  highlight(node);

  return true;
}

/**
 * Timers em andamento por linha, para o primeiro timer não limpar o destaque no
 * meio de um segundo salto à mesma mensagem.
 */
const timers = new WeakMap<HTMLElement, number>();

function highlight(node: HTMLElement): void {
  window.clearTimeout(timers.get(node));

  // Reaplicar uma classe existente não reinicia a animação; remover, forçar reflow
  // lendo `offsetWidth` e recolocar sim.
  node.classList.remove('ChatMessage--flash');
  void node.offsetWidth;
  node.classList.add('ChatMessage--flash');

  timers.set(
    node,
    window.setTimeout(() => {
      node.classList.remove('ChatMessage--flash');
      timers.delete(node);
    }, HIGHLIGHT_MS)
  );
}

/**
 * Salta para uma mensagem do canal, carregando antes o trecho de histórico que a
 * separa da janela quando ela é mais antiga. O scroller é relido após carregar
 * porque o redesenho pode tê-lo recriado. Retorna `false` só quando a mensagem não
 * pode estar no stream: resposta de tópico, apagada ou além do limite de páginas.
 */
export async function revealAndJump(state: ChatState, channelId: number, id: number | string, from?: HTMLElement | null): Promise<boolean> {
  if (jumpToMessage(id, from)) return true;

  const scrollerSelector = from?.closest('.ChatThreadPanel-stream') ? '.ChatThreadPanel-stream' : '.ChatChannel-stream';

  if (!(await state.revealMessage(channelId, Number(id)))) return false;

  m.redraw.sync();

  return jumpToMessage(id, document.querySelector<HTMLElement>(scrollerSelector));
}
