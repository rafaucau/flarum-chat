<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Formatter;

use Flarum\Post\Post;
use Illuminate\Database\Eloquent\Collection;
use s9e\TextFormatter\Utils;

/**
 * Deixa um model do chat sobreviver a callbacks do formatter escritos para posts.
 *
 * Callbacks de render/unparse de outras extensões (ex.: fof/move-posts) leem
 * `$context->mentionsPosts` sem checar se é um Post. O accessor resolve os posts
 * mencionados de fato; memoizado porque unparse e render o chamam.
 *
 * @property-read Collection<int, Post> $mentionsPosts
 */
trait ResolvesMentionedPosts
{
    /**
     * @var Collection<int, Post>|null
     */
    protected ?Collection $resolvedMentionedPosts = null;

    /**
     * @return Collection<int, Post>
     */
    public function getMentionsPostsAttribute(): Collection
    {
        if ($this->resolvedMentionedPosts !== null) {
            return $this->resolvedMentionedPosts;
        }

        $ids = $this->mentionedPostIds();

        $posts = $ids === []
            ? new Collection()
            : Post::query()->whereIn('id', $ids)->get();

        return $this->resolvedMentionedPosts = $posts;
    }

    /**
     * Ids dos posts mencionados, lidos das tags POSTMENTION.
     *
     * @return list<int>
     */
    public function mentionedPostIds(): array
    {
        $xml = $this->attributes['content'] ?? null;

        if (! is_string($xml) || $xml === '' || ! str_contains($xml, '<POSTMENTION')) {
            return [];
        }

        $ids = [];

        foreach (Utils::getAttributeValues($xml, 'POSTMENTION', 'id') as $id) {
            $id = (int) $id;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * O conteúdo como texto simples escapado, para quando o render falhou.
     *
     * Lido direto do XML: `$this->content` roda os callbacks de unparse, o mesmo
     * pipeline que acabou de falhar.
     */
    public function fallbackContentHtml(): string
    {
        $xml = $this->attributes['content'] ?? null;

        if (! is_string($xml) || $xml === '') {
            return '';
        }

        try {
            $text = Utils::removeFormatting($xml);
        } catch (\Throwable) {
            $text = strip_tags($xml);
        }

        return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
