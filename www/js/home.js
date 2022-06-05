(() => {
    window.addEventListener('load', () => {
        const ws = new WebSocket(window.__CONFIG.ws_uri);
        const ping = () => ws.send(JSON.stringify({type: 'PING'}));
        ws.onopen = () => {
            ping();
            pingHandle = setInterval(ping, 60000);
        };
        ws.onclose = () => {
            clearInterval(pingHandle);
        };
        ws.onmessage = (e) => {
            const msg = JSON.parse(e.data);
            if (!msg.hasOwnProperty('type')) {
                throw new Error('Malformed message received');
            }
            if (msg.type !== 'PONG') {
                machine.trigger(msg.type, msg.payload);
            }
        }

        const machine = TreeMachine((action, store) => {
            const setOverlay = (str) => {
                const el = document.querySelector('#new-tree-overlay p');
                el.innerText = str;
            };
            switch (action) {
                case 'register':
                    ws.send(JSON.stringify({
                        type: 'REGISTER',
                        payload: {
                            id: store.id,
                        },
                    }));

                    // Kick off a fetch, we don't actually care about the page returned
                    fetch('/index/save/tweet/' + store.id);
                    break;

                case 'fetch_started':
                    setOverlay('Found conversation');
                    break;

                case 'list_fetched':
                    setOverlay('Conversation has ' + store.total + ' tweets');
                    break;

                case 'extant_tweets':
                case 'new_tweet':
                    setOverlay('Fetched ' + store.fetched + '/' + store.total);
                    break;

                case 'complete':
                    setOverlay('Tree is ready');
                    window.location.href = '/' + store.id;
                    break;
            }
        });

        window.onerror = (msg) => {
            document.getElementById('new-tree-overlay').classList.add('hidden');

            const errEl = document.getElementById('new-tree-error');
            errEl.innerHTML = msg;
            errEl.classList.add('shown');
        }

        document.getElementById('new-tree').addEventListener('submit', (e) => {
            e.preventDefault();

            let valid = false;
            const url = document.getElementById('new-tree-url').value;
            if (!url) {
                throw new Error('Please provide a tweet URL');
            }

            const urlObj = new URL(document.getElementById('new-tree-url').value);
            let id;
            if (urlObj.hostname == 'twitter.com') {
                if (urlObj.pathname.split('/').length == 4) {
                    id = urlObj.pathname.split('/')[3];
                    if (+id == id) {
                        valid = true;
                    }
                }
            }
            if (!valid) {
                throw new Error("That isn't a valid tweet URL, sorry");
                return false;
            }

            document.getElementById('new-tree-overlay').classList.remove('hidden');
            machine.trigger('SUBMIT', { id });
            return false;
        });
    });
})();
