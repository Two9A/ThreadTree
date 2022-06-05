<?php
use Abraham\TwitterOAuth\TwitterOAuth;

class TweetModel {
    const MENTIONS_PER_RUN = 5;
    const MAX_TWEETS = 500;

    protected $config;
    protected $dbc;
    protected static $connection = null;
    protected static $websocket = null;

    public function __construct() {
        $this->config = bsFactory::get('config');
        $this->dbc = bsFactory::get('pdo')->get();
        $this->ensure_connection();
    }

    protected function ensure_connection() {
        if (!self::$connection) {
            self::$connection = new TwitterOAuth(
                $this->config->api_key,
                $this->config->api_secret,
                $this->config->user_key,
                $this->config->user_secret
            );
        }
        self::$connection->setApiVersion('2');
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

    //--- Tweets ----------------------------------------------------------

    public function get_tweet($id, $fetch_from_source = true) {
        $st = $this->dbc->prepare('SELECT * FROM tweets WHERE id=:id');
        $st->bindValue(':id', (int)$id);
        $st->execute();

        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows)) {
            return $rows[0];
        }

        // Tweet is not held locally, fetch and save
        if ($fetch_from_source) {
            return $this->fetch_tweet($id);
        }

        return false;
    }

    public function get_full_tweet($id) {
        $st = $this->dbc->prepare('
            SELECT tn.mptt_left, tn.mptt_right, COUNT(tp.tweet_id)-1 AS mptt_depth,
                t.id AS tweet_id, t.author_id, t.text, t.source, t.created_at,
                a.username, a.name, a.description, a.location, a.avatar, a.verified, a.created_at AS user_created_at
            FROM tree_nodes tn
                LEFT JOIN tree_nodes tp ON (tn.mptt_left BETWEEN tp.mptt_left AND tp.mptt_right)
                LEFT JOIN tweets t ON tn.tweet_id=t.id
                LEFT JOIN authors a ON t.author_id=a.id
            WHERE tn.tree_id=tp.tree_id AND tn.tweet_id=:tweet
            GROUP BY tn.tweet_id ORDER BY tn.mptt_left
        ');
        $st->bindValue(':tweet', (int)$id);
        $st->execute();
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    public function save_tweet($obj) {
        $data = $this->tweet_from_object($obj);
        if (!$this->get_tweet($obj->id, false)) {
            $st = $this->dbc->prepare('INSERT INTO tweets(id, author_id, root_id, text, source, created_at, raw_object) VALUES(:id, :author_id, :root_id, :text, :source, STR_TO_DATE(:created_at, "%Y-%m-%dT%H:%i:%s.%fZ"), :raw_object)');
            foreach ($data as $k => $v) {
                $st->bindValue(':'.$k, $v);
            }
            $st->execute();
        }
    }

    protected function fetch_tweet($id) {
        $tweet = self::$connection->get('tweets/'.(int)$id, [
            'expansions' => join(',', [
                'entities.mentions.username',
                'referenced_tweets.id',
                'attachments.media_keys',
                'author_id',
                'attachments.poll_ids',
                'in_reply_to_user_id',
                'geo.place_id',
                'referenced_tweets.id.author_id',
            ]),
            'tweet.fields' => join(',', [
                'attachments', 'author_id', 'context_annotations', 'created_at',
                'conversation_id', 'entities', 'geo', 'id', 'in_reply_to_user_id',
                'lang', 'possibly_sensitive', 'referenced_tweets', 'source', 'text', 
                'reply_settings', 'withheld', 'public_metrics',
            ]),
            'user.fields' => join(',', [
                'created_at', 'description', 'entities', 'id', 'location', 'name',
                'profile_image_url', 'url', 'username', 'verified', 'protected',
                'pinned_tweet_id', 'withheld', 'public_metrics',
            ]),
            'media.fields' => join(',', [
                'alt_text', 'duration_ms', 'height', 'media_key', 'preview_image_url',
                'public_metrics', 'type', 'url', 'variants', 'width',
            ]),
            'place.fields' => join(',', [
                'contained_within', 'country', 'country_code', 'full_name', 'geo',
                'name', 'id', 'place_type',
            ]),
            'poll.fields' => join(',', [
                'duration_minutes', 'end_datetime', 'id', 'options', 'voting_status',
            ]),
        ]);
        if (self::$connection->getLastHttpCode() != 200) {
            throw new bsException('Twitter connection failed', 500);
        }
        if (!isset($tweet, $tweet->data, $tweet->data->id)) {
            throw new bsException('Tweet fetch failed', 404);
        }
        if (isset($tweet->data->referenced_tweets) && count($tweet->data->referenced_tweets)) {
            foreach ($tweet->data->referenced_tweets as $ref) {
                if (!$this->get_link($ref->id, $tweet->data->id)) {
                    $type_map = [
                        'replied_to' => 'reply',
                        'quoted' => 'quote',
                    ];
                    $this->save_link($ref->id, $tweet->data->id, $type_map[$ref->type]);
                }
            }
        }
        if (isset($tweet->includes)) {
            if (isset($tweet->includes->users)) {
                foreach ($tweet->includes->users as $user) {
                    $this->save_author($user);
                }
            }
            if (isset($tweet->includes->tweets)) {
                foreach($tweet->includes->tweets as $ref_tweet) {
                    $this->get_tweet($ref_tweet->id);
                }
            }
        }

        $this->save_tweet($tweet->data);
        return $this->tweet_from_object($tweet->data);
    }

    protected function tweet_from_object($obj) {
        return [
            'id' => $obj->id,
            'author_id' => $obj->author_id,
            'root_id' => $obj->conversation_id,
            'text' => $obj->text,
            'source' => $obj->source,
            'created_at' => $obj->created_at,
            'raw_object' => json_encode($obj),
        ];
    }

    //--- Authors ---------------------------------------------------------

    public function get_author($id) {
        $st = $this->dbc->prepare('SELECT * FROM authors WHERE id=:id');
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
        $existing_author = $this->get_author($obj->id);
        if ($existing_author) {
            if (hash('sha256', $existing_author['raw_object']) === hash('sha256', json_encode($obj))) {
                // No change to the existing record, drop out
                return;
            }
            $st = $this->dbc->prepare('DELETE from authors WHERE id=:id');
            $st->bindValue(':id', $obj->id);
            $st->execute();
        }

        $st = $this->dbc->prepare('INSERT INTO authors(id, username, name, description, avatar, location, verified, created_at, raw_object) VALUES(:id, :username, :name, :description, :avatar, :location, :verified, STR_TO_DATE(:created_at, "%Y-%m-%dT%H:%i:%s.%fZ"), :raw_object)');
        foreach ($data as $k => $v) {
            $st->bindValue(':'.$k, $v);
        }
        $st->execute();
    }

    protected function author_from_object($obj) {
        return [
            'id' => $obj->id,
            'name' => $obj->name,
            'created_at' => $obj->created_at,
            'avatar' => $obj->profile_image_url,
            'description' => $obj->description,
            'location' => isset($obj->location) ? $obj->location : '',
            'username' => $obj->username,
            'verified' => $obj->verified ? 1 : 0,
            'raw_object' => json_encode($obj),
        ];
    }

    //--- Tweet links -----------------------------------------------------

    public function get_link($parent, $child) {
        $st = $this->dbc->prepare('SELECT link_type FROM tweet_links WHERE parent_id=:parent AND child_id=:child');
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
        $st = $this->dbc->prepare('SELECT child_id FROM tweet_links WHERE link_type="reply" AND parent_id=:parent ORDER BY child_id');
        $st->bindValue(':parent', $parent);
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $links[$row['child_id']] = $this->get_reply_links($row['child_id']);
        }
        return $links;
    }

    public function save_link($parent, $child, $link_type) {
        $st = $this->dbc->prepare('INSERT INTO tweet_links(parent_id, child_id, link_type) VALUES(:parent, :child, :type)');
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
                a.username, a.name, a.description, a.location, a.avatar, a.verified, a.created_at AS user_created_at
            FROM tree_nodes tn
                LEFT JOIN tree_nodes tp ON (tn.mptt_left BETWEEN tp.mptt_left AND tp.mptt_right)
                LEFT JOIN tweets t ON tn.tweet_id=t.id
                LEFT JOIN authors a ON t.author_id=a.id
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
                throw new bsException('Thread is less than 10 tweets, viewing through Thread Tree is not advised', 400);
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
            $tweets[$row['root_id']] = $row + ['tweet_data' => $this->get_full_tweet($row['root_id'])];
        }
        return $tweets;
    }

    public function get_recent_roots() {
        $st = $this->dbc->prepare('SELECT * FROM trees WHERE (deleted_at="0000-00-00" OR deleted_at IS NULL) ORDER BY id DESC LIMIT 4');
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $tweets = [];
        foreach ($rows as $row) {
            $tweets[$row['root_id']] = $row + ['tweet_data' => $this->get_full_tweet($row['root_id'])];
            $tweets[$row['root_id']]['count'] = $tweets[$row['root_id']]['tweet_data']['mptt_right'] / 2;
        }

        return $tweets;
    }

    public function handle_recent_mentions() {
        $st = $this->dbc->prepare('SELECT setting_value FROM settings WHERE setting_key="last_mention"');
        $st->execute();
        $last_mention = $st->fetch(PDO::FETCH_ASSOC)['setting_value'];

        $mentions = self::$connection->get('users/'.$this->config->user_id.'/mentions', [
            'since_id' => (int)$last_mention, 'max_results' => self::MENTIONS_PER_RUN
        ]);
        if (self::$connection->getLastHttpCode() != 200) {
            throw new bsException('Twitter connection failed', 500);
        }
        $trees = [];

        if ($mentions->meta->result_count) {
            foreach ($mentions->data as $mention) {
                if (stripos($mention->text, "@{$this->config->user_name} unroll") !== false) {
                    $mention_tweet = $this->get_tweet($mention->id);
                    $trees[$mention->id] = $this->save_tree($mention->id, $mention_tweet['author_id']);

                    // DMs aren't in Twitter API 2 yet...
                    self::$connection->setApiVersion('1.1');
                    self::$connection->post('direct_messages/events/new', [
                        'event' => [
                            'type' => 'message_create',
                            'message_create' => [
                                'target' => ['recipient_id' => $mention_tweet['author_id']],
                                'message_data' => ['text' => sprintf(
                                    "Your thread has been unrolled! You can view the full conversation at: %s/%s",
                                    $this->config->root_url,
                                    $mention->id
                                )]
                            ]
                        ]
                    ], true);
                    if (self::$connection->getLastHttpCode() != 200) {
                        throw new bsException('Twitter post failed', 500);
                    }
                    self::$connection->setApiVersion('2');
                }
            }

            $st = $this->dbc->prepare('UPDATE settings SET setting_value=:v WHERE setting_key="last_mention"');
            $st->bindValue(':v', $mentions->meta->newest_id);
            $st->execute();
        }

        return $trees;
    }
}
