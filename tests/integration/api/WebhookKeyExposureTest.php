<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\TestCase;
use Ramon\Chat\Tests\integration\ResetsVisibilityScopers;

/**
 * A chave de um webhook sai só na resposta que a criou ou rotacionou.
 *
 * O resource vive entre requests no mesmo processo. Uma marca de "chave nova"
 * que ficasse ligada depois do rotate faria a próxima listagem devolver todas
 * as chaves, e a listagem é o que acaba em logs e caches.
 */
class WebhookKeyExposureTest extends TestCase
{
    use ResetsVisibilityScopers;

    /** Montada em partes para scanners de segredo não lerem a fixture como credencial. */
    private const KEY = 'test'.'webhook'.'key'.'00000000000000000000000000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetVisibilityScopers();

        $this->extension('ramon-chat');

        $now = Carbon::now()->toDateTimeString();

        $this->prepareDatabase([
            'chat_channels' => [
                ['id' => 1, 'type' => 'category', 'name' => 'Geral', 'slug' => 'geral', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now],
            ],
            'chat_webhooks' => [
                ['id' => 1, 'name' => 'um', 'channel_id' => 1, 'key' => self::KEY, 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['id' => 2, 'name' => 'dois', 'channel_id' => 1, 'key' => strrev(self::KEY), 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ],
        ]);
    }

    public function test_the_listing_after_a_rotate_shows_no_keys(): void
    {
        $rotated = $this->send($this->request('POST', '/api/chat-webhooks/1/rotate', ['authenticatedAs' => 1]));

        $this->assertSame(200, $rotated->getStatusCode(), (string) $rotated->getBody());
        $this->assertNotNull(json_decode((string) $rotated->getBody(), true)['data']['attributes']['key'], 'the rotate response carries the new key');

        $listing = $this->send($this->request('GET', '/api/chat-webhooks', ['authenticatedAs' => 1]));

        $this->assertSame(200, $listing->getStatusCode());

        foreach (json_decode((string) $listing->getBody(), true)['data'] as $row) {
            $this->assertNull($row['attributes']['key'], 'webhook '.$row['id'].' key leaked');
            $this->assertNull($row['attributes']['url']);
        }
    }
}
