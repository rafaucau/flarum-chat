<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\unit\Listener;

use PHPUnit\Framework\TestCase;
use Ramon\Chat\Listener\AnnounceDiscussions;

/**
 * A imagem do anúncio de uma discussão nova. URL dentro de código não conta:
 * o post mostra só texto ali, e a imagem seria carregada pelo navegador de todo
 * o canal.
 */
class AnnouncementImageTest extends TestCase
{
    private function firstImage(string $content): ?string
    {
        $listener = (new \ReflectionClass(AnnounceDiscussions::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($listener, 'firstImage');

        return $method->invoke($listener, (object) ['content' => $content]);
    }

    public function test_an_image_inside_code_is_not_an_image(): void
    {
        $this->assertNull($this->firstImage('veja `![x](https://evil.example/px.gif)` aqui'));
        $this->assertNull($this->firstImage("```\n![x](https://evil.example/px.gif)\n```"));
        $this->assertNull($this->firstImage('[code][img]https://evil.example/px.gif[/img][/code]'));
    }

    public function test_a_real_image_is_still_found(): void
    {
        $this->assertSame('https://forum.example/a.png', $this->firstImage("`código`\n\n![logo](https://forum.example/a.png)"));
    }
}
