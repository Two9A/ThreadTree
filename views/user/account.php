<?php
include_once('../views/index/_helpers.php');
?>
<h1>Logged in as @<?= $this->username ?></h1>
<?php if (isset($this->flash_msg)) { ?>
<h2><?= $this->flash_msg ?></h2>
<?php } ?>
<h2>Your unrolls</h2>
<?php if (count($this->roots)) { ?>
<?php foreach ($this->roots as $tweet_id => $data) { ?>
 <ul class="tweet-list tweet-list-top">
  <li>
   <a href="/<?= $tweet_id ?>" class="home-tweet">Visit this thread</a>
   <?php render_tweet($tweet_id, [$tweet_id => $data['tweet_data']]); ?>
  </li>
 </ul>
<?php } ?>
<?php } else { ?>
<p>You don't have any unrolls! Request one today by tweeting <code>@threadtreeapp unroll</code> on any Twitter thread.</p>
<?php } ?>
