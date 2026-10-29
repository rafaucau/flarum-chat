/**
 * Atalhos globais do chat. Poucos e sempre com modificador: numa página cheia de
 * campos de texto, a única tecla solta tratada é Escape, e só quando o chat tem um
 * modo aberto.
 *
 *   Alt+C          abre/fecha a gaveta
 *   Alt+Shift+C    vai para o chat em tela cheia
 *   Alt+K          busca, no escopo do canal aberto
 *   Escape         sai da seleção, depois fecha o painel de tópico/fixadas
 *
 * Alt em vez de Ctrl/Cmd: o navegador e o SO já usam quase toda combinação com Ctrl.
 */
export declare function bindShortcuts(): void;
/** Tecla de envio: Enter, a menos que o membro tenha escolhido Ctrl+Enter. */
export type SendKey = 'enter' | 'ctrl';
/**
 * Preferência do membro, Enter por padrão. Qualquer valor diferente de "ctrl" vale
 * Enter, o que cobre o antigo "default" gravado sem reescrever linhas.
 */
export declare function sendKeyPreference(): SendKey;
/** Lido a cada chamada: um cache manteria o comportamento antigo após a troca. */
export declare function sendsOnCtrlEnter(): boolean;
