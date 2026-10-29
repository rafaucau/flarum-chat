import type Mithril from 'mithril';

/**
 * Placeholders de carregamento no formato do conteúdo real, para a interface não
 * pular quando os dados chegam. Cada função espelha uma tela: mudou o layout lá,
 * mude aqui.
 */

/** Repete o builder `count` vezes, com key. */
function repeat(count: number, build: (index: number) => Mithril.Children): Mithril.Children[] {
  return Array.from({ length: count }, (_, index) => build(index));
}

/**
 * Larguras variam por linha; derivadas do índice, não aleatórias, para ficarem
 * estáveis entre redraws.
 */
function width(index: number, steps: number[]): string {
  return steps[index % steps.length] + '%';
}

// ── Stream de mensagens ──

/** Streams de canal, thread e fixadas. */
export function MessageStreamSkeleton(rows = 6): Mithril.Children {
  return (
    <div className="ChatSkeleton" aria-hidden="true">
      {repeat(rows, (i) => (
        <div className="ChatSkeleton-row" key={i}>
          <div className="ChatSkeleton-avatar" />
          <div className="ChatSkeleton-lines">
            <div className="ChatSkeleton-line" style={{ width: width(i, [22, 30, 26]) }} />
            <div className="ChatSkeleton-line" style={{ width: width(i, [70, 45, 85, 55]) }} />
          </div>
        </div>
      ))}
    </div>
  );
}

// ── Painel do canal ──

/** Painel principal antes do canal carregar: cabeçalho, stream e composer. */
export function ChannelSkeleton(): Mithril.Children {
  return (
    <div className="ChatChannel ChatSkeleton-channel" aria-hidden="true">
      <div className="ChatChannel-header">
        <div className="ChatSkeleton-avatar ChatSkeleton-avatar--small" />
        <div className="ChatSkeleton-line" style={{ width: '140px' }} />
      </div>

      <div className="ChatSkeleton-channelStream">{MessageStreamSkeleton(7)}</div>

      <div className="ChatSkeleton-composer" />
    </div>
  );
}

// ── Sidebar ──

/** Lista de canais: atalhos, título de seção e linhas. */
export function SidebarSkeleton(): Mithril.Children {
  return (
    <div className="ChatSkeleton-sidebar" aria-hidden="true">
      {repeat(2, (i) => (
        <div className="ChatSkeleton-quickLink" key={'q' + i}>
          <div className="ChatSkeleton-avatar ChatSkeleton-avatar--tiny" />
          <div className="ChatSkeleton-line" style={{ width: width(i, [40, 30]) }} />
        </div>
      ))}

      <div className="ChatSkeleton-heading" />

      {repeat(5, (i) => (
        <div className="ChatSkeleton-channelRow" key={'c' + i}>
          <div className="ChatSkeleton-avatar ChatSkeleton-avatar--tiny" />
          <div className="ChatSkeleton-line" style={{ width: width(i, [55, 40, 65, 35, 50]) }} />
        </div>
      ))}
    </div>
  );
}

// ── Explorar canais ──

/** Cards em coluna, como os reais; em linha, a lista pularia ao carregar. */
export function BrowseSkeleton(cards = 6): Mithril.Children {
  return (
    <div className="ChatBrowse-list" aria-hidden="true">
      {repeat(cards, (i) => (
        <div className="ChatBrowseCard ChatSkeleton" key={i}>
          <div className="ChatBrowseCard-head">
            <div className="ChatSkeleton-avatar ChatSkeleton-avatar--large" />

            <div className="ChatSkeleton-lines">
              <div className="ChatSkeleton-line ChatSkeleton-line--title" style={{ width: width(i, [55, 40, 65, 45, 60, 35]) }} />
              <div className="ChatSkeleton-line ChatSkeleton-line--meta" style={{ width: width(i, [30, 22, 38, 26, 34, 20]) }} />
            </div>
          </div>

          <div className="ChatSkeleton-lines">
            <div className="ChatSkeleton-line" style={{ width: '92%' }} />
            <div className="ChatSkeleton-line" style={{ width: width(i, [70, 55, 80, 45, 65, 50]) }} />
          </div>

          <div className="ChatBrowseCard-footer">
            <div className="ChatSkeleton-line ChatSkeleton-line--meta" style={{ width: '45%' }} />
            <div className="ChatSkeleton-button" />
          </div>
        </div>
      ))}
    </div>
  );
}

// ── Resultados de busca ──

export function SearchResultsSkeleton(rows = 5): Mithril.Children {
  return (
    <div className="ChatSkeleton ChatSkeleton--flush" aria-hidden="true">
      {repeat(rows, (i) => (
        <div className="ChatSkeleton-row ChatSkeleton-row--tight" key={i}>
          <div className="ChatSkeleton-avatar ChatSkeleton-avatar--small" />
          <div className="ChatSkeleton-lines">
            <div className="ChatSkeleton-line ChatSkeleton-line--meta" style={{ width: width(i, [40, 32, 45]) }} />
            <div className="ChatSkeleton-line" style={{ width: width(i, [75, 60, 85, 50]) }} />
          </div>
        </div>
      ))}
    </div>
  );
}

// ── Minhas threads ──

export function ThreadsSkeleton(rows = 5): Mithril.Children {
  return (
    <div className="ChatSkeleton ChatSkeleton--flush" aria-hidden="true">
      {repeat(rows, (i) => (
        <div className="ChatSkeleton-row ChatSkeleton-row--tight" key={i}>
          <div className="ChatSkeleton-avatar ChatSkeleton-avatar--tiny" />
          <div className="ChatSkeleton-lines">
            <div className="ChatSkeleton-line" style={{ width: width(i, [50, 65, 40, 58]) }} />
            <div className="ChatSkeleton-line ChatSkeleton-line--meta" style={{ width: '30%' }} />
          </div>
        </div>
      ))}
    </div>
  );
}

// ── Lista de membros ──

export function MembersSkeleton(rows = 6): Mithril.Children {
  return (
    <div className="ChatSkeleton ChatSkeleton--flush" aria-hidden="true">
      {repeat(rows, (i) => (
        <div className="ChatSkeleton-memberRow" key={i}>
          <div className="ChatSkeleton-avatar ChatSkeleton-avatar--small" />
          <div className="ChatSkeleton-line" style={{ width: width(i, [45, 32, 55, 38]) }} />
        </div>
      ))}
    </div>
  );
}
