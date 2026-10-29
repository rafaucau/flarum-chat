<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Flarum\Discussion\Discussion;
use Flarum\Post\Event\Posted;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Tags\Tag;
use Flarum\User\Guest;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Ramon\Chat\Access\AudienceProbe;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\MessageWasSent;
use Ramon\Chat\Message;

/**
 * Avisa no canal quando uma discussão é aberta na categoria vinculada.
 *
 * Só o primeiro post conta: `Posted` dispara a cada resposta. Por padrão o aviso
 * sai pelo bot do chat (nome e avatar do admin); se o admin escolher uma conta
 * real, vira uma mensagem comum dela.
 *
 * Título, trecho e imagem saem do fórum para outra audiência, então só publica
 * onde todo leitor do canal já poderia abrir a discussão: nada pendente de
 * aprovação, privado (byobu) ou com tag restrita extra. Ver audienceMaySee().
 */
class AnnounceDiscussions
{
    public function __construct(
        protected Events $events,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(Posted $event): void
    {
        $post = $event->post;

        // `number` é 1 só no post que abriu a discussão.
        if ((int) ($post->number ?? 0) !== 1) {
            return;
        }

        $discussion = $post->discussion;

        if ($discussion === null) {
            return;
        }

        $tagIds = $this->tagIdsFor($discussion);

        if ($tagIds === []) {
            return;
        }

        $channels = Channel::query()
            ->where('post_discussions', true)
            ->where('type', Channel::TYPE_CATEGORY)
            ->where('status', Channel::STATUS_OPEN)
            ->whereNull('deleted_at')
            ->whereIn('tag_id', $tagIds)
            ->get();

        foreach ($channels as $channel) {
            if (! $channel->acceptsMessages()) {
                continue;
            }

            if (! $this->audienceMaySee($channel, $discussion)) {
                continue;
            }

            $excerpt = $this->excerpt($post);
            $image = $this->firstImage($post);

            $body = $this->body($discussion, $excerpt);

            // Com um anunciante escolhido no admin, é mensagem comum de uma conta real:
            // sem selo BOT, que pertence ao tipo de mensagem sem autor.
            $announcer = $this->announcer();

            $message = $announcer !== null
                ? Message::build($channel, $announcer, $body, parseAs: new Guest())
                : Message::buildBot(
                    $channel,
                    'discussion_started',
                    $body,
                    [
                        'title' => $discussion->title,
                        // O id, não a URL: uma URL gravada agora não sobreviveria a uma troca de endereço.
                        'discussionId' => (int) $discussion->id,
                        'username' => $event->actor?->display_name,
                        'userId'   => $event->actor?->id !== null ? (int) $event->actor->id : null,

                        // Snapshot, não leitura ao vivo: o aviso registra o que foi publicado, e ler
                        // ao vivo custaria uma consulta por aviso a cada rolagem.
                        'excerpt'  => $excerpt,

                        // A primeira imagem do post, como marca no topo do card; também em snapshot.
                        'image'    => $image,
                    ]
                );

            $message->save();

            $channel->refreshMetadata()->save();

            // Mesmo evento das mensagens comuns: broadcast e não lidas iguais.
            $this->events->dispatch(new MessageWasSent($message, $event->actor));
        }
    }

    /**
     * Se o leitor menos privilegiado do canal pode ver a discussão.
     *
     * Esse leitor tem `useChat` e `viewForum` na tag e na mãe; a discussão é buscada
     * com o scope de visibilidade do core, que inclui as regras de outras extensões
     * (tags, byobu, aprovação, ocultação). As checagens antes da consulta são uma
     * segunda linha e poupam a consulta nos casos comuns.
     */
    protected function audienceMaySee(object $channel, object $discussion): bool
    {
        if ((bool) ($discussion->is_private ?? false) || $discussion->hidden_at !== null) {
            return false;
        }

        // Null sem o flarum/approval; aí não há o que esperar.
        $approved = $discussion->is_approved ?? null;

        if ($approved !== null && ! (bool) $approved) {
            return false;
        }

        $tag = Tag::query()->find($channel->tag_id);

        if ($tag === null) {
            return false;
        }

        $granted = ['tag'.$tag->id.'.viewForum'];

        if (! empty($tag->parent_id)) {
            $granted[] = 'tag'.$tag->parent_id.'.viewForum';
        }

        // O mesmo para as tags já em memória, sem consulta: tag restrita que os
        // leitores do canal não têm garantida.
        try {
            foreach ($discussion->tags ?? [] as $other) {
                if ($other->is_restricted && ! in_array('tag'.$other->id.'.viewForum', $granted, true)) {
                    return false;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }

        return Discussion::query()
            // @phpstan-ignore method.notFound (Flarum model scope)
            ->whereVisibleTo(AudienceProbe::memberWith($granted))
            ->whereKey($discussion->id)
            ->exists();
    }

    /**
     * A conta que publica os avisos, ou null para o bot.
     *
     * Sem cache: a configuração pode mudar entre uma discussão e outra. Id que não
     * existe mais cai no bot; perder a autoria é melhor que perder o aviso.
     */
    protected function announcer(): ?User
    {
        $id = (int) $this->settings->get('ramon-chat.bot_user_id');

        if ($id <= 0) {
            return null;
        }

        return User::query()->find($id);
    }

    /**
     * O texto do aviso, em Markdown, para passar pelo formatter como qualquer
     * mensagem. URL relativa para sobreviver a mudança de endereço; o título é
     * escapado para que colchetes e parênteses não fechem o link e virem markup.
     */
    protected function body(object $discussion, ?string $excerpt): string
    {
        $title = str_replace(['[', ']'], ['\\[', '\\]'], (string) $discussion->title);

        $body = '**['.$title.'](/d/'.(int) $discussion->id.')**';

        if ($excerpt !== null && $excerpt !== '') {
            $body .= "\n\n".$excerpt;
        }

        return $body;
    }

    /**
     * A primeira imagem do post, para o topo do card.
     *
     * Procura Markdown, BBCode e HTML. Só http(s): o valor vai para um `src`, e
     * `javascript:` ou `data:` ali não pode acontecer. Não filtra por extensão; o
     * que não for imagem só falha ao carregar.
     */
    protected function firstImage(object $post): ?string
    {
        $content = (string) ($post->content ?? '');

        // Código não é imagem: um `![x](url)` dentro de crase ou bloco é texto e não
        // pode virar imagem carregada por todo o canal.
        $content = (string) preg_replace(['/```.*?(```|$)/s', '/~~~.*?(~~~|$)/s', '/`[^`\n]*`/', '/\[code\].*?(\[\/code\]|$)/is'], ' ', $content);

        if (trim($content) === '') {
            return null;
        }

        // Vence a ocorrência mais cedo no texto, não a sintaxe testada primeiro.
        $patterns = [
            '/!\[[^\]]*\]\(\s*([^)\s]+)/',            // ![alt](url)
            '/\[img\b[^\]]*\]\s*([^\[\s]+)/i',        // [img]url[/img]
            '/<img[^>]+src=["\']([^"\']+)/i',         // <img src="url">
        ];

        $best = null;
        $bestAt = PHP_INT_MAX;

        foreach ($patterns as $pattern) {
            // Todas as ocorrências: um candidato rejeitado não pode esconder as
            // imagens válidas da mesma sintaxe abaixo dele.
            if (! preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $m) {
                [$url, $at] = $m[1];
                $url = trim($url);

                // Só http(s): o valor vai para um `src`.
                if (! preg_match('~^https?://~i', $url)) {
                    continue;
                }

                if ($at < $bestAt) {
                    $best = $url;
                    $bestAt = $at;
                }

                // Ordenadas dentro do padrão; a primeira aceita é a mais cedo desta sintaxe.
                break;
            }
        }

        return $best === null ? null : mb_substr($best, 0, 500);
    }

    /**
     * Começo do post em texto simples, para o card.
     *
     * Feito do conteúdo cru: formatar exige contexto de requisição, e o card quer
     * texto. O resultado é anexado a um corpo Markdown e renderizado de novo, então
     * sobra parcial vira markup: imagem dentro de link (`[![CI](a)](b)`) exige
     * repetir até estabilizar, e HTML (`<img>`) precisa sair. No fim, neutraliza o
     * que restou em vez de confiar na remoção.
     */
    protected function excerpt(object $post): ?string
    {
        $content = trim((string) ($post->content ?? ''));

        if ($content === '') {
            return null;
        }

        // Citações primeiro, ainda em linhas: senão sobraria a frase de outra pessoa
        // como abertura deste post.
        $content = preg_replace('/\[quote[^\]]*\].*?\[\/quote\]/is', ' ', $content) ?? $content;
        $content = preg_replace('/<blockquote.*?<\/blockquote>/is', ' ', $content) ?? $content;
        $content = preg_replace('/^[ 	]*>[^
]*$/m', ' ', $content) ?? $content;

        // Código: raramente é uma boa abertura e traz justamente os caracteres a remover.
        $content = preg_replace('/```.*?```/s', ' ', $content) ?? $content;
        $content = preg_replace('/`[^`]*`/', ' ', $content) ?? $content;

        // Links e imagens, de dentro para fora, até estabilizar; com limite para
        // post patológico não travar aqui.
        for ($pass = 0; $pass < 6; $pass++) {
            $collapsed = preg_replace('/!?\[([^\[\]]*)\]\([^)\s]*(?:\s+[^)]*)?\)/', '$1', $content) ?? $content;

            if ($collapsed === $content) {
                break;
            }

            $content = $collapsed;
        }

        // `[img]` antes da remoção genérica de BBCode e com o conteúdo: a URL fica
        // dentro da tag e sobraria solta no meio da frase.
        $content = preg_replace('/\[img\b[^\]]*\].*?\[\/img\]/is', ' ', $content) ?? $content;

        // HTML e BBCode: o post é fonte, e um `<img>` que sobrar é desenhado no canal.
        $content = preg_replace('/<[^>]*>/', ' ', $content) ?? $content;
        $content = preg_replace('/\[\/?[a-z*][^\]]*\]/i', ' ', $content) ?? $content;

        // Ênfase, títulos, marcadores e linhas horizontais.
        $content = preg_replace('/^[ 	]*(?:[-*+]|\d+\.)\s+/m', ' ', $content) ?? $content;
        $content = preg_replace('/[`*_~]+/', '', $content) ?? $content;
        $content = preg_replace('/^\s*#+\s*/m', ' ', $content) ?? $content;

        // O que sobrou deve ser texto: escapa os caracteres que ainda poderiam abrir
        // markup ao ser anexado ao corpo.
        $content = str_replace(['[', ']', '<', '>', '`'], '', $content);

        $content = trim(preg_replace('/\s+/u', ' ', $content) ?? $content);

        if ($content === '') {
            return null;
        }

        return mb_strlen($content) > 180
            ? mb_substr($content, 0, 180).'…'
            : $content;
    }

    /**
     * @see JoinChannelsOnReply::tagIdsFor() — mesmo motivo, inclusive por que
     * `method_exists` não serve para a relação registrada dinamicamente.
     *
     * @return int[]
     */
    protected function tagIdsFor(object $discussion): array
    {
        try {
            $tags = $discussion->tags;
        } catch (\Throwable $e) {
            return [];
        }

        if ($tags === null) {
            return [];
        }

        $ids = [];

        foreach ($tags as $tag) {
            $ids[] = (int) $tag->id;

            if ($tag->parent_id !== null) {
                $ids[] = (int) $tag->parent_id;
            }
        }

        return array_values(array_unique($ids));
    }
}
