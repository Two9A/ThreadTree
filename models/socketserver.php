<?php
use Ratchet\Http\HttpServer;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;

class SocketserverModel implements MessageComponentInterface {
    protected $clients_by_resourceid;
    protected $resourceids_by_tweetid;

    protected function _log($str) {
        printf("[%s] %s\n", date('YmdHis'), $str);
    }

    public function start($port) {
        $this->_log('Server started on port '.$port);
        $server = IoServer::factory(new HttpServer(new WsServer($this)), $port);
        $server->run();
    }

    public function __construct() {
        $this->clients_by_resourceid = [];
        $this->resourceids_by_tweetid = [];
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients_by_resourceid[$conn->resourceId] = $conn;
        $this->_log('New connection: '.$conn->resourceId);
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $this->_log('From: '.$from->resourceId.'; message: '.$msg);
        $json = json_decode($msg, true);
        switch ($json['type']) {
            case 'PING':
                $this->_log('  Replying with PONG');
                $from->send(json_encode(['type' => 'PONG']));
                break;

            case 'REGISTER':
                $this->_log(sprintf('  Connection for tweet %d', $json['payload']['id']));
                $this->resourceids_by_tweetid[$json['payload']['id']] = $from->resourceId;
                break;

            case 'FETCH_INIT':
            case 'FETCH_LIST':
            case 'FETCH_EXTANT':
            case 'FETCH_MISSING':
            case 'FETCH_DONE':
            case 'FETCH_ERROR':
                if (isset(
                    $json['payload']['client'],
                    $this->resourceids_by_tweetid[$json['payload']['client']],
                    $this->clients_by_resourceid[$this->resourceids_by_tweetid[$json['payload']['client']]]
                )) {
                    $this->_log(sprintf('  Forwarding to %d', $this->resourceids_by_tweetid[$json['payload']['client']]));
                    $this->clients_by_resourceid[$this->resourceids_by_tweetid[$json['payload']['client']]]->send($msg);
                }
                break;
        }
    }

    public function onClose(ConnectionInterface $conn) {
        if (in_array($conn->resourceId, array_values($this->resourceids_by_tweetid))) {
            $k = array_search($conn->resourceId, $this->resourceids_by_tweetid);
            unset($this->resourceids_by_tweetid[$k]);
        }
        unset($this->clients_by_resourceid[$conn->resourceId]);
        $this->_log('Connection closed: '.$conn->resourceId);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $conn->close();
        $this->_log('Connection error: '.$e->getMessage());
    }
}
