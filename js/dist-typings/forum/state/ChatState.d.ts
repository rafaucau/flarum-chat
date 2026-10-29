import type Channel from '../../common/models/Channel';
import type Message from '../../common/models/Message';
import type Thread from '../../common/models/Thread';
import type Upload from '../../common/models/Upload';
/** Janela de mensagens carregada de um canal e o estado de paginação. */
interface ChannelStream {
    messages: Message[];
    /** Vira false quando uma página volta menor que o tamanho da página. */
    hasMore: boolean;
    loading: boolean;
    loadedInitial: boolean;
    /**
     * Marcador de leitura como estava ao abrir o canal. Congelado de propósito: o
     * divisor "novas mensagens" fica parado enquanto se lê.
     */
    dividerAfterId: number | null;
    /**
     * Semeado do snapshot local, não do servidor. Pintado na hora e trocado pela
     * resposta do servidor na primeira exibição; ver loadChannel() e revalidateStream().
     */
    stale: boolean;
}
interface TypingEntry {
    username: string;
    /** Epoch ms após o qual o indicador sai. */
    expiresAt: number;
    /**
     * Redesenho agendado para quando expira. `typistsIn()` só descarta entradas
     * vencidas quando chamado, e quem para de digitar não gera evento; sem o timer o
     * nome ficaria na tela.
     */
    timer: number;
}
/**
 * Fonte única de verdade da UI do chat. Os componentes leem daqui e ficam só de
 * apresentação; paginação, leitura, envio otimista e reconciliação de realtime num
 * só lugar mantêm a gaveta e a página em sincronia.
 */
export default class ChatState {
    /** Canais da sidebar, atividade mais recente primeiro. */
    channels: Channel[];
    channelsLoading: boolean;
    channelsLoaded: boolean;
    /**
     * Request de canais em andamento, para chamadas simultâneas dividirem a ida ao
     * servidor. Devolver `this.channels` não basta: no início ele ainda está vazio.
     */
    private channelsRequest;
    /**
     * Se os rascunhos já foram buscados na sessão. O composer os grava ao mudar, então
     * a cópia em memória é a mais nova; buscar de novo poderia sobrescrevê-la.
     */
    private draftsLoaded;
    private draftsRequest;
    /** A sidebar veio do snapshot local; rebusca no primeiro uso. */
    private channelsStale;
    /** Canais cuja prévia de fixada veio do snapshot local. */
    private pinnedStale;
    /** Prévias de fixada sendo buscadas, para prefetch e view dividirem o request. */
    private pinnedInFlight;
    /** Gravação de snapshot pendente (debounce), se houver. */
    private snapshotTimer;
    /**
     * Canais vistos na sessão, mais recente primeiro: aqueles cujo histórico o
     * snapshot guarda.
     */
    private recentChannelIds;
    /** Janelas de mensagens carregadas, por id de canal. */
    streams: Record<number, ChannelStream>;
    /** Tópicos carregados, por id de canal. */
    threads: Record<number, Thread[]>;
    /** Janelas de mensagens dos tópicos abertos, por id de tópico. */
    threadStreams: Record<number, ChannelStream>;
    /**
     * Fixada mais recente por canal, para a barra acima do stream. Buscada à parte
     * porque costuma estar bem acima da janela. `null` é "perguntado, nada fixado";
     * `undefined` é "ainda não perguntado".
     */
    pinnedPreviews: Record<number, Message | null>;
    /**
     * Quantas fixadas cada canal tem, segundo a última contagem do servidor (lida do
     * meta de paginação do request da prévia). Separado de `pinnedPreviews`: um canal
     * pode ter total sem prévia se o request da prévia falhou.
     */
    private pinnedTotals;
    /** Rascunhos do composer, por `channelId` ou `channelId:threadId`. */
    drafts: Record<string, string>;
    /** Indicadores de digitação, por id de canal e depois de usuário. */
    typing: Record<number, Record<number, TypingEntry>>;
    /** Canal e tópico em exibição. */
    activeChannelId: number | null;
    activeThreadId: number | null;
    /**
     * Se o painel de fixadas está aberto. Divide o slot direito com o painel de
     * tópico; abrir um fecha o outro (ver togglePinned() / setActiveThread()).
     */
    showPinned: boolean;
    /**
     * Se a gaveta mostra a busca em vez da conversa. Só na gaveta: na página a busca é
     * a rota `chat.search`, e navegar a partir da gaveta a fecharia. Divide o slot de
     * sobreposição com os painéis de tópico e de fixadas.
     */
    showSearch: boolean;
    /**
     * Gaveta aberta/recolhida. Persistido, para sobreviver ao reload; altere pelos
     * setters abaixo, ou a mudança não é lembrada.
     */
    drawerOpen: boolean;
    drawerCollapsed: boolean;
    /**
     * A gaveta fechou para ir à tela cheia, não foi dispensada. Fechada no X fica
     * fechada; a que só saiu da frente volta ao deixar a página.
     */
    drawerSuspended: boolean;
    /** Modo de seleção, para citar/copiar/mover. */
    selecting: boolean;
    selected: Set<number>;
    /**
     * Mensagem sendo respondida ou editada, por escopo do composer (a mesma chave
     * `channelId` / `channelId:threadId` dos rascunhos). Por escopo porque o canal e
     * um tópico aberto desenham dois composers sobre este estado.
     */
    private replyTargets;
    private editTargets;
    /**
     * Escopos cujo próximo envio deve abrir um tópico novo. Responder e ramificar
     * preparam igual (ambos põem um alvo em `replyTargets`), então isto os distingue.
     */
    private branchTargets;
    /**
     * Anexos preparados e ainda não enviados, por conversa (`draftKey`), para um
     * anexo de uma DM não sair na próxima mensagem de outro canal.
     */
    private pendingUploadsByKey;
    /**
     * Linhas otimistas aguardando resposta, por token gerado no cliente. Aparecem
     * esmaecidas e são trocadas no lugar quando dá certo.
     */
    private pending;
    private pendingSeq;
    /** Por usuário, para um navegador compartilhado não restaurar o canal de outra conta. */
    private storageKey;
    /**
     * Lê o estado da gaveta salvo pela visita anterior. Retorna se ela deve abrir,
     * para quem chama decidir se carrega a lista de canais.
     */
    restoreDrawer(): boolean;
    private persistDrawer;
    /**
     * Espelha "gaveta aberta neste canal" num cookie que o servidor lê. O
     * localStorage é invisível ao render, e Content\PreloadChat usa o cookie para pôr
     * a lista e a conversa da gaveta no payload de boot de toda página.
     *
     * Só o id do canal, nunca conteúdo; ausente com a gaveta fechada, para o servidor
     * não trabalhar à toa.
     */
    private persistDrawerCookie;
    setDrawerOpen(open: boolean): void;
    /**
     * Fecha a gaveta porque a página cheia assume a tela. Diferente de
     * `setDrawerOpen(false)`, que limparia a suspensão, e de `suspendDrawer()`, que
     * criaria uma: chegar à página por outra rota não garante reabertura na saída.
     */
    hideDrawerForPage(): void;
    /**
     * Fecha a gaveta devendo reabri-la. Usado ao passar a conversa para a página
     * cheia, o único caso em que a gaveta some sem o usuário dispensá-la.
     */
    suspendDrawer(): void;
    /**
     * Reabre uma gaveta suspensa e diz se havia uma. Consumir a flag aqui torna
     * "restaurar no máximo uma vez" uma propriedade do estado.
     */
    resumeDrawer(): boolean;
    setDrawerCollapsed(collapsed: boolean): void;
    /** Registra o canal em exibição, para o reload reabrir o mesmo. */
    setActiveChannel(channelId: number | null): void;
    /** Se o payload de boot já foi lido; só vale uma vez. */
    private bootHydrated;
    /**
     * Preenche o estado com o payload que Content\PreloadChat pôs na página, de forma
     * síncrona, para o primeiro frame já ser o chat pronto.
     *
     * Chamado no initializer, antes de montar: `app.data` vem de `Application#load()`,
     * que roda antes dos initializers, mas `app.forum` e `app.session` ainda não
     * existem, então nada aqui pode tocá-los.
     *
     * Cada passo é opcional e protegido; o que o servidor não produziu, ou um stream
     * já carregado, fica para o caminho assíncrono normal.
     */
    hydrateFromBoot(): void;
    /**
     * Semeia o que o payload de boot não trouxe a partir da cópia que a última visita
     * deixou no localStorage: a sidebar e o fim dos canais vistos recentemente.
     *
     * localStorage e não IndexedDB porque a leitura precisa ser síncrona para cair
     * antes do primeiro view(); no tamanho mantido (ver MAX_BYTES em utils/snapshot)
     * custa poucos milissegundos.
     *
     * Nada daqui é confiável além da primeira pintura: cada seção é marcada como velha
     * e trocada pela resposta do servidor em segundo plano (ver loadChannels(),
     * loadChannel() e loadPinnedPreview()). O id do usuário vem de `app.data`, não de
     * `app.session`, pelo mesmo motivo de hydrateFromBoot().
     */
    hydrateFromSnapshot(): void;
    private snapshotUserId;
    /**
     * Grava o snapshot em breve, quando as coisas assentarem. Com debounce porque as
     * mudanças vêm em rajadas, e serializar cinquenta mensagens a cada uma custaria
     * mais que o snapshot economiza.
     */
    scheduleSnapshot(): void;
    /**
     * Grava o snapshot agora. Também é o hook de `pagehide` e aba oculta, para uma
     * mensagem que chegou logo antes de fechar estar no próximo boot. Só o que o
     * servidor confirmou: um stream velho é do snapshot anterior.
     */
    flushSnapshot(): void;
    /** Apaga o snapshot de todas as contas; ver utils/snapshot. */
    purgeSnapshots(): void;
    /**
     * Coloca um documento JSON:API no store e devolve os models primários. `null` para
     * documento ausente ou malformado ("o servidor não respondeu este"), diferente de
     * `data` vazio, que é resposta real e não deve ser rebuscada.
     */
    private pushDocument;
    private hydrateChannels;
    private hydrateDrafts;
    /**
     * Semeia uma janela de mensagens a partir de uma página pré-carregada. A página
     * vem do mais novo para o mais antigo e é invertida, como em `fetchInto`.
     *
     * `channelId` só para stream de canal: é de onde vem o divisor de não lidas, e
     * tópicos não têm marcador de leitura.
     */
    private hydrateStream;
    private hydratePinned;
    /**
     * Busca a lista de canais da sidebar, no máximo uma vez por sessão. Nada mais a
     * deixa velha: mensagens, joins, saídas e edições chegam pelo websocket e são
     * aplicadas nestes mesmos objetos, e o poller cobre o socket fora do ar.
     *
     * `force` para quando isso não vale: login/logout, ou um join pela página de
     * navegação. `errorHandler` é para o poller, que não deve mostrar o alerta do
     * core quando a sessão acabou.
     */
    loadChannels(force?: boolean, errorHandler?: (error: {
        status?: number;
    }) => false | void): Promise<Channel[]>;
    /**
     * Marca um canal recém-criado como carregado e vazio, sem perguntar ao servidor.
     * Só correto para um canal que acabou de ser criado (por isso é método separado):
     * não tem mensagens nem fixada. Economiza dois requests em "Enviar mensagem" no perfil.
     */
    seedEmptyChannel(channelId: number): void;
    /**
     * Põe um canal no topo da sidebar, se ainda não estiver. A lista é por última
     * atividade, e uma conversa recém-iniciada é a mais recente.
     */
    rememberChannel(channel: Channel): void;
    /**
     * Canal que não existe mais para este leitor: excluído ou fora de alcance. Sai da
     * lista, do stream em cache, da fixada e do snapshot, para um reload não trazê-lo
     * de volta; quem o vê sai dele com um aviso em vez de ficar numa página que só
     * responde 404.
     */
    channelGone(channelId: number): void;
    /**
     * Handler de erro para requests de um canal: 403 ou 404 quer dizer que o canal
     * sumiu para o leitor, e `channelGone` resolve em silêncio no lugar do alerta
     * genérico do core. O resto segue adiante.
     */
    private goneHandler;
    /**
     * Tira o canal da sidebar, e da tela se estava aberto. Para uma participação que
     * terminou (saída, remoção, canal privado que deixou de ser visível). O registro
     * no store fica; a próxima busca diz o que ainda é permitido.
     */
    forgetChannel(channelId: number): void;
    /**
     * Quem quer saber quando a lista de membros de um canal muda. A aba de membros é a
     * única superfície desenhada de uma lista que o payload de realtime não carrega.
     * Listeners em vez de contador no estado: um modal fechado não custa nada.
     */
    private membershipListeners;
    /** Retorna a função que cancela a inscrição. */
    onMembershipChange(listener: (channelId: number) => void): () => void;
    notifyMembershipChange(channelId: number): void;
    /**
     * Aquece tudo o que abrir um canal pediria, sem esperar. Chamado no hover e no
     * foco, para o clique cair numa conversa já carregada em navegações do cliente,
     * que não têm payload de boot. As duas chamadas são idempotentes.
     */
    prefetchChannel(channelId: number): void;
    /**
     * Apaga a lista de canais em cache para a próxima leitura rebuscar. Para mudanças
     * que invalidam a lista inteira: troca de conta, ou join feito onde o model não existe.
     */
    invalidateChannels(): void;
    channel(id: number | null): Channel | null;
    /** Canais de categoria, para a seção "Canais" da sidebar. */
    categoryChannels(): Channel[];
    /** Canais diretos e de grupo, para a seção "Mensagens diretas". */
    directChannels(): Channel[];
    /**
     * Canais com não lidas e quantas menções pendentes. Prefere a lista carregada, que
     * reflete os pushes na hora (`bumpChannel` a incrementa). Sem ela, usa os
     * contadores serializados no usuário da sessão, presentes desde a primeira pintura.
     */
    unreadSummary(): {
        channels: number;
        messages: number;
        mentions: number;
    };
    /**
     * Move os contadores serializados do ator. O badge do header e o ponto da
     * navegação os usam quando a lista de canais não foi carregada.
     */
    bumpUnreadCounters(messages: number, mentions: number, newChannel: boolean): void;
    stream(channelId: number): ChannelStream;
    /**
     * Carrega a página mais nova e congela o divisor de não lidas no marcador de
     * leitura da entrada.
     */
    loadChannel(channelId: number): Promise<void>;
    /**
     * Busca uma página mais antiga que a carregada. A API ordena do mais novo para o
     * mais antigo, então a página é invertida antes de ir para o início.
     */
    fetchPage(channelId: number): Promise<void>;
    /**
     * Pagina um stream para trás a partir de um filtro base. Compartilhado por canal e
     * tópico, que são janelas sobre o mesmo endpoint, para não mostrarem históricos
     * diferentes.
     */
    private fetchInto;
    /**
     * Garante que uma mensagem está na janela carregada do canal, para poder rolar
     * até ela (fixada ou citação antigas). Pagina a lacuna entre a linha mais antiga e
     * o alvo numa só passada, mantendo a janela contígua. Resolve se a mensagem está
     * lá; resposta de tópico ou linha excluída nunca está.
     */
    revealMessage(channelId: number, messageId: number): Promise<boolean>;
    /**
     * Troca uma janela semeada pelo snapshot pela página mais nova do servidor, sem
     * await porque a conversa já está desenhada. Dentro do intervalo da página o
     * servidor manda: linhas que não voltam foram excluídas e saem. Linhas mais antigas
     * não foram consultadas e ficam.
     *
     * Se falhar, o snapshot fica sem revalidar; os cursores `greaterThan` e
     * `updatedSince` do poller o alcançam no próximo tick.
     */
    private revalidateStream;
    /**
     * Janela de mensagens própria de um tópico, por id. Separada de `streams` porque o
     * canal mostra o tópico só como indicador sob a raiz; as respostas nunca estão
     * naquela janela.
     */
    threadStream(threadId: number): ChannelStream;
    loadThread(threadId: number): Promise<void>;
    fetchThreadPage(threadId: number): Promise<void>;
    /** Carrega o registro do tópico, para o título e a contagem do painel. */
    findThread(threadId: number): Promise<Thread | null>;
    closeThread(): void;
    /**
     * Fecha o que estiver cobrindo a conversa na gaveta. Chamado ao trocar de canal:
     * uma busca ou lista de fixadas pertence ao canal de onde foi aberta.
     */
    closeOverlays(): void;
    /**
     * Busca a fixada mais nova acima da janela carregada, uma vez por canal.
     * `latestPinned()` já responde pela janela quando pode; cada busca é uma leitura
     * completa de mensagem (nove policies e quatro resumos de relação).
     *
     * `channelId in this.pinnedPreviews` em vez de truthiness: `null` é "perguntado,
     * nada fixado" e não deve ser repetido. O realtime mantém a resposta correta (ver
     * `onMessageChanged`).
     */
    loadPinnedPreview(channelId: number, force?: boolean): Promise<void>;
    private fetchPinnedPreview;
    /**
     * Apaga a fixada em cache do canal, para o próximo acesso perguntar ao servidor.
     * Para mensagem fixada acima da janela, que este cliente não tem para atualizar.
     */
    invalidatePinnedPreview(channelId: number): void;
    /**
     * Quantas fixadas o canal tem: o maior entre a contagem do servidor e as fixadas na
     * janela, pois a contagem não vê fixações posteriores e a janela não vê as de cima.
     *
     * Decide se a barra merece um segundo controle: com uma fixada, a barra já a
     * mostra e o clique salta até ela.
     */
    pinnedCount(channelId: number): number;
    /**
     * Mensagem que a barra de fixadas mostra, calculada sobre a janela carregada e a
     * prévia buscada; o `pinnedAt` mais novo vence. Fixar algo visível atualiza a
     * barra sem refetch, e desafixar a mensagem exibida a remove.
     */
    latestPinned(channelId: number): Message | null;
    /** Painel de fixadas, busca e painel de tópico dividem um slot. */
    togglePinned(): void;
    /** Busca, no slot de sobreposição da gaveta. Ver `showSearch`. */
    toggleSearch(): void;
    /**
     * Lista de tópicos do canal no slot de sobreposição da gaveta. Só na gaveta, como
     * a busca: na página é a rota `chat.threads`, e navegar fecharia a gaveta.
     */
    showThreads: boolean;
    toggleThreads(): void;
    /** Cronológico, por id; ids são monotônicos por canal. */
    private sortStream;
    /**
     * Insere ou substitui uma mensagem no stream do canal. Usado pelo envio e pelo
     * realtime, para ordenação e deduplicação ficarem num só lugar.
     */
    upsertMessage(message: Message): void;
    private isThreadRoot;
    private insertInto;
    removeMessage(channelId: number, messageId: number): void;
    /**
     * O mesmo, para o painel do tópico. Separado porque streams de tópico ficam em
     * outro mapa: passar um id de tópico a `removeMessage` buscaria entre os canais e
     * poderia apagar a conversa errada.
     */
    removeThreadMessage(threadId: number, messageId: number): void;
    /**
     * Envia uma mensagem e retorna o model salvo. O composer é limpo antes da resposta
     * para a digitação seguir; se falhar, o conteúdo volta.
     */
    send(channelId: number, content: string, options?: {
        threadId?: number | null;
        replyToId?: number | null;
        createThread?: boolean;
    }): Promise<Message | null>;
    hasPendingSends(channelId: number): boolean;
    /**
     * Marca o canal como lido até a mensagem carregada mais nova. Fire-and-forget: um
     * recibo de leitura falho não vale alerta, e a próxima chamada leva um marcador
     * mais novo.
     *
     * Não depende de `hasUnread()`: mensagem que chega no canal aberto é marcada lida
     * sem nunca ganhar badge (`bumpChannel` chama direto), e os recibos de leitura
     * leem o marcador. A comparação abaixo é a trava certa, a mesma que o servidor usa
     * para decidir se transmite.
     */
    markRead(channelId: number): void;
    draftKey(channelId: number, threadId?: number | null): string;
    pendingUploadsFor(channelId: number, threadId?: number | null): Upload[];
    addPendingUpload(channelId: number, threadId: number | null, upload: Upload): void;
    removePendingUpload(channelId: number, threadId: number | null, id: number): void;
    replyingTo(channelId: number, threadId?: number | null): Message | null;
    editing(channelId: number, threadId?: number | null): Message | null;
    /** Se a resposta preparada começou como ramificação, não como resposta. */
    branchingFrom(channelId: number, threadId?: number | null): boolean;
    /**
     * Responder e editar se excluem dentro de um escopo. `branch` indica que veio de
     * "responder em tópico"; só o composer lê, para decidir se o envio abre um tópico
     * (ver ChatComposer.submit()).
     */
    setReplyingTo(channelId: number, message: Message | null, threadId?: number | null, branch?: boolean): void;
    setEditing(channelId: number, message: Message | null, threadId?: number | null): void;
    clearContext(channelId: number, threadId?: number | null): void;
    draft(channelId: number, threadId?: number | null): string;
    setDraft(channelId: number, content: string, threadId?: number | null): void;
    clearDraft(channelId: number, threadId?: number | null): void;
    private draftTimer;
    /**
     * Com debounce para uma tecla não virar request. Rascunhos ficam no servidor para
     * seguir o usuário entre dispositivos, mas não valem uma gravação por caractere.
     */
    private persistDraft;
    /**
     * Restaura os rascunhos, no máximo uma vez por sessão. Rebuscar a cada visita
     * competia com uma gravação ainda não concluída e podia trazer a revisão anterior.
     */
    loadDrafts(): Promise<void>;
    private fetchDrafts;
    /** Quem está digitando no canal, sem as entradas vencidas. */
    typistsIn(channelId: number): string[];
    noteTyping(channelId: number, userId: number, username: string, typing: boolean, expiresIn?: number): void;
    /**
     * Remove quem digitava na hora: acabou de enviar. Esperar expirar deixaria
     * "X está digitando…" embaixo da mensagem de X.
     */
    clearTyping(channelId: number, userId: number): void;
    private typingSentAt;
    /** Avisa que está digitando, no máximo a cada 3s; sem isso seria um request por tecla. */
    announceTyping(channelId: number): void;
    toggleSelecting(on?: boolean): void;
    toggleSelected(messageId: number): void;
    /**
     * Transforma a seleção em transcrição. No servidor para as regras de citação
     * baterem com as do arquivamento.
     */
    transcript(format?: 'markup' | 'plain'): Promise<string>;
}
export {};
