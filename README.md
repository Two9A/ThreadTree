# Thread Tree

A visualizer for threaded conversations on Twitter, built after I became
frustrated with the official apps and their handling of deeply threaded
conversations. Also acts as a test-bed for learning about state machines and
their transitions using WebSocket messages.

![](https://user-images.githubusercontent.com/235537/172041486-bcbbd983-da46-4298-94b8-aba641def82d.gif)

Utilizes the [BirSaat](https://github.com/Two9A/BirSaat) PHP MVC microframework
by yours truly, and:

* Twitter API: Abraham Williams' [TwitterOauth](https://twitteroauth.com/)
* WebSocket server: ReactPHP's [Ratchet](https://github.com/ratchetphp/Ratchet)
* WebSocket client: Simon Riget's [PHP-websocket-client](https://github.com/paragi/PHP-websocket-client)
