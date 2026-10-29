<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Gdpr;

use Flarum\Gdpr\Data\Type;
use Illuminate\Support\Arr;
use Ramon\Chat\Bookmark;
use Ramon\Chat\ChannelInvite;
use Ramon\Chat\ChannelRankUser;
use Ramon\Chat\ChannelTransfer;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\Draft;
use Ramon\Chat\Message;
use Ramon\Chat\MessageFlag;
use Ramon\Chat\MessageReaction;
use Ramon\Chat\MessageRevision;
use Ramon\Chat\Service\OwnershipSuccession;
use Ramon\Chat\Storage\UploadStorage;
use Ramon\Chat\ThreadUser;
use Ramon\Chat\Upload;

/**
 * A parte do chat na exportação e no apagamento do flarum/gdpr.
 *
 * Registrado via `UserData` dentro de um Conditional: a classe-mãe é do
 * flarum/gdpr e daria fatal sem ele.
 *
 * Anonimizar desvincula a autoria (`user_id` null) e mantém o texto, para não
 * deixar as respostas dos outros sem contexto; apagar remove as mensagens. Os
 * uploads saem nos dois casos.
 */
class ChatData extends Type
{
    public static function dataType(): string
    {
        return 'ChatMessages';
    }

    /**
     * Sobrescrito porque a base procura a chave em `flarum-gdpr.lib.data.*`, onde
     * um tipo de terceiro não existe; as nossas ficam no locale do chat.
     */
    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ramon-chat.lib.gdpr.export_description');
    }

    public static function anonymizeDescription(): string
    {
        return self::staticTranslator()->trans('ramon-chat.lib.gdpr.anonymize_description');
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ramon-chat.lib.gdpr.delete_description');
    }

    /**
     * Arquivos exportados com o que o usuário escreveu ou nomeou: texto e versões
     * anteriores, nomes de anexos e de favoritos e o texto livre das denúncias.
     */
    public static function piiFields(): array
    {
        return ['content', 'revisions', 'file_name', 'name', 'detail'];
    }

    public function export(): ?array
    {
        $exportData = [];
        $userId = (int) $this->user->id;

        // Só o que o usuário escreveu, sem `whereVisibleTo`: mensagem em canal de onde
        // ele saiu continua sendo dele. Versões antigas só se a edição foi dele; a de
        // um moderador é registro do moderador.
        Message::query()
            ->where('user_id', $userId)
            ->where('type', Message::TYPE_TEXT)
            ->with([
                'channel',
                'uploads',
                'revisions' => fn ($query) => $query->where('edited_by_id', $userId)->orderBy('id'),
            ])
            ->orderBy('id')
            ->each(function (Message $message) use (&$exportData) {
                $exportData[] = [
                    "chat/message-{$message->id}.json" => $this->encodeForExport([
                        'content'      => $message->content,
                        'channel'      => $message->channel?->name,
                        'channel_type' => $message->channel?->type,
                        'thread_id'    => $message->thread_id,
                        'created_at'   => $message->created_at?->toIso8601String(),
                        'edited_at'    => $message->edited_at?->toIso8601String(),
                        'attachments'  => $message->uploads
                            ->map(fn (Upload $upload) => Arr::only($upload->toArray(), ['file_name', 'mime_type', 'size']))
                            ->values()
                            ->all(),
                        'revisions'    => $message->revisions
                            ->map(fn (MessageRevision $revision) => [
                                'content'    => $revision->content,
                                'created_at' => $revision->created_at?->toIso8601String(),
                            ])
                            ->values()
                            ->all(),
                    ]),
                ];
            });

        // Participar de um canal já é dado pessoal, mesmo sem mensagem.
        $memberships = ChannelUser::query()
            ->where('user_id', $userId)
            ->with('channel')
            ->get()
            ->map(fn (ChannelUser $membership) => [
                'channel'   => $membership->channel?->name,
                'joined_at' => $membership->joined_at?->toIso8601String(),
                'left_at'   => $membership->left_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        if ($memberships !== []) {
            $exportData[] = ['chat/channels.json' => $this->encodeForExport($memberships)];
        }

        // Cargos dados pelos donos: rótulos sobre a pessoa, então ela pode ver; quem
        // deu e as configurações do cargo, não.
        $ranks = ChannelRankUser::query()
            ->where('user_id', $userId)
            ->with(['rank', 'channel'])
            ->orderBy('channel_id')
            ->get()
            ->map(fn (ChannelRankUser $held) => [
                'channel'     => $held->channel?->name,
                'rank'        => $held->rank?->name,
                'assigned_at' => $held->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        if ($ranks !== []) {
            $exportData[] = ['chat/ranks.json' => $this->encodeForExport($ranks)];
        }

        $bookmarks = Bookmark::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get()
            ->map(fn (Bookmark $bookmark) => [
                'message_id' => $bookmark->message_id,
                'name'       => $bookmark->name,
                'remind_at'  => $bookmark->remind_at?->toIso8601String(),
                'created_at' => $bookmark->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        if ($bookmarks !== []) {
            $exportData[] = ['chat/bookmarks.json' => $this->encodeForExport($bookmarks)];
        }

        // Denúncias feitas por ele, nas palavras dele. As feitas sobre ele e as
        // resoluções pertencem aos moderadores.
        $flags = MessageFlag::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get()
            ->map(fn (MessageFlag $flag) => [
                'message_id' => $flag->message_id,
                'reason'     => $flag->reason,
                'detail'     => $flag->detail,
                'created_at' => $flag->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        if ($flags !== []) {
            $exportData[] = ['chat/reports.json' => $this->encodeForExport($flags)];
        }

        return $exportData === [] ? null : $exportData;
    }

    public function anonymize(): void
    {
        // Autoria sai, conversa fica legível. Reações e rascunhos saem de vez: sem o
        // autor não valem nada.
        Message::query()
            ->where('user_id', $this->user->id)
            ->update(['user_id' => null]);

        // A denúncia continua como registro, mas sem quem a fez nem o texto.
        MessageFlag::query()
            ->where('user_id', $this->user->id)
            ->update(['user_id' => null, 'detail' => null]);

        $this->detachFromOthersRecords();
        $this->purgeIncidentals();
    }

    public function delete(): void
    {
        // Anexos antes: as linhas apontam para os arquivos, e o cascade as levaria.
        $this->deleteUploads();

        Message::query()->where('user_id', $this->user->id)->delete();
        MessageFlag::query()->where('user_id', $this->user->id)->delete();

        $this->detachFromOthersRecords();
        $this->purgeIncidentals();
    }

    /**
     * O que fica em linhas de outros: o nome na narração ("X entrou", "X adicionou
     * Y", o anúncio da discussão) e o id como editor ou resolvedor de denúncia. As
     * linhas ficam; nome e id saem.
     */
    protected function detachFromOthersRecords(): void
    {
        MessageRevision::query()
            ->where('edited_by_id', $this->user->id)
            ->update(['edited_by_id' => null]);

        MessageFlag::query()
            ->where('resolved_by_id', $this->user->id)
            ->update(['resolved_by_id' => null]);

        $this->scrubNarration();
    }

    /**
     * Mensagens de sistema e de bot guardam o nome exibido em `system_data`, não o
     * id, para ler igual após renomear. Por isso casa pelo nome atual, antes de o
     * flarum/gdpr renomear a conta (o tipo dele roda por último).
     *
     * A consulta filtra por um trecho do JSON; quem decide é a comparação exata no PHP.
     */
    protected function scrubNarration(): void
    {
        $names = array_values(array_unique(array_filter([
            (string) $this->user->username,
            (string) $this->user->display_name,
        ], fn (string $name) => $name !== '')));

        if ($names === []) {
            return;
        }

        $replacement = $this->anonymousName();
        $userId = (int) $this->user->id;
        $fragments = array_map(fn (string $name) => $this->likeFragment($name), $names);

        $query = Message::query()
            ->whereIn('type', [Message::TYPE_SYSTEM, Message::TYPE_BOT])
            ->whereNotNull('system_data');

        if (! in_array(null, $fragments, true)) {
            $query->where(function ($query) use ($fragments, $userId) {
                foreach ($fragments as $fragment) {
                    $query->orWhere('system_data', 'like', '%'.$fragment.'%');
                }

                $query->orWhere('system_data', 'like', '%"userId":'.$userId.'%');
            });
        }

        $query->chunkById(200, function ($messages) use ($names, $replacement, $userId) {
            foreach ($messages as $message) {
                $data = $message->system_data;

                if (! is_array($data)) {
                    continue;
                }

                $changed = false;

                foreach (['username', 'actor'] as $key) {
                    if (isset($data[$key]) && in_array($data[$key], $names, true)) {
                        $data[$key] = $replacement;
                        $changed = true;
                    }
                }

                if (isset($data['userId']) && (int) $data['userId'] === $userId) {
                    $data['userId'] = null;
                    $data['username'] = $replacement;
                    $changed = true;
                }

                if ($changed) {
                    $message->system_data = $data;
                    $message->save();
                }
            }
        });
    }

    /**
     * O maior trecho do nome, como fica no JSON, sem barra invertida: o JSON escapa
     * não-ASCII e barras, e a barra é escape no LIKE de alguns bancos. Null se
     * nenhum trecho filtra o suficiente; aí toda narração é checada.
     */
    protected function likeFragment(string $name): ?string
    {
        $runs = explode('\\', substr((string) json_encode($name), 1, -1));
        usort($runs, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return strlen($runs[0]) >= 3 ? $runs[0] : null;
    }

    /**
     * O nome que o flarum/gdpr dá à conta, para a narração bater com o perfil.
     */
    protected function anonymousName(): string
    {
        $prefix = (string) ($this->settings->get('flarum-gdpr.default-anonymous-username') ?: 'Anonymous');

        return $this->erasureRequest !== null ? $prefix.$this->erasureRequest->id : $prefix;
    }

    /**
     * Reações, rascunhos, favoritos, memberships, leitura e uploads: o que é só
     * deste usuário.
     */
    protected function purgeIncidentals(): void
    {
        // Canais dele passam ao moderador mais antigo, como ao sair, antes de as
        // memberships serem apagadas. Resolvido aqui porque o construtor é da base do gdpr.
        resolve(OwnershipSuccession::class)->handOverAll($this->user);

        $this->deleteUploads();

        MessageReaction::query()->where('user_id', $this->user->id)->delete();
        Draft::query()->where('user_id', $this->user->id)->delete();
        Bookmark::query()->where('user_id', $this->user->id)->delete();
        ThreadUser::query()->where('user_id', $this->user->id)->delete();
        ChannelUser::query()->where('user_id', $this->user->id)->delete();
        ChannelRankUser::query()->where('user_id', $this->user->id)->delete();
        ChannelInvite::query()->where('user_id', $this->user->id)->delete();

        // Transferências pendentes em qualquer direção ficam sem ninguém para responder.
        ChannelTransfer::query()
            ->where('from_user_id', $this->user->id)
            ->orWhere('to_user_id', $this->user->id)
            ->delete();
    }

    protected function deleteUploads(): void
    {
        // Resolvido aqui, não injetado: o construtor é da base do gdpr. O resolver sabe
        // se o arquivo está nos discos do chat ou no fof/upload.
        $storage = resolve(UploadStorage::class);

        Upload::query()
            ->where('user_id', $this->user->id)
            ->each(function (Upload $upload) use ($storage) {
                // Melhor esforço no arquivo; o resolver loga a falha. Remover a linha é o que
                // importa legalmente, e uma exceção abortaria a requisição inteira.
                $storage->delete($upload);

                $upload->delete();
            });
    }
}
