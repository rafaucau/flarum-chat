import type Mithril from 'mithril';
import type Channel from '../../common/models/Channel';
/**
 * Marca do canal: imagem, participantes, emoji ou hash. Um só helper para a
 * sidebar, a navegação, o header do canal e o modal de info não divergirem.
 *
 * Precedência: imagem (upload é deliberado), depois participantes (uma conversa
 * se identifica por quem está nela), depois emoji.
 *
 * Retorna o próprio elemento do slot, para cada superfície passar `className` com
 * seu tamanho e para o caso de avatares poder alargar o slot.
 */
export declare function channelIcon(channel: Channel, className?: string): Mithril.Children;
export default channelIcon;
