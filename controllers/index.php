<?php
class IndexController extends BaseController {
    public function __construct() {
        parent::__construct();
    }

    public function indexAction() {
        $this->view->prepend_title('Visualize threaded conversations on Mastodon: ');
        $t = new TootModel();
        $this->view->unroll_roots = $t->get_recent_roots();
        $this->view->add_asset('js', '/js/machine.js');
        //$this->view->add_asset('js', '/js/home.js');
        return 'index';
    }

    public function catchall($method) {
        $id = (int)$method;
        if (!$id) {
            return $this->indexAction();
        }
        $t = new TootModel();
        
        $this->view->tweet_id = $id;
        $this->view->tweets = $t->get_tree_by_tweet($id);
        if (!$this->view->tweets || !count($this->view->tweets['data'])) {
            return $this->indexAction();
        }

        $root = current($this->view->tweets['data']);
        $this->view->prepend_title('Thread by '.$root['username'].' - ');
        $this->view->add_asset('js', '/js/thread.js');

        if ($this->loggedin) {
            $this->view->loggedin = $this->loggedin;
        }
        return 'tree';
    }

    public function saveAction($params) {
        if (isset($_POST['tweet'])) {
            $url_parts = parse_url($_POST['tweet']);
            if ($url_parts['host'] === 'twitter.com' &&
                count(explode('/', $url_parts['path'])) == 4
            ) {
                $path_parts = explode('/', $url_parts['path']);
                $params = ['tweet' => (int)$path_parts[3]];
            } else {
                throw new bsException("That doesn't look like a tweet URL, sorry", 400);
            }
        } else if (isset($_POST['toot'])) {
            $params = ['toot' => $_POST['toot']];
        }
        if (!isset($params)) {
            throw new bsException('Nope', 404);
        }

        if (isset($params['toot'])) {
            $t = new TootModel();
            $toot = $t->fetch_toot_by_url($params['toot']);
            if ($t->save_tree((int)$toot['id'], 0)) {
                $this->redirect($this->config->root_url.'/'.$toot['id']);
            }
        } else if (isset($params['tweet'])) {
            if ($this->loggedin) {
                $user_id = $this->loggedin['user_id'];
            } else {
                $user_id = $this->config->user_id;
            }

            $t = new TweetModel();
            if ($t->save_tree((int)$params['tweet'], $user_id)) {
                $this->redirect($this->config->root_url.'/'.(int)$params['tweet']);
            }
        }
    }

    public function cronAction() {
        $t = new TootModel();
        var_dump($t->handle_recent_mentions());exit;
    }
}
