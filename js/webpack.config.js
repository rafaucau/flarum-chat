const config = require('flarum-webpack-config');

module.exports = config({
  useExtensions: [
    // Opcionais: o chat funciona sem elas, mas o bundle precisa dos namespaces
    // para importar delas.
    'flarum/tags',
    'flarum/mentions',
    'flarum/emoji',
    'flarum-realtime',
  ],
});
