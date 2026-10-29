import app from 'flarum/forum/app';

/**
 * Som de notificação. Não toca se o canal da mensagem está na tela e em foco, e é
 * limitado por tempo para uma rajada de mensagens soar uma vez só. O admin escolhe
 * o arquivo; a preferência do usuário de silenciar vence.
 */

/** Intervalo mínimo entre dois toques. */
const THROTTLE_MS = 2000;

let lastPlayed = 0;
let element: HTMLAudioElement | null = null;
let loadedFor: string | null = null;

/** Arquivo escolhido pelo admin, ou null com som desligado no fórum ou pelo usuário. */
function chosen(): string | null {
  // Ausente significa "seguir o fórum"; só um `false` explícito silencia.
  if (app.session.user?.preferences()?.['ramon-chat.sound'] === false) return null;

  const setting = app.forum.attribute<string>('ramon-chat.notificationSound');

  if (!setting || setting === 'none') return null;

  return setting;
}

/**
 * URL publicada de um som. Os arquivos ficam em `assets/` da extensão e o
 * `flarum assets:publish` os copia para `assets/extensions/ramon-chat/`. Usa
 * `assetsBaseUrl` para funcionar com CDN ou subdiretório.
 */
function url(name: string): string {
  const base = app.forum.attribute<string>('assetsBaseUrl') ?? '/assets';

  return `${base}/extensions/ramon-chat/sounds/${name}.mp3`;
}

export function playNotificationSound(): void {
  const name = chosen();

  if (!name) return;

  const now = Date.now();

  if (now - lastPlayed < THROTTLE_MS) return;

  lastPlayed = now;

  try {
    // Um elemento reutilizado: um Audio por mensagem vaza, e o navegador não coleta
    // um que está tocando.
    if (!element || loadedFor !== name) {
      element = new Audio(url(name));
      element.preload = 'auto';
      loadedFor = name;
    }

    element.currentTime = 0;

    // Autoplay é recusado até o usuário interagir com a página; é política do
    // navegador, não erro.
    void element.play().catch(() => {});
  } catch {
    // URL malformada ou arquivo ausente nunca pode quebrar a entrega da mensagem.
  }
}

/** Toca uma vez ignorando o limite e a preferência; para a prévia do admin. */
export function previewSound(name: string): void {
  if (!name || name === 'none') return;

  try {
    const audio = new Audio(url(name));

    void audio.play().catch(() => {});
  } catch {
    // Idem.
  }
}
