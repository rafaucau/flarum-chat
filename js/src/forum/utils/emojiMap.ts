import * as source from 'simple-emoji-map';

/**
 * Mapa de emojis num módulo local para o `import()` lazy de emoji.ts apontar para
 * um arquivo real. Importando o pacote pelo nome, o loader do flarum-webpack-config
 * deriva um caminho que não existe, o chunk não é registrado em `flarum.reg` e o
 * pedido em runtime dá 404.
 */
const map = ((source as any).default ?? source) as Record<string, string[]>;

export default map;
