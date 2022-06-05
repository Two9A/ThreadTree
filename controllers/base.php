<?php
class BaseController extends bsControllerBase {
    protected $loggedin;
    protected $config;

    public function __construct() {
        parent::__construct();
        
        $this->config = bsFactory::get('config');
        $this->view->add_template_value('config', [
            'ws_uri' => $this->config->ws_uri,
        ]);
        if (isset($_SESSION['access_token'])) {
            $this->loggedin = $_SESSION['access_token'];
            $this->view->add_template_value('loggedin', $this->loggedin);
        }
    }
}
