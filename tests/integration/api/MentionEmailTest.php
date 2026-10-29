<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\LocaleManager;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory;
use Illuminate\Mail\Events\MessageSending;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramon\Chat\Message;
use Ramon\Chat\Notification\ChatMentionBlueprint;
use Symfony\Component\Mime\Email;

/**
 * O corpo renderizado do e-mail de menção.
 *
 * A string de locale não pode passar pelo formatter de posts, que é o pipeline
 * de conteúdo do usuário: o Escaper imprimiria as tags como texto e o e-mail
 * chegaria com `<p>` e `<a href="...">` literais.
 *
 * As asserções fixam a forma da saída, não o texto exato, para uma mudança de
 * redação não quebrá-las mas uma regressão no mecanismo sim.
 */
class MentionEmailTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** BCrypt de "too-obscure", o mesmo hash que o `normalUser()` traz. */
    private const PASSWORD_HASH = '$2y$10$LO59tiT7uggl6Oe23o/O6.utnF6ipngYjvMvaxo1TciKqBttDNKim';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ramon-chat');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'Ramon', 'password' => self::PASSWORD_HASH, 'email' => 'ramon@machine.local', 'is_email_confirmed' => 1],
                // O nome de exibição é o único valor do usuário que cai num contexto HTML,
                // então uma conta carrega um nome hostil.
                ['id' => 4, 'username' => '<script>alert(1)</script>', 'password' => self::PASSWORD_HASH, 'email' => 'xss@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'ramon-chat.use'],
            ],
            'chat_channels' => [
                [
                    'id'         => 1,
                    'type'       => 'category',
                    'name'       => 'Geral',
                    'slug'       => 'geral',
                    'status'     => 'open',
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ],
            ],
            'chat_messages' => [
                $this->message(1, 3),
                $this->message(2, 4),
            ],
        ]);
    }

    private function message(int $id, int $userId): array
    {
        return [
            'id'         => $id,
            'channel_id' => 1,
            'user_id'    => $userId,
            'number'     => $id,
            'type'       => 'text',
            'content'    => '<t>oi</t>',
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /**
     * Renderiza a view HTML como o NotificationMailer faz, com os dados
     * compartilhados de que o layout depende.
     */
    private function renderHtml(int $messageId): string
    {
        return $this->render('html', $messageId);
    }

    private function renderPlain(int $messageId): string
    {
        return $this->render('plain', $messageId);
    }

    private function render(string $variant, int $messageId): string
    {
        $container = $this->app()->getContainer();

        // `Extend\Locales` engancha `$container->resolving(LocaleManager::class, …)`,
        // e o harness resolve o manager antes dos extenders das extensões; o callback
        // nunca dispara e toda chave do ramon-chat sairia como o próprio id. Carregar
        // o catálogo à mão deixa as asserções verem o texto real.
        $locales = $container->make(LocaleManager::class);
        $locales->clearCache();
        $locales->addTranslations('en', dirname(__DIR__, 3).'/locale/en.yml');

        $blueprint = new ChatMentionBlueprint(Message::query()->findOrFail($messageId));
        $user = User::query()->findOrFail(2);
        $url = $container->make(UrlGenerator::class);
        $settings = $container->make(SettingsRepositoryInterface::class);
        $translator = $container->make(TranslatorInterface::class);

        $data = [
            'blueprint'       => $blueprint,
            'user'            => $user,
            'unsubscribeLink' => $url->to('forum')->base().'/unsubscribe',
            'settingsLink'    => $url->to('forum')->base().'/settings',
            'type'            => ChatMentionBlueprint::getType(),
            'forumTitle'      => $settings->get('forum_title'),
            'username'        => $user->display_name,
            'userEmail'       => $user->email,
            'title'           => $translator->trans('core.email.notification.default_title'),
        ];

        /** @var Factory $view */
        $view = $container->make(Factory::class);
        $view->share($data);

        return $view->make("ramon-chat::emails.{$variant}.mentioned", $data)->render();
    }

    public function test_the_body_is_real_html_not_escaped_markup(): void
    {
        $html = $this->renderHtml(1);

        $this->assertStringNotContainsString('&lt;p&gt;', $html, 'paragraph tags reached the inbox as text');
        $this->assertStringNotContainsString('&lt;a href', $html, 'the anchor reached the inbox as text');
        $this->assertStringNotContainsString('**', $html, 'Markdown emphasis was never converted');
    }

    public function test_the_author_and_channel_are_emphasised(): void
    {
        $html = $this->renderHtml(1);

        $this->assertStringContainsString('<strong>Ramon</strong>', $html);
        $this->assertStringContainsString('<strong>Geral</strong>', $html);
    }

    public function test_the_call_to_action_is_a_real_link_to_the_channel(): void
    {
        $html = $this->renderHtml(1);

        $this->assertMatchesRegularExpression(
            '#<a href="[^"]*/chat/c/1">\s*Open the channel\s*</a>#',
            $html,
            'the action should be an anchor whose text is the label, not a bare autolinked URL'
        );
    }

    public function test_a_hostile_display_name_is_escaped(): void
    {
        $html = $this->renderHtml(2);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * O core entrega às views de e-mail um tradutor que troca cada parâmetro por um
     * marcador opaco, para um nome de exibição não ser lido como markup. Desde o
     * core 2.0.0-rc.8 quem devolve os valores é o `MutateEmail`, sobre a mensagem
     * pronta, então a view renderizada ainda tem o marcador. A garantia mora na
     * mensagem de saída: nada com cara de `flarumsafevalue…` pode chegar ao corpo
     * entregue ao Symfony.
     *
     * Despachar `MessageSending` é o que o mailer faz; o core escuta em
     * `MailServiceProvider::boot()`. Conferido nas duas variantes e na conta hostil,
     * porque o vazamento estaria em como os valores chegam ao tradutor, não numa
     * string específica.
     */
    #[DataProvider('renderedBodies')]
    public function test_no_substitution_marker_survives_into_the_sent_message(string $variant, int $messageId): void
    {
        $rendered = $this->render($variant, $messageId);

        // Aqui o marcador é esperado num core que marca: a asserção é sobre o que
        // acontece com ele depois, não sobre ele ter sido criado.
        $email = new Email();

        $variant === 'html'
            ? $email->html($rendered)
            : $email->text($rendered);

        $this->app()->getContainer()->make(Dispatcher::class)->dispatch(
            new MessageSending($email)
        );

        $body = $variant === 'html' ? $email->getHtmlBody() : $email->getTextBody();

        $this->assertStringNotContainsString('flarumsafevalue', (string) $body);
    }

    /**
     * O valor que o marcador representa precisa voltar, não só sumir.
     *
     * Sem isto, um passo de restauração que descartasse os marcadores passaria na
     * asserção acima mandando uma frase com um buraco.
     */
    public function test_the_recipient_name_reaches_the_sent_message(): void
    {
        $email = new Email();
        $email->html($this->render('html', 1));

        $this->app()->getContainer()->make(Dispatcher::class)->dispatch(
            new MessageSending($email)
        );

        $this->assertStringContainsString('normal', (string) $email->getHtmlBody());
        $this->assertStringContainsString('Ramon', (string) $email->getHtmlBody());
    }

    public static function renderedBodies(): array
    {
        return [
            'html'         => ['html', 1],
            'plain'        => ['plain', 1],
            'html hostile' => ['html', 2],
            'plain hostile'=> ['plain', 2],
        ];
    }

    /**
     * A alternativa em texto puro é onde um marcador vazado mais aparece: não há
     * markup para culpar, só a palavra errada na frase.
     */
    public function test_the_plain_body_names_the_author_and_the_channel(): void
    {
        $plain = $this->renderPlain(1);

        $this->assertStringContainsString('Ramon', $plain);
        $this->assertStringContainsString('Geral', $plain);
    }

    /**
     * Um nome de exibição hostil chega inerte ao corpo em texto puro.
     *
     * O layout plain do core passa o slot por `strip_tags()` e depois pelas chaves
     * de escape do Blade, então as tags são removidas e o nome chega só como texto.
     * Isso é do core, não deste template, que entrega o nome cru contando com o
     * layout; uma mudança em qualquer dos lados tem que falhar.
     *
     * O texto interno também é conferido, senão o teste passaria se a substituição
     * parasse e o nome nem aparecesse.
     */
    public function test_a_hostile_display_name_is_inert_in_the_plain_body(): void
    {
        $plain = $this->renderPlain(2);

        $this->assertStringNotContainsString('<script', $plain);
        $this->assertStringContainsString('alert(1)', $plain);
    }
}
