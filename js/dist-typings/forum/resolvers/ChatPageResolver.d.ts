import DefaultResolver from 'flarum/common/resolvers/DefaultResolver';
/**
 * Mantém todas as rotas do chat numa mesma página montada. O DefaultResolver do
 * core usa `routeName + JSON.stringify(params)` como key, e trocar a key faz o
 * Mithril recriar o componente: abrir um tópico ou trocar de canal reconstruiria a
 * sidebar e o scroll. Uma key constante torna a navegação um redesenho comum.
 *
 * `routeName` continua chegando via makeAttrs; o ChatPage o lê para escolher o
 * painel e sincroniza canal e tópico no update, já que o oninit não roda a cada
 * navegação. BrowseChannelsPage usa o resolver padrão: é outra página de fato.
 */
export default class ChatPageResolver<Attrs extends Record<string, unknown> = Record<string, unknown>, Comp = any, RouteArgs extends Record<string, unknown> = {}> extends DefaultResolver<Attrs, any, RouteArgs> {
    makeKey(): string;
}
