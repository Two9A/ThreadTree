<?php
include_once('_helpers.php');
?>
<h1>Visualizing threaded conversations on Twitter</h1>
<ul class="tweet-list tweet-list-top">
 <li class="tweet-body">Find a conversation on Twitter</li>
 <ul class="tweet-list">
  <li class="tweet-body">Grab a link to a tweet in the thread, and post it here:<form id="new-tree" action="/index/save" method="post">
    <div id="new-tree-error"></div>
    <div id="new-tree-content">
     <input type="text" name="tweet" id="new-tree-url" />
     <input type="submit" name="new-tree-submit" id="new-tree-submit" value="Get tree" />
    </div>
   </form></li>
  <ul class="tweet-list">
   <li class="tweet-body">Thread Tree will build a threaded view for you to read, in realtime (up to 500 tweets)</li>
  </ul>
  <li class="tweet-body">Or reply anywhere in the thread, with <code>@threadtreeapp unroll</code></li>
  <ul class="tweet-list">
   <li class="tweet-body">Thread Tree will DM you a link to a threaded view of the full conversation (up to 500 tweets)</li>
   <ul class="tweet-list">
    <li class="tweet-body"><a href="/user/login">Login to Thread Tree with your Twitter account</a> to view or delete your unrolls</li>
   </ul>
  </ul>
  <li class="tweet-body">Note that Twitter limits conversation search to the past 7 days: if the root of the thread is older than this, Thread Tree won't be able to build a thread</li>
 </ul>
</ul>
<div id="new-tree-overlay" class="hidden">
 <p>Reticulating splines...</p>
</div>
<h2>Recent unrolled threads</h2>
<div class="home-unrolls">
<?php foreach ($this->unroll_roots as $tweet_id => $data) { ?>
 <ul class="tweet-list tweet-list-top">
  <li>
   <a href="/<?= $tweet_id ?>" class="home-tweet">Visit this thread</a>
   <?php render_tweet($tweet_id, [$tweet_id => $data['tweet_data']], $data['count']); ?>
  </li>
 </ul>
<?php } ?>
</div>
