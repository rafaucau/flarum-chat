import Avatar from 'flarum/common/components/Avatar';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';

import type Channel from '../../common/models/Channel';
import { displayEmoji } from './emoji';
import { customEmoji, customEmojiImage } from './flamoji';

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
export function channelIcon(channel: Channel, className = ''): Mithril.Children {
  const image = channel.imageUrl();

  if (image) {
    return (
      <span className={classList('ChatChannelIcon', className)}>
        <img
          className="ChatChannelIcon-image"
          src={image}
          alt=""
          // Decorativo: o nome do canal já está ao lado.
          aria-hidden="true"
          loading="lazy"
        />
      </span>
    );
  }

  if (channel.isDirect()) {
    // No máximo dois, para não empurrar a linha; o nome já lista todos.
    const others = channel.others().slice(0, 2);

    if (others.length > 0) {
      return (
        <span className={classList('ChatChannelIcon ChatChannelIcon--avatars', className)}>
          {others.map((user) => (
            <Avatar key={user.id()} user={user} className="Avatar" />
          ))}
        </span>
      );
    }

    // Conversa sem participantes carregados ou vazia. Envelope, não hash: hash
    // indicaria um canal aberto a qualquer um.
    return (
      <span className={classList('ChatChannelIcon', className)}>
        <i className="fas fa-envelope" aria-hidden="true" />
      </span>
    );
  }

  const emoji = channel.emoji();

  // O campo aceita shortcode, então pode nomear um emoji custom do Flamoji;
  // `displayEmoji` o mostraria como `:kappa:` literal.
  const custom = customEmoji(emoji);

  return (
    <span className={classList('ChatChannelIcon', className)}>
      {custom ? (
        customEmojiImage(custom, 'ChatChannelIcon-flamoji')
      ) : emoji ? (
        displayEmoji(emoji)
      ) : (
        <i className="fas fa-hashtag" aria-hidden="true" />
      )}
    </span>
  );
}

export default channelIcon;
