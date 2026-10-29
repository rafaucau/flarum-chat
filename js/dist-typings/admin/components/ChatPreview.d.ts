import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
export interface BrandingPreviewAttrs extends ComponentAttrs {
    title: string;
    icon: string;
    showIcon: boolean;
}
/**
 * Preview do nome e do ícone com os valores ainda não salvos. Repete os
 * fallbacks de forum/utils/branding: título vazio vira "Chat" e ícone vazio
 * vira `fas fa-comments`.
 */
export declare class BrandingPreview extends Component<BrandingPreviewAttrs> {
    view(): Mithril.Children;
}
export interface MessagePreviewAttrs extends ComponentAttrs {
    name: string;
    avatarUrl: string | null;
    /** Conta real: sem o selo de bot ao lado do nome. */
    isUser: boolean;
}
export declare class MessagePreview extends Component<MessagePreviewAttrs> {
    view(): Mithril.Children;
}
