/**
 * Mapa de emojis num módulo local para o `import()` lazy de emoji.ts apontar para
 * um arquivo real. Importando o pacote pelo nome, o loader do flarum-webpack-config
 * deriva um caminho que não existe, o chunk não é registrado em `flarum.reg` e o
 * pedido em runtime dá 404.
 */
declare const map: Record<string, string[]>;
export default map;
