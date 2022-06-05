<?php
include_once('_helpers.php');
function render_tree($tree, $tweet_data) {
    foreach ($tree as $item) {
        if (is_array($item)) {
            ?><ul class="tweet-list"><? render_tree($item, $tweet_data); ?></ul><?
        } else {
            ?><li><? render_tweet($item, $tweet_data); ?></li><?
        }
    }
}
?>
<?php if ($this->loggedin && $this->loggedin['user_id'] === $this->tweets['info']['requester_id']) { ?>
<p>You are logged in as the requester of this tree.</p>
<form action="/user/delete/tweet/<?= $this->tweet_id ?>" method="post" onsubmit="return confirm('Only if you\'re sure?')">
 <input type="submit" value="Delete tree" />
</form>
<?php } ?>
<?php
$root = current($this->tweets['data']);
?>
<h2>Thread by @<?= $root['username'] ?>, <?= count($this->tweets['data']) ?> tweets</h2>
<ul class="tweet-list tweet-list-top">
<?
render_tree($this->tweets['tree'], $this->tweets['data']);
?>
</ul>
