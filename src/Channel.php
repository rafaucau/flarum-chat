<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Database\ScopeVisibilityTrait;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\Config;
use Flarum\Foundation\EventGeneratorTrait;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $type
 * @property string|null $name
 * @property string|null $slug
 * @property string|null $description
 * @property string|null $emoji
 * @property string|null $image_path
 * @property int|null $tag_id
 * @property string $status
 * @property bool $is_private
 * @property string $post_permission
 * @property bool $threading_enabled
 * @property int $slow_mode_seconds
 * @property int|null $max_message_length
 * @property bool $auto_join
 * @property bool $auto_join_on_reply
 * @property bool $post_discussions
 * @property bool $allow_channel_wide_mentions
 * @property int|null $creator_id
 * @property int $messages_count
 * @property int $user_count
 * @property int|null $last_message_id
 * @property Carbon|null $last_message_at
 * @property int|null $archived_discussion_id
 * @property Carbon|null $archived_at
 * @property int|null $archived_by_id
 * @property Carbon|null $deleted_at
 * @property int|null $deleted_by_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $creator
 * @property-read Collection<int, Message> $messages
 * @property-read Collection<int, Thread> $threads
 * @property-read Collection<int, User> $participants
 * @property-read Message|null $lastMessage
 */
class Channel extends AbstractModel
{
    use EventGeneratorTrait;
    use ScopeVisibilityTrait;

    /**
     * A channel bound to a tag (or to the whole forum when tags is disabled).
     * Visibility is inherited from the tag, so category permissions govern chat
     * access without a second permission surface to maintain.
     */
    public const TYPE_CATEGORY = 'category';

    /**
     * A 1:1 or group conversation with an explicit participant list.
     */
    public const TYPE_DIRECT = 'direct';

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'chat_channels';

    /**
     * App-wide services the model reads from, handed over by ChatServiceProvider
     * the same way HasFormattedContent receives its formatter. Neither holds
     * actor or request state, so sharing them across a worker's requests is safe.
     */
    protected static ?ExtensionManager $extensions = null;

    protected static ?Config $config = null;

    public $timestamps = true;

    /**
     * Memberships already looked up on this instance, by user id. Per-instance
     * and not static: a static would survive between actors in a queue worker.
     *
     * @var array<int, ChannelUser|null>
     */
    protected array $membershipCache = [];

    /**
     * Pending invites already looked up on this instance, by user id.
     *
     * @var array<int, ChannelInvite|null>
     */
    protected array $inviteCache = [];

    /**
     * Whether a user has left this channel, by user id, for `hasLeft()`.
     *
     * @var array<int, bool>
     */
    protected array $departureCache = [];

    protected $casts = [
        'tag_id'                      => 'integer',
        'creator_id'                  => 'integer',
        'messages_count'              => 'integer',
        'user_count'                  => 'integer',
        'last_message_id'             => 'integer',
        'archived_discussion_id'      => 'integer',
        'archived_by_id'              => 'integer',
        'deleted_by_id'               => 'integer',
        'slow_mode_seconds'           => 'integer',
        'max_message_length'          => 'integer',
        'is_private'                  => 'boolean',
        'threading_enabled'           => 'boolean',
        'auto_join'                   => 'boolean',
        'auto_join_on_reply'          => 'boolean',
        'post_discussions'            => 'boolean',
        'allow_channel_wide_mentions' => 'boolean',
        'last_message_at'             => 'datetime',
        'archived_at'                 => 'datetime',
        'deleted_at'                  => 'datetime',
    ];

    public static function setServices(ExtensionManager $extensions, Config $config): void
    {
        static::$extensions = $extensions;
        static::$config = $config;
    }

    public static function build(
        string $type,
        ?string $name = null,
        ?string $description = null,
        ?int $tagId = null,
        ?User $creator = null
    ): static {
        $channel = new static();

        $channel->type = $type;
        $channel->name = $name;
        $channel->description = $description;
        $channel->tag_id = $tagId;
        $channel->creator_id = $creator?->id;
        $channel->status = self::STATUS_OPEN;

        if ($type === self::TYPE_CATEGORY && $name !== null) {
            $channel->slug = $channel->generateSlug($name);
        }

        return $channel;
    }

    /**
     * Slugs are only meaningful for category channels — direct channels are
     * addressed by id because their name is derived from the participant list.
     */
    public function generateSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'channel';
        $slug = $base;
        $suffix = 1;

        while (
            static::query()
                ->where('slug', $slug)
                ->when($this->exists, fn (Builder $q) => $q->whereKeyNot($this->id))
                ->exists()
        ) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    public function isDirect(): bool
    {
        return $this->type === self::TYPE_DIRECT;
    }

    /**
     * Invitation-only: visible to members alone, so it is absent from Browse and
     * cannot be joined by someone who was not added.
     *
     * A direct channel is never marked private — it is private by construction, and
     * treating it as both would make the flag mean two different things.
     */
    /** Only holders of `ramon-chat.moderate` may post. */
    public const POST_ALL = 'all';
    public const POST_MODERATORS = 'moderators';

    /**
     * Whether posting is narrowed to moderators — an announcement channel.
     *
     * Never true for a direct channel: a conversation only one side may write to is
     * not a conversation, and the setting is not offered for them.
     */
    public function restrictsPostingToModerators(): bool
    {
        return $this->isCategory() && $this->post_permission === self::POST_MODERATORS;
    }

    public function isPrivate(): bool
    {
        return $this->isCategory() && (bool) $this->is_private;
    }

    public function isCategory(): bool
    {
        return $this->type === self::TYPE_CATEGORY;
    }

    /**
     * Whether everyone who can see the channel is in it — the "add all users"
     * setting, an IRC-style room open to the whole forum.
     *
     * Nobody gets a membership row for it up front. A row appears only when
     * someone does something that has to be stored: reading the channel (the
     * read marker lives on the row), changing how it notifies them, or leaving
     * it. Writing one row per account to open a general channel is what made a
     * forum of 400k users crawl.
     *
     * Never true for a private channel: "everyone" and "invitation only"
     * contradict each other, and the invitation is the one that keeps people out.
     */
    public function isForEveryone(): bool
    {
        return $this->isCategory() && (bool) $this->auto_join && ! $this->isPrivate();
    }

    /**
     * Narrows a query over `chat_channels` to the channels for everyone: the SQL
     * form of isForEveryone(), which the two must keep in step with.
     */
    public static function onlyForEveryone(QueryBuilder $query): QueryBuilder
    {
        return $query
            ->where('chat_channels.type', self::TYPE_CATEGORY)
            ->where('chat_channels.auto_join', true)
            ->where('chat_channels.is_private', false);
    }

    /**
     * The ids of every channel for everyone, as a subquery.
     */
    public static function idsForEveryone(): \Closure
    {
        return fn (QueryBuilder $sub) => static::onlyForEveryone($sub->select('chat_channels.id')->from('chat_channels'));
    }

    /**
     * Whether the user is in the channel: a live membership, or, in a channel
     * for everyone, simply never having left it.
     *
     * Visibility is not part of the answer. Callers ask it of a channel the user
     * can already see, which is what makes "everyone" mean everyone who may see
     * the channel rather than every row in `users`.
     */
    public function includes(?User $user): bool
    {
        if ($this->membershipFor($user) !== null) {
            return true;
        }

        if ($user === null || ! $user->exists || ! $this->isForEveryone()) {
            return false;
        }

        return ! $this->hasLeft($user);
    }

    /**
     * Whether the user holds a membership they have left, memoised like
     * `membershipFor()`. In a channel for everyone that row is the only thing
     * that keeps them out.
     */
    public function hasLeft(User $user): bool
    {
        $id = (int) $user->id;

        if (! array_key_exists($id, $this->departureCache)) {
            $this->departureCache[$id] = ChannelUser::query()
                ->where('channel_id', $this->id)
                ->where('user_id', $id)
                ->whereNotNull('left_at')
                ->exists();
        }

        return $this->departureCache[$id];
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    /**
     * Whether new messages may be posted. Closed and archived channels stay
     * readable but frozen, which is what makes archiving non-destructive.
     */
    public function acceptsMessages(): bool
    {
        return $this->isOpen() && ! $this->isDeleted();
    }

    /**
     * How long a message may be here, given the forum-wide default.
     *
     * Null and zero both mean "follow the forum": a channel that never had an
     * opinion and a channel whose own limit was cleared must behave the same,
     * and zero as a literal cap would forbid every message.
     */
    public function maxMessageLength(int $forumDefault): int
    {
        return $this->max_message_length ?: $forumDefault;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Whether the user created this channel. What that grants is decided by
     * Service\ChannelOwnership, which reads the forum's ownership mode; this
     * only answers the fact. Direct channels never qualify: their creator merely
     * opened the conversation, and a group DM's participant list is governed by
     * ChannelPolicy::manageMembers on its own terms.
     */
    public function isOwnedBy(?User $user): bool
    {
        if ($user === null || $user->isGuest() || $this->creator_id === null) {
            return false;
        }

        return $this->isCategory() && (int) $this->creator_id === (int) $user->id;
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'channel_id');
    }

    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class, 'channel_id');
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class, 'channel_id');
    }

    /**
     * Every user with a membership row, including those who have muted the
     * channel. Excludes users who left a direct channel.
     */
    /**
     * The people in the channel, as everyone else sees them.
     *
     * Hidden memberships are excluded: a moderator who joined to observe should not
     * appear in the member list, which is the whole point of joining that way. They
     * still have a membership row — see `memberships()`, which does not filter — so
     * their unread state and sidebar entry work normally.
     *
     * In a channel for everyone it is the people who run the room: its creator
     * and its moderators. Everyone else is in it whether or not they have a row,
     * so the rows would list whoever happened to read the channel — in a general
     * channel, most of the forum, which the members tab could never load. The
     * constraint is in SQL rather than on the instance so it holds for eager
     * loads too, which build the relation from a blank model.
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_channel_user', 'channel_id', 'user_id')
            ->withPivot('is_moderator')
            ->whereNull('chat_channel_user.left_at')
            ->where('chat_channel_user.hidden', false)
            ->where(function ($query) {
                $query->whereNotIn('chat_channel_user.channel_id', static::idsForEveryone())
                    ->orWhere('chat_channel_user.is_moderator', true)
                    ->orWhereIn('chat_channel_user.user_id', function (QueryBuilder $sub) {
                        $sub->select('owned.creator_id')
                            ->from('chat_channels', 'owned')
                            ->whereColumn('owned.id', 'chat_channel_user.channel_id');
                    });
            });
    }

    /**
     * The same set as `participants`, for the endpoints that label direct
     * channels.
     *
     * A relation of its own rather than eager-loading `participants`: that one is
     * what `?include=participants` serves, and constraining it to direct channels
     * would hand the members tab of a category channel an empty list. The
     * constraint lives at eager-load time (`eagerLoadWhere`), because loading
     * every member of every listed channel to label the handful of direct ones is
     * the cost this exists to avoid.
     */
    public function directParticipants(): BelongsToMany
    {
        return $this->participants();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ChannelUser::class, 'channel_id');
    }

    public function invites(): HasMany
    {
        return $this->hasMany(ChannelInvite::class, 'channel_id');
    }

    /**
     * Who was invited and has not yet answered, for the members tab of whoever
     * manages the channel.
     */
    public function invitedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_channel_invites', 'channel_id', 'user_id')
            ->withPivot('inviter_id');
    }

    /**
     * The invite of whoever is asking, for the endpoints that load it along.
     *
     * Unconstrained here, like `actorMembership()`: only the endpoint knows who
     * the actor is, and it is the one that constrains the eager load. Nothing
     * but `pendingInviteFor()` should read this relation.
     */
    public function actorInvite(): HasOne
    {
        return $this->hasOne(ChannelInvite::class, 'channel_id');
    }

    /**
     * The user's pending invite, memoized like `membershipFor()`.
     *
     * An already loaded `actorInvite` answers without a query, even when it came
     * back empty: the relation is only loaded constrained to the request's
     * actor, and without that trust the channel list would cost one query per
     * row just to find out there is no invite at all.
     */
    public function pendingInviteFor(?User $user): ?ChannelInvite
    {
        if ($user === null || ! $user->exists) {
            return null;
        }

        $id = (int) $user->id;

        if (array_key_exists($id, $this->inviteCache)) {
            return $this->inviteCache[$id];
        }

        if ($this->relationLoaded('actorInvite')) {
            $loaded = $this->getRelation('actorInvite');

            if ($loaded === null) {
                return $this->inviteCache[$id] = null;
            }

            if ($loaded instanceof ChannelInvite && (int) $loaded->user_id === $id) {
                return $this->inviteCache[$id] = $loaded;
            }
        }

        /** @var ChannelInvite|null $invite */
        $invite = $this->invites()
            ->where('user_id', $id)
            ->first();

        return $this->inviteCache[$id] = $invite;
    }

    public function forgetInvite(?User $user = null): void
    {
        if ($user === null) {
            $this->inviteCache = [];

            return;
        }

        unset($this->inviteCache[(int) $user->id]);
    }

    /**
     * The tag this channel inherits permissions from. Resolved dynamically so
     * the model does not hard-depend on flarum/tags being installed.
     *
     * Gated on the extension being *enabled*, not on the class existing: the
     * class is autoloaded from vendor/ either way, and loading this relation
     * against a `tags` table whose migrations never ran is a 500.
     */
    public function tag(): ?BelongsTo
    {
        if (! static::$extensions?->isEnabled('flarum-tags')) {
            return null;
        }

        return $this->belongsTo(\Flarum\Tags\Tag::class, 'tag_id');
    }

    /**
     * The membership of whoever is asking, for the endpoints that eager-load it.
     *
     * Deliberately unconstrained here: the constraint is applied at eager-load
     * time (`eagerLoadWhere`), because only the endpoint knows the actor. Reading
     * it directly off a model would give you an arbitrary member's row, which is
     * why nothing but `membershipFor()` — which checks whose row it got — touches
     * it.
     */
    public function actorMembership(): HasOne
    {
        return $this->hasOne(ChannelUser::class, 'channel_id');
    }

    /**
     * Memoised on the instance, including the misses.
     *
     * ChannelPolicy reaches this from `postMessage`, `join` and `edit`, and the
     * resource resolves all three for every channel the sidebar lists — so a
     * fifty channel list ran the same lookup several times per row. Model
     * instances do not outlive the request, so the cache cannot leak between
     * actors; `forgetMembership()` covers the one case where the answer changes
     * while the request is still running.
     *
     * `array_key_exists` rather than `isset`, so a cached "not a member" is not
     * re-queried on every ask.
     */
    public function membershipFor(?User $user): ?ChannelUser
    {
        if ($user === null || ! $user->exists) {
            return null;
        }

        $id = (int) $user->id;

        if (array_key_exists($id, $this->membershipCache)) {
            return $this->membershipCache[$id];
        }

        // The eager-loaded row, but only once it has proved it belongs to the user
        // being asked about. A null there is ambiguous — it could mean "this actor
        // is not a member" or "the relation was constrained to somebody else" — so
        // it falls through to the query rather than answering wrongly.
        if ($this->relationLoaded('actorMembership')) {
            $loaded = $this->getRelation('actorMembership');

            if ($loaded instanceof ChannelUser && (int) $loaded->user_id === $id) {
                return $this->membershipCache[$id] = $loaded;
            }
        }

        return $this->membershipCache[$id] = $this->memberships()
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->first();
    }

    /**
     * Drops a memoised membership after joining, leaving, or being added or
     * removed — the mutation happens mid-request and the next read must see it.
     *
     * Passing null clears the lot, for a bulk change.
     */
    public function forgetMembership(?User $user = null): void
    {
        if ($user === null) {
            $this->membershipCache = [];
            $this->departureCache = [];

            return;
        }

        unset($this->membershipCache[(int) $user->id], $this->departureCache[(int) $user->id]);
    }

    /**
     * Answers membershipFor() and pendingInviteFor() for a whole list of users
     * with one query each, for the requests that walk many people at once.
     * Whoever has neither is remembered as having neither, so the walk does
     * not fall back to a query per person.
     *
     * @param  int[]  $userIds
     */
    public function rememberLookupsFor(array $userIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));

        if ($ids === [] || ! $this->exists) {
            return;
        }

        $memberships = $this->memberships()
            ->whereIn('user_id', $ids)
            ->whereNull('left_at')
            ->get()
            ->keyBy('user_id');

        $invites = $this->invites()
            ->whereIn('user_id', $ids)
            ->get()
            ->keyBy('user_id');

        foreach ($ids as $id) {
            $this->membershipCache[$id] = $memberships->get($id);
            $this->inviteCache[$id] = $invites->get($id);
        }
    }

    /**
     * The channel's picture, or null.
     *
     * Built from the assets base each time rather than stored as a URL, so moving
     * the forum to another host does not leave every channel icon pointing at the
     * old one. Mirrors how core resolves the logo path.
     */
    public function imageUrl(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        return static::$config->url()->getPath()
            .'/assets/'.$this->image_path;
    }

    /**
     * Recomputes the denormalised counters from the messages table. Used after
     * bulk operations (message moves, retention pruning, archiving) where
     * tracking increments incrementally would be error-prone.
     *
     * `user_count` counts visible members only, the same set MembershipManager
     * keeps it to: a hidden member counted here made the card read one higher
     * than the list for each observer, which is the presence joining hidden is
     * meant to keep quiet.
     */
    public function refreshMetadata(): static
    {
        $last = $this->messages()
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        $this->messages_count = $this->messages()->whereNull('deleted_at')->count();
        $this->last_message_id = $last?->id;
        $this->last_message_at = $last?->created_at;
        $this->user_count = $this->memberships()
            ->whereNull('left_at')
            ->where('hidden', false)
            ->count();

        return $this;
    }
}
