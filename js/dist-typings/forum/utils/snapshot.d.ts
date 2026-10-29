import type Model from 'flarum/common/Model';
/** Documento JSON:API no formato aceito por `Store.pushPayload`. */
export interface Document {
    data: unknown[];
    included?: unknown[];
    meta?: Record<string, unknown>;
}
/**
 * O que a última visita deixou, para a próxima pintar antes de consultar o
 * servidor. Só a sidebar e o fim de alguns canais vistos, no mesmo formato do
 * payload de boot (ver Content\PreloadChat). Tudo lido daqui é marcado como velho e
 * revalidado assim que aparece na tela.
 */
export interface Snapshot {
    version: number;
    savedAt: number;
    channels: Document;
    streams: Record<string, Document>;
    pinned: Record<string, Document>;
}
/**
 * Reconstrói um documento JSON:API a partir dos models no store. Cada model guarda
 * em `data` o recurso de origem; seguir as relações e pegar o que o store ainda
 * tem gera o documento que o servidor mandaria, inclusive linhas que chegaram pelo
 * websocket.
 *
 * Limitado por profundidade, não por tipo, para pegar relações novas sozinho. Três
 * níveis alcançam `replyTo.user.groups`, o mais fundo que a lista de mensagens inclui.
 */
export declare function serialize(models: Model[], depth?: number): Document;
export declare function readSnapshot(userId: string): Snapshot | null;
export declare function writeSnapshot(userId: string, snapshot: Snapshot): void;
/**
 * Apaga o snapshot de todas as contas. Chamado quando a página sobe sem sessão: o
 * histórico de um canal privado não pode sobreviver à sessão num navegador
 * compartilhado.
 */
export declare function purgeSnapshots(): void;
/**
 * Se alguma aba saiu da conta depois de `since` (ms). Uma aba aberta antes da
 * saída não sabe que a sessão acabou e não deve gravar o snapshot de volta.
 */
export declare function signedOutSince(since: number): boolean;
