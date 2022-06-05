<?php
use Abraham\TwitterOAuth\TwitterOAuth;

class UserController extends BaseController {
    protected $config;

    public function __construct() {
        parent::__construct();
        $this->config = bsFactory::get('config');
    }

    public function loginAction() {
        if (isset($_SESSION['access_token'])) {
            $this->redirect($this->config->root_url.'/user/account');
        }
        
        $conn = new TwitterOAuth(
            $this->config->api_key,
            $this->config->api_secret
        );
        $request_token = $conn->oauth('oauth/request_token', [
            'oauth_callback' => sprintf("%s/user/oauth", $this->config->root_url)
        ]);
        if (!isset($request_token, $request_token['oauth_token'])) {
            throw new bsException('Authentication failure', 500);
        }

        $_SESSION['oauth_token'] = $request_token['oauth_token'];
        $_SESSION['oauth_token_secret'] = $request_token['oauth_token_secret'];

        $auth_url = $conn->url('oauth/authenticate', ['oauth_token' => $request_token['oauth_token']]);
        if (!$auth_url) {
            throw new bsException('Authorization failure', 500);
        }
        $this->redirect($auth_url);
    }

    public function oauthAction() {
        if (!isset($_GET['oauth_token'], $_GET['oauth_verifier'])) {
            throw new bsException('Invalid return', 400);
        }
        if ($_GET['oauth_token'] !== $_SESSION['oauth_token']) {
            throw new bsException('Authentication mismatch', 500);
        }
        $conn = new TwitterOAuth(
            $this->config->api_key,
            $this->config->api_secret,
            $_SESSION['oauth_token'],
            $_SESSION['oauth_token_secret']
        );
        $access_token = $conn->oauth('oauth/access_token', ['oauth_verifier' => $_GET['oauth_verifier']]);
        if (!isset($access_token, $access_token['oauth_token'])) {
            throw new bsException('Login failure', 500);
        }

        // oauth_token, oauth_token_secret, user_id, screen_name
        $_SESSION['access_token'] = $access_token;
        $this->redirect($this->config->root_url.'/user/account');
    }

    public function logoutAction() {
        session_destroy();
        $this->redirect($this->config->root_url);
    }

    public function accountAction() {
        if (!$this->loggedin) {
            $this->redirect($this->config->root_url);
        }

        $t = new TweetModel();
        $this->view->username = $this->loggedin['screen_name'];
        $this->view->roots = $t->get_roots_by_requester($this->loggedin['user_id']);

        if (isset($_SESSION['flash_msg'])) {
            $this->view->flash_msg = $_SESSION['flash_msg'];
            unset($_SESSION['flash_msg']);
        }
    }

    public function deleteAction($params) {
        if (!$this->loggedin) {
            $this->redirect($this->config->root_url);
        }
        if (!isset($params['tweet'])) {
            $this->redirect($this->config->root_url);
        }

        $t = new TweetModel();
        $id = (int)$params['tweet'];
        $tree = $t->get_tree_by_tweet($id);

        if ($tree['info']['requester_id'] !== $this->loggedin['user_id']) {
            $this->redirect($this->config->root_url);
        }

        if (!$t->delete_tree($id, $this->loggedin['user_id'])) {
            $this->redirect($this->config->root_url);
        }

        $_SESSION['flash_msg'] = sprintf("Tree by @%s deleted", $tree['data'][$tree['info']['root_id']]['username']);
        $this->redirect($this->config->root_url . '/user/account');
    }
}
