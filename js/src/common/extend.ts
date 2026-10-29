import Extend from 'flarum/common/extenders';

import Channel from './models/Channel';
import Message from './models/Message';
import MessageFlag from './models/MessageFlag';
import Thread from './models/Thread';
import Upload from './models/Upload';

// Registrados antes dos initializers: a store descarta payloads de tipos sem
// model, e o fórum já lê os canais pré-carregados no initializer.
export default [
  new Extend.Store()
    .add('chat-channels', Channel)
    .add('chat-messages', Message)
    .add('chat-threads', Thread)
    .add('chat-uploads', Upload)
    .add('chat-message-flags', MessageFlag),
];
