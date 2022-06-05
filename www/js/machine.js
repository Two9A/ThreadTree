TreeMachine = (cb) => {
    const machine = {
        callback: (action, store) => {},
        transitions: {START: 'start'},
        store: {},
        running: false,

        handlers: {
            start: () => {
                machine.log('Started');
                return {
                    SUBMIT: 'register',
                };
            },
            register: (payload) => {
                if (!payload.id) {
                    throw new Error('Register expects an id');
                }
                machine.log('Registering: ' + payload.id);
                machine.store['id'] = payload.id;
                return {
                    FETCH_INIT: 'fetch_started',
                };
            },
            fetch_started: (payload) => {
                return {
                    FETCH_LIST: 'list_fetched',
                };
            },
            list_fetched: (payload) => {
                machine.store['total'] = payload.ids.length;
                machine.store['fetched'] = 0;
                return {
                    FETCH_EXTANT: 'extant_tweets',
                    FETCH_MISSING: 'new_tweet',
                };
            },
            extant_tweets: (payload) => {
                machine.store['fetched'] += payload.ids.length;
                return {
                    FETCH_EXTANT: 'extant_tweets',
                    FETCH_MISSING: 'new_tweet',
                    FETCH_DONE: 'complete',
                };
            },
            new_tweet: (payload) => {
                machine.store['fetched']++;
                return {
                    FETCH_EXTANT: 'extant_tweets',
                    FETCH_MISSING: 'new_tweet',
                    FETCH_DONE: 'complete',
                };
            },
            complete: (payload) => {
                return {};
            },
        },
        trigger: (action, payload = {}) => {
            if (!machine.running) {
                throw new Error('Machine is not running');
            }
            if (!machine.transitions.hasOwnProperty(action)) {
                throw new Error('Invalid state transition: ' + action + '; ' + JSON.stringify(payload));
            }
            const handler = machine.transitions[action];
            machine.transitions = machine.handlers[handler](payload);
            machine.callback(handler, machine.store);
            if (!Object.keys(machine.transitions).length) {
                machine.running = false;
            }
        },
        log: (str) => {
            const fn = (new Error()).stack.match(/at (\S+)/g)[1].slice(3);
            console.log('[Machine] ' + fn + ': ' + str);
        },
    };

    machine.callback = cb;
    machine.running = true;
    machine.trigger('START');
    return machine;
};
