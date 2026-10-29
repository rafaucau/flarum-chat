<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\unit\Service;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Ramon\Chat\Channel;
use Ramon\Chat\Service\ActionThrottle;
use Ramon\Chat\Service\ChannelOwnership;
use Ramon\Chat\Service\RateLimiter;
use Ramon\Chat\Service\SlowMode;

/**
 * Limite por segundo e modo lento sob envios em paralelo.
 *
 * Os dois liam o estado e só gravavam depois (o modo lento, só após o commit),
 * então N envios simultâneos passavam todos. Agora a vez é reservada com
 * operações atômicas do cache (`add`, `increment`): a segunda reserva na mesma
 * janela perde, mesmo antes de a primeira mensagem ser gravada.
 */
class SendLimitsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private function translator(): Translator
    {
        $translator = Mockery::mock(Translator::class);
        $translator->shouldReceive('trans')->andReturn('espere');

        return $translator;
    }

    private function member(): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 7;
        $user->shouldReceive('isAdmin')->andReturn(false);
        $user->shouldReceive('hasPermission')->andReturn(false);

        return $user;
    }

    public function test_slow_mode_gives_the_window_to_one_send_only(): void
    {
        $ownership = Mockery::mock(ChannelOwnership::class);
        $ownership->shouldReceive('exemptFromSlowMode')->andReturn(false);

        $slowMode = new SlowMode(new Repository(new ArrayStore()), $this->translator(), $ownership);

        $channel = new Channel();
        $channel->id = 1;
        $channel->slow_mode_seconds = 30;
        $actor = $this->member();

        // Dois envios em paralelo: os dois passam pela checagem...
        $slowMode->assertMayPost($channel, $actor);
        $slowMode->assertMayPost($channel, $actor);

        // ...mas só o primeiro reserva a vez.
        $slowMode->claim($channel, $actor);

        $this->expectException(ValidationException::class);
        $slowMode->claim($channel, $actor);
    }

    public function test_the_per_second_limit_counts_atomically(): void
    {
        $settings = Mockery::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->with('ramon-chat.max_messages_per_second', 2)->andReturn('2');

        $limiter = new RateLimiter(new ActionThrottle(new Repository(new ArrayStore())), $settings, $this->translator());
        $actor = $this->member();

        $refused = 0;

        // Dentro do mesmo segundo (o teste roda em milissegundos).
        for ($i = 0; $i < 5; $i++) {
            try {
                $limiter->assertWithinLimit($actor);
            } catch (ValidationException) {
                $refused++;
            }
        }

        $this->assertGreaterThanOrEqual(3, $refused);
    }
}
