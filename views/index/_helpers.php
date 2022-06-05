<?php
function render_tweet($tweet_id, $tweet_data, $count = null) {
    if (!isset($tweet_data[$tweet_id])) {
        return;
    }
    $data = $tweet_data[$tweet_id];
    ?>
    <span class="expando"></span>
    <div class="tweet" id="tweet-<?= $tweet_id ?>">
     <div class="tweet-head">
      <span class="tweet-avatar"><img src="<?= $data['avatar'] ?>" /></span>
      <span class="tweet-author">
       <span class="tweet-author-name"><?= $data['name'] ?></span>
       <a href="https://twitter.com/<?= $data['username'] ?>" class="tweet-author-username">@<?= $data['username'] ?></a>
       <a href="https://twitter.com/<?= $data['username'] ?>/status/<?= $tweet_id ?>" class="tweet-link"><?= $data['created_at'] ?></a>
      </span>
     </div>
     <?php if ($count) { ?>
     <div class="tweet-count"><?= $count,' ' ?> tweets</div>
     <?php } ?>
     <div class="tweet-body"><?= $data['text'] ?></div>
    </div>
    <?
}
