<?php

class TootModel {
    const MENTIONS_PER_RUN = 5;
    const ENDPOINTS = [
        '__API' => '/api/v1/',
        '__AUTH' => '/oauth/token',
        '__SEARCH' => '/api/v2/search',
    ];

    protected $config;
    protected $dbc;
    protected static $auth_token = null;
    protected static $websocket = null;

    public function __construct() {
        $this->config = bsFactory::get('config');
        $this->dbc = bsFactory::get('pdo')->get();
        $this->ensure_connection();
    }

    //--- Tweets ----------------------------------------------------------

    public function get_tweet($id, $fetch_from_source = true) { return $this->get_toot($id, $fetch_from_source); }
    public function get_toot($id, $fetch_from_source = true) {
        $st = $this->dbc->prepare('SELECT * FROM toots WHERE id=:id');
        $st->bindValue(':id', (int)$id);
        $st->execute();

        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows)) {
            return $rows[0];
        }

        // Tweet is not held locally, fetch and save
        if ($fetch_from_source) {
            return $this->fetch_toot($id);
        }

        return false;
    }

    public function get_full_toot($id) {
        $st = $this->dbc->prepare('
            SELECT tn.mptt_left, tn.mptt_right, COUNT(tp.tweet_id)-1 AS mptt_depth,
                t.id AS tweet_id, t.author_id, t.text, t.source, t.created_at,
                a.username, a.name, a.description, a.avatar, a.created_at AS user_created_at
            FROM tree_nodes tn
                LEFT JOIN tree_nodes tp ON (tn.mptt_left BETWEEN tp.mptt_left AND tp.mptt_right)
                LEFT JOIN toots t ON tn.tweet_id=t.id
                LEFT JOIN toot_authors a ON t.author_id=a.id
            WHERE tn.tree_id=tp.tree_id AND tn.tweet_id=:toot
            GROUP BY tn.tweet_id ORDER BY tn.mptt_left
        ');
        $st->bindValue(':toot', (int)$id);
        $st->execute();
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    protected function save_toot($obj, $root_id = null) {
        $data = $this->toot_from_object($obj, $root_id);
        if (!$this->get_toot($obj['id'], false)) {
            $st = $this->dbc->prepare('INSERT INTO toots(id, author_id, root_id, text, source, created_at, raw_object) VALUES(:id, :author_id, :root_id, :text, :source, STR_TO_DATE(:created_at, "%Y-%m-%dT%H:%i:%s.%fZ"), :raw_object)');
            foreach ($data as $k => $v) {
                $st->bindValue(':'.$k, $v);
            }
            $st->execute();
        }
    }

    public function fetch_toot_by_url($url, $fetch_from_source = true) {
        $sr = self::send($this->config, '__SEARCH', 'GET', [
            'q' => $url,
            'type' => 'statuses',
            'resolve' => 'true',
        ]);
        if (!$sr) {
            throw new bsException('Toot fetch failed, source may not be federated', 404);
        }
        if (!isset(
            $sr['body'],
            $sr['body']['statuses'],
            $sr['body']['statuses'][0],
            $sr['body']['statuses'][0]['id']
        )) {
            throw new bsException('Toot not found, source may not be federated', 404);
        }
        return $this->toot_from_object($sr['body']['statuses'][0]);
    }

    protected function fetch_toot($id) {
        $tr = self::send($this->config, 'statuses/'.$id, 'GET');
        if (!$tr) {
            throw new bsException('Toot fetch failed, source may not be federated', 404);
        }
        $toot = $tr['body'];

        $cr = self::send($this->config, 'statuses/'.$id.'/context', 'GET');
        if (!$cr) {
            throw new bsException('Toot context fetch failed', 500);
        }
        $ctx = $cr['body'];

        $root_id = $toot['id'];
        if ($toot['in_reply_to_id']) {
            foreach ($ctx['ancestors'] as $ancestor) {
                if (!$ancestor['in_reply_to_id']) {
                    $root_id = $ancestor['id'];
                    break;
                }
            }
        }

        $this->save_toot($toot, $root_id);
        $this->save_author($toot['account']);

        foreach (['ancestors', 'descendants'] as $direction) {
            foreach ($ctx[$direction] as $other_toot) {
                $parent_id = $other_toot['in_reply_to_id'] ?? $root_id;
                if ($parent_id != $other_toot['id']) {
                    if (!$this->get_link($parent_id, $other_toot['id'])) {
                        // TODO: When Mastodon supports quotes
                        $this->save_link($parent_id, $other_toot['id'], 'reply');
                    }
                }
                $this->save_toot($other_toot, $root_id);
                $this->save_author($other_toot['account']);
            }
        }

        // If we're down the tree somewhere, the context is incomplete
        if ($root_id != $toot['id']) {
            $this->fetch_toot($root_id);
        }

        return $this->toot_from_object($toot, $root_id);
    }

    protected function toot_from_object($obj, $root_id = null) {
        return [
            'id' => $obj['id'],
            'author_id' => $obj['account']['id'],
            'root_id' => $root_id ?? $obj['id'],
            'text' => $obj['content'],
            'source' => parse_url($obj['uri'])['host'],
            'created_at' => $obj['created_at'],
            'raw_object' => json_encode($obj),
        ];
    }

    //--- Authors ---------------------------------------------------------

    public function get_author($id) {
        $st = $this->dbc->prepare('SELECT * FROM toot_authors WHERE id=:id');
        $st->bindValue(':id', (int)$id);
        $st->execute();

        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows)) {
            return $rows[0];
        }

        return false;
    }

    public function save_author($obj) {
        $data = $this->author_from_object($obj);
        $existing_author = $this->get_author($obj['id']);
        if ($existing_author) {
            if (hash('sha256', $existing_author['raw_object']) === hash('sha256', json_encode($obj))) {
                // No change to the existing record, drop out
                return;
            }
            $st = $this->dbc->prepare('DELETE from toot_authors WHERE id=:id');
            $st->bindValue(':id', $obj['id']);
            $st->execute();
        }

        $st = $this->dbc->prepare('INSERT INTO toot_authors(id, username, name, description, avatar, created_at, raw_object) VALUES(:id, :username, :name, :description, :avatar, STR_TO_DATE(:created_at, "%Y-%m-%dT%H:%i:%s.%fZ"), :raw_object)');
        foreach ($data as $k => $v) {
            $st->bindValue(':'.$k, $v);
        }
        $st->execute();
    }

    protected function author_from_object($obj) {
        return [
            'id' => $obj['id'],
            'name' => $obj['display_name'],
            'created_at' => $obj['created_at'],
            'avatar' => $obj['avatar'],
            'description' => $obj['note'],
            'username' => $obj['acct'],
            'raw_object' => json_encode($obj),
        ];
    }

    //--- Tweet links -----------------------------------------------------

    public function get_link($parent, $child) {
        $st = $this->dbc->prepare('SELECT link_type FROM toot_links WHERE parent_id=:parent AND child_id=:child');
        $st->bindValue(':parent', $parent);
        $st->bindValue(':child', $child);
        $st->execute();

        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows)) {
            return $rows[0]['link_type'];
        }

        return false;
    }

    public function get_reply_links($parent) {
        $links = [];
        $st = $this->dbc->prepare('SELECT child_id FROM toot_links WHERE link_type="reply" AND parent_id=:parent ORDER BY child_id');
        $st->bindValue(':parent', $parent);
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $links[$row['child_id']] = $this->get_reply_links($row['child_id']);
        }
        return $links;
    }

    public function save_link($parent, $child, $link_type) {
        $st = $this->dbc->prepare('INSERT INTO toot_links(parent_id, child_id, link_type) VALUES(:parent, :child, :type)');
        $st->bindValue(':parent', $parent);
        $st->bindValue(':child', $child);
        $st->bindValue(':type', $link_type);
        $st->execute();
    }

    //--- Conversations ---------------------------------------------------

    public function fetch_conversation($root_id) {
        $ids = [];
        $next_token = null;
        do {
            $params = [
                'query' => 'conversation_id:' . (int)$root_id,
                'max_results' => 100,
            ];
            if ($next_token) {
                $params['next_token'] = $next_token;
            }
            $search = self::$connection->get('tweets/search/recent', $params);
            if (self::$connection->getLastHttpCode() != 200) {
                throw new bsException('Twitter connection failed', 500);
            }
            if (!isset($search->data)) {
                throw new bsException('Conversation search failed. This can happen if the root of the conversation is more than seven days old', 500);
            }
            foreach ($search->data as $tweet) {
                $ids[] = $tweet->id;
            }
            if (isset($search->meta, $search->meta->next_token) && $search->meta->next_token) {
                $next_token = $search->meta->next_token;
            }
        } while (count($ids) < self::MAX_TWEETS && $next_token != null);

        return $ids;
    }

    public function get_tweets_for_conversation($id) {
        return;

        $tweet = $this->get_tweet($id);
        if (!$tweet) {
            throw new bsException('Tweet fetch failed', 500);
        }
        $ids = $this->fetch_conversation($tweet['root_id']);
        if (!$ids) {
            throw new bsException('Conversation fetch failed', 500);
        }

        $this->send_to_websocket('FETCH_LIST', [
            'client' => $id,
            'root' => $tweet['root_id'],
            'ids' => $ids,
        ]);

        $st = $this->dbc->prepare('SELECT id FROM tweets WHERE id IN ('.join(',', array_map(function() { return '?'; }, $ids)).')');
        foreach ($ids as $k => $v) {
            $st->bindValue($k + 1, $v);
        }
        $st->execute();

        $extant_ids = array_map(function($row) { return $row['id']; }, $st->fetchAll(PDO::FETCH_ASSOC));
        $this->send_to_websocket('FETCH_EXTANT', [
            'client' => $id,
            'root' => $tweet['root_id'],
            'ids' => $extant_ids,
        ]);

        foreach(array_diff($ids, $extant_ids) as $missing_id) {
            usleep(500);
            if ($this->get_tweet($missing_id)) {
                $this->send_to_websocket('FETCH_MISSING', [
                    'client' => $id,
                    'root' => $tweet['root_id'],
                    'id' => $missing_id,
                ]);
            }
        }
    }

    //--- Trees -----------------------------------------------------------

    public function get_tree($id) {
        $st = $this->dbc->prepare('
            SELECT tn.mptt_left, tn.mptt_right, COUNT(tp.tweet_id)-1 AS mptt_depth,
                t.id AS tweet_id, t.author_id, t.text, t.source, t.created_at,
                a.username, a.name, a.description, a.avatar, a.created_at AS user_created_at
            FROM tree_nodes tn
                LEFT JOIN tree_nodes tp ON (tn.mptt_left BETWEEN tp.mptt_left AND tp.mptt_right)
                LEFT JOIN toots t ON tn.tweet_id=t.id
                LEFT JOIN toot_authors a ON t.author_id=a.id
            WHERE tn.tree_id=:tree AND tp.tree_id=:tree
            GROUP BY tn.tweet_id ORDER BY tn.mptt_left
        ');
        $st->bindValue(':tree', (int)$id);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get_tree_by_tweet($id) {
        $tweet = $this->get_tweet($id, false);
        if (!$tweet) {
            return false;
        }

        $st = $this->dbc->prepare('SELECT * FROM trees WHERE (deleted_at="0000-00-00" OR deleted_at IS NULL) AND root_id=:tweet');
        $st->bindValue(':tweet', $tweet['root_id']);
        $st->execute();
        $tree_info = $st->fetch(PDO::FETCH_ASSOC);
        if (!$tree_info) {
            return false;
        }

        $st = $this->dbc->prepare('UPDATE trees SET views=views+1 WHERE id=:id');
        $st->bindValue(':id', $tree_info['id']);
        $st->execute();

        $tree = $this->get_tree($tree_info['id']);
        $data_by_tweetid = [];
        foreach ($tree as $node) {
            $data_by_tweetid[$node['tweet_id']] = $node;
        }

        return [
            'info' => $tree_info,
            'data' => $data_by_tweetid,
            'tree' => json_decode($this->mptt_to_json($tree)),
        ];
    }

    public function save_tree($tweet_id, $requester_id) {
        $this->send_to_websocket('REGISTER', ['id' => 0]);

        try {
            $tweet = $this->get_tweet($tweet_id);
            if (!$tweet) {
                $this->send_to_websocket('FETCH_ERROR', [
                    'client' => $tweet_id,
                    'msg' => 'Tweet not found',
                ]);
                return false;
            }
            $root = $tweet['root_id'];

            $this->send_to_websocket('FETCH_INIT', [
                'client' => $tweet['id'],
                'root' => $tweet['root_id'],
            ]);

            $this->get_tweets_for_conversation($tweet_id);
            $links = $this->get_reply_links($root);
            $mptt = $this->build_mptt($root, $links);
            
            if (count($mptt) < 10) {
                throw new bsException('Thread is less than 10 toots, viewing through Thread Tree is not advised', 400);
            }

            $st = $this->dbc->prepare('SELECT * FROM trees WHERE (deleted_at="0000-00-00" OR deleted_at IS NULL) AND root_id=:root');
            $st->bindValue(':root', (int)$root);
            $st->execute();
            $trees = $st->fetchAll(PDO::FETCH_ASSOC);

            if (count($trees)) {
                $tree_id = $trees[0]['id'];
            } else {
                $st = $this->dbc->prepare('INSERT INTO trees(root_id, requester_id, created_at, updated_at) VALUES(:root, :requester, NOW(), NOW())');
                $st->bindValue(':root', (int)$root);
                $st->bindValue(':requester', (int)$requester_id);
                $st->execute();

                $st = $this->dbc->prepare('SELECT LAST_INSERT_ID()');
                $st->execute();
                $tree_id = $st->fetchColumn();
            }

            $st = $this->dbc->prepare('DELETE FROM tree_nodes WHERE tree_id=:tree');
            $st->bindValue(':tree', $tree_id);
            $st->execute();

            $st = $this->dbc->prepare('INSERT INTO tree_nodes(tree_id, tweet_id, mptt_left, mptt_right) VALUES(:tree, :tweet, :lft, :rgt)');
            foreach ($mptt as $twid => $vals) {
                $st->bindValue(':tree', $tree_id);
                $st->bindValue(':tweet', $twid);
                $st->bindValue(':lft', $vals['left']);
                $st->bindValue(':rgt', $vals['right']);
                $st->execute();
            }

            $this->send_to_websocket('FETCH_DONE', [
                'client' => $tweet['id'],
                'root' => $tweet['root_id'],
                'tree' => $tree_id,
            ]);

            $st = $this->dbc->prepare('UPDATE trees SET updated_at=NOW() WHERE id=:tree');
            $st->bindValue(':tree', $tree_id);
            $st->execute();

            return $tree_id;
        } catch (Exception $e) {
            $this->send_to_websocket('FETCH_ERROR', [
                'client' => $tweet_id,
                'msg' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function delete_tree($tweet_id, $requester_id) {
        $tweet = $this->get_tweet($tweet_id, false);
        if (!$tweet) {
            return false;
        }

        $st = $this->dbc->prepare('SELECT id FROM trees WHERE (deleted_at="0000-00-00" OR deleted_at IS NULL) AND requester_id=:requester AND root_id=:tweet');
        $st->bindValue(':tweet', $tweet['root_id']);
        $st->bindValue(':requester', $requester_id);
        $st->execute();
        $tree_id = $st->fetchColumn();
        if (!$tree_id) {
            return false;
        }

        $st = $this->dbc->prepare('UPDATE trees SET deleted_at=NOW() WHERE id=:id');
        $st->bindValue(':id', $tree_id);
        $st->execute();
        return true;
    }

    public function mptt_to_json($tree) {
        $json = '[';
        $depth = 0;
        foreach ($tree as $node) {
            if ($node['mptt_depth'] > $depth) {
                $json .= '[';
            }
            if ($node['mptt_depth'] < $depth) {
                $json .= str_repeat(']', $depth - $node['mptt_depth']);
                $json .= ',';
            }
            $json .= $node['tweet_id'];
            $json .= ',';
            $depth = $node['mptt_depth'];
        }
        $json .= str_repeat(']', $depth + 1);
        $json = str_replace(',]', ']', $json);
        return $json;
    }

    protected function build_mptt($node, $links, $mptt = null) {
        if ($mptt === null) {
            $mptt = [$node => ['left' => 1, 'right' => 2]];
        }
        foreach ($links as $child => $content) {
            if (!isset($mptt[$child])) {
                $rgt = $mptt[$node]['right'];
                foreach ($mptt as &$m) {
                    if ($m['left'] >= $rgt) $m['left'] += 2;
                    if ($m['right'] >= $rgt) $m['right'] += 2;
                }
                $mptt[$child] = ['left' => $rgt, 'right' => $rgt + 1];
                if (count($content)) {
                    $mptt = $this->build_mptt($child, $content, $mptt);
                }
            }
        }
        return $mptt;
    }

    //--- Other public methods --------------------------------------------

    public function get_roots_by_requester($requester_id) {
        $st = $this->dbc->prepare('SELECT * FROM trees WHERE (deleted_at="0000-00-00" OR deleted_at IS NULL) AND requester_id=:requester ORDER BY root_id DESC');
        $st->bindValue(':requester', (int)$requester_id);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $tweets = [];
        foreach ($rows as $row) {
            $tweets[$row['root_id']] = $row + ['tweet_data' => $this->get_full_toot($row['root_id'])];
        }
        return $tweets;
    }

    public function get_recent_roots() {
        $st = $this->dbc->prepare('SELECT * FROM trees WHERE (deleted_at="0000-00-00" OR deleted_at IS NULL) ORDER BY id DESC LIMIT 4');
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $tweets = [];
        foreach ($rows as $row) {
            $tweets[$row['root_id']] = $row + ['tweet_data' => $this->get_full_toot($row['root_id'])];
            $tweets[$row['root_id']]['count'] = $tweets[$row['root_id']]['tweet_data']['mptt_right'] / 2;
        }

        return $tweets;
    }

    public function handle_recent_mentions() {
        $st = $this->dbc->prepare('SELECT setting_value FROM settings WHERE setting_key="toot_last_mention"');
        $st->execute();
        $last_mention = $st->fetch(PDO::FETCH_ASSOC)['setting_value'];

        $mentions = $this->send($this->config, 'notifications', 'GET', [
            'since_id' => (int)$last_mention,
            'types[]' => 'mention',
            'limit' => self::MENTIONS_PER_RUN,
        ]);
        $trees = [];

        if (count($mentions['body'])) {
            foreach ($mentions['body'] as $mention) {
                $last_mention = $mention['id'];
                $toot = $mention['status'];
                $content = json_decode('["'.$toot['content'].'"]');
                if (preg_match("#@{$this->config->mastodon_user_name}\b.*\bunroll\b#i", strip_tags($toot['content']))) {
                    $trees[$toot['id']] = $this->save_tree($toot['id'], $toot['account']['id']);

                    $this->send($this->config, 'statuses', 'POST', [
                        'in_reply_to_id' => $toot['id'],
                        'status' => sprintf(
                            "@%s Your thread has been unrolled! You can view the full conversation at: %s/%s",
                            $toot['account']['acct'],
                            $this->config->root_url,
                            $toot['id']
                        ),
                    ]);
                }
            }

            $st = $this->dbc->prepare('UPDATE settings SET setting_value=:v WHERE setting_key="toot_last_mention"');
            $st->bindValue(':v', $last_mention);
            $st->execute();
        }

        return $trees;
    }

    //--- Support ---------------------------------------------------------

    protected function ensure_connection() {
        if (!self::$auth_token) {
            $r = self::send($this->config, '__AUTH', 'POST', [
                'client_id' => $this->config->mastodon_api_key,
                'client_secret' => $this->config->mastodon_api_secret,
                'redirect_uri' => 'urn:ietf:wg:oauth:2.0:oob',
                'grant_type' => 'client_credentials',
            ]);
            if (isset($r, $r['body'], $r['body']['access_token'])) {
                self::$auth_token = $r['body']['access_token'];
            } else {
                throw new bsException('Login failed', 500);
            }
        }
    }

    protected function ensure_websocket() {
        if (!self::$websocket) {
            self::$websocket = new \Paragi\PhpWebsocket\Client('localhost', $this->config->ws_port);
        }
    }

    protected function send_to_websocket($type, $payload) {
        $this->ensure_websocket();
        self::$websocket->write(json_encode([
            'type' => $type,
            'payload' => $payload,
        ]));
    }

    protected static function send($config, $endpoint, $method, $params = array()) {
        switch ($endpoint) {
            case '__AUTH':
            case '__SEARCH':
                $url = self::ENDPOINTS[$endpoint];
                break;
            default:
                $url = self::ENDPOINTS['__API'].$endpoint;
                break;
        }
        $curl_options = [
            CURLOPT_URL => $config->mastodon_instance_url.$url,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_SSL_VERIFYHOST  => false,
            CURLOPT_HEADER          => true,
            CURLOPT_HTTPHEADER      => array(
                'Content-Type: application/json',
                'Accept: application/json',
            )
        ];
        if ($endpoint !== '__AUTH') {
            $curl_options[CURLOPT_HTTPHEADER][] = 'Authorization: Bearer '.$config->mastodon_api_token;
        }
        switch ($method) {
            case 'PUT':
            case 'POST':
                $curl_options[CURLOPT_CUSTOMREQUEST] = $method;
                $curl_options[CURLOPT_POSTFIELDS] = json_encode($params);
                break;

            case 'DELETE':
            case 'PATCH':
                $curl_options[CURLOPT_CUSTOMREQUEST] = $method;
                // Fallthrough

            case 'GET':
                if (count($params)) {
                    $curl_options[CURLOPT_URL] .= ('?' . http_build_query($params));
                }
                break;
        }

        self::_log('Request: '.$url.'; '.json_encode($params));

        $c = curl_init();
        curl_setopt_array($c, $curl_options);
        $r = curl_exec($c);
        $err = curl_error($c);
        $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
        $header_size = curl_getinfo($c, CURLINFO_HEADER_SIZE);
        curl_close($c);

        if (!$r) {
            self::_log('Request failed with code '.$code.': '.$err);
            return false;
        }
        if ($code != 200) {
            self::_log('Request failed with code '.$code.': '.$r);
            return false;
        }
        self::_log('Response: '.$r);
        $headers = [];
        foreach (array_map('trim', explode("\n", trim(substr($r, 0, $header_size)))) as $header) {
            if (strpos($header, ':')) {
                list($k, $v) = explode(':', $header);
                $headers[trim($k)] = trim($v);
            }
        }
        $body = substr($r, $header_size);
        $rj = json_decode($body, true);
        if (!$rj) {
            self::_log('Response failed to parse');
        }
        return ['headers' => $headers, 'body' => $rj];
    }

    protected static function _log($str) {
        $f = fopen('/tmp/threadtree.log', 'a');
        fprintf($f, "[%s] %s\n", date('YmdHis'), $str);
        fclose($f);
    }
}
