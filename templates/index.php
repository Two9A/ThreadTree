<!doctype html>
<html>
 <head>
  <title><?=$this->__info['title']?></title>
  <link rel="stylesheet" type="text/css" href="/css/index.css">
  <link rel="icon" type="image/png" href="/img/favicon-16.png" sizes="16x16">
  <link rel="icon" type="image/png" href="/img/favicon-32.png" sizes="32x32">
  <link rel="icon" type="image/png" href="/img/favicon-96.png" sizes="96x96">
  <?php foreach ($this->__assets['css'] as $css) { ?>
  <link rel="stylesheet" type="text/css" href="<?= $css ?>">
  <?php } ?>
  <?php foreach ($this->__assets['js'] as $js) { ?>
  <script src="<?= $js ?>"></script>
  <?php } ?>
  <script>
window.__CONFIG = <?= json_encode($config) ?>;
  </script>
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-Y8SVBSRQBQ"></script>
  <script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date()); gtag('config', 'G-Y8SVBSRQBQ');
  </script>
 </head>
 <body>
  <header>
   <div id="head-content">
    <a href="https://threadtree.xyz/" id="logo">Thread Tree</a>
    <nav>
     <?php if (isset($loggedin)) { ?>
     <a href="/user/account">Account</a>
     <a href="/user/logout">Logout</a>
     <?php } else { ?>
     <a href="/user/login">Login</a>
     <?php } ?>
    </nav>
   </div>
  </header>
  <article>
<?=$view_output?>
  </article>
  <footer>
   <div id="foot-content">
    <a href="https://threadtree.xyz/">Thread Tree</a> by <a href="https://imrannazar.com/">Imran Nazar</a>, <a href="https://twitter.com/_inazar">@_inazar</a>, 2022. <a href="https://pixabay.com/illustrations/tree-plant-nature-spring-forest-1035173/">Tree illustration</a> by <a href="https://pixabay.com/users/catkin-127770/">Catkin</a>.
   </div>
  </footer>
 </body>
</html>
