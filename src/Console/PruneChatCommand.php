<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Console;

use Carbon\Carbon;
use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Symfony\Component\Console\Input\InputOption;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\Message;
use Ramon\Chat\Service\UnreadTracker;
use Ramon\Chat\Storage\UploadStorage;
use Ramon\Chat\Thread;
use Ramon\Chat\Upload;

/**
 * Aplica a retenção de mensagens e remove uploads órfãos. Roda toda noite; é
 * destrutivo por natureza, por isso tem --dry-run.
 */
class PruneChatCommand extends AbstractCommand
{
    /**
     * Órfãos mais novos que isso ficam: o composer pode estar aberto com o anexo pendente.
     */
    protected const ORPHAN_GRACE_HOURS = 24;

    protected function configure(): void
    {
        $this
            ->setName('chat:prune')
            ->setDescription('Delete chat messages past their retention window and sweep orphaned uploads.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted without deleting it.');
    }

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected UploadStorage $storage,
        protected UnreadTracker $unread
    ) {
        parent::__construct();
    }

    protected function fire(): int
    {
        $dryRun = (bool) $this->input->getOption('dry-run');

        if ($dryRun) {
            $this->info('Dry run — nothing will be deleted.');
        }

        $channelDays = (int) $this->settings->get('ramon-chat.channel_retention_days', 90);
        $dmDays = (int) $this->settings->get('ramon-chat.dm_retention_days', 0);

        $touched = [];

        $channelDeleted = $this->pruneByType(Channel::TYPE_CATEGORY, $channelDays, $dryRun, $touched);
        $dmDeleted = $this->pruneByType(Channel::TYPE_DIRECT, $dmDays, $dryRun, $touched);

        $uploadsDeleted = $this->pruneOrphanedUploads($dryRun);

        // Contadores derivam da tabela de mensagens; sem recalcular, a lista
        // mostraria uma última mensagem que não existe mais.
        if (! $dryRun && $touched !== []) {
            Channel::query()
                ->whereIn('id', array_keys($touched))
                ->each(fn (Channel $channel) => $channel->refreshMetadata()->save());

            // Tópicos e não lidas também derivam das mensagens.
            Thread::query()
                ->whereIn('channel_id', array_keys($touched))
                ->each(fn (Thread $thread) => $thread->refreshMetadata()->save());

            ChannelUser::query()
                ->whereIn('channel_id', array_keys($touched))
                ->whereNull('left_at')
                ->where(fn ($query) => $query->where('unread_count', '>', 0)->orWhere('unread_mentions_count', '>', 0))
                ->each(fn (ChannelUser $membership) => $this->unread->recalculate($membership)->save());
        }

        $this->info(sprintf(
            '%s %d channel message(s), %d direct message(s), %d orphaned upload(s) across %d channel(s).',
            $dryRun ? 'Would delete' : 'Deleted',
            $channelDeleted,
            $dmDeleted,
            $uploadsDeleted,
            count($touched)
        ));

        return 0;
    }

    /**
     * @param  array<int, true>  $touched  canais que perderam mensagens
     */
    protected function pruneByType(string $type, int $days, bool $dryRun, array &$touched): int
    {
        // 0 é "guardar para sempre", o padrão documentado para DMs.
        if ($days <= 0) {
            return 0;
        }

        $cutoff = Carbon::now()->subDays($days);

        $channelIds = Channel::query()
            ->where('type', $type)
            ->pluck('id');

        if ($channelIds->isEmpty()) {
            return 0;
        }

        $deleted = 0;

        Message::query()
            ->whereIn('channel_id', $channelIds)
            ->where('created_at', '<', $cutoff)
            // Ficam as fixadas, as com denúncia aberta (são a evidência) e a raiz
            // de tópico ainda ativo.
            ->whereNull('pinned_at')
            ->whereDoesntHave('flags', fn ($query) => $query->whereNull('resolved_at'))
            ->whereNotIn('id', Thread::query()
                ->where('last_message_at', '>=', $cutoff)
                ->whereNotNull('original_message_id')
                ->select('original_message_id'))
            ->select(['id', 'channel_id'])
            // chunkById para que apagar linhas não desloque as páginas.
            ->chunkById(500, function ($messages) use (&$deleted, &$touched, $dryRun) {
                $ids = $messages->pluck('id')->all();

                foreach ($messages as $message) {
                    $touched[$message->channel_id] = true;
                }

                if (! $dryRun) {
                    // Arquivos antes: as linhas de upload caem em cascata e
                    // depois não haveria como achá-los.
                    $this->deleteFilesFor($ids);

                    Message::query()->whereIn('id', $ids)->delete();
                }

                $deleted += count($ids);
            });

        return $deleted;
    }

    protected function pruneOrphanedUploads(bool $dryRun): int
    {
        $cutoff = Carbon::now()->subHours(self::ORPHAN_GRACE_HOURS);

        $deleted = 0;

        Upload::query()
            // `where(..., null)` em vez de `whereNull` para o PHPStan tipar as
            // linhas como models, não stdClass.
            ->where('message_id', null)
            ->where('created_at', '<', $cutoff)
            ->chunkById(500, function ($uploads) use (&$deleted, $dryRun) {
                if (! $dryRun) {
                    foreach ($uploads as $upload) {
                        // Falha em um arquivo não aborta a varredura; a linha sai de qualquer jeito.
                        if (! $this->storage->delete($upload)) {
                            $this->error('Could not delete '.$upload->path.'; see the log.');
                        }
                    }

                    Upload::query()->whereIn('id', $uploads->pluck('id')->all())->delete();
                }

                $deleted += $uploads->count();
            });

        return $deleted;
    }

    /**
     * @param  int[]  $messageIds
     */
    protected function deleteFilesFor(array $messageIds): void
    {
        Upload::query()
            ->whereIn('message_id', $messageIds)
            ->each(function (Upload $upload) {
                if (! $this->storage->delete($upload)) {
                    $this->error('Could not delete '.$upload->path.'; see the log.');
                }
            });
    }
}
