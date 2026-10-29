import type Mithril from 'mithril';
import type Message from '../../common/models/Message';
/**
 * Identidade do bot do chat. Não há conta por trás, de propósito: uma conta
 * apareceria em listas de membros e menções e poderia ser logada. O bot é só um
 * rótulo de autor, definido por configurações.
 */
/** Sem configuração, usa o nome do fórum. */
export declare function botName(): string;
/**
 * Arquivo enviado vence a URL digitada. Os dois são guardados: removendo o upload,
 * a URL externa volta.
 */
export declare function botAvatarUrl(): string | null;
/**
 * Avatar no formato do core, para ocupar a mesma calha. Sem imagem, usa a inicial
 * num disco colorido, como o core faz com usuário sem avatar.
 */
export declare function botAvatar(className?: string): Mithril.Children;
/**
 * Avatar de uma mensagem, de qualquer autor. Mensagem do bot não tem `user_id`,
 * então renderizar `message.user()` direto a mostraria como conta apagada.
 */
export declare function authorAvatar(message: Message, className?: string): Mithril.Children;
/** Nome do autor em texto simples, para resumos e linhas curtas. */
export declare function authorName(message: Message): Mithril.Children;
/** Nome do autor com link para o perfil; o bot não tem perfil. */
export declare function authorLink(message: Message): Mithril.Children;
