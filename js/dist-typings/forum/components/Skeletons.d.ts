import type Mithril from 'mithril';
/** Streams de canal, thread e fixadas. */
export declare function MessageStreamSkeleton(rows?: number): Mithril.Children;
/** Painel principal antes do canal carregar: cabeçalho, stream e composer. */
export declare function ChannelSkeleton(): Mithril.Children;
/** Lista de canais: atalhos, título de seção e linhas. */
export declare function SidebarSkeleton(): Mithril.Children;
/** Cards em coluna, como os reais; em linha, a lista pularia ao carregar. */
export declare function BrowseSkeleton(cards?: number): Mithril.Children;
export declare function SearchResultsSkeleton(rows?: number): Mithril.Children;
export declare function ThreadsSkeleton(rows?: number): Mithril.Children;
export declare function MembersSkeleton(rows?: number): Mithril.Children;
