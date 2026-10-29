import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import type Channel from '../../common/models/Channel';
import type ChatState from '../state/ChatState';
export interface ChatSelectionBarAttrs extends ComponentAttrs {
    channel: Channel;
    state: ChatState;
}
interface TranscriptResponse {
    data: {
        attributes: {
            format: string;
            content: string;
            count: number;
            omitted: number;
        };
    };
}
/**
 * Barra da seleção de mensagens: citar numa discussão, copiar, mover.
 *
 * O servidor monta a transcrição (TranscriptController) e rechecka cada id com
 * `whereVisibleTo`; as descartadas voltam contadas.
 */
export default class ChatSelectionBar extends Component<ChatSelectionBarAttrs> {
    private working;
    view(): Mithril.Children;
    /**
     * Mover exige `ramon-chat.moderate`. Canais fechados ou arquivados são recusados
     * pelo servidor, então não aparecem como destino.
     */
    protected moveControls(count: number): Mithril.Children;
    protected transcript(format: 'markup' | 'plain'): Promise<TranscriptResponse['data']['attributes'] | null>;
    protected quote(): Promise<void>;
    protected copy(): Promise<void>;
    protected move(target: string): Promise<void>;
    protected cancel(): void;
}
export {};
