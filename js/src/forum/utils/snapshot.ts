import app from 'flarum/forum/app';
import type Model from 'flarum/common/Model';

/** Documento JSON:API no formato aceito por `Store.pushPayload`. */
export interface Document {
  data: unknown[];
  included?: unknown[];
  meta?: Record<string, unknown>;
}

/**
 * O que a última visita deixou, para a próxima pintar antes de consultar o
 * servidor. Só a sidebar e o fim de alguns canais vistos, no mesmo formato do
 * payload de boot (ver Content\PreloadChat). Tudo lido daqui é marcado como velho e
 * revalidado assim que aparece na tela.
 */
export interface Snapshot {
  version: number;
  savedAt: number;
  channels: Document;
  streams: Record<string, Document>;
  pinned: Record<string, Document>;
}

const VERSION = 1;

const KEY_PREFIX = 'ramon-chat.snapshot.';

/**
 * Acima disso o snapshot é pulado, não cortado. O localStorage é dividido com o
 * fórum e erros de cota são silenciosos; encher a cota levaria junto as
 * preferências do fórum.
 */
const MAX_BYTES = 1_500_000;

/** Um histórico de uma semana engana mais do que ajuda. */
const MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;

/**
 * Reconstrói um documento JSON:API a partir dos models no store. Cada model guarda
 * em `data` o recurso de origem; seguir as relações e pegar o que o store ainda
 * tem gera o documento que o servidor mandaria, inclusive linhas que chegaram pelo
 * websocket.
 *
 * Limitado por profundidade, não por tipo, para pegar relações novas sozinho. Três
 * níveis alcançam `replyTo.user.groups`, o mais fundo que a lista de mensagens inclui.
 */
export function serialize(models: Model[], depth = 3): Document {
  const included: unknown[] = [];
  const seen = new Set<string>();

  for (const model of models) {
    seen.add(`${model.data.type}:${model.id()}`);
  }

  const visit = (resource: Model['data'], level: number): void => {
    const relationships = resource?.relationships;

    if (!relationships || level <= 0) return;

    for (const relationship of Object.values(relationships)) {
      const linkage = (relationship as { data?: unknown } | undefined)?.data;

      if (!linkage) continue;

      for (const ref of Array.isArray(linkage) ? linkage : [linkage]) {
        const type = (ref as { type?: unknown })?.type;
        const id = (ref as { id?: unknown })?.id;

        if (typeof type !== 'string' || id === undefined || id === null) continue;

        const key = `${type}:${id}`;

        if (seen.has(key)) continue;

        const related = app.store.getById<Model>(type, String(id));

        if (!related) continue;

        seen.add(key);
        included.push(related.data);
        visit(related.data, level - 1);
      }
    }
  };

  for (const model of models) {
    visit(model.data, depth);
  }

  return { data: models.map((model) => model.data), included };
}

export function readSnapshot(userId: string): Snapshot | null {
  try {
    const raw = localStorage.getItem(KEY_PREFIX + userId);

    if (!raw) return null;

    const snapshot = JSON.parse(raw) as Partial<Snapshot> | null;

    if (!snapshot || snapshot.version !== VERSION) return null;
    if (typeof snapshot.savedAt !== 'number') return null;
    if (Date.now() - snapshot.savedAt > MAX_AGE_MS) return null;
    if (!snapshot.channels || typeof snapshot.channels !== 'object') return null;

    return {
      version: VERSION,
      savedAt: snapshot.savedAt,
      channels: snapshot.channels,
      streams: snapshot.streams ?? {},
      pinned: snapshot.pinned ?? {},
    };
  } catch {
    // Navegação privada, erro de cota ou lixo editado à mão: o chat carrega normalmente.
    return null;
  }
}

export function writeSnapshot(userId: string, snapshot: Snapshot): void {
  try {
    const raw = JSON.stringify(snapshot);

    if (raw.length > MAX_BYTES) return;

    localStorage.setItem(KEY_PREFIX + userId, raw);
  } catch {
    // Não fatal, como acima.
  }
}

/**
 * Apaga o snapshot de todas as contas. Chamado quando a página sobe sem sessão: o
 * histórico de um canal privado não pode sobreviver à sessão num navegador
 * compartilhado.
 */
export function purgeSnapshots(): void {
  try {
    for (let i = localStorage.length - 1; i >= 0; i--) {
      const key = localStorage.key(i);

      if (key?.startsWith(KEY_PREFIX)) localStorage.removeItem(key);
    }

    localStorage.setItem(SIGNED_OUT_KEY, String(Date.now()));
  } catch {
    // Não fatal.
  }
}

/** Quando uma aba sem sessão apagou os snapshots pela última vez. */
const SIGNED_OUT_KEY = 'ramon-chat.signedOutAt';

/**
 * Se alguma aba saiu da conta depois de `since` (ms). Uma aba aberta antes da
 * saída não sabe que a sessão acabou e não deve gravar o snapshot de volta.
 */
export function signedOutSince(since: number): boolean {
  try {
    return Number(localStorage.getItem(SIGNED_OUT_KEY) ?? 0) > since;
  } catch {
    return false;
  }
}
