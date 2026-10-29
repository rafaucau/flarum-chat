import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import Avatar from 'flarum/common/components/Avatar';
import HeaderListItem from 'flarum/forum/components/HeaderListItem';
import type Mithril from 'mithril';

import type MessageFlag from '../../common/models/MessageFlag';
import { messagePreview } from '../../common/utils/preview';
import { authorName } from './bot';

/**
 * Coloca as denúncias do chat na lista do flarum/flags. No banco não dá:
 * `flags.post_id` é FK não nula para `posts`, e alterar o schema da extensão seria
 * desfeito na próxima versão. Compartilha-se a interface: o dropdown e a página
 * /flags renderizam `FlagList`.
 *
 * Sem o flarum/flags nada disto roda; a fila própria em /chat/flags não depende dele.
 */

/** Carregado uma vez por página e mantido entre redesenhos. */
let reports: MessageFlag[] = [];
let loaded = false;
let loading = false;

/**
 * Resolve um módulo de outra extensão sem importá-lo. Um `import` direto seria
 * dependência obrigatória e quebraria o bundle sem o flarum/flags; `checkModule`
 * só responde false.
 */
function moduleOf(path: string): any {
  const registry = (window as any)?.flarum?.reg;

  if (!registry) return null;

  try {
    const found = typeof registry.checkModule === 'function' ? registry.checkModule('flarum-flags', path) : registry.get?.('flarum-flags', path);

    // O registro devolve o namespace do módulo; o componente é o export padrão.
    return found ? (found.default ?? found) : null;
  } catch {
    return null;
  }
}

function load(): void {
  if (loaded || loading) return;

  loading = true;

  app.store
    .find('chat-message-flags', {
      filter: { resolved: '0' },
      sort: '-createdAt',
      page: { limit: 20 },
    })
    .then((results) => {
      reports = (Array.isArray(results) ? results : []) as unknown as MessageFlag[];
    })
    .catch(() => {
      // Fórum sem a fila do chat, ou moderador que perdeu a permissão na sessão; a
      // lista de flags continua útil sem isto.
      reports = [];
    })
    .finally(() => {
      loading = false;
      loaded = true;
      m.redraw();
    });
}

/**
 * Uma denúncia, desenhada como o flarum/flags desenha um post denunciado. Sem key
 * de propósito: as linhas do flarum/flags não têm key, e o Mithril recusa um
 * fragmento que mistura filhos com e sem key, o que derrubaria o dropdown inteiro.
 */
function row(report: MessageFlag): Mithril.Children {
  const message = report.message();

  if (!message) return null;

  return (
    <li>
      <HeaderListItem
        className="Flag Flag--chat"
        avatar={<Avatar user={message.user() || null} />}
        icon="fas fa-comment-dots"
        content={app.translator.trans('ramon-chat.forum.flags.flarum_item', {
          username: authorName(message),
          em: <em />,
          channel: (message.channel() || null)?.displayName() ?? '',
          reason: app.translator.trans(`ramon-chat.forum.flag.reasons.${report.reason()}`, {}, true),
        })}
        excerpt={messagePreview(message, 120)}
        datetime={report.createdAt()}
        href={app.route('chat.flags')}
        onclick={(e: MouseEvent) => {
          e.redraw = false;
        }}
      />
    </li>
  );
}

export default function bindFlagsIntegration(): void {
  const FlagList = moduleOf('forum/components/FlagList');
  const FlagsDropdown = moduleOf('forum/components/FlagsDropdown');

  if (!FlagList?.prototype) return;

  // Busca ao montar a lista, não no boot: quem nunca abre o dropdown não paga o request.
  extend(FlagList.prototype, 'oninit', function () {
    if (app.forum.attribute<boolean>('canModerateChat')) load();
  });

  // `override`, não `extend`: extend() descarta o retorno do callback, e
  // `content()` devolve um array novo ou null.
  override(FlagList.prototype, 'content', function (this: any, original: Function, state: any) {
    const vdom = original(state);

    if (!app.forum.attribute<boolean>('canModerateChat')) return vdom;

    const rows = reports.map(row).filter(Boolean);

    if (rows.length === 0) return vdom;

    // Anexado no fim, não intercalado por data: o flarum/flags pagina a própria
    // lista e carregaria posts por cima.
    return vdom === null ? rows : [vdom, rows];
  });

  // O badge do header conta os dois, para acender só com denúncias do chat. É um
  // número, então `extend` não o alcança.
  if (FlagsDropdown?.prototype) {
    override(FlagsDropdown.prototype, 'getUnreadCount', function (this: any, original: Function) {
      const own = Number(original() ?? 0);
      const chat = Number(app.forum.attribute<number>('chatOpenFlagsCount') ?? 0);

      return own + chat;
    });
  }
}
