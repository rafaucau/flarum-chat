import app from 'flarum/forum/app';

/**
 * Duração em unidades inteiras ("30s", "5m", "1h"), para caber numa frase e ao
 * lado do nome do canal. O slow mode só usa valores redondos, então não existe
 * caso "1m 30s".
 */
export function humanDuration(seconds: number): string {
  const total = Math.max(0, Math.round(seconds));

  if (total >= 3600 && total % 3600 === 0) {
    return app.translator.trans('ramon-chat.forum.duration.hours', { count: total / 3600 }, true);
  }

  if (total >= 60 && total % 60 === 0) {
    return app.translator.trans('ramon-chat.forum.duration.minutes', { count: total / 60 }, true);
  }

  return app.translator.trans('ramon-chat.forum.duration.seconds', { count: total }, true);
}
